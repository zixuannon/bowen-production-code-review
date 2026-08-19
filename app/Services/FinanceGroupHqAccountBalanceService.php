<?php

namespace App\Services;

use App\Models\FinanceGroupHqAccount;
use App\Models\FinanceGroupTransfer;
use App\Models\FinanceGroupHqAccountAdjustment;

/** Read-only balance projection for a central HQ Fund Account. */
class FinanceGroupHqAccountBalanceService
{
    public function currentBalance(FinanceGroupHqAccount $account): float
    {
        $in = (float) FinanceGroupTransfer::query()->confirmed()->where('hq_account_id', $account->id)
            ->where('direction', FinanceGroupTransfer::DIRECTION_SCHOOL_TO_HQ)->sum('amount');
        $out = (float) FinanceGroupTransfer::query()->confirmed()->where('hq_account_id', $account->id)
            ->where('direction', FinanceGroupTransfer::DIRECTION_HQ_TO_SCHOOL)->sum('amount');
        $adjustments = (float) FinanceGroupHqAccountAdjustment::query()->where('hq_account_id', $account->id)->sum('amount');
        return (float) $account->opening_balance + $adjustments + $in - $out;
    }

    public function hasSufficientBalance(FinanceGroupHqAccount $account, float $amount): bool
    {
        return $this->currentBalance($account) + 0.0001 >= $amount;
    }
}
