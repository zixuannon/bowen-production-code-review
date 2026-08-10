<?php

namespace App\Services;

use App\Helpers\MoneyDecimal;
use App\Models\BankAccount;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesAdvance;
use App\Models\FeesPaid;
use App\Models\SessionYearsTracking;
use Illuminate\Support\Facades\Auth;

/**
 * FeesPaymentService
 *
 * Core payment processing for compulsory fees.
 * Supports ALL manual payment features:
 *   - MMK / USD / CNY multi-currency
 *   - Full payment and installment payment
 *   - Advance / FeesAdvance
 *   - Due Charges
 *   - fees_paids accumulation
 *
 * TRANSACTION RULES (enforced by CALLER, NOT this service):
 *   - Manual payment Controller: wraps processPayment() in its own DB::beginTransaction / commit / rollback
 *   - Excel import Confirm: wraps the entire batch in its own transaction
 *   - This service NEVER calls DB::beginTransaction / commit / rollback
 *
 * NOTIFICATION RULES:
 *   - This service NEVER sends notifications
 *   - Caller sends notification AFTER successful commit
 *   - Excel P0 does NOT send notifications
 *
 * AMOUNT SEMANTICS (clarified from original payCompulsoryFeesStore):
 *   - $amount (variable): total MMK-equivalent payment for this call, including
 *     installment amounts, due charges embedded in installments, and advance.
 *   - fees_paids.amount: accumulated MMK-equivalent of all payments for this
 *     student+fees pair. Each new payment ADDS to this total.
 *   - compulsory_fees.amount: the per-installment or full-payment MMK amount.
 *     Advance is later ADDED to the latest compulsory_fees.amount.
 *   - is_fully_paid: accumulated_amount >= fee.total_compulsory_fees
 *     (compares against BASE fee total, not including due charges).
 *   - Due Charges: stored separately in compulsory_fees.due_charges.
 *     For installments, due charges are already included in the installment amount.
 *   - remaining (view): total_compulsory_fees - alreadyPaid + due_charges.
 *     This is what the user sees as "remaining" including overdue charges.
 *
 * EXCEL IMPORT P0 RESTRICTIONS (enforced by CALLER, not this service):
 *   - currency fixed to MMK
 *   - advance fixed to 0
 *   - one row = at most one installment
 *   - no overpayment (amount <= remaining)
 *
 * @see MoneyDecimal for safe amount arithmetic
 */
class FeesPaymentService
{
    /**
     * Process a single compulsory fee payment.
     *
     * Supports full manual payment (MMK/USD/CNY, advance, multi-installment).
     *
     * @param array $data  All request data including:
     *   - fees_id              (int)
     *   - student_id           (int)
     *   - installment_mode     (bool)
     *   - installment_fees     (array) [{id, amount, due_charges}]
     *   - mode                 (string)  Payment mode
     *   - cheque_no            (?string)
     *   - bank_account_id      (?int)
     *   - date                 (string)  Y-m-d
     *   - enter_amount         (?float)  Full payment amount (MMK equivalent)
     *   - total_amount         (?float)  Total compulsory fee (MMK)
     *   - due_charges_amount   (?float)  Flat due charges for full payment
     *   - advance              (?float)  Advance amount (MMK)
     *   - parent_id            (?int)    Parent ID for FeesAdvance
     *   - transaction_currency (?string) MMK/USD/CNY
     *   - original_amount      (?float)  Foreign currency amount
     *   - exchange_rate_snapshot (?float)
     *   - reference_no         (?string) External reference (Excel import)
     *
     * @param Fee   $fee    Pre-loaded Fee model with installments relation
     *
     * @return array {
     *   fees_paid:        FeesPaid,
     *   compulsory_fees:  CompulsoryFee[],
     *   amount_mmk:       string,
     *   is_fully_paid:    bool,
     *   student_id:       int,
     *   fees_id:          int,
     *   installment_mode: bool,
     *   transaction_currency: string,
     * }
     *
     * @throws \InvalidArgumentException
     */
    public function processPayment(array $data, Fee $fee): array
    {
        $schoolId = Auth::user()->school_id;
        $userId   = Auth::id();

        // ---- 0. Guard: bank_account_id must be a valid, active, school-owned fund account ----
        $bankAccountId = $data['bank_account_id'] ?? null;
        if (empty($bankAccountId)) {
            throw new \InvalidArgumentException('Fund account (bank_account_id) is required for fee payment.');
        }
        $bankAccount = BankAccount::where('id', $bankAccountId)
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
        if (!$bankAccount) {
            throw new \InvalidArgumentException(
                'Fund account is not valid, not active, or does not belong to this school.'
            );
        }

        // ---- 1. Load existing FeesPaid ----
        $feesPaid = FeesPaid::where([
            'fees_id'    => $data['fees_id'],
            'student_id' => $data['student_id'],
        ])->first();

        if ($feesPaid && $feesPaid->is_fully_paid) {
            throw new \InvalidArgumentException('Compulsory Fees already Paid');
        }

        // ---- 2. Calculate total MMK-equivalent amount ----
        // This mirrors the original payCompulsoryFeesStore logic exactly.
        $amount = $this->calculateAmountMmK($data);

        // ---- 3. Multi-currency handling ----
        $transactionCurrency = strtoupper($data['transaction_currency'] ?? 'MMK');
        $exchangeRate = (float) ($data['exchange_rate_snapshot'] ?? 1);
        $originalAmount = (float) ($data['original_amount'] ?? 0);

        $amountMmk = $amount;
        if ($transactionCurrency === 'MMK') {
            $originalAmount = $amount;
            $exchangeRate = 1;
        } else {
            // USD / CNY: amount is already MMK equivalent
            if ($originalAmount <= 0) {
                $originalAmount = $amount / $exchangeRate;
            }
        }

        // ---- 4. Validate reference_no uniqueness (if provided) ----
        $referenceNo = null;
        if (!empty($data['reference_no'])) {
            $referenceNo = strtoupper(trim($data['reference_no']));
            $existing = CompulsoryFee::where('school_id', $schoolId)
                ->where('reference_no', $referenceNo)
                ->exists();
            if ($existing) {
                throw new \InvalidArgumentException("Reference number '{$referenceNo}' already exists");
            }
        }

        // ---- 5. Create or update FeesPaid ----
        $installmentMode = !empty($data['installment_mode']);
        $isFullyPaid = $amount >= $fee->total_compulsory_fees;
        $dateStr = date('Y-m-d', strtotime($data['date']));

        if (empty($feesPaid)) {
            $feesPaidResult = FeesPaid::create([
                'date'                    => $dateStr,
                'is_fully_paid'           => $isFullyPaid,
                'is_used_installment'     => $installmentMode,
                'fees_id'                 => $data['fees_id'],
                'student_id'              => $data['student_id'],
                'amount'                  => $amount,
                'school_id'               => $schoolId,
                'transaction_currency'    => $transactionCurrency,
                'original_amount'         => $originalAmount,
                'exchange_rate_snapshot'  => $exchangeRate,
                'amount_mmk'              => $amountMmk,
            ]);
        } else {
            $newAmount = $amount + $feesPaid->amount;
            $feesPaid->update([
                'amount'                  => $newAmount,
                'is_fully_paid'           => $newAmount >= $fee->total_compulsory_fees,
                'school_id'               => $schoolId,
                'transaction_currency'    => $transactionCurrency,
                'original_amount'         => $originalAmount,
                'exchange_rate_snapshot'  => $exchangeRate,
                'amount_mmk'              => $amountMmk,
            ]);
            $feesPaidResult = $feesPaid;
        }

        // ---- 6. Create CompulsoryFee records ----
        $compulsoryFees = [];
        $paymentMode = $data['mode'] ?? 'Cash';
        $chequeNo = null;
        if ($paymentMode == '2' || $paymentMode === 'Cheque') {
            $chequeNo = $data['cheque_no'] ?? null;
        }

        if ($installmentMode && !empty($data['installment_fees'])) {
            foreach ($data['installment_fees'] as $inst) {
                $cfData = [
                    'student_id'      => $data['student_id'],
                    'type'            => 'Installment Payment',
                    'installment_id'  => $inst['id'],
                    'mode'            => $paymentMode,
                    'cheque_no'       => $chequeNo,
                    'reference_no'    => $referenceNo,
                    'amount'          => (float) $inst['amount'],
                    'due_charges'     => $inst['due_charges'] ?? 0,
                    'fees_paid_id'    => $feesPaidResult->id,
                    'date'            => $dateStr,
                    'school_id'       => $schoolId,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                ];
                if (!empty($data['import_batch_id'])) {
                    $cfData['import_batch_id'] = $data['import_batch_id'];
                }
                $cf = CompulsoryFee::create($cfData);
                $compulsoryFees[] = $cf;
            }
        } else {
            $cfData = [
                'type'            => 'Full Payment',
                'student_id'      => $data['student_id'],
                'mode'            => $paymentMode,
                'cheque_no'       => $chequeNo,
                'reference_no'    => $referenceNo,
                'amount'          => $amount,
                'due_charges'     => $data['due_charges_amount'] ?? 0,
                'fees_paid_id'    => $feesPaidResult->id,
                'date'            => $dateStr,
                'school_id'       => $schoolId,
                'bank_account_id' => $data['bank_account_id'] ?? null,
            ];
            if (!empty($data['import_batch_id'])) {
                $cfData['import_batch_id'] = $data['import_batch_id'];
            }
            $cf = CompulsoryFee::create($cfData);
            $compulsoryFees[] = $cf;
        }

        // ---- 7. Handle Advance ----
        if (($data['advance'] ?? 0) > 0) {
            // Find the latest compulsory_fees entry for this student+fees
            $lastCf = CompulsoryFee::where('student_id', $data['student_id'])
                ->whereHas('fees_paid', function ($q) use ($data) {
                    $q->where('fees_id', $data['fees_id']);
                })
                ->orderBy('id', 'DESC')
                ->first();

            if ($lastCf) {
                $advanceAmount = (float) $data['advance'];
                $lastCf->amount += $advanceAmount;
                $lastCf->save();

                FeesAdvance::create([
                    'compulsory_fee_id' => $lastCf->id,
                    'student_id'        => $data['student_id'],
                    'parent_id'         => $data['parent_id'],
                    'amount'            => $advanceAmount,
                ]);
            }
        }

        // ---- 8. Create SessionYearsTracking entries ----
        foreach ($compulsoryFees as $cf) {
            $cache = app(CachingService::class);
            $sessionYear = $cache->getDefaultSessionYear();
            SessionYearsTracking::create([
                'modal_type'      => CompulsoryFee::class,
                'modal_id'        => $cf->id,
                'user_id'         => $userId,
                'session_year_id' => $sessionYear->id,
                'school_id'       => $schoolId,
                'semester_id'     => null,
            ]);
        }

        // ---- 9. Return result for caller ----
        return [
            'fees_paid'            => $feesPaidResult,
            'compulsory_fees'      => $compulsoryFees,
            'amount_mmk'           => $amountMmk,
            'is_fully_paid'        => $feesPaidResult->is_fully_paid,
            'student_id'           => $data['student_id'],
            'fees_id'              => $data['fees_id'],
            'installment_mode'     => $installmentMode,
            'transaction_currency' => $transactionCurrency,
        ];
    }

    /**
     * Calculate the total MMK-equivalent amount from request data.
     *
     * Mirrors original payCompulsoryFeesStore logic exactly:
     * - Installment mode: sum of installment_fees amounts + advance
     * - Full payment: enter_amount or total_amount
     */
    private function calculateAmountMmK(array $data): float
    {
        $installmentMode = !empty($data['installment_mode']);

        if ($installmentMode) {
            $amount = 0;
            if (!empty($data['installment_fees'])) {
                $amount = array_sum(array_column($data['installment_fees'], 'amount'));
            }
            $amount += ($data['advance'] ?? 0);
            return (float) $amount;
        }

        // Full Payment mode
        if (!empty($data['enter_amount'])) {
            return (float) $data['enter_amount'];
        }
        return (float) ($data['total_amount'] ?? 0);
    }
}
