<?php

namespace App\Services;

use App\Models\CentralFinancePromotion;
use App\Models\CentralFinancePromotionApplication;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\FinanceGroupSchool;
use App\Models\School;
use App\Support\CentralFinanceDecimal;
use App\Services\CentralFinanceWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Promotion definitions are Group master data; applications are immutable receivable snapshots. */
final class CentralFinancePromotionService
{
    public const DUPLICATE_CODE_MESSAGE = 'Promotion code is already used in this Finance Group.';

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
        $studentProfileId = isset($input['student_profile_id']) && $input['student_profile_id'] !== '' ? (int) $input['student_profile_id'] : null;
        $scope = (string) ($input['scope'] ?? ($studentProfileId === null ? CentralFinancePromotion::GENERAL : CentralFinancePromotion::STUDENT_SPECIFIC));
        if ($studentProfileId !== null || $scope === CentralFinancePromotion::STUDENT_SPECIFIC) {
            throw new InvalidArgumentException('Student-specific Discounts must be submitted from Student Fee Setup and approved by Head Finance.');
        }
        if (!in_array($scope, CentralFinancePromotion::SCOPES, true)) {
            throw new InvalidArgumentException('Promotion scope is invalid.');
        }
        if ($scope === CentralFinancePromotion::GENERAL && $studentProfileId !== null) {
            throw new InvalidArgumentException('A general Promotion cannot target an individual Student.');
        }
        if ($scope === CentralFinancePromotion::STUDENT_SPECIFIC && $studentProfileId === null) {
            throw new InvalidArgumentException('A student-specific Promotion requires one Student.');
        }
        if ($studentProfileId !== null) {
            if (!$this->studentSpecificSchemaInstalled()) {
                throw new InvalidArgumentException('Student-specific Promotion scope is not available until the approved schema update is installed.');
            }
            $profile = CentralFinanceStudentProfile::on('mysql')->find($studentProfileId);
            if ($profile === null || !in_array((int) $profile->school_id, $schoolIds, true)) {
                throw new AuthorizationException('The selected Student must belong to an allocated School.');
            }
            if (count($schoolIds) !== 1) {
                throw new InvalidArgumentException('A student-specific Promotion must be allocated to exactly that Student’s School.');
            }
            if ($this->dataIsolation->classification('student_profile', $profile->id) !== $classifications->first()) {
                throw new AuthorizationException('The selected Student does not match the Promotion data classification.');
            }
        }
        $value = CentralFinanceDecimal::normalize((string) $input['discount_value']);
        if (CentralFinanceDecimal::compare($value, '0') <= 0) throw new InvalidArgumentException('Promotion discount value must be greater than zero.');
        if ($input['discount_type'] === CentralFinancePromotion::PERCENTAGE && CentralFinanceDecimal::compare($value, '100') > 0) throw new InvalidArgumentException('Percentage Promotion may not exceed 100%.');
        if (!in_array($input['discount_type'], [CentralFinancePromotion::PERCENTAGE, CentralFinancePromotion::FIXED], true) || !in_array($input['status'], CentralFinancePromotion::STATUSES, true)) throw new InvalidArgumentException('Promotion definition is invalid.');
        if (!empty($input['valid_until']) && $input['valid_until'] < $input['valid_from']) throw new InvalidArgumentException('Promotion end date cannot be before its start date.');
        $code = strtoupper(trim((string) $input['code']));
        if (CentralFinancePromotion::on('mysql')->where('group_id', $groupId)->where('code', $code)->exists()) {
            throw new InvalidArgumentException(self::DUPLICATE_CODE_MESSAGE);
        }

        try {
            return DB::connection('mysql')->transaction(function () use ($actor, $groupId, $schoolIds, $input, $value, $studentProfileId, $scope, $code): CentralFinancePromotion {
                $attributes = ['group_id'=>$groupId,'name'=>trim($input['name']),'code'=>$code,'description'=>trim((string) ($input['description'] ?? '')) ?: null,'discount_type'=>$input['discount_type'],'discount_value'=>$value,'valid_from'=>$input['valid_from'],'valid_until'=>$input['valid_until'] ?: null,'status'=>$input['status'],'fee_scope'=>'all_approved_fees','created_by'=>$actor->id];
                if ($this->studentSpecificSchemaInstalled()) {
                    $attributes['student_profile_id'] = $studentProfileId;
                    $attributes['scope'] = $scope;
                }
                $promotion = CentralFinancePromotion::on('mysql')->create($attributes);
                foreach ($schoolIds as $schoolId) $promotion->allocations()->create(['school_id'=>$schoolId,'status'=>'active']);
                CentralFinanceDocumentAudit::on('mysql')->create(['school_id'=>$schoolIds[0],'group_id'=>$groupId,'document_type'=>'central_finance_promotion','document_id'=>$promotion->id,'action'=>'created','actor_id'=>$actor->id,'reason'=>'Promotion definition created.','before_values'=>null,'after_values'=>['code'=>$promotion->code,'discount_type'=>$promotion->discount_type,'discount_value'=>$promotion->discount_value,'school_ids'=>$schoolIds,'scope'=>$scope,'student_profile_id'=>$promotion->student_profile_id]]);
                $classification = $this->dataIsolation->classification('school', $schoolIds[0]);
                if ($classification !== 'production') $this->dataIsolation->classify($actor, $schoolIds[0], 'promotion', $promotion->id, $classification, 'Promotion definition classification inherited from its allocated Schools.');
                return $promotion;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'cf_promotion_group_code_unique')) {
                throw new InvalidArgumentException(self::DUPLICATE_CODE_MESSAGE, previous: $exception);
            }

            throw $exception;
        }
    }

    /** Edit only an unused general definition; applied snapshots stay immutable. @param list<int> $schoolIds */
    public function editUnused(CentralFinanceUser $actor, int $promotionId, array $schoolIds, array $input, string $reason): CentralFinancePromotion
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $promotionId, $schoolIds, $input, $reason): CentralFinancePromotion {
            $promotion = CentralFinancePromotion::on('mysql')->lockForUpdate()->findOrFail($promotionId);
            $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $promotion->group_id);
            if ($this->hasHistoricalUse($promotion)) {
                throw new InvalidArgumentException('A used Promotion cannot be edited. Create a new Promotion for changed terms.');
            }
            if ((string) ($promotion->scope ?? CentralFinancePromotion::GENERAL) !== CentralFinancePromotion::GENERAL) {
                throw new InvalidArgumentException('Student-specific Discounts cannot be edited from Promotion management.');
            }

            $schoolIds = array_values(array_unique(array_map('intval', $schoolIds)));
            if ($schoolIds === []) throw new InvalidArgumentException('Allocate a Promotion to at least one active School.');
            $members = FinanceGroupSchool::on('mysql')->where('group_id', $promotion->group_id)->where('status', 'active')->whereIn('school_id', $schoolIds)->get();
            if ($members->count() !== count($schoolIds)) throw new AuthorizationException('Every Promotion allocation must be an active member of the selected Finance Group.');
            $classifications = collect($schoolIds)->map(fn (int $schoolId) => $this->dataIsolation->classification('school', $schoolId))->unique();
            if ($classifications->count() !== 1 || $classifications->first() !== $this->dataIsolation->classification('promotion', (int) $promotion->id)) {
                throw new InvalidArgumentException('Promotion allocations must retain one matching QA/Test or Official classification.');
            }

            $name = trim((string) ($input['name'] ?? ''));
            $type = (string) ($input['discount_type'] ?? '');
            $value = CentralFinanceDecimal::normalize((string) ($input['discount_value'] ?? '0'));
            $from = (string) ($input['valid_from'] ?? '');
            $until = trim((string) ($input['valid_until'] ?? ''));
            if ($name === '' || mb_strlen($name) > 191 || !in_array($type, [CentralFinancePromotion::PERCENTAGE, CentralFinancePromotion::FIXED], true)
                || CentralFinanceDecimal::compare($value, '0') <= 0
                || ($type === CentralFinancePromotion::PERCENTAGE && CentralFinanceDecimal::compare($value, '100') > 0)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                || ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < $from))) {
                throw new InvalidArgumentException('Promotion terms are invalid.');
            }
            $reason = trim($reason);
            if ($reason === '' || mb_strlen($reason) > 2000) throw new InvalidArgumentException('A reason is required to edit a Promotion.');

            $before = [
                'name' => $promotion->name, 'description' => $promotion->description,
                'discount_type' => $promotion->discount_type, 'discount_value' => (string) $promotion->discount_value,
                'valid_from' => $promotion->valid_from?->format('Y-m-d'), 'valid_until' => $promotion->valid_until?->format('Y-m-d'),
                'school_ids' => $promotion->allocations()->where('status', 'active')->orderBy('school_id')->pluck('school_id')->map(fn ($id) => (int) $id)->all(),
            ];
            $promotion->fill([
                'name' => $name,
                'description' => trim((string) ($input['description'] ?? '')) ?: null,
                'discount_type' => $type,
                'discount_value' => $value,
                'valid_from' => $from,
                'valid_until' => $until !== '' ? $until : null,
                'updated_by' => $actor->id,
            ])->save();

            $allocations = $promotion->allocations()->lockForUpdate()->get()->keyBy('school_id');
            foreach ($allocations as $schoolId => $allocation) {
                $allocation->update(['status' => in_array((int) $schoolId, $schoolIds, true) ? 'active' : 'inactive']);
            }
            foreach ($schoolIds as $schoolId) {
                $allocation = $allocations->get($schoolId);
                if ($allocation === null) {
                    $promotion->allocations()->create(['school_id' => $schoolId, 'status' => 'active']);
                }
            }
            CentralFinanceDocumentAudit::on('mysql')->create([
                'school_id' => $schoolIds[0], 'group_id' => $promotion->group_id, 'document_type' => 'central_finance_promotion',
                'document_id' => $promotion->id, 'action' => 'edited_unused', 'actor_id' => $actor->id, 'reason' => $reason,
                'before_values' => $before,
                'after_values' => ['name' => $promotion->name, 'description' => $promotion->description, 'discount_type' => $promotion->discount_type,
                    'discount_value' => (string) $promotion->discount_value, 'valid_from' => $from, 'valid_until' => $until ?: null, 'school_ids' => $schoolIds],
            ]);

            return $promotion->fresh(['allocations']);
        });
    }

    /** Enable or disable future use without changing immutable application snapshots. */
    public function setStatus(CentralFinanceUser $actor, int $promotionId, string $status, string $reason): CentralFinancePromotion
    {
        if (!in_array($status, [CentralFinancePromotion::ACTIVE, CentralFinancePromotion::INACTIVE], true)) {
            throw new InvalidArgumentException('Promotion status must be active or inactive.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) throw new InvalidArgumentException('A reason is required to change Promotion status.');

        return DB::connection('mysql')->transaction(function () use ($actor, $promotionId, $status, $reason): CentralFinancePromotion {
            $promotion = CentralFinancePromotion::on('mysql')->lockForUpdate()->findOrFail($promotionId);
            $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $promotion->group_id);
            $before = $promotion->status;
            if ($before !== $status) {
                $auditSchoolId = $promotion->allocations()->orderBy('school_id')->value('school_id');
                if ($auditSchoolId === null) {
                    throw new InvalidArgumentException('A Promotion needs a retained School allocation before its status can be audited.');
                }
                $promotion->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
                CentralFinanceDocumentAudit::on('mysql')->create([
                    'school_id' => $auditSchoolId, 'group_id' => $promotion->group_id,
                    'document_type' => 'central_finance_promotion', 'document_id' => $promotion->id,
                    'action' => $status === CentralFinancePromotion::ACTIVE ? 'enabled' : 'disabled', 'actor_id' => $actor->id,
                    'reason' => $reason, 'before_values' => ['status' => $before], 'after_values' => ['status' => $status],
                ]);
            }
            return $promotion->fresh();
        });
    }

    /** Delete only a never-applied, unreferenced definition and retain an append-only deletion audit. */
    public function deleteUnused(CentralFinanceUser $actor, int $promotionId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) throw new InvalidArgumentException('A reason is required to delete a Promotion.');

        DB::connection('mysql')->transaction(function () use ($actor, $promotionId, $reason): void {
            $promotion = CentralFinancePromotion::on('mysql')->lockForUpdate()->findOrFail($promotionId);
            $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $promotion->group_id);
            if ($this->hasHistoricalUse($promotion)) {
                throw new InvalidArgumentException('A Promotion with historical or request dependencies cannot be deleted. Disable it instead.');
            }
            if (Schema::connection('mysql')->hasTable('central_finance_promotion_fee_allocations')
                && DB::connection('mysql')->table('central_finance_promotion_fee_allocations')->where('promotion_id', $promotion->id)->exists()) {
                throw new InvalidArgumentException('A Promotion with Fee allocation dependencies cannot be deleted.');
            }

            $allocations = $promotion->allocations()->lockForUpdate()->get(['school_id', 'status']);
            if ($allocations->isEmpty()) {
                throw new InvalidArgumentException('A Promotion needs a retained School allocation before its deletion can be audited.');
            }
            CentralFinanceDocumentAudit::on('mysql')->create([
                'school_id' => $allocations->first()?->school_id, 'group_id' => $promotion->group_id,
                'document_type' => 'central_finance_promotion', 'document_id' => $promotion->id,
                'action' => 'deleted_unused', 'actor_id' => $actor->id, 'reason' => $reason,
                'before_values' => ['promotion_uuid' => $promotion->promotion_uuid, 'group_id' => $promotion->group_id,
                    'name' => $promotion->name, 'code' => $promotion->code, 'description' => $promotion->description,
                    'discount_type' => $promotion->discount_type, 'discount_value' => (string) $promotion->discount_value,
                    'valid_from' => $promotion->valid_from?->format('Y-m-d'), 'valid_until' => $promotion->valid_until?->format('Y-m-d'),
                    'status' => $promotion->status, 'school_allocations' => $allocations->map(fn ($row) => ['school_id' => (int) $row->school_id, 'status' => $row->status])->all()],
                'after_values' => null,
            ]);
            DB::connection('mysql')->table('central_finance_promotion_school_allocations')->where('promotion_id', $promotion->id)->delete();
            DB::connection('mysql')->table('central_finance_promotions')->where('id', $promotion->id)->delete();
        });
    }

    private function hasHistoricalUse(CentralFinancePromotion $promotion): bool
    {
        if ($promotion->applications()->exists()) return true;
        foreach (['central_finance_student_discount_requests', 'central_finance_promotion_fee_allocations'] as $table) {
            if (Schema::connection('mysql')->hasTable($table)
                && DB::connection('mysql')->table($table)->where('promotion_id', $promotion->id)->exists()) {
                return true;
            }
        }
        return false;
    }

    /** @return \Illuminate\Support\Collection<int, CentralFinancePromotion> */
    public function eligibleFor(CentralFinanceUser $actor, CentralFinanceReceivable $receivable, CarbonImmutable $date): \Illuminate\Support\Collection
    {
        $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $receivable->school);
        return $this->eligibleForSchool((int) $receivable->school_id, null, $date, (int) $receivable->student_profile_id);
    }

    /**
     * Front Desk can see only approved definitions that match its trusted
     * School/Fee Setup item. It receives no definition-management authority.
     */
    public function eligibleForFeeSetup(CentralFinanceUser $actor, int $schoolId, int $studentProfileId, int $feesClassTypeId, CarbonImmutable $date): \Illuminate\Support\Collection
    {
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $schoolId);
        return $this->eligibleForSchool($schoolId, $feesClassTypeId, $date, $studentProfileId);
    }

    /** Throws unless this Front Desk actor has the explicit narrow capability. */
    public function assertCanCreateStudentSpecificDiscount(CentralFinanceUser $actor, int $schoolId): void
    {
        if (!$this->studentSpecificSchemaInstalled()) {
            throw new AuthorizationException('Student-specific Discounts are unavailable until the approved schema update is installed.');
        }
        // A Central Head Finance decision is the approval boundary. It may
        // materialise the exact request without inheriting a Front Desk scope.
        try {
            $this->configuration->assertHeadFinanceCanConfigureSchool($actor, School::on('mysql')->findOrFail($schoolId));
            return;
        } catch (AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // A tenant Front Desk still requires the deliberately narrow scope
            // below merely to submit a request from its own Fee Setup screen.
            // The legacy User model intentionally follows a tenant session;
            // that makes the Central-only Head Finance role lookup unavailable
            // here and is equivalent to a non-Head-Finance result.
        }
        $this->workspace->assertCanCreateStudentSpecificDiscountsSchool($actor, $schoolId);
    }

    /**
     * Creates one active, exact-Student and exact-Fee definition through the
     * established Promotion engine.  No browser-supplied School, Student,
     * Group, or Fee allocation is trusted.
     *
     * @param array{discount_type:string,discount_value:string,reason:string,effective_date:CarbonImmutable} $input
     */
    public function defineStudentSpecificForFeeSetup(CentralFinanceUser $actor, CentralFinanceStudentProfile $profile, int $feesClassTypeId, array $input, string $idempotencyKey): CentralFinancePromotion
    {
        $schoolId = (int) $profile->school_id;
        $this->assertCanCreateStudentSpecificDiscount($actor, $schoolId);
        $groupId = $this->singleActiveGroupForStudentDiscount($actor, $schoolId);

        return $this->createStudentSpecificForFeeSetup($actor, $groupId, $profile, $feesClassTypeId, $input, $idempotencyKey);
    }

    /**
     * Materialises only the immutable terms of a Head Finance-approved request.
     * The requester cannot turn its narrow submit permission into definition
     * authority, and Head Finance needs control of the request's own Group,
     * not an unrelated school-operate grant.
     *
     * @param array{discount_type:string,discount_value:string,reason:string,effective_date:CarbonImmutable} $input
     */
    public function defineApprovedStudentSpecificForFeeSetupRequest(CentralFinanceUser $actor, int $groupId, CentralFinanceStudentProfile $profile, int $feesClassTypeId, array $input, string $idempotencyKey): CentralFinancePromotion
    {
        $this->configuration->assertHeadFinanceCanConfigureGroup($actor, $groupId);
        $schoolId = (int) $profile->school_id;
        if (!FinanceGroupSchool::on('mysql')->where(['group_id' => $groupId, 'school_id' => $schoolId, 'status' => 'active'])->exists()) {
            throw new AuthorizationException('The approved Student-specific Discount is outside its active Finance Group School allocation.');
        }

        return $this->createStudentSpecificForFeeSetup($actor, $groupId, $profile, $feesClassTypeId, $input, $idempotencyKey);
    }

    /**
     * @param array{discount_type:string,discount_value:string,reason:string,effective_date:CarbonImmutable} $input
     */
    private function createStudentSpecificForFeeSetup(CentralFinanceUser $actor, int $groupId, CentralFinanceStudentProfile $profile, int $feesClassTypeId, array $input, string $idempotencyKey): CentralFinancePromotion
    {
        $schoolId = (int) $profile->school_id;
        if ($feesClassTypeId < 1 || !preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('Student-specific Discount request is invalid.');
        }
        $type = (string) ($input['discount_type'] ?? '');
        $value = CentralFinanceDecimal::normalize((string) ($input['discount_value'] ?? '0'));
        $reason = trim((string) ($input['reason'] ?? ''));
        $effectiveDate = $input['effective_date'] ?? null;
        if (!in_array($type, [CentralFinancePromotion::PERCENTAGE, CentralFinancePromotion::FIXED], true)
            || CentralFinanceDecimal::compare($value, '0') <= 0
            || ($type === CentralFinancePromotion::PERCENTAGE && CentralFinanceDecimal::compare($value, '100') >= 0)
            || $reason === '' || mb_strlen($reason) > 2000 || !$effectiveDate instanceof CarbonImmutable) {
            throw new InvalidArgumentException('Student-specific Discount details are invalid.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $profile, $feesClassTypeId, $idempotencyKey, $type, $value, $reason, $effectiveDate, $schoolId, $groupId): CentralFinancePromotion {
            $existing = CentralFinancePromotion::on('mysql')->where('creation_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->scope !== CentralFinancePromotion::STUDENT_SPECIFIC
                    || (int) $existing->student_profile_id !== (int) $profile->id
                    || (int) $existing->group_id !== $groupId
                    || $existing->discount_type !== $type
                    || CentralFinanceDecimal::compare((string) $existing->discount_value, $value) !== 0
                    || !hash_equals((string) $existing->student_discount_reason, $reason)
                    || (string) $existing->valid_from?->format('Y-m-d') !== $effectiveDate->toDateString()
                    || !DB::connection('mysql')->table('central_finance_promotion_fee_allocations')->where([
                        'promotion_id' => $existing->id, 'school_id' => $schoolId, 'fees_class_type_id' => $feesClassTypeId, 'status' => 'active',
                    ])->exists()) {
                    throw new AuthorizationException('The existing Student-specific Discount does not match this trusted Fee Setup request.');
                }
                return $existing;
            }
            $suffix = strtoupper(substr($idempotencyKey, 0, 12));
            $promotion = CentralFinancePromotion::on('mysql')->create([
                'group_id' => $groupId,
                'student_profile_id' => $profile->id,
                'scope' => CentralFinancePromotion::STUDENT_SPECIFIC,
                'creation_idempotency_key' => $idempotencyKey,
                'name' => 'Student-specific Discount · '.mb_substr((string) $profile->student_name, 0, 120),
                'code' => 'STD-'.$profile->id.'-'.$feesClassTypeId.'-'.$suffix,
                'description' => 'Created from trusted Student Fee Setup.',
                'student_discount_reason' => $reason,
                'discount_type' => $type,
                'discount_value' => $value,
                'valid_from' => $effectiveDate->toDateString(),
                'valid_until' => null,
                'status' => CentralFinancePromotion::ACTIVE,
                'fee_scope' => 'specific_fee_items',
                'created_by' => $actor->id,
            ]);
            $promotion->allocations()->create(['school_id' => $schoolId, 'status' => 'active']);
            DB::connection('mysql')->table('central_finance_promotion_fee_allocations')->insert([
                'promotion_id' => $promotion->id,
                'school_id' => $schoolId,
                'fees_class_type_id' => $feesClassTypeId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $auditAttributes = [
                'school_id' => $schoolId,
                'document_type' => 'central_finance_promotion',
                'document_id' => $promotion->id,
                'action' => 'student_specific_discount_created',
                'actor_id' => $actor->id,
                'reason' => $reason,
                'before_values' => null,
                'after_values' => [
                    'scope' => CentralFinancePromotion::STUDENT_SPECIFIC,
                    'student_profile_id' => (int) $profile->id,
                    'fees_class_type_id' => $feesClassTypeId,
                    'discount_type' => $type,
                    'discount_value' => $value,
                    'effective_date' => $effectiveDate->toDateString(),
                ],
            ];
            if (Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id')) {
                $auditAttributes['group_id'] = $groupId;
            }
            CentralFinanceDocumentAudit::on('mysql')->create($auditAttributes);
            $classification = $this->dataIsolation->classification('school', $schoolId);
            if ($classification !== 'production') {
                $this->dataIsolation->classify($actor, $schoolId, 'promotion', $promotion->id, $classification, 'Student-specific Discount classification inherited from its trusted School.');
            }
            return $promotion;
        });
    }

    private function singleActiveGroupForStudentDiscount(CentralFinanceUser $actor, int $schoolId): int
    {
        $groupIds = FinanceGroupSchool::on('mysql')->where('school_id', $schoolId)->where('status', 'active')
            ->whereIn('group_id', DB::connection('mysql')->table('finance_group_users')->where('central_user_id', $actor->id)->where('status', 'active')->pluck('group_id'))
            ->pluck('group_id')->unique()->values();
        if ($groupIds->count() !== 1) {
            throw new AuthorizationException('The current Front Desk identity has no unambiguous active Finance Group for this Student.');
        }

        return (int) $groupIds->sole();
    }

    /** @return \Illuminate\Support\Collection<int, CentralFinancePromotion> */
    private function eligibleForSchool(int $schoolId, ?int $feesClassTypeId, CarbonImmutable $date, ?int $studentProfileId = null): \Illuminate\Support\Collection
    {
        $schoolClassification = $this->dataIsolation->classification('school', $schoolId);
        return CentralFinancePromotion::on('mysql')->where('status', CentralFinancePromotion::ACTIVE)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date): void { $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date->toDateString()); })
            ->whereHas('allocations', fn ($q) => $q->where('school_id', $schoolId)->where('status', 'active'))
            ->when($this->studentSpecificSchemaInstalled(), function ($query) use ($studentProfileId): void {
                $query->where(function ($scoped) use ($studentProfileId): void {
                    $scoped->where(function ($general): void {
                        $general->where('scope', CentralFinancePromotion::GENERAL)->whereNull('student_profile_id');
                    });
                    if ($studentProfileId !== null) {
                        $scoped->orWhere(function ($student) use ($studentProfileId): void {
                            $student->where('scope', CentralFinancePromotion::STUDENT_SPECIFIC)->where('student_profile_id', $studentProfileId);
                        });
                    }
                });
            })
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
    public function applyFromFeeSetup(CentralFinanceUser $actor, int $receivableId, int $promotionId, int $feesClassTypeId, CarbonImmutable $effectiveDate, CarbonImmutable $recordedAt, string $key, ?string $reason = null): CentralFinancePromotionApplication
    {
        return $this->applyInternal($actor, $receivableId, $promotionId, $effectiveDate, $reason ?: 'Promotion selected during Student Fee Setup.', $recordedAt, $key, true, $feesClassTypeId);
    }

    /**
     * Read-only Fee Setup quote. Preview and confirmed application share the
     * same eligibility and exact-decimal calculation; this creates no
     * adjustment, Promotion Application, or Receivable.
     *
     * @return array{promotion:string,discount:string,net:string}
     */
    public function previewForFeeSetup(CentralFinanceUser $actor, int $schoolId, int $feesClassTypeId, int $promotionId, string $gross, CarbonImmutable $effectiveDate, ?int $studentProfileId = null): array
    {
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $schoolId);
        $promotion = $this->eligibleForSchool($schoolId, $feesClassTypeId, $effectiveDate, $studentProfileId)->firstWhere('id', $promotionId);
        if ($promotion === null) {
            throw new AuthorizationException('This Promotion is not active for the selected School, Fee Item, and effective date.');
        }

        $gross = CentralFinanceDecimal::normalize($gross);
        $discount = $this->discountForGross($promotion, $gross);
        return [
            'promotion' => trim((string) $promotion->code.' · '.(string) $promotion->name),
            'discount' => $discount,
            'net' => CentralFinanceDecimal::subtract($gross, $discount),
        ];
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
            if (!$this->eligibleForSchool((int) $receivable->school_id, $fromFeeSetup ? $feesClassTypeId : null, $effectiveDate, (int) $receivable->student_profile_id)->contains('id', $promotion->id)) throw new AuthorizationException('This Promotion is not active for the selected School, Student, Fee Item, and effective date.');
            $gross = CentralFinanceDecimal::normalize((string) ($receivable->source_amount_due ?? $receivable->amount_due));
            $discount = $this->discountForGross($promotion, $gross);
            $note = trim($reason) === '' ? 'Promotion '.(string) $promotion->code.' applied.' : trim($reason);
            $adjustment = $fromFeeSetup
                ? $this->adjustments->applyPromotionDuringFeeSetup($actor, $receivable->id, $discount, $note, $effectiveDate, $recordedAt, $key)
                : $this->adjustments->applyPromotion($actor, $receivable->id, $discount, $note, $effectiveDate, $recordedAt, $key);
            $applicationAttributes = [
                'school_id' => $receivable->school_id, 'receivable_id' => $receivable->id, 'promotion_id' => $promotion->id,
                'adjustment_id' => $adjustment->id, 'idempotency_key' => $idempotency,
                'promotion_name_snapshot' => $promotion->name, 'promotion_code_snapshot' => $promotion->code,
                'discount_type_snapshot' => $promotion->discount_type, 'discount_value_snapshot' => $promotion->discount_value,
                'gross_amount_snapshot' => $gross, 'discount_amount' => $discount,
                'net_amount_snapshot' => CentralFinanceDecimal::subtract($gross, $discount),
                'effective_date' => $effectiveDate->toDateString(), 'reason' => $note,
                'applied_by' => $actor->id, 'applied_at' => $recordedAt,
            ];
            if ($this->studentSpecificSchemaInstalled()) {
                $applicationAttributes += [
                    'promotion_scope_snapshot' => $promotion->scope,
                    'student_profile_id_snapshot' => $promotion->student_profile_id,
                    'fees_class_type_id_snapshot' => $feesClassTypeId,
                    'applied_by_role_snapshot' => $this->workspace->isSchoolStaffPrincipal($actor)
                        ? 'front_desk'
                        : ($this->workspace->canReviewPendingCollections($actor) ? 'head_finance' : 'central_finance'),
                ];
            }
            $application = CentralFinancePromotionApplication::on('mysql')->create($applicationAttributes);
            $this->dataIsolation->inheritWorkflowClassification($actor, (int) $receivable->school_id, 'promotion_application', (int) $application->id);
            return $application;
        });
    }

    private function discountForGross(CentralFinancePromotion $promotion, string $gross): string
    {
        $discount = $promotion->discount_type === CentralFinancePromotion::PERCENTAGE
            ? CentralFinanceDecimal::percentageOf($gross, (string) $promotion->discount_value)
            : CentralFinanceDecimal::normalize((string) $promotion->discount_value);
        if (CentralFinanceDecimal::compare($discount, '0') <= 0 || CentralFinanceDecimal::compare($discount, $gross) >= 0) {
            throw new InvalidArgumentException('Promotion discount must be positive and less than the gross receivable.');
        }

        return $discount;
    }

    private function studentSpecificSchemaInstalled(): bool
    {
        $schema = Schema::connection('mysql');
        return $schema->hasColumns('central_finance_promotions', ['student_profile_id', 'scope', 'creation_idempotency_key', 'student_discount_reason'])
            && $schema->hasColumns('central_finance_promotion_applications', ['promotion_scope_snapshot', 'student_profile_id_snapshot', 'fees_class_type_id_snapshot', 'applied_by_role_snapshot'])
            && $schema->hasColumn('central_finance_user_school_scopes', 'can_create_student_specific_discounts');
    }
}
