<?php

namespace App\Services;

use App\Models\OtherIncome;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OtherIncomeService
{
    /**
     * Records a genuine non-fee receipt. This is intentionally separate from
     * student fee services so it cannot bypass fee status/outstanding/audit.
     */
    public function receive(User $actor, array $data, ?callable $afterCreate = null): OtherIncome
    {
        app(CentralFinanceSchoolCutoverService::class)->assertTenantFinanceWritesAllowed($actor);
        if (empty($data['bank_account_id'])) {
            throw ValidationException::withMessages(['bank_account_id' => __('A fund account is required.')]);
        }
        if (empty($data['payment_method']) || !in_array($data['payment_method'], FeesPaymentService::PAYMENT_METHODS, true)) {
            throw ValidationException::withMessages(['payment_method' => __('A valid payment method is required.')]);
        }
        if (!isset($data['amount']) || (float) $data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => __('Amount must be greater than zero.')]);
        }
        $account = app(FinanceAccountAccessService::class)->authorize($actor, (int) $data['bank_account_id']);
        if (!$account->is_active) {
            throw ValidationException::withMessages(['bank_account_id' => __('The selected fund account is inactive.')]);
        }
        $snapshot = app(FinancialCurrencyService::class)->receiptSnapshot($account, $data);

        $reference = trim((string) ($data['reference_no'] ?? ''));

        return DB::connection('school')->transaction(function () use ($actor, $account, $data, $snapshot, $reference, $afterCreate) {
            if ($reference !== '' && OtherIncome::withTrashed()
                ->where('school_id', $actor->school_id)
                ->where('reference_no', $reference)
                ->exists()) {
                throw ValidationException::withMessages(['reference_no' => __('This reference number is already reserved.')]);
            }

            $income = OtherIncome::create([
                'school_id' => $actor->school_id,
                'bank_account_id' => $account->id,
                'date' => $data['date'],
                'payer' => $data['payer'],
                'description' => $data['description'],
                // Fund Account ledgers use original/account currency. amount is
                // retained as the immutable MMK-equivalent reporting value.
                'amount' => $snapshot['amount_mmk'],
                'transaction_currency' => $snapshot['transaction_currency'],
                'original_amount' => $snapshot['original_amount'],
                'exchange_rate_snapshot' => $snapshot['exchange_rate_snapshot'],
                'amount_mmk' => $snapshot['amount_mmk'],
                'payment_method' => $data['payment_method'],
                'reference_no' => $reference ?: null,
                'remark' => $data['remark'] ?? null,
                'created_by' => $actor->id,
            ]);
            if ($afterCreate) { $afterCreate($income); }
            return $income;
        });
    }
}
