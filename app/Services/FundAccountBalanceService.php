<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\OptionalFee;

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
        $schoolId = $account->school_id;
        $income = (float) CompulsoryFee::where('school_id', $schoolId)->where('bank_account_id', $account->id)->sum('amount')
            + (float) OptionalFee::where('school_id', $schoolId)->where('bank_account_id', $account->id)->sum('amount');
        $expenses = (float) Expense::where('school_id', $schoolId)->where('bank_account_id', $account->id)->sum('amount');
        $in = (float) BankTransfer::completed()->where('school_id', $schoolId)->where('to_account_id', $account->id)->sum('amount');
        $out = (float) BankTransfer::completed()->where('school_id', $schoolId)->where('from_account_id', $account->id)->sum('amount');

        return (float) $account->opening_balance + $income + $in - $expenses - $out;
    }

    public function hasSufficientBalance(BankAccount $account, float $amount): bool
    {
        return $this->currentBalance($account) + 0.0001 >= $amount;
    }
}
