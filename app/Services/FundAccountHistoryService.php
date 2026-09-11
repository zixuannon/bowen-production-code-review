<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankAccountBalanceAdjustment;
use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\FinanceGroupTransfer;
use App\Models\OptionalFee;
use App\Models\OtherIncome;
use Illuminate\Support\Facades\Schema;

final class FundAccountHistoryService
{
    public function hasHistory(BankAccount $account): bool
    {
        // During an update the model may already contain a proposed opening
        // balance. Only the persisted value is historical; otherwise an empty
        // account could never set its initial currency and balance atomically.
        $persistedOpeningBalance = $account->exists
            ? $account->getRawOriginal('opening_balance')
            : $account->opening_balance;

        if (abs((float) $persistedOpeningBalance) > 0.0001
            || BankAccountBalanceAdjustment::query()->where('bank_account_id', $account->id)->exists()
            || CompulsoryFee::withTrashed()->where('school_id', $account->school_id)->where('bank_account_id', $account->id)->exists()
            || OptionalFee::withTrashed()->where('school_id', $account->school_id)->where('bank_account_id', $account->id)->exists()
            || OtherIncome::withTrashed()->where('school_id', $account->school_id)->where('bank_account_id', $account->id)->exists()
            || Expense::withTrashed()->where('school_id', $account->school_id)->where('bank_account_id', $account->id)->exists()
            || BankTransfer::withTrashed()->where('school_id', $account->school_id)
                ->where(fn ($query) => $query->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))->exists()) {
            return true;
        }

        return Schema::connection('mysql')->hasTable('finance_group_transfers')
            && FinanceGroupTransfer::on('mysql')->where('school_id', $account->school_id)
                ->where('tenant_bank_account_id', $account->id)->exists();
    }
}
