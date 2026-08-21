<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;

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

    /** @return array{money_in:float,money_out:float,operating_income:float,operating_expense:float,operating_net:float} */
    public function totalsForSchool(int $schoolId): array
    {
        $totals = CentralFinanceLedgerEntry::on('mysql')->where('school_id', $schoolId)
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
}
