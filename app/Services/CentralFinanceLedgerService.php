<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Central Finance Ledger writer has no route/controller in Checkpoint 3.
 * It is the single canonical recording seam for future central documents.
 */
final class CentralFinanceLedgerService
{
    public function __construct(
        private readonly CentralFinanceFundAccountScopeService $scope,
        private readonly CentralFinanceFundAccountBalanceService $balances,
    ) {}

    public function recordOperatingIncome(User $actor, CentralFinanceFundAccount $account, int $schoolId, string $sourceType, string $sourceId, float $amount, CarbonImmutable $occurredAt, ?string $referenceNo = null): CentralFinanceLedgerEntry
    {
        return $this->recordSingle($actor, $account, $schoolId, $sourceType, $sourceId, 'primary', CentralFinanceLedgerEntry::TYPE_OPERATING_INCOME, $amount, $occurredAt, $referenceNo);
    }

    public function recordOperatingExpense(User $actor, CentralFinanceFundAccount $account, int $schoolId, string $sourceType, string $sourceId, float $amount, CarbonImmutable $occurredAt, ?string $referenceNo = null): CentralFinanceLedgerEntry
    {
        return $this->recordSingle($actor, $account, $schoolId, $sourceType, $sourceId, 'primary', CentralFinanceLedgerEntry::TYPE_OPERATING_EXPENSE, $amount, $occurredAt, $referenceNo);
    }

    /**
     * Reversals are append-only corrections for a soft-deleted source record.
     * The signed operating amount restores the operating result without
     * presenting the correction as unrelated income or expense.
     */
    public function reverseOperatingIncome(User $actor, CentralFinanceFundAccount $account, int $schoolId, string $sourceType, string $sourceId, float $amount, CarbonImmutable $occurredAt, ?string $referenceNo = null): CentralFinanceLedgerEntry
    {
        return $this->recordReversal($actor, $account, $schoolId, $sourceType, $sourceId, 'void', CentralFinanceLedgerEntry::TYPE_OPERATING_INCOME_REVERSAL, 0, $amount, -$amount, 0, $occurredAt, $referenceNo);
    }

    public function reverseOperatingExpense(User $actor, CentralFinanceFundAccount $account, int $schoolId, string $sourceType, string $sourceId, float $amount, CarbonImmutable $occurredAt, ?string $referenceNo = null): CentralFinanceLedgerEntry
    {
        return $this->recordReversal($actor, $account, $schoolId, $sourceType, $sourceId, 'void', CentralFinanceLedgerEntry::TYPE_OPERATING_EXPENSE_REVERSAL, $amount, 0, 0, -$amount, $occurredAt, $referenceNo);
    }

    /** @return array{source:CentralFinanceLedgerEntry,destination:CentralFinanceLedgerEntry} */
    public function recordInternalTransfer(User $actor, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination, int $schoolId, string $sourceType, string $sourceId, float $amount, CarbonImmutable $occurredAt, ?string $referenceNo = null): array
    {
        $this->assertSource($sourceType, $sourceId);
        $this->assertPositiveAmount($amount);
        $this->scope->assertCanOperate($actor, $source);
        $this->scope->assertCanOperate($actor, $destination);
        return DB::connection('mysql')->transaction(function () use ($actor, $source, $destination, $schoolId, $sourceType, $sourceId, $amount, $occurredAt, $referenceNo): array {
            $source = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($source->id);
            $destination = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($destination->id);
            if (strtoupper($source->currency) !== strtoupper($destination->currency)) {
                throw new InvalidArgumentException('目前仅支持同币种资金移动，跨币种兑换尚未启用。');
            }
            if ($source->id === $destination->id) {
                throw new InvalidArgumentException('Central internal transfers require two distinct accounts.');
            }
            $this->assertSchoolAttribution($schoolId, $source);
            $this->assertSchoolAttribution($schoolId, $destination);
            if (!$this->balances->hasSufficientBalance($source, $amount)) {
                throw new RuntimeException('The source Central Fund Account has insufficient balance.');
            }
            return [
                'source' => $this->append($actor, $source, $schoolId, $sourceType, $sourceId, 'source', CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER, 0, $amount, 0, 0, $occurredAt, $referenceNo),
                'destination' => $this->append($actor, $destination, $schoolId, $sourceType, $sourceId, 'destination', CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER, $amount, 0, 0, 0, $occurredAt, $referenceNo),
            ];
        });
    }

    /**
     * Custody handovers are the one deliberate exception to the normal
     * "one actor operates both accounts" transfer rule. The sender must have
     * custody of the source and the designated receiver must have custody of
     * the destination; no participant gains access to the other account.
     *
     * @return array{source:CentralFinanceLedgerEntry,destination:CentralFinanceLedgerEntry}
     */
    public function recordInternalTransferWithSplitCustody(User $sourceActor, User $destinationActor, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination, int $schoolId, string $sourceType, string $sourceId, float $amount, CarbonImmutable $occurredAt, ?string $referenceNo = null): array
    {
        $this->assertSource($sourceType, $sourceId);
        $this->assertPositiveAmount($amount);
        $this->scope->assertCanOperate($sourceActor, $source);
        $this->scope->assertCanOperate($destinationActor, $destination);

        return DB::connection('mysql')->transaction(function () use ($sourceActor, $destinationActor, $source, $destination, $schoolId, $sourceType, $sourceId, $amount, $occurredAt, $referenceNo): array {
            $source = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($source->id);
            $destination = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($destination->id);
            if (strtoupper($source->currency) !== strtoupper($destination->currency)) {
                throw new InvalidArgumentException('目前仅支持同币种资金移动，跨币种兑换尚未启用。');
            }
            if ($source->id === $destination->id) {
                throw new InvalidArgumentException('Central internal transfers require two distinct accounts.');
            }
            $this->assertSchoolAttribution($schoolId, $source);
            $this->assertSchoolAttribution($schoolId, $destination);
            if (!$this->balances->hasSufficientBalance($source, $amount)) {
                throw new RuntimeException('The source Central Fund Account has insufficient balance.');
            }

            return [
                'source' => $this->append($sourceActor, $source, $schoolId, $sourceType, $sourceId, 'source', CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER, 0, $amount, 0, 0, $occurredAt, $referenceNo),
                'destination' => $this->append($destinationActor, $destination, $schoolId, $sourceType, $sourceId, 'destination', CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER, $amount, 0, 0, 0, $occurredAt, $referenceNo),
            ];
        });
    }

    private function recordSingle(User $actor, CentralFinanceFundAccount $account, int $schoolId, string $sourceType, string $sourceId, string $sourceLine, string $type, float $amount, CarbonImmutable $occurredAt, ?string $referenceNo): CentralFinanceLedgerEntry
    {
        $this->assertSource($sourceType, $sourceId);
        $this->assertPositiveAmount($amount);
        $this->scope->assertCanOperate($actor, $account);
        $account = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($account->id);
        $this->assertSchoolAttribution($schoolId, $account);

        return DB::connection('mysql')->transaction(function () use ($actor, $account, $schoolId, $sourceType, $sourceId, $sourceLine, $type, $amount, $occurredAt, $referenceNo): CentralFinanceLedgerEntry {
            return $type === CentralFinanceLedgerEntry::TYPE_OPERATING_INCOME
                ? $this->append($actor, $account, $schoolId, $sourceType, $sourceId, $sourceLine, $type, $amount, 0, $amount, 0, $occurredAt, $referenceNo)
                : $this->append($actor, $account, $schoolId, $sourceType, $sourceId, $sourceLine, $type, 0, $amount, 0, $amount, $occurredAt, $referenceNo);
        });
    }

    private function recordReversal(User $actor, CentralFinanceFundAccount $account, int $schoolId, string $sourceType, string $sourceId, string $sourceLine, string $type, float $moneyIn, float $moneyOut, float $operatingIncome, float $operatingExpense, CarbonImmutable $occurredAt, ?string $referenceNo): CentralFinanceLedgerEntry
    {
        $this->assertSource($sourceType, $sourceId);
        $this->assertPositiveAmount(max($moneyIn, $moneyOut));
        $this->scope->assertCanOperate($actor, $account);
        $account = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($account->id);
        $this->assertSchoolAttribution($schoolId, $account);

        return DB::connection('mysql')->transaction(fn (): CentralFinanceLedgerEntry => $this->append(
            $actor, $account, $schoolId, $sourceType, $sourceId, $sourceLine,
            $type, $moneyIn, $moneyOut, $operatingIncome, $operatingExpense,
            $occurredAt, $referenceNo,
        ));
    }

    private function append(User $actor, CentralFinanceFundAccount $account, int $schoolId, string $sourceType, string $sourceId, string $sourceLine, string $type, float $moneyIn, float $moneyOut, float $operatingIncome, float $operatingExpense, CarbonImmutable $occurredAt, ?string $referenceNo): CentralFinanceLedgerEntry
    {
        try {
            return CentralFinanceLedgerEntry::on('mysql')->create([
                'entry_uuid' => (string) Str::uuid(), 'school_id' => $schoolId,
                'fund_account_id' => $account->id, 'entry_date' => $occurredAt->toDateString(),
                'occurred_at' => $occurredAt, 'source_type' => $sourceType,
                'source_id' => $sourceId, 'source_line' => $sourceLine,
                'reference_no' => $referenceNo, 'transaction_type' => $type,
                'currency' => strtoupper($account->currency), 'money_in' => $moneyIn,
                'money_out' => $moneyOut, 'operating_income' => $operatingIncome,
                'operating_expense' => $operatingExpense, 'created_by' => $actor->id,
            ]);
        } catch (QueryException $exception) {
            // A retry of the same canonical document must not duplicate a
            // balance movement. Return the existing immutable entry only when
            // it is exactly the same source/account/line identity.
            $existing = CentralFinanceLedgerEntry::on('mysql')->where([
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'fund_account_id' => $account->id, 'source_line' => $sourceLine,
            ])->first();
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }
    }

    private function assertSchoolAttribution(int $schoolId, CentralFinanceFundAccount $account): void
    {
        School::on('mysql')->findOrFail($schoolId);
        if (!in_array($account->owner_type, [CentralFinanceFundAccount::OWNER_HQ, CentralFinanceFundAccount::OWNER_SCHOOL], true)) {
            throw new InvalidArgumentException('Central Fund Account ownership is invalid.');
        }
        if (($account->owner_type === CentralFinanceFundAccount::OWNER_HQ && $account->school_id !== null)
            || ($account->owner_type === CentralFinanceFundAccount::OWNER_SCHOOL && (int) $account->school_id !== $schoolId)) {
            throw new InvalidArgumentException('The Fund Account cannot be attributed to this School.');
        }
    }

    private function assertSource(string $sourceType, string $sourceId): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $sourceType) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $sourceId)) {
            throw new InvalidArgumentException('Central Ledger source identity is invalid.');
        }
    }

    private function assertPositiveAmount(float $amount): void
    {
        if ($amount <= 0 || !is_finite($amount)) {
            throw new InvalidArgumentException('Central Ledger amount must be positive and finite.');
        }
    }
}
