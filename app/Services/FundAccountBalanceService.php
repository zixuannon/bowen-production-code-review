<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\OptionalFee;
use App\Models\OtherIncome;
use Illuminate\Support\Collection;

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

        $compulsoryIncome = $this->groupedSum(
            CompulsoryFee::query()->whereIn('school_id', $schoolIds),
            'bank_account_id',
            $accountIds,
        );
        $optionalIncome = $this->groupedSum(
            OptionalFee::query()->whereIn('school_id', $schoolIds),
            'bank_account_id',
            $accountIds,
        );
        $otherIncome = $this->groupedSum(
            OtherIncome::query()->whereIn('school_id', $schoolIds),
            'bank_account_id',
            $accountIds,
        );
        $expenses = $this->groupedSum(
            Expense::query()->whereIn('school_id', $schoolIds),
            'bank_account_id',
            $accountIds,
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

        return $accounts->mapWithKeys(function (BankAccount $account) use (
            $compulsoryIncome,
            $optionalIncome,
            $otherIncome,
            $expenses,
            $transferIn,
            $transferOut,
        ) {
            $operatingIncome = (float) ($compulsoryIncome[$account->id] ?? 0)
                + (float) ($optionalIncome[$account->id] ?? 0)
                + (float) ($otherIncome[$account->id] ?? 0);
            $operatingExpense = (float) ($expenses[$account->id] ?? 0);
            $internalIn = (float) ($transferIn[$account->id] ?? 0);
            $internalOut = (float) ($transferOut[$account->id] ?? 0);
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

    private function groupedSum($query, string $accountColumn, Collection $accountIds): Collection
    {
        return $query->whereIn($accountColumn, $accountIds)
            ->selectRaw("{$accountColumn}, SUM(amount) as total")
            ->groupBy($accountColumn)
            ->pluck('total', $accountColumn);
    }
}
