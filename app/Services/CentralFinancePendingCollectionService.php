<?php

namespace App\Services;

use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinancePendingCollectionAllocation;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Creates a non-financial Front Desk declaration. It never calls payment, receipt, ledger, or balance code. */
final class CentralFinancePendingCollectionService
{
    public const METHOD_CASH = 'Cash';
    public const METHOD_BANK_TRANSFER = 'Bank Transfer';
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinanceDocumentAuditService $audits,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    public function submit(CentralFinanceUser $actor, int $profileId, int $receivableId, string|float $amount, string $method, CarbonImmutable $collectedAt, string $idempotencyReference, ?int $intendedFundAccountId = null, ?string $paymentReference = null, ?string $note = null): CentralFinancePendingCollection
    {
        return $this->submitAllocations($actor, $profileId, [['receivable_id' => $receivableId, 'amount' => (string) $amount]], $method, $collectedAt, $idempotencyReference, $intendedFundAccountId, $paymentReference, $note);
    }

    /**
     * Creates one parent collection declaration with explicit, immutable
     * line allocations. No canonical Payment, Receipt, Ledger, or Fund
     * Account effect exists until Head Finance confirms this parent document.
     *
     * @param list<array{receivable_id:mixed,amount:mixed}> $requestedAllocations
     */
    public function submitAllocations(CentralFinanceUser $actor, int $profileId, array $requestedAllocations, string $method, CarbonImmutable $collectedAt, string $idempotencyReference, ?int $intendedFundAccountId = null, ?string $paymentReference = null, ?string $note = null): CentralFinancePendingCollection
    {
        $method = trim($method);
        $allocations = $this->canonicalAllocations($requestedAllocations);
        if (!in_array($method, [self::METHOD_CASH, self::METHOD_BANK_TRANSFER], true) || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) {
            throw new InvalidArgumentException('Pending collection input is invalid.');
        }
        $school = $this->workspace->currentSchool($actor);
        if ($school === null) throw new AuthorizationException('Select an authorized School before submitting a collection.');
        $this->workspace->assertCanSubmitCollectionsSchool($actor, (int) $school->id);
        $this->cutovers->assertCentralWritesAllowed((int) $school->id);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $profileId, $allocations, $method, $collectedAt, $idempotencyReference, $intendedFundAccountId, $paymentReference, $note): CentralFinancePendingCollection {
            $profile = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)->lockForUpdate()->findOrFail($profileId);
            $key = hash('sha256', 'pending-collection-v2|'.$school->id.'|'.$profile->id.'|'.$idempotencyReference);
            $existing = CentralFinancePendingCollection::on('mysql')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) return $existing;
            // Preserve retry convergence for an in-flight client using the
            // pre-V2 single-receivable key. It cannot manufacture a second
            // parent document after the collection has already been posted.
            if (count($allocations) === 1) {
                $legacyKey = hash('sha256', 'pending-collection|'.$school->id.'|'.$allocations[0]['receivable_id'].'|'.$idempotencyReference);
                $legacy = CentralFinancePendingCollection::on('mysql')->where('idempotency_key', $legacyKey)->lockForUpdate()->first();
                if ($legacy) return $legacy;
            }

            $receivableIds = collect($allocations)->pluck('receivable_id')->sort()->values()->all();
            $receivablesQuery = CentralFinanceReceivable::on('mysql')
                ->where('school_id', $school->id)->where('student_profile_id', $profile->id)
                ->whereIn('id', $receivableIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if (Schema::connection('mysql')->hasTable('central_finance_promotion_applications')) {
                $receivablesQuery = CentralFinanceReceivable::on('mysql')->with('promotionApplication')
                    ->where('school_id', $school->id)->where('student_profile_id', $profile->id)
                    ->whereIn('id', $receivableIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            }
            $receivables = $receivablesQuery;
            if ($receivables->count() !== count($allocations)) throw new AuthorizationException('Every selected receivable must belong to the current Student and School.');
            app(CentralFinanceQaRunService::class)->lockActiveRunForCentralRecords((int) $school->id, 'receivable', $receivables->keys()->all());
            $currencies = $receivables->pluck('currency')->map(fn ($currency) => strtoupper((string) $currency))->unique();
            if ($currencies->count() !== 1) throw new InvalidArgumentException('A Collection V2 parent payment cannot mix currencies.');
            $classifications = $receivables->keys()->map(fn (int $id) => $this->dataIsolation->classification('receivable', $id))->unique();
            if ($classifications->count() !== 1) throw new InvalidArgumentException('A Collection V2 parent payment cannot mix QA/Test and Official receivables.');
            foreach ($allocations as $allocation) {
                $receivable = $receivables->get($allocation['receivable_id']);
                $this->dataIsolation->assertWorkflowWritable('receivable', (int) $receivable->id);
                if (!in_array($receivable->status, [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL], true)) {
                    throw new InvalidArgumentException('Only open or partially paid receivables can accept a pending collection.');
                }
                $reserved = $this->reservedAmount((int) $receivable->id);
                $available = CentralFinanceDecimal::subtract(CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid), $reserved);
                if (CentralFinanceDecimal::compare($allocation['amount'], $available) > 0) {
                    throw new InvalidArgumentException('A requested allocation exceeds this receivable’s available amount.');
                }
            }
            $currency = $currencies->first();
            $total = collect($allocations)->reduce(fn (string $sum, array $line): string => CentralFinanceDecimal::add($sum, $line['amount']), CentralFinanceDecimal::normalize('0'));
            if ($method === self::METHOD_BANK_TRANSFER && $intendedFundAccountId === null) {
                throw new InvalidArgumentException('Bank Transfer requires the intended Bank Fund Account.');
            }
            if ($method === self::METHOD_CASH && $intendedFundAccountId !== null) {
                throw new InvalidArgumentException('Cash collections do not select a Fund Account until Head Finance confirms the handover.');
            }
            if ($intendedFundAccountId !== null) {
                $intended = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($intendedFundAccountId);
                $this->availability->assertAccountAvailableForSchool($intended, (int) $school->id);
                $this->dataIsolation->assertFundAccountMatchesSchoolWorkflow((int) $school->id, (int) $intended->id);
                if ($method === self::METHOD_BANK_TRANSFER && $intended->account_type !== 'bank') {
                    throw new InvalidArgumentException('Bank Transfer requires an active Bank Fund Account.');
                }
                if (strtoupper((string) $intended->currency) !== $currency) {
                    throw new InvalidArgumentException('The intended Fund Account currency does not match the selected receivables.');
                }
            }
            $paymentReference = $paymentReference === null ? null : trim($paymentReference);
            if ($paymentReference === '') $paymentReference = null;
            if ($paymentReference !== null) {
                if (CentralFinancePayment::on('mysql')->where(['school_id' => $school->id, 'payment_reference' => $paymentReference])->exists()) {
                    throw new InvalidArgumentException('Payment reference is already used for this School. Use the existing Payment or correct the reference before submitting.');
                }
                if (CentralFinancePendingCollection::on('mysql')->where('school_id', $school->id)->where('payment_reference', $paymentReference)->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD])->exists()) {
                    throw new InvalidArgumentException('Payment reference is already reserved by a pending collection for this School.');
                }
            }
            $pending = CentralFinancePendingCollection::on('mysql')->create([
                'school_id' => $school->id, 'student_profile_id' => $profile->id, 'receivable_id' => count($allocations) === 1 ? $allocations[0]['receivable_id'] : null,
                'intended_fund_account_id' => $intendedFundAccountId, 'idempotency_key' => $key,
                'acknowledgement_no' => 'PCA-'.$school->id.'-'.strtoupper(Str::random(12)),
                'status' => CentralFinancePendingCollection::SUBMITTED, 'amount' => $total, 'currency' => $currency,
                'payment_method' => $method, 'payment_reference' => $paymentReference, 'note' => $note ? trim($note) : null,
                'collected_at' => $collectedAt, 'collected_by' => $actor->id, 'submitted_by' => $actor->id, 'submitted_at' => now(),
            ]);
            $this->dataIsolation->inheritWorkflowClassification($actor, (int) $school->id, 'pending_collection', (int) $pending->id);
            foreach ($allocations as $allocation) {
                $receivable = $receivables->get($allocation['receivable_id']);
                if (!Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')) continue;
                $gross = CentralFinanceDecimal::normalize((string) ($receivable->source_amount_due ?? $receivable->amount_due));
                $promotion = $receivable->promotionApplication?->discount_amount ?? '0';
                $line = CentralFinancePendingCollectionAllocation::on('mysql')->create([
                    'pending_collection_id' => $pending->id, 'receivable_id' => $receivable->id,
                    'school_id' => $school->id, 'student_profile_id' => $profile->id,
                    'description_snapshot' => $receivable->description,
                    'unit_price_snapshot' => $receivable->unit_price_snapshot,
                    'quantity_snapshot' => max(1, (int) ($receivable->quantity_snapshot ?? 1)),
                    'gross_amount_snapshot' => $gross, 'promotion_amount_snapshot' => $promotion,
                    'net_due_snapshot' => $receivable->amount_due, 'paid_before_snapshot' => $receivable->amount_paid,
                    'outstanding_before_snapshot' => CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid),
                    'amount' => $allocation['amount'], 'currency' => $currency,
                ]);
                $this->dataIsolation->inheritWorkflowClassification($actor, (int) $school->id, 'pending_collection_allocation', (int) $line->id);
            }
            // Multi-allocation Collections are linked only after all allocation
            // rows exist, allowing the run service to verify every parent.
            $this->dataIsolation->inheritWorkflowClassification($actor, (int) $school->id, 'pending_collection', (int) $pending->id);
            $this->audits->record($actor, $pending, 'pending_collection', 'submitted', null, null, $this->snapshot($pending));
            return $pending;
        });
    }

    /** @param list<array{receivable_id:mixed,amount:mixed}> $requested @return list<array{receivable_id:int,amount:string}> */
    private function canonicalAllocations(array $requested): array
    {
        if ($requested === []) throw new InvalidArgumentException('Select at least one receivable allocation.');
        $result = [];
        foreach ($requested as $line) {
            $id = (int) ($line['receivable_id'] ?? 0);
            if ($id < 1 || isset($result[$id])) throw new InvalidArgumentException('Each receivable may appear only once in a parent collection.');
            $amount = CentralFinanceDecimal::normalize((string) ($line['amount'] ?? ''));
            if (CentralFinanceDecimal::compare($amount, '0') <= 0) throw new InvalidArgumentException('Each collection allocation must be greater than zero.');
            $result[$id] = ['receivable_id' => $id, 'amount' => $amount];
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    public function reservedAmount(int $receivableId, bool $lock = true): string
    {
        // Existing installations remain readable/testable while the additive
        // P0 migration is being rehearsed.  Once the allocation table exists
        // it is the only reservation source for new and migrated documents.
        if (!Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')) {
            $amounts = CentralFinancePendingCollection::on('mysql')->where('receivable_id', $receivableId)
                ->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD])
                ->when($lock, fn ($query) => $query->lockForUpdate())->pluck('amount');
            return $amounts->reduce(fn (string $sum, mixed $amount): string => CentralFinanceDecimal::add($sum, (string) $amount), CentralFinanceDecimal::normalize('0'));
        }
        $amounts = CentralFinancePendingCollectionAllocation::on('mysql')->where('receivable_id', $receivableId)
            ->whereHas('pendingCollection', fn ($query) => $query->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD]))
            ->when($lock, fn ($query) => $query->lockForUpdate())->pluck('amount');
        return $amounts->reduce(fn (string $sum, mixed $amount): string => CentralFinanceDecimal::add($sum, (string) $amount), CentralFinanceDecimal::normalize('0'));
    }

    public function hold(CentralFinanceUser $actor, int $pendingId, string $reason): CentralFinancePendingCollection { return $this->review($actor, $pendingId, CentralFinancePendingCollection::HELD, $reason); }
    public function reject(CentralFinanceUser $actor, int $pendingId, string $reason): CentralFinancePendingCollection { return $this->review($actor, $pendingId, CentralFinancePendingCollection::REJECTED, $reason); }

    private function review(CentralFinanceUser $actor, int $pendingId, string $target, string $reason): CentralFinancePendingCollection
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A review reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $pendingId, $target, $reason): CentralFinancePendingCollection {
            $pending = CentralFinancePendingCollection::on('mysql')->lockForUpdate()->findOrFail($pendingId);
            $this->workspace->assertHeadFinance($actor);
            $this->workspace->assertCanOperateSchool($actor, (int) $pending->school_id);
            $this->cutovers->assertCentralWritesAllowed((int) $pending->school_id);
            if (!in_array($pending->status, [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD], true)) throw new InvalidArgumentException('Only a submitted or held collection can be reviewed.');
            $before = $this->snapshot($pending);
            $pending->update(['status' => $target, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => trim($reason)]);
            $this->audits->record($actor, $pending, 'pending_collection', $target, trim($reason), $before, $this->snapshot($pending));
            return $pending->fresh();
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(CentralFinancePendingCollection $pending): array { return $pending->only(['student_profile_id','receivable_id','intended_fund_account_id','confirmed_payment_id','status','amount','currency','payment_method','payment_reference','collected_at','collected_by','submitted_by','submitted_at','reviewed_by','reviewed_at','review_reason','confirmed_by','confirmed_at']); }
}
