<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Support\CentralFinanceCurrency;
use LogicException;

final class CentralFinanceFundAccountBalanceService
{
    /**
     * Summary of physical custody. Account IDs are de-duplicated before
     * aggregation, so an allocation never turns one shared opening balance
     * into multiple School balances.
     *
     * @param iterable<CentralFinanceFundAccount> $accounts
     * @return array<string,array{opening_balance:float,money_in:float,money_out:float,closing_balance:float}>
     */
    public function physicalSummaryByCurrency(iterable $accounts): array
    {
        $summary = collect(CentralFinanceCurrency::ALLOWED)->mapWithKeys(fn (string $currency) => [$currency => [
            'opening_balance' => 0.0, 'money_in' => 0.0, 'money_out' => 0.0, 'closing_balance' => 0.0,
        ]])->all();

        foreach (collect($accounts)->unique('id') as $account) {
            $currency = CentralFinanceCurrency::normalize((string) $account->currency);
            $ledger = CentralFinanceLedgerEntry::on('mysql')->where('fund_account_id', $account->id)
                ->selectRaw('COALESCE(SUM(money_in), 0) as money_in, COALESCE(SUM(money_out), 0) as money_out')->first();
            $opening = (float) $account->opening_balance;
            $in = (float) $ledger->money_in;
            $out = (float) $ledger->money_out;
            $summary[$currency]['opening_balance'] += $opening;
            $summary[$currency]['money_in'] += $in;
            $summary[$currency]['money_out'] += $out;
            $summary[$currency]['closing_balance'] += $opening + $in - $out;
        }
        foreach ($summary as &$row) foreach ($row as $key => $value) $row[$key] = round($value, 4);
        unset($row);
        return $summary;
    }

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

    /**
     * School attribution is an activity slice, never a second account balance.
     * The physical balance remains currentBalance() and is identical for every
     * School with an active allocation.
     *
     * @return array{money_in:float,money_out:float,income:float,expense:float,net_movement:float}
     */
    public function schoolActivity(CentralFinanceFundAccount $account, int $schoolId): array
    {
        $totals = CentralFinanceLedgerEntry::on('mysql')
            ->where('fund_account_id', $account->id)
            ->where('school_id', $schoolId)
            ->selectRaw('COALESCE(SUM(money_in), 0) as money_in, COALESCE(SUM(money_out), 0) as money_out, COALESCE(SUM(operating_income), 0) as income, COALESCE(SUM(operating_expense), 0) as expense')
            ->first();

        $moneyIn = (float) $totals->money_in;
        $moneyOut = (float) $totals->money_out;

        return [
            'money_in' => $moneyIn,
            'money_out' => $moneyOut,
            'income' => (float) $totals->income,
            'expense' => (float) $totals->expense,
            'net_movement' => round($moneyIn - $moneyOut, 4),
        ];
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
