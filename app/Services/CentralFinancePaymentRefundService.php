<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinancePaymentRefund;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only correction path for completed Central student payments. */
final class CentralFinancePaymentRefundService
{
    public function __construct(private readonly CentralFinanceSchoolScopeService $schools, private readonly CentralFinanceFundAccountScopeService $accounts, private readonly CentralFinanceLedgerService $ledger, private readonly CentralFinanceDocumentAuditService $audits) {}

    public function refund(CentralFinanceUser $actor, int $paymentId, CentralFinanceFundAccount $account, float $amount, string $reason, CarbonImmutable $refundedAt, string $idempotencyReference, ?string $refundReference = null): CentralFinancePaymentRefund
    {
        if ($amount <= 0 || !is_finite($amount) || trim($reason) === '' || mb_strlen($reason) > 2000 || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) throw new InvalidArgumentException('Central payment refund input is invalid.');
        return DB::connection('mysql')->transaction(function () use ($actor, $paymentId, $account, $amount, $reason, $refundedAt, $idempotencyReference, $refundReference): CentralFinancePaymentRefund {
            $payment = CentralFinancePayment::on('mysql')->lockForUpdate()->findOrFail($paymentId);
            $receivable = CentralFinanceReceivable::on('mysql')->lockForUpdate()->findOrFail($payment->receivable_id);
            $this->schools->assertCanOperate($actor, (int) $payment->school_id);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $payment->school_id);
            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            $this->accounts->assertCanOperate($actor, $account);
            if ((int) $account->id !== (int) $payment->fund_account_id || strtoupper($account->currency) !== strtoupper($payment->currency)) throw new InvalidArgumentException('A Central payment refund must use the original active Fund Account and currency.');
            $key = hash('sha256', $payment->school_id.'|'.$payment->id.'|'.$idempotencyReference);
            if ($existing = CentralFinancePaymentRefund::on('mysql')->where('idempotency_key', $key)->lockForUpdate()->first()) return $existing;
            if ($refundReference && CentralFinancePaymentRefund::on('mysql')->where(['school_id' => $payment->school_id, 'refund_reference' => $refundReference])->exists()) throw new InvalidArgumentException('Refund reference is already used for this School.');
            $refunded = (float) CentralFinancePaymentRefund::on('mysql')->where('payment_id', $payment->id)->lockForUpdate()->sum('amount');
            if ($refunded + $amount > (float) $payment->amount + 0.00001) throw new InvalidArgumentException('Central payment refund exceeds the original collected amount.');
            if (!app(CentralFinanceFundAccountBalanceService::class)->hasSufficientBalance($account, $amount)) throw new InvalidArgumentException('The original Central Fund Account has insufficient balance for this refund.');
            $refund = CentralFinancePaymentRefund::on('mysql')->create(['refund_uuid' => (string) Str::uuid(), 'school_id' => $payment->school_id, 'payment_id' => $payment->id, 'original_receipt_id' => $payment->receipt?->id, 'fund_account_id' => $account->id, 'idempotency_key' => $key, 'refund_reference' => $refundReference, 'amount' => $amount, 'currency' => strtoupper($payment->currency), 'reason' => trim($reason), 'refunded_at' => $refundedAt, 'refunded_by' => $actor->id]);
            $this->ledger->reverseOperatingIncome($actor, $account, (int) $payment->school_id, 'central_payment_refund', $refund->refund_uuid, $amount, $refundedAt, $refundReference ?: ('REFUND-'.$payment->id));
            $paid = max(0, (float) $receivable->amount_paid - $amount);
            $receivable->update(['amount_paid' => $paid, 'status' => $paid <= 0 ? CentralFinanceReceivable::OPEN : CentralFinanceReceivable::PARTIAL]);
            $this->audits->record($actor, $refund, 'central_payment_refund', 'refund', trim($reason), null, ['payment_id' => $payment->id, 'original_receipt_id' => $refund->original_receipt_id, 'amount' => $amount]);
            return $refund;
        });
    }

    /** Completed Central payments always have a receipt, ledger, and balance impact; hard void is forbidden. */
    public function void(CentralFinanceUser $actor, int $paymentId, string $reason): never
    {
        CentralFinancePayment::on('mysql')->findOrFail($paymentId);
        throw new InvalidArgumentException('A completed Central payment cannot be voided; use the append-only refund/reversal path.');
    }
}
