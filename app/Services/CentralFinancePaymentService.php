<?php
namespace App\Services;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinancePaymentAllocation;
use App\Models\CentralFinanceReceipt;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUser;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CentralFinancePaymentService {
    public function __construct(private readonly CentralFinanceSchoolScopeService $schools, private readonly CentralFinanceFundAccountScopeService $accounts, private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability, private readonly CentralFinanceLedgerService $ledger, private readonly CentralFinanceDocumentAuditService $audits, private readonly CentralFinanceDataIsolationService $dataIsolation) {}
    /** @return array{payment:CentralFinancePayment,receipt:CentralFinanceReceipt} */
    public function collect(CentralFinanceUser $actor,int $receivableId,CentralFinanceFundAccount $account,string|float $amount,string $method,CarbonImmutable $paidAt,string $idempotencyReference,?string $paymentReference=null,?string $note=null,?CarbonImmutable $receiptIssuedAt=null): array {
        return $this->collectAllocations($actor, [['receivable_id' => $receivableId, 'amount' => (string) $amount]], $account, $method, $paidAt, $idempotencyReference, $paymentReference, $note, $receiptIssuedAt);
    }

    /**
     * One parent Payment and one physical money-in effect settle one or more
     * explicit receivable allocations. The client explicitly supplies each
     * amount; canonical ID ordering is used solely for stable idempotency.
     *
     * @param list<array{receivable_id:mixed,amount:mixed}> $requestedAllocations
     * @return array{payment:CentralFinancePayment,receipt:CentralFinanceReceipt}
     */
    public function collectAllocations(CentralFinanceUser $actor, array $requestedAllocations, CentralFinanceFundAccount $account, string $method, CarbonImmutable $paidAt, string $idempotencyReference, ?string $paymentReference = null, ?string $note = null, ?CarbonImmutable $receiptIssuedAt = null): array
    {
        if (!preg_match('/^[A-Za-z0-9 _.-]{2,40}$/', trim($method)) || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) {
            throw new InvalidArgumentException('Central payment input is invalid.');
        }
        $allocations = $this->canonicalAllocations($requestedAllocations);
        return DB::connection('mysql')->transaction(function() use($actor,$allocations,$account,$method,$paidAt,$idempotencyReference,$paymentReference,$note,$receiptIssuedAt): array {
            $receivableIds = collect($allocations)->pluck('receivable_id')->sort()->values()->all();
            $receivables = CentralFinanceReceivable::on('mysql')->whereIn('id', $receivableIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($receivables->count() !== count($allocations)) throw new InvalidArgumentException('A selected Central receivable no longer exists.');
            $schoolIds = $receivables->pluck('school_id')->unique();
            $profileIds = $receivables->pluck('student_profile_id')->unique();
            $currencies = $receivables->pluck('currency')->map(fn ($currency) => strtoupper((string) $currency))->unique();
            if ($schoolIds->count() !== 1 || $profileIds->count() !== 1 || $currencies->count() !== 1) {
                throw new InvalidArgumentException('A parent payment must contain receivables for one Student, one School, and one currency.');
            }
            $schoolId = (int) $schoolIds->first();
            $key = count($allocations) === 1
                ? hash('sha256', $schoolId.'|'.$allocations[0]['receivable_id'].'|'.$idempotencyReference)
                : hash('sha256', implode('|', ['central-payment-v2', $schoolId, $profileIds->first(), implode(',', $receivableIds), $idempotencyReference]));
            $existing=CentralFinancePayment::on('mysql')->where('idempotency_key',$key)->lockForUpdate()->first();
            if ($existing) return ['payment'=>$existing,'receipt'=>CentralFinanceReceipt::on('mysql')->where('payment_id',$existing->id)->firstOrFail()];
            // The Run lock is acquired before any receivable or money state
            // changes and held by this transaction through receipt and ledger
            // posting. Complete/Archive therefore serialize with posting.
            app(CentralFinanceQaRunService::class)->lockActiveRunForCentralRecords($schoolId, 'receivable', $receivableIds);
            $this->schools->assertCanOperate($actor,$schoolId);
            $this->accounts->assertCanOperate($actor,$account,$schoolId);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
            $account=CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            $this->availability->assertAccountAvailableForSchool($account, $schoolId);
            $this->dataIsolation->assertFundAccountMatchesSchoolWorkflow($schoolId, (int) $account->id);
            if (strtoupper((string) $account->currency) !== $currencies->first()) throw new InvalidArgumentException('Central payment currency does not match the Fund Account.');
            $classifications = $receivables->keys()->map(fn (int $id) => $this->dataIsolation->classification('receivable', $id))->unique();
            if ($classifications->count() !== 1) throw new InvalidArgumentException('A parent payment cannot mix QA/Test and Official receivables.');
            foreach ($allocations as $allocation) {
                $receivable = $receivables->get($allocation['receivable_id']);
                $this->dataIsolation->assertWorkflowWritable('receivable', (int) $receivable->id);
                if (!in_array($receivable->status, [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL], true)
                    || CentralFinanceDecimal::compare(CentralFinanceDecimal::add((string) $receivable->amount_paid, $allocation['amount']), (string) $receivable->amount_due) > 0) {
                    throw new InvalidArgumentException('A payment allocation exceeds the eligible outstanding receivable amount.');
                }
            }
            $paymentReference = $paymentReference === null ? null : trim($paymentReference);
            if ($paymentReference === '') $paymentReference = null;
            if ($paymentReference && CentralFinancePayment::on('mysql')->where(['school_id'=>$schoolId,'payment_reference'=>$paymentReference])->exists()) throw new InvalidArgumentException('Payment reference is already used for this School.');
            $total = collect($allocations)->reduce(fn (string $sum, array $line): string => CentralFinanceDecimal::add($sum, $line['amount']), CentralFinanceDecimal::normalize('0'));
            $payment=CentralFinancePayment::on('mysql')->create(['payment_uuid'=>(string)Str::uuid(),'school_id'=>$schoolId,'receivable_id'=>count($allocations) === 1 ? $allocations[0]['receivable_id'] : null,'fund_account_id'=>$account->id,'idempotency_key'=>$key,'payment_reference'=>$paymentReference,'payment_method'=>trim($method),'note'=>$note,'currency'=>$currencies->first(),'amount'=>$total,'paid_at'=>$paidAt,'received_by'=>$actor->id]);
            $receipt=CentralFinanceReceipt::on('mysql')->create(['receipt_uuid'=>(string)Str::uuid(),'school_id'=>$schoolId,'payment_id'=>$payment->id,'receipt_no'=>'CFR-'.$schoolId.'-'.strtoupper(substr(str_replace('-','',$payment->payment_uuid),0,12)),'issued_at'=>$receiptIssuedAt ?? $paidAt,'issued_by'=>$actor->id]);
            foreach ($allocations as $allocation) {
                $receivable = $receivables->get($allocation['receivable_id']);
                $line = null;
                if (Schema::connection('mysql')->hasTable('central_finance_payment_allocations')) {
                    $gross = CentralFinanceDecimal::normalize((string) ($receivable->source_amount_due ?? $receivable->amount_due));
                    $promotion = $receivable->promotionApplication?->discount_amount ?? '0.0000';
                    $line = CentralFinancePaymentAllocation::on('mysql')->create([
                        'payment_id' => $payment->id, 'receivable_id' => $receivable->id,
                        'school_id' => $schoolId, 'student_profile_id' => $receivable->student_profile_id,
                        'description_snapshot' => $receivable->description,
                        'unit_price_snapshot' => $receivable->unit_price_snapshot,
                        'quantity_snapshot' => max(1, (int) ($receivable->quantity_snapshot ?? 1)),
                        'gross_amount_snapshot' => $gross, 'promotion_amount_snapshot' => $promotion,
                        'net_due_snapshot' => $receivable->amount_due,
                        'paid_before_snapshot' => $receivable->amount_paid,
                        'outstanding_before_snapshot' => CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid),
                        'amount' => $allocation['amount'], 'currency' => $currencies->first(),
                        'allocated_at' => $paidAt, 'created_by' => $actor->id,
                    ]);
                }
                $paid = CentralFinanceDecimal::add((string) $receivable->amount_paid, $allocation['amount']);
                $receivable->update(['amount_paid' => $paid, 'status' => CentralFinanceDecimal::compare($paid, (string) $receivable->amount_due) >= 0 ? CentralFinanceReceivable::PAID : CentralFinanceReceivable::PARTIAL]);
                if ($line !== null) $this->dataIsolation->inheritWorkflowClassification($actor, $schoolId, 'payment_allocation', (int) $line->id);
            }
            $ledger=$this->ledger->recordOperatingIncome($actor,$account,$schoolId,'central_payment',$payment->payment_uuid,$total,$paidAt,$receipt->receipt_no);
            foreach ([['payment', $payment->id], ['receipt', $receipt->id], ['ledger', $ledger->id]] as [$subjectType, $subjectId]) {
                $this->dataIsolation->inheritWorkflowClassification($actor, $schoolId, $subjectType, (int) $subjectId);
            }
            $this->audits->record($actor, $payment, 'central_payment', 'collected', null, null, ['receipt_id' => $receipt->id, 'receivable_ids' => $receivableIds, 'allocation_total' => $total, 'fund_account_id' => $account->id]);
            return ['payment'=>$payment,'receipt'=>$receipt];
        });
    }

    /** @param list<array{receivable_id:mixed,amount:mixed}> $requested @return list<array{receivable_id:int,amount:string}> */
    private function canonicalAllocations(array $requested): array
    {
        if ($requested === []) throw new InvalidArgumentException('Select at least one payment allocation.');
        $result = [];
        foreach ($requested as $line) {
            $receivableId = (int) ($line['receivable_id'] ?? 0);
            if ($receivableId < 1 || isset($result[$receivableId])) throw new InvalidArgumentException('Each selected receivable needs one explicit allocation.');
            $amount = CentralFinanceDecimal::normalize((string) ($line['amount'] ?? ''));
            if (CentralFinanceDecimal::compare($amount, '0') <= 0) throw new InvalidArgumentException('Payment allocation amount must be greater than zero.');
            $result[$receivableId] = ['receivable_id' => $receivableId, 'amount' => $amount];
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }
}
