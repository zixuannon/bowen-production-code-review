<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinancePaymentRefund;
use App\Models\CentralFinancePaymentReversal;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Full reversal of an invalid confirmed Payment.  The canonical ledger model
 * records the original collection as money-in / operating-income; an invalid
 * collection must therefore append one money-out / negative-income entry on
 * its original Fund Account.  Nothing historical is edited or deleted.
 */
final class CentralFinancePaymentReversalService
{
    public function __construct(private readonly CentralFinanceSchoolScopeService $schools, private readonly CentralFinanceFundAccountScopeService $accounts, private readonly CentralFinanceLedgerService $ledger, private readonly CentralFinanceDocumentAuditService $audits, private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceDataIsolationService $dataIsolation) {}

    public function reverse(CentralFinanceUser $actor, int $paymentId, CarbonImmutable $effectiveDate, string $reason, CarbonImmutable $reversedAt, string $idempotencyReference, ?string $reference = null): CentralFinancePaymentReversal
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000 || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) throw new InvalidArgumentException('Central payment reversal input is invalid.');

        return DB::connection('mysql')->transaction(function () use ($actor, $paymentId, $effectiveDate, $reason, $reversedAt, $idempotencyReference, $reference): CentralFinancePaymentReversal {
            $payment = CentralFinancePayment::on('mysql')->with('receipt')->lockForUpdate()->findOrFail($paymentId);
            $receivable = CentralFinanceReceivable::on('mysql')->lockForUpdate()->findOrFail($payment->receivable_id);
            $this->workspace->assertHeadFinance($actor);
            $this->schools->assertCanOperate($actor, (int) $payment->school_id);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $payment->school_id);
            $this->dataIsolation->assertWorkflowWritable('payment', (int) $payment->id);
            if (CentralFinancePaymentRefund::on('mysql')->where('payment_id', $payment->id)->exists()) throw new InvalidArgumentException('A payment with a Refund cannot be reversed.');
            $key = hash('sha256', $payment->school_id.'|'.$payment->id.'|'.$idempotencyReference);
            if ($existing = CentralFinancePaymentReversal::on('mysql')->where('idempotency_key', $key)->lockForUpdate()->first()) return $existing;
            if (CentralFinancePaymentReversal::on('mysql')->where('payment_id', $payment->id)->exists()) throw new InvalidArgumentException('This payment has already been reversed.');
            if ($reference && CentralFinancePaymentReversal::on('mysql')->where(['school_id' => $payment->school_id, 'reversal_reference' => $reference])->exists()) throw new InvalidArgumentException('Reversal reference is already used for this School.');
            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($payment->fund_account_id);
            $this->accounts->assertCanOperate($actor, $account, (int) $payment->school_id);
            $this->dataIsolation->assertFundAccountMatchesSchoolWorkflow((int) $payment->school_id, (int) $account->id);
            if (strtoupper($account->currency) !== strtoupper($payment->currency) || !app(CentralFinanceFundAccountBalanceService::class)->hasSufficientBalance($account, (float) $payment->amount)) throw new InvalidArgumentException('The original active Fund Account cannot safely reverse this payment.');

            $reversal = CentralFinancePaymentReversal::on('mysql')->create(['reversal_uuid' => (string) Str::uuid(), 'school_id' => $payment->school_id, 'payment_id' => $payment->id, 'original_receipt_id' => $payment->receipt?->id, 'fund_account_id' => $account->id, 'idempotency_key' => $key, 'reversal_reference' => $reference, 'amount' => $payment->amount, 'currency' => $payment->currency, 'reason' => trim($reason), 'effective_date' => $effectiveDate->toDateString(), 'reversed_at' => $reversedAt, 'reversed_by' => $actor->id]);
            $ledger = $this->ledger->reverseOperatingIncome($actor, $account, (int) $payment->school_id, 'central_payment_reversal', $reversal->reversal_uuid, (float) $payment->amount, $effectiveDate, $reference ?: ('REVERSAL-'.$payment->id));
            $paid = max(0, (float) $receivable->amount_paid - (float) $payment->amount);
            $receivable->update(['amount_paid' => $paid, 'status' => $paid <= 0 ? CentralFinanceReceivable::OPEN : CentralFinanceReceivable::PARTIAL]);
            $this->audits->record($actor, $reversal, 'central_payment_reversal', 'reversal', trim($reason), null, ['payment_id' => $payment->id, 'original_receipt_id' => $reversal->original_receipt_id, 'amount' => (float) $payment->amount]);
            $this->dataIsolation->inheritWorkflowClassification($actor, (int) $payment->school_id, 'payment_reversal', (int) $reversal->id);
            $this->dataIsolation->inheritWorkflowClassification($actor, (int) $payment->school_id, 'ledger', (int) $ledger->id);
            return $reversal;
        });
    }
}
