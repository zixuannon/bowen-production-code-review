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
    public function receive(User $actor, array $data): OtherIncome
    {
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

        $reference = trim((string) ($data['reference_no'] ?? ''));

        return DB::connection('school')->transaction(function () use ($actor, $account, $data, $reference) {
            if ($reference !== '' && OtherIncome::withTrashed()
                ->where('school_id', $actor->school_id)
                ->where('reference_no', $reference)
                ->exists()) {
                throw ValidationException::withMessages(['reference_no' => __('This reference number is already reserved.')]);
            }

            return OtherIncome::create([
                'school_id' => $actor->school_id,
                'bank_account_id' => $account->id,
                'date' => $data['date'],
                'payer' => $data['payer'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'reference_no' => $reference ?: null,
                'remark' => $data['remark'] ?? null,
                'created_by' => $actor->id,
            ]);
        });
    }
}
