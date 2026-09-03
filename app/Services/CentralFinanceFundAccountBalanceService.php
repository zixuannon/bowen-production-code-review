<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountSchoolAllocation;
use App\Models\CentralFinanceLedgerEntry;
use App\Support\CentralFinanceCurrency;
use LogicException;
use Illuminate\Support\Facades\Schema;

final class CentralFinanceFundAccountBalanceService
{
    public function currentBalance(CentralFinanceFundAccount $account): float
    {
        $totals = CentralFinanceLedgerEntry::on('mysql')->where('fund_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(money_in), 0) as money_in, COALESCE(SUM(money_out), 0) as money_out')
            ->first();

        return round((float) $account->opening_balance + (float) $totals->money_in - (float) $totals->money_out, 4);
    }

    public function hasSufficientBalance(CentralFinanceFundAccount $account, float $amount): bool
    {
        return $amount > 0 && $this->currentBalance($account) >= $amount;
    }

    /** Physical balance remains exactly once at the account level. */
    public function schoolOpeningBalance(CentralFinanceFundAccount $account, int $schoolId): float
    {
        if ($account->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL) return 0.0;
        $allocation = Schema::connection('mysql')->hasTable('central_finance_fund_account_school_allocations')
            ? CentralFinanceFundAccountSchoolAllocation::on('mysql')->where('fund_account_id', $account->id)->where('school_id', $schoolId)->effective()->first()
            : null;
        if ($allocation !== null) return (float) $allocation->opening_allocation_amount;

        // Transitional compatibility during phased additive rollout only.
        return (int) $account->school_id === $schoolId ? (float) $account->opening_balance : 0.0;
    }

    /** A School sees only its allocation baseline plus its own direct Ledger. */
    public function schoolBalance(CentralFinanceFundAccount $account, int $schoolId): float
    {
        $totals = CentralFinanceLedgerEntry::on('mysql')->where('fund_account_id', $account->id)->where('school_id', $schoolId)
            ->selectRaw('COALESCE(SUM(money_in), 0) as money_in, COALESCE(SUM(money_out), 0) as money_out')->first();
        return round($this->schoolOpeningBalance($account, $schoolId) + (float) $totals->money_in - (float) $totals->money_out, 4);
    }

    /**
     * A scalar total is meaningful only for one original currency.  Callers
     * rendering multiple accounts must use totalsByCurrencyForSchool().
     *
     * @return array{money_in:float,money_out:float,operating_income:float,operating_expense:float,operating_net:float}
     */
    public function totalsForSchool(int $schoolId, ?string $currency = null): array
    {
        $currencies = CentralFinanceLedgerEntry::on('mysql')->where('school_id', $schoolId)
            ->distinct()->pluck('currency')->map(fn ($value) => CentralFinanceCurrency::normalize((string) $value))->values();
        if ($currency === null && $currencies->count() > 1) {
            throw new LogicException('A Central Finance School total requires a currency when more than one currency exists.');
        }
        $currency ??= $currencies->first() ?? CentralFinanceCurrency::MMK;
        $currency = CentralFinanceCurrency::normalize($currency);
        $totals = CentralFinanceLedgerEntry::on('mysql')->where('school_id', $schoolId)->where('currency', $currency)
            ->selectRaw('COALESCE(SUM(money_in), 0) as money_in, COALESCE(SUM(money_out), 0) as money_out, COALESCE(SUM(operating_income), 0) as operating_income, COALESCE(SUM(operating_expense), 0) as operating_expense')
            ->first();
        $income = (float) $totals->operating_income;
        $expense = (float) $totals->operating_expense;

        return [
            'money_in' => (float) $totals->money_in,
            'money_out' => (float) $totals->money_out,
            'operating_income' => $income,
            'operating_expense' => $expense,
            'operating_net' => round($income - $expense, 4),
        ];
    }

    /** @return array<string,array{money_in:float,money_out:float,operating_income:float,operating_expense:float,operating_net:float}> */
    public function totalsByCurrencyForSchool(int $schoolId): array
    {
        $rows = CentralFinanceLedgerEntry::on('mysql')->where('school_id', $schoolId)
            ->selectRaw('currency, COALESCE(SUM(money_in), 0) as money_in, COALESCE(SUM(money_out), 0) as money_out, COALESCE(SUM(operating_income), 0) as operating_income, COALESCE(SUM(operating_expense), 0) as operating_expense')
            ->groupBy('currency')->get();
        $totals = [];
        foreach ($rows as $row) {
            $currency = CentralFinanceCurrency::normalize((string) $row->currency);
            $income = (float) $row->operating_income;
            $expense = (float) $row->operating_expense;
            $totals[$currency] = ['money_in' => (float) $row->money_in, 'money_out' => (float) $row->money_out, 'operating_income' => $income, 'operating_expense' => $expense, 'operating_net' => round($income - $expense, 4)];
        }
        return $totals;
    }
}
