<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUnidentifiedDeposit;
use App\Models\CentralFinanceUnidentifiedDepositAllocation;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records physical group money which cannot yet be attributed to a School or
 * Student. Matching later settles a receivable only; it never posts a second
 * Fund Account or Ledger movement.
 */
final class CentralFinanceUnidentifiedDepositService
{
    public function __construct(
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    public function record(
        CentralFinanceUser $actor,
        CentralFinanceFundAccount $account,
        string $amount,
        CarbonImmutable $receivedAt,
        string $idempotencyReference,
        ?string $bankReference = null,
        ?string $description = null,
        ?string $knownPayer = null,
    ): CentralFinanceUnidentifiedDeposit {
        $amount = CentralFinanceDecimal::normalize($amount);
        if (CentralFinanceDecimal::compare($amount, '0') <= 0 || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) {
            throw new InvalidArgumentException('Unidentified Deposit input is invalid.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $account, $amount, $receivedAt, $idempotencyReference, $bankReference, $description, $knownPayer): CentralFinanceUnidentifiedDeposit {
            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            if ($account->owner_type !== CentralFinanceFundAccount::OWNER_HQ || $account->school_id !== null || (int) $account->group_id < 1) {
                throw new AuthorizationException('Unidentified Deposits require an active Group-owned Fund Account.');
            }
            $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $account->group_id);
            $key = hash('sha256', implode('|', ['unidentified-deposit', $account->id, $idempotencyReference]));
            $existing = CentralFinanceUnidentifiedDeposit::on('mysql')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) return $existing;
            $bankReference = $bankReference === null ? null : trim($bankReference);
            if ($bankReference === '') $bankReference = null;
            if ($bankReference !== null && CentralFinanceUnidentifiedDeposit::on('mysql')->where(['fund_account_id' => $account->id, 'bank_reference' => $bankReference])->exists()) {
                throw new InvalidArgumentException('This bank reference is already recorded for the selected Fund Account.');
            }
            $deposit = CentralFinanceUnidentifiedDeposit::on('mysql')->create([
                'group_id' => $account->group_id, 'fund_account_id' => $account->id,
                'idempotency_key' => $key, 'bank_reference' => $bankReference,
                'description' => $description === null ? null : trim($description),
                'known_payer' => $knownPayer === null ? null : trim($knownPayer),
                'amount' => $amount, 'currency' => $account->currency,
                'status' => CentralFinanceUnidentifiedDeposit::UNIDENTIFIED,
                'received_date' => $receivedAt->toDateString(), 'recorded_at' => $receivedAt,
                'recorded_by' => $actor->id,
            ]);
            CentralFinanceLedgerEntry::on('mysql')->create([
                'entry_uuid' => (string) Str::uuid(), 'school_id' => null,
                'fund_account_id' => $account->id, 'entry_date' => $receivedAt->toDateString(),
                'occurred_at' => $receivedAt, 'source_type' => 'central_unidentified_deposit',
                'source_id' => $deposit->deposit_uuid, 'source_line' => 'primary',
                'reference_no' => $bankReference, 'transaction_type' => 'unidentified_deposit',
                'currency' => $account->currency, 'money_in' => $amount, 'money_out' => '0',
                'operating_income' => '0', 'operating_expense' => '0',
                'memo' => $deposit->description, 'created_by' => $actor->id,
            ]);
            CentralFinanceDocumentAudit::on('mysql')->create([
                'school_id' => null, 'group_id' => $account->group_id,
                'document_type' => 'unidentified_deposit', 'document_id' => $deposit->id,
                'action' => 'recorded', 'actor_id' => $actor->id,
                'reason' => 'Physical group deposit recorded pending identification.',
                'before_values' => null,
                'after_values' => ['fund_account_id' => $account->id, 'amount' => $amount, 'currency' => $account->currency, 'bank_reference' => $bankReference],
            ]);
            return $deposit;
        });
    }

    public function match(
        CentralFinanceUser $actor,
        int $depositId,
        int $receivableId,
        string $amount,
        CarbonImmutable $matchedAt,
        string $reason,
        string $idempotencyReference,
    ): CentralFinanceUnidentifiedDepositAllocation {
        $amount = CentralFinanceDecimal::normalize($amount);
        if (CentralFinanceDecimal::compare($amount, '0') <= 0 || trim($reason) === '' || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) {
            throw new InvalidArgumentException('Unidentified Deposit matching input is invalid.');
        }
        return DB::connection('mysql')->transaction(function () use ($actor, $depositId, $receivableId, $amount, $matchedAt, $reason, $idempotencyReference): CentralFinanceUnidentifiedDepositAllocation {
            $deposit = CentralFinanceUnidentifiedDeposit::on('mysql')->lockForUpdate()->findOrFail($depositId);
            if ($deposit->status === CentralFinanceUnidentifiedDeposit::REVERSED) throw new InvalidArgumentException('A reversed Unidentified Deposit cannot be matched.');
            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($deposit->fund_account_id);
            if ($account->owner_type !== CentralFinanceFundAccount::OWNER_HQ
                || $account->school_id !== null
                || (int) $account->group_id !== (int) $deposit->group_id
                || strtoupper((string) $account->currency) !== strtoupper((string) $deposit->currency)) {
                throw new InvalidArgumentException('The Unidentified Deposit no longer has its original active Group Fund Account context.');
            }
            $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $deposit->group_id);
            $receivable = CentralFinanceReceivable::on('mysql')->lockForUpdate()->findOrFail($receivableId);
            $this->configuration->assertHeadFinanceCanConfigureSchool($actor, School::on('mysql')->findOrFail($receivable->school_id));
            $this->dataIsolation->assertWorkflowWritable('receivable', $receivable->id);
            if (strtoupper((string) $receivable->currency) !== strtoupper((string) $deposit->currency)) throw new InvalidArgumentException('Deposit and receivable currency must match.');
            if (!in_array($receivable->status, [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL], true)) throw new InvalidArgumentException('Only an open or partially paid receivable can be matched.');
            $key = hash('sha256', implode('|', ['unidentified-deposit-match', $deposit->id, $receivable->id, $idempotencyReference]));
            $existing = CentralFinanceUnidentifiedDepositAllocation::on('mysql')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) return $existing;
            $alreadyMatched = CentralFinanceUnidentifiedDepositAllocation::on('mysql')->where('unidentified_deposit_id', $deposit->id)->lockForUpdate()->pluck('amount')
                ->reduce(fn (string $sum, mixed $line): string => CentralFinanceDecimal::add($sum, (string) $line), CentralFinanceDecimal::normalize('0'));
            $remainingDeposit = CentralFinanceDecimal::subtract((string) $deposit->amount, $alreadyMatched);
            $remainingReceivable = CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid);
            if (CentralFinanceDecimal::compare($amount, $remainingDeposit) > 0 || CentralFinanceDecimal::compare($amount, $remainingReceivable) > 0) throw new InvalidArgumentException('The matching amount exceeds the remaining Deposit or Receivable balance.');
            $allocation = CentralFinanceUnidentifiedDepositAllocation::on('mysql')->create([
                'unidentified_deposit_id' => $deposit->id, 'school_id' => $receivable->school_id,
                'student_profile_id' => $receivable->student_profile_id, 'receivable_id' => $receivable->id,
                'idempotency_key' => $key, 'amount' => $amount, 'currency' => $deposit->currency,
                'matched_at' => $matchedAt, 'matched_by' => $actor->id, 'reason' => trim($reason),
            ]);
            $beforeDepositStatus = $deposit->status;
            $beforeReceivablePaid = (string) $receivable->amount_paid;
            $paid = CentralFinanceDecimal::add((string) $receivable->amount_paid, $amount);
            $receivable->update(['amount_paid' => $paid, 'status' => CentralFinanceDecimal::compare($paid, (string) $receivable->amount_due) === 0 ? CentralFinanceReceivable::PAID : CentralFinanceReceivable::PARTIAL]);
            $matched = CentralFinanceDecimal::add($alreadyMatched, $amount);
            $deposit->update(['status' => CentralFinanceDecimal::compare($matched, (string) $deposit->amount) === 0 ? CentralFinanceUnidentifiedDeposit::APPLIED : CentralFinanceUnidentifiedDeposit::PARTIALLY_APPLIED]);
            CentralFinanceDocumentAudit::on('mysql')->create([
                'school_id' => $receivable->school_id, 'group_id' => $deposit->group_id,
                'document_type' => 'unidentified_deposit_allocation', 'document_id' => $allocation->id,
                'action' => 'matched', 'actor_id' => $actor->id, 'reason' => trim($reason),
                'before_values' => ['deposit_status' => $beforeDepositStatus, 'receivable_paid' => $beforeReceivablePaid],
                'after_values' => ['deposit_status' => $deposit->status, 'receivable_paid' => $paid, 'amount' => $amount],
            ]);
            // Deliberately no Payment, Receipt, Fund Account, or Ledger write:
            // physical money was recorded exactly once when the Deposit arrived.
            return $allocation;
        });
    }
}
