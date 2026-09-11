<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\FinanceGroupTransfer;
use App\Models\OptionalFee;
use App\Models\OtherIncome;
use App\Models\School;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class FundAccountBalanceService
{
    /**
     * The canonical balance calculation used before recording a completed
     * BankTransfer. A FundHandover deliberately never updates money itself:
     * confirmation creates one normal completed BankTransfer, which this
     * calculation then reflects everywhere else.
     */
    public function currentBalance(BankAccount $account): float
    {
        return $this->cashFlowSummary($account)['current_balance'];
    }

    public function hasSufficientBalance(BankAccount $account, float $amount): bool
    {
        return $this->currentBalance($account) + 0.0001 >= $amount;
    }

    /**
     * Canonical per-account cash-flow semantics for balance-bearing views.
     *
     * Internal movements are directional for one Fund Account but remain
     * separate from operating income/expense. A confirmed FundHandover is not
     * queried here because its single completed BankTransfer is the canonical
     * money movement; a pending handover has no BankTransfer and no effect.
     *
     * @return array{
     *     money_in: float,
     *     money_out: float,
     *     operating_income: float,
     *     operating_expense: float,
     *     operating_net: float,
     *     internal_in: float,
     *     internal_out: float,
     *     net_movement: float,
     *     current_balance: float
     * }
     */
    public function cashFlowSummary(BankAccount $account): array
    {
        return $this->cashFlowSummaries(collect([$account]))->get($account->id);
    }

    /**
     * Bulk variant used by the paginated Fund Account list to avoid one query
     * set per account while retaining the same source-of-truth calculation as
     * transfer balance validation.
     *
     * @param Collection<int, BankAccount> $accounts
     * @return Collection<int, array<string, float>> keyed by Fund Account ID
     */
    public function cashFlowSummaries(Collection $accounts): Collection
    {
        if ($accounts->isEmpty()) {
            return collect();
        }

        $accountIds = $accounts->pluck('id');
        $schoolIds = $accounts->pluck('school_id')->unique()->values();

        $compulsoryIncome = $this->groupedAccountCurrencySum(
            CompulsoryFee::query()->whereIn('compulsory_fees.school_id', $schoolIds)
                ->whereRaw('LOWER(compulsory_fees.status) = ?', ['success']),
            'compulsory_fees', $accountIds,
        );
        $optionalIncome = $this->groupedAccountCurrencySum(
            OptionalFee::query()->whereIn('optional_fees.school_id', $schoolIds)
                ->whereRaw('LOWER(optional_fees.status) = ?', ['success']),
            'optional_fees', $accountIds,
        );
        $otherIncome = $this->groupedAccountCurrencySum(
            OtherIncome::query()->whereIn('other_incomes.school_id', $schoolIds),
            'other_incomes', $accountIds,
        );
        $expenses = $this->groupedAccountCurrencySum(
            Expense::query()->whereIn('expenses.school_id', $schoolIds),
            'expenses', $accountIds,
        );
        $transferIn = $this->groupedSum(
            BankTransfer::query()->completed()->whereIn('school_id', $schoolIds),
            'to_account_id',
            $accountIds,
        );
        $transferOut = $this->groupedSum(
            BankTransfer::query()->completed()->whereIn('school_id', $schoolIds),
            'from_account_id',
            $accountIds,
        );
        $groupTransferIn = collect();
        $groupTransferOut = collect();

        // Group/HQ transfers are canonical central records because their other
        // side is not in this tenant DB. Project only confirmed records; a
        // pending request deliberately has no balance effect.
        if (Schema::connection('mysql')->hasTable('finance_group_transfers')
            && $this->currentTenantMatchesCentralSchools($schoolIds)) {
            $groupTransferIn = $this->groupedSum(
                FinanceGroupTransfer::on('mysql')->confirmed()
                    ->whereIn('school_id', $schoolIds)
                    ->where('direction', FinanceGroupTransfer::DIRECTION_HQ_TO_SCHOOL),
                'tenant_bank_account_id',
                $accountIds,
            );
            $groupTransferOut = $this->groupedSum(
                FinanceGroupTransfer::on('mysql')->confirmed()
                    ->whereIn('school_id', $schoolIds)
                    ->where('direction', FinanceGroupTransfer::DIRECTION_SCHOOL_TO_HQ),
                'tenant_bank_account_id',
                $accountIds,
            );
        }

        return $accounts->mapWithKeys(function (BankAccount $account) use (
            $compulsoryIncome,
            $optionalIncome,
            $otherIncome,
            $expenses,
            $transferIn,
            $transferOut,
            $groupTransferIn,
            $groupTransferOut,
        ) {
            $operatingIncome = (float) ($compulsoryIncome[$account->id] ?? 0)
                + (float) ($optionalIncome[$account->id] ?? 0)
                + (float) ($otherIncome[$account->id] ?? 0);
            $operatingExpense = (float) ($expenses[$account->id] ?? 0);
            $internalIn = (float) ($transferIn[$account->id] ?? 0)
                + (float) ($groupTransferIn[$account->id] ?? 0);
            $internalOut = (float) ($transferOut[$account->id] ?? 0)
                + (float) ($groupTransferOut[$account->id] ?? 0);
            $moneyIn = $operatingIncome + $internalIn;
            $moneyOut = $operatingExpense + $internalOut;

            return [$account->id => [
                'money_in' => $moneyIn,
                'money_out' => $moneyOut,
                'operating_income' => $operatingIncome,
                'operating_expense' => $operatingExpense,
                'operating_net' => $operatingIncome - $operatingExpense,
                'internal_in' => $internalIn,
                'internal_out' => $internalOut,
                'net_movement' => $moneyIn - $moneyOut,
                'current_balance' => (float) $account->opening_balance + $moneyIn - $moneyOut,
            ]];
        });
    }

    /** @param Collection<int, int> $schoolIds */
    private function currentTenantMatchesCentralSchools(Collection $schoolIds): bool
    {
        $schoolIds = $schoolIds->map(static fn ($id) => (int) $id)->filter()->unique()->values();
        $database = (string) config('database.connections.school.database');
        if ($schoolIds->isEmpty() || $database === '') {
            return false;
        }

        return School::on('mysql')->whereIn('id', $schoolIds)
            ->where('database_name', $database)
            ->count() === $schoolIds->count();
    }

    private function groupedSum($query, string $accountColumn, Collection $accountIds): Collection
    {
        return $query->whereIn($accountColumn, $accountIds)
            ->selectRaw("{$accountColumn}, SUM(amount) as total")
            ->groupBy($accountColumn)
            ->pluck('total', $accountColumn);
    }

    /**
     * Balance-bearing records are always projected into the Fund Account's own
     * currency. Legacy rows without an FX snapshot are safe only for MMK
     * accounts; foreign-currency ambiguity stops the calculation.
     */
    private function groupedAccountCurrencySum($query, string $table, Collection $accountIds): Collection
    {
        $accountColumn = "{$table}.bank_account_id";
        $joined = $query->join('bank_accounts', 'bank_accounts.id', '=', $accountColumn)
            ->whereIn($accountColumn, $accountIds);

        if (!Schema::connection('school')->hasColumn($table, 'transaction_currency')) {
            if ((clone $joined)->whereRaw("UPPER(bank_accounts.currency) <> 'MMK'")->exists()) {
                throw new \DomainException('Legacy Fund Account history has no currency snapshot.');
            }
            return $joined->selectRaw("{$accountColumn} as account_id, SUM({$table}.amount) as total")
                ->groupBy($accountColumn)->pluck('total', 'account_id');
        }

        $invalid = (clone $joined)->where(function ($where) use ($table): void {
            $where->where(function ($legacy) use ($table): void {
                $legacy->whereNull("{$table}.transaction_currency")
                    ->whereRaw("UPPER(bank_accounts.currency) <> 'MMK'");
            })->orWhere(function ($snapshotted) use ($table): void {
                $snapshotted->whereNotNull("{$table}.transaction_currency")
                    ->where(function ($mismatch) use ($table): void {
                        $mismatch->where(function ($missing) use ($table): void {
                            $missing->whereNull("{$table}.original_amount")->orWhere("{$table}.original_amount", '<=', 0);
                        })->whereRaw("UPPER({$table}.transaction_currency) <> 'MMK'")
                            ->orWhereRaw("UPPER({$table}.transaction_currency) <> UPPER(bank_accounts.currency)");
                    });
            });
        })->exists();

        if ($invalid) {
            throw new \DomainException('Fund Account history has a missing or mismatched currency snapshot.');
        }

        return $joined->selectRaw(
            "{$accountColumn} as account_id, SUM(CASE WHEN {$table}.transaction_currency IS NULL OR (UPPER({$table}.transaction_currency) = 'MMK' AND ({$table}.original_amount IS NULL OR {$table}.original_amount <= 0)) THEN {$table}.amount ELSE {$table}.original_amount END) as total"
        )->groupBy($accountColumn)->pluck('total', 'account_id');
    }

    public function accountCurrencyAmount(object $record, BankAccount $account): float
    {
        $currency = $record->transaction_currency ?? null;
        if ($currency === null) {
            if (strtoupper((string) $account->currency) !== 'MMK') {
                throw new \DomainException('Legacy transaction has no FX snapshot for this non-MMK Fund Account.');
            }
            return (float) $record->amount;
        }
        if (strtoupper((string) $currency) !== strtoupper((string) $account->currency)
            || (($record->original_amount === null || (float) $record->original_amount <= 0)
                && strtoupper((string) $currency) !== 'MMK')) {
            throw new \DomainException('Transaction currency snapshot does not match the Fund Account currency.');
        }
        return ($record->original_amount === null || (float) $record->original_amount <= 0)
            ? (float) $record->amount
            : (float) $record->original_amount;
    }
}
