<?php

namespace App\Services;

use App\Models\CentralFinanceBankTransactionIdentity;
use App\Models\CentralFinanceFundAccount;
use App\Support\CentralFinanceCurrency;
use App\Support\CentralFinanceDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

/** Shared physical receipt identity; document services retain authorization. */
final class CentralFinanceBankTransactionIdentityService
{
    /**
     * Call inside the same Central transaction that posts the physical receipt.
     * sourceId is the document's stable, server-computed idempotency key.
     * A later deposit allocation uses its original reservation, never a new one.
     */
    public function reserve(
        CentralFinanceFundAccount $account,
        string $currency,
        string $amount,
        string $sourceType,
        string $sourceId,
        ?string $bankReference,
        ?string $manualIdentity = null,
        ?string $manualReason = null,
        ?string $payloadHash = null,
    ): CentralFinanceBankTransactionIdentity {
        $connection = DB::connection('mysql');
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Bank transaction identity reservation requires the posting transaction.');
        }
        if (!Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities')) {
            throw new LogicException('The Central bank transaction identity migration is required before posting.');
        }
        $identity = self::identity($bankReference, $manualIdentity, $manualReason);
        $currency = CentralFinanceCurrency::normalize($currency);
        $amount = CentralFinanceDecimal::normalize($amount);
        if (!in_array($sourceType, ['unidentified_deposit', 'payment', 'other_income'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $sourceId)
            || ($payloadHash !== null && !preg_match('/^[a-f0-9]{64}$/D', $payloadHash))
            || CentralFinanceDecimal::compare($amount, '0') <= 0) {
            throw new InvalidArgumentException('Bank transaction origin or amount is invalid.');
        }

        // All posting paths serialize on the physical account, including two
        // different document types trying to reserve the same bank reference.
        $lockedAccount = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
        if (CentralFinanceCurrency::normalize((string) $lockedAccount->currency) !== $currency) {
            throw new InvalidArgumentException('Bank transaction currency does not match the Fund Account.');
        }
        $existing = CentralFinanceBankTransactionIdentity::on('mysql')->where([
            'fund_account_id' => $lockedAccount->id,
            'currency' => $currency,
            'identity_hash' => $identity['identity_hash'],
        ])->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->source_type !== $sourceType || $existing->source_id !== $sourceId
                || CentralFinanceDecimal::compare((string) $existing->amount, $amount) !== 0
                || $existing->payload_hash !== $payloadHash
                || $existing->identity_namespace !== $identity['identity_namespace']
                || $existing->manual_reason !== $identity['manual_reason']) {
                throw new InvalidArgumentException('This bank transaction is already recorded with another origin or content.');
            }
            return $existing;
        }
        if (CentralFinanceBankTransactionIdentity::on('mysql')->where(['source_type' => $sourceType, 'source_id' => $sourceId])->exists()) {
            throw new InvalidArgumentException('This receipt origin is already linked to another bank transaction.');
        }

        return CentralFinanceBankTransactionIdentity::on('mysql')->create($identity + [
            'fund_account_id' => $lockedAccount->id, 'currency' => $currency,
            'amount' => $amount, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'payload_hash' => $payloadHash,
        ]);
    }

    /**
     * Reference matching is case-insensitive and collapses whitespace; punctuation
     * remains significant. Manual identities retain an explicit audit namespace,
     * but share the uniqueness key so switching mode cannot bypass a reference.
     * No client request key is accepted as evidence of physical bank uniqueness.
     * @return array{identity_namespace:string,normalized_identity:string,identity_hash:string,manual_reason:?string}
     */
    public static function identity(?string $bankReference, ?string $manualIdentity = null, ?string $manualReason = null): array
    {
        $reference = self::normalizeReference($bankReference);
        $manual = self::normalizeReference($manualIdentity);
        $reason = trim((string) $manualReason);
        if ($reference !== '') {
            if ($manual !== '' || $reason !== '') {
                throw new InvalidArgumentException('Use either a Bank Reference or a manual transaction identity.');
            }
            $namespace = 'bank_reference';
            $value = $reference;
        } else {
            if ($manual === '' || $reason === '' || mb_strlen($reason) > 2000) {
                throw new InvalidArgumentException('A missing Bank Reference requires an explicit manual transaction identity and reason.');
            }
            $namespace = 'manual';
            $value = $manual;
        }

        return [
            'identity_namespace' => $namespace, 'normalized_identity' => $value,
            'identity_hash' => hash('sha256', $value),
            'manual_reason' => $namespace === 'manual' ? $reason : null,
        ];
    }

    public static function normalizeReference(?string $value): string
    {
        if ($value === null) return '';
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Bank transaction identity contains invalid characters.');
        }
        $value = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
        if (mb_strlen($value) > 100) throw new InvalidArgumentException('Bank transaction identity must not exceed 100 characters.');
        return $value;
    }
}
