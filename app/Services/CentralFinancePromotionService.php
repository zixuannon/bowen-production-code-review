<?php

namespace App\Services;

use App\Models\CentralFinancePromotion;
use App\Models\CentralFinancePromotionApplication;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUser;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\FinanceGroupSchool;
use App\Support\CentralFinanceDecimal;
use App\Services\CentralFinanceWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Promotion definitions are Group master data; applications are immutable receivable snapshots. */
final class CentralFinancePromotionService
{
    public function __construct(
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
        private readonly CentralFinanceReceivableAdjustmentService $adjustments,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
        private readonly CentralFinanceWorkspaceService $workspace,
    ) {}

    /** @param list<int> $schoolIds */
    public function define(CentralFinanceUser $actor, int $groupId, array $schoolIds, array $input): CentralFinancePromotion
    {
        $this->configuration->assertHeadFinanceCanConfigureGroup($actor, $groupId);
        $schoolIds = array_values(array_unique(array_map('intval', $schoolIds)));
        if ($schoolIds === []) throw new InvalidArgumentException('Allocate a Promotion to at least one active School.');
        $members = FinanceGroupSchool::on('mysql')->where('group_id', $groupId)->where('status', 'active')->whereIn('school_id', $schoolIds)->get();
        if ($members->count() !== count($schoolIds)) throw new AuthorizationException('Every Promotion allocation must be an active member of the selected Finance Group.');
        $classifications = collect($schoolIds)->map(fn (int $schoolId) => $this->dataIsolation->classification('school', $schoolId))->unique();
        if ($classifications->count() !== 1) throw new InvalidArgumentException('A Promotion definition cannot mix QA/Test and Official Schools.');
        $value = CentralFinanceDecimal::normalize((string) $input['discount_value']);
        if (CentralFinanceDecimal::compare($value, '0') <= 0) throw new InvalidArgumentException('Promotion discount value must be greater than zero.');
        if ($input['discount_type'] === CentralFinancePromotion::PERCENTAGE && CentralFinanceDecimal::compare($value, '100') > 0) throw new InvalidArgumentException('Percentage Promotion may not exceed 100%.');
        if (!in_array($input['discount_type'], [CentralFinancePromotion::PERCENTAGE, CentralFinancePromotion::FIXED], true) || !in_array($input['status'], CentralFinancePromotion::STATUSES, true)) throw new InvalidArgumentException('Promotion definition is invalid.');
        if (!empty($input['valid_until']) && $input['valid_until'] < $input['valid_from']) throw new InvalidArgumentException('Promotion end date cannot be before its start date.');
        return DB::connection('mysql')->transaction(function () use ($actor, $groupId, $schoolIds, $input, $value): CentralFinancePromotion {
            $promotion = CentralFinancePromotion::on('mysql')->create(['group_id'=>$groupId,'name'=>trim($input['name']),'code'=>strtoupper(trim($input['code'])),'description'=>trim((string) ($input['description'] ?? '')) ?: null,'discount_type'=>$input['discount_type'],'discount_value'=>$value,'valid_from'=>$input['valid_from'],'valid_until'=>$input['valid_until'] ?: null,'status'=>$input['status'],'fee_scope'=>'all_approved_fees','created_by'=>$actor->id]);
            foreach ($schoolIds as $schoolId) $promotion->allocations()->create(['school_id'=>$schoolId,'status'=>'active']);
            CentralFinanceDocumentAudit::on('mysql')->create(['school_id'=>$schoolIds[0],'group_id'=>$groupId,'document_type'=>'central_finance_promotion','document_id'=>$promotion->id,'action'=>'created','actor_id'=>$actor->id,'reason'=>'Promotion definition created.','before_values'=>null,'after_values'=>['code'=>$promotion->code,'discount_type'=>$promotion->discount_type,'discount_value'=>$promotion->discount_value,'school_ids'=>$schoolIds]]);
            $classification = $this->dataIsolation->classification('school', $schoolIds[0]);
            if ($classification !== 'production') $this->dataIsolation->classify($actor, $schoolIds[0], 'promotion', $promotion->id, $classification, 'Promotion definition classification inherited from its allocated Schools.');
            return $promotion;
        });
    }

    /** @return \Illuminate\Support\Collection<int, CentralFinancePromotion> */
    public function eligibleFor(CentralFinanceUser $actor, CentralFinanceReceivable $receivable, CarbonImmutable $date): \Illuminate\Support\Collection
    {
        $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $receivable->school);
        return $this->eligibleForSchool((int) $receivable->school_id, null, $date);
    }

    /**
     * Front Desk can see only approved definitions that match its trusted
     * School/Fee Setup item. It receives no definition-management authority.
     */
    public function eligibleForFeeSetup(CentralFinanceUser $actor, int $schoolId, int $feesClassTypeId, CarbonImmutable $date): \Illuminate\Support\Collection
    {
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $schoolId);
        return $this->eligibleForSchool($schoolId, $feesClassTypeId, $date);
    }

    /** @return \Illuminate\Support\Collection<int, CentralFinancePromotion> */
    private function eligibleForSchool(int $schoolId, ?int $feesClassTypeId, CarbonImmutable $date): \Illuminate\Support\Collection
    {
        $schoolClassification = $this->dataIsolation->classification('school', $schoolId);
        return CentralFinancePromotion::on('mysql')->where('status', CentralFinancePromotion::ACTIVE)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date): void { $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date->toDateString()); })
            ->whereHas('allocations', fn ($q) => $q->where('school_id', $schoolId)->where('status', 'active'))
            ->where(function ($query) use ($schoolId, $feesClassTypeId): void {
                $query->where('fee_scope', 'all_approved_fees');
                if ($feesClassTypeId !== null) {
                    $query->orWhereExists(function ($fee) use ($schoolId, $feesClassTypeId): void {
                        $fee->selectRaw('1')->from('central_finance_promotion_fee_allocations')
                            ->whereColumn('central_finance_promotion_fee_allocations.promotion_id', 'central_finance_promotions.id')
                            ->where('school_id', $schoolId)->where('fees_class_type_id', $feesClassTypeId)->where('status', 'active');
                    });
                }
            })
            ->orderBy('code')->get()
            ->filter(fn (CentralFinancePromotion $promotion): bool => $this->dataIsolation->classification('promotion', (int) $promotion->id) === $schoolClassification)
            ->values();
    }

    public function apply(CentralFinanceUser $actor, int $receivableId, int $promotionId, CarbonImmutable $effectiveDate, string $reason, CarbonImmutable $recordedAt, string $key): CentralFinancePromotionApplication
    {
        return $this->applyInternal($actor, $receivableId, $promotionId, $effectiveDate, $reason, $recordedAt, $key, false);
    }

    /** Promotion selection during Front Desk Student Fee Setup. */
    public function applyFromFeeSetup(CentralFinanceUser $actor, int $receivableId, int $promotionId, int $feesClassTypeId, CarbonImmutable $effectiveDate, CarbonImmutable $recordedAt, string $key): CentralFinancePromotionApplication
    {
        return $this->applyInternal($actor, $receivableId, $promotionId, $effectiveDate, 'Promotion selected during Student Fee Setup.', $recordedAt, $key, true, $feesClassTypeId);
    }

    private function applyInternal(CentralFinanceUser $actor, int $receivableId, int $promotionId, CarbonImmutable $effectiveDate, string $reason, CarbonImmutable $recordedAt, string $key, bool $fromFeeSetup, ?int $feesClassTypeId = null): CentralFinancePromotionApplication
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $receivableId, $promotionId, $effectiveDate, $reason, $recordedAt, $key, $fromFeeSetup, $feesClassTypeId): CentralFinancePromotionApplication {
            $receivable = CentralFinanceReceivable::on('mysql')->lockForUpdate()->findOrFail($receivableId);
            if ($fromFeeSetup) {
                $this->workspace->assertCanSubmitCollectionsSchool($actor, (int) $receivable->school_id);
            } else {
                $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $receivable->school);
            }
            $this->dataIsolation->assertWorkflowWritable('receivable', $receivable->id);
            $idempotency = hash('sha256', implode('|', ['promotion-application', $receivable->school_id, $receivable->id, $key]));
            $existing = CentralFinancePromotionApplication::on('mysql')->where('idempotency_key', $idempotency)->lockForUpdate()->first();
            if ($existing !== null) return $existing;
            if (CentralFinancePromotionApplication::on('mysql')->where('receivable_id', $receivable->id)->exists()) throw new InvalidArgumentException('Only one Promotion may be applied to a receivable.');
            if ($receivable->adjustments()->exists()) throw new InvalidArgumentException('Apply a Promotion before any waiver or correction so its snapshot remains unambiguous.');
            $promotion = CentralFinancePromotion::on('mysql')->lockForUpdate()->findOrFail($promotionId);
            if (!$this->eligibleForSchool((int) $receivable->school_id, $fromFeeSetup ? $feesClassTypeId : null, $effectiveDate)->contains('id', $promotion->id)) throw new AuthorizationException('This Promotion is not active for the selected School, Fee Item, and effective date.');
            $gross = CentralFinanceDecimal::normalize((string) ($receivable->source_amount_due ?? $receivable->amount_due));
            $discount = $promotion->discount_type === CentralFinancePromotion::PERCENTAGE
                ? CentralFinanceDecimal::percentageOf($gross, (string) $promotion->discount_value)
                : CentralFinanceDecimal::normalize((string) $promotion->discount_value);
            if (CentralFinanceDecimal::compare($discount, '0') <= 0 || CentralFinanceDecimal::compare($discount, $gross) >= 0) throw new InvalidArgumentException('Promotion discount must be positive and less than the gross receivable.');
            $note = trim($reason) === '' ? 'Promotion '.(string) $promotion->code.' applied.' : trim($reason);
            $adjustment = $fromFeeSetup
                ? $this->adjustments->applyPromotionDuringFeeSetup($actor, $receivable->id, $discount, $note, $effectiveDate, $recordedAt, $key)
                : $this->adjustments->applyPromotion($actor, $receivable->id, $discount, $note, $effectiveDate, $recordedAt, $key);
            $application = CentralFinancePromotionApplication::on('mysql')->create([
                'school_id' => $receivable->school_id, 'receivable_id' => $receivable->id, 'promotion_id' => $promotion->id,
                'adjustment_id' => $adjustment->id, 'idempotency_key' => $idempotency,
                'promotion_name_snapshot' => $promotion->name, 'promotion_code_snapshot' => $promotion->code,
                'discount_type_snapshot' => $promotion->discount_type, 'discount_value_snapshot' => $promotion->discount_value,
                'gross_amount_snapshot' => $gross, 'discount_amount' => $discount,
                'net_amount_snapshot' => CentralFinanceDecimal::subtract($gross, $discount),
                'effective_date' => $effectiveDate->toDateString(), 'reason' => $note,
                'applied_by' => $actor->id, 'applied_at' => $recordedAt,
            ]);
            $this->dataIsolation->inheritWorkflowClassification($actor, (int) $receivable->school_id, 'promotion_application', (int) $application->id);
            return $application;
        });
    }
}
