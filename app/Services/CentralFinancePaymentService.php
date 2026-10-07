<?php
namespace App\Services;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinancePaymentAllocation;
use App\Models\CentralFinanceReceipt;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUser;
use App\Models\CentralFinanceUnidentifiedDeposit;
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
        return $this->postAllocations($actor, $requestedAllocations, $account, $method, $paidAt, $idempotencyReference, $paymentReference, $note, $receiptIssuedAt);
    }

    /** Existing cash settlement uses the SAME document writer, never an HTTP-controlled skip-ledger flag. */
    public function settleUnidentifiedDeposit(CentralFinanceUser $actor, int $depositId, int $receivableId, string $amount, CarbonImmutable $allocatedAt, string $idempotencyReference, string $reason): array
    {
        $deposit = CentralFinanceUnidentifiedDeposit::on('mysql')->findOrFail($depositId);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($deposit->fund_account_id);
        return $this->postAllocations($actor, [['receivable_id' => $receivableId, 'amount' => $amount]], $account,
            'Bank Transfer', CarbonImmutable::parse($deposit->getRawOriginal('received_date'), 'Asia/Yangon'),
            $idempotencyReference, $deposit->bank_reference, $reason, $allocatedAt, $depositId);
    }

    private function postAllocations(CentralFinanceUser $actor, array $requestedAllocations, CentralFinanceFundAccount $account, string $method, CarbonImmutable $paidAt, string $idempotencyReference, ?string $paymentReference, ?string $note, ?CarbonImmutable $receiptIssuedAt, ?int $depositId = null): array
    {
        if (!preg_match('/^[A-Za-z0-9 _.-]{2,40}$/', trim($method)) || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) {
            throw new InvalidArgumentException('Central payment input is invalid.');
        }
        $allocations = $this->canonicalAllocations($requestedAllocations);
        return DB::connection('mysql')->transaction(function() use($actor,$allocations,$account,$method,$paidAt,$idempotencyReference,$paymentReference,$note,$receiptIssuedAt,$depositId): array {
            $deposit = $depositId === null ? null : CentralFinanceUnidentifiedDeposit::on('mysql')->lockForUpdate()->findOrFail($depositId);
            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
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
            $paymentReference = trim((string) $paymentReference) ?: null;
            $total = collect($allocations)->reduce(fn (string $sum, array $line): string => CentralFinanceDecimal::add($sum, $line['amount']), CentralFinanceDecimal::normalize('0'));
            $requestHash = hash('sha256', json_encode([$allocations, (int) $account->id, trim($method), $paidAt->format('Y-m-d H:i:s'), $paymentReference, $note, $depositId], JSON_THROW_ON_ERROR));
            $this->schools->assertCanOperate($actor,$schoolId);
            $this->accounts->assertCanOperate($actor,$account,$schoolId);
            if ($deposit !== null) {
                $configuration = app(CentralFinanceConfigurationAuthorizationService::class);
                $configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $deposit->group_id);
                $configuration->assertHeadFinanceCanConfigureSchool($actor, \App\Models\School::on('mysql')->findOrFail($schoolId));
                if ((int) $deposit->fund_account_id !== (int) $account->id || (int) $deposit->group_id !== (int) $account->group_id
                    || $account->owner_type !== 'hq' || $account->school_id !== null
                    || !DB::connection('mysql')->table('finance_group_schools')->where(['group_id' => $deposit->group_id, 'school_id' => $schoolId, 'status' => 'active'])->exists()) {
                    throw new InvalidArgumentException('Deposit Group and target School/account scope must match.');
                }
                $this->availability->assertAccountAvailableForSchool($account, $schoolId);
                $this->dataIsolation->assertFundAccountMatchesSchoolWorkflow($schoolId, (int) $account->id);
                $classification = $this->dataIsolation->classification('unidentified_deposit', $deposit->id);
                if ($classification !== $this->dataIsolation->classification('fund_account', $account->id)
                    || $classification !== $this->dataIsolation->classification('school', $schoolId)
                    || $receivables->keys()->contains(fn ($id) => $this->dataIsolation->classification('receivable', $id) !== $classification)) {
                    throw new InvalidArgumentException('Deposit, Fund Account, School and receivable classifications must match.');
                }
            }
            $existing=CentralFinancePayment::on('mysql')->where('idempotency_key',$key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== null && !hash_equals($existing->request_hash, $requestHash)) throw new InvalidArgumentException('Payment idempotency content conflicts with the original request.');
                if ((int) $existing->fund_account_id !== (int) $account->id || CentralFinanceDecimal::compare((string) $existing->amount, $total) !== 0 || (int) $existing->unidentified_deposit_id !== (int) $depositId) throw new InvalidArgumentException('Payment idempotency content conflicts with the original request.');
                return ['payment'=>$existing,'receipt'=>CentralFinanceReceipt::on('mysql')->where('payment_id',$existing->id)->firstOrFail()];
            }
            if ($deposit !== null) {
                app(CentralFinanceQaRunService::class)->lockActiveRunForUnidentifiedAllocation($deposit, $receivables->first());
                if (!in_array($deposit->status, [CentralFinanceUnidentifiedDeposit::UNIDENTIFIED, CentralFinanceUnidentifiedDeposit::PARTIALLY_APPLIED], true)) throw new InvalidArgumentException('The Deposit is not open for allocation.');
                $posted = CentralFinancePayment::on('mysql')->where('unidentified_deposit_id', $deposit->id)->lockForUpdate()->pluck('amount')->reduce(fn ($sum, $value) => CentralFinanceDecimal::add($sum, (string) $value), '0');
                if (CentralFinanceDecimal::compare(CentralFinanceDecimal::add($posted, $total), (string) $deposit->amount) > 0) throw new InvalidArgumentException('Allocation exceeds the remaining Deposit.');
                $backing = \App\Models\CentralFinanceLedgerEntry::on('mysql')->where(['source_type' => 'central_unidentified_deposit', 'source_id' => $deposit->deposit_uuid, 'fund_account_id' => $account->id, 'source_line' => 'primary'])->first();
                if (!$backing || CentralFinanceDecimal::compare((string) $backing->money_in, (string) $deposit->amount) !== 0 || CentralFinanceDecimal::compare((string) $backing->money_out, '0') !== 0) throw new InvalidArgumentException('The Deposit has no verified original physical receipt.');
            }
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
                if ($deposit !== null) {
                    $reserved = app(CentralFinancePendingCollectionService::class)->reservedAmount((int) $receivable->id);
                    $available = CentralFinanceDecimal::subtract(CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid), $reserved);
                    if (CentralFinanceDecimal::compare($allocation['amount'], $available) > 0) throw new InvalidArgumentException('Allocation exceeds outstanding after Pending Collection reservations.');
                }
            }
            $paymentReference = $paymentReference === null ? null : trim($paymentReference);
            if ($paymentReference === '') $paymentReference = null;
            if ($deposit === null && $paymentReference && CentralFinancePayment::on('mysql')->where(['school_id'=>$schoolId,'payment_reference'=>$paymentReference])->exists()) throw new InvalidArgumentException('Payment reference is already used for this School.');
            $total = collect($allocations)->reduce(fn (string $sum, array $line): string => CentralFinanceDecimal::add($sum, $line['amount']), CentralFinanceDecimal::normalize('0'));
            if ($deposit === null && $account->account_type === 'bank') {
                app(CentralFinanceBankTransactionIdentityService::class)->reserve($account, $account->currency, $total, 'payment', $key, $paymentReference, null, null, $requestHash);
            }
            $extra = Schema::connection('mysql')->hasColumn('central_finance_payments', 'request_hash') ? ['request_hash' => $requestHash, 'unidentified_deposit_id' => $depositId] : [];
            $payment=CentralFinancePayment::on('mysql')->create($extra + ['payment_uuid'=>(string)Str::uuid(),'school_id'=>$schoolId,'receivable_id'=>count($allocations) === 1 ? $allocations[0]['receivable_id'] : null,'fund_account_id'=>$account->id,'idempotency_key'=>$key,'payment_reference'=>$deposit === null ? $paymentReference : null,'payment_method'=>trim($method),'note'=>$note,'currency'=>$currencies->first(),'amount'=>$total,'paid_at'=>$paidAt,'received_by'=>$actor->id]);
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
                        'allocated_at' => $deposit === null ? $paidAt : $receiptIssuedAt, 'created_by' => $actor->id,
                    ]);
                }
                $paid = CentralFinanceDecimal::add((string) $receivable->amount_paid, $allocation['amount']);
                $receivable->update(['amount_paid' => $paid, 'status' => CentralFinanceDecimal::compare($paid, (string) $receivable->amount_due) >= 0 ? CentralFinanceReceivable::PAID : CentralFinanceReceivable::PARTIAL]);
                if ($line !== null) $this->dataIsolation->inheritWorkflowClassification($actor, $schoolId, 'payment_allocation', (int) $line->id);
            }
            $ledger=$deposit === null
                ? $this->ledger->recordOperatingIncome($actor,$account,$schoolId,'central_payment',$payment->payment_uuid,$total,$paidAt,$receipt->receipt_no)
                : $this->ledger->recordDepositPaymentAttribution($actor, $payment);
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
