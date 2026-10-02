<?php

namespace App\Services;

use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinancePromotion;
use App\Models\CentralFinanceStudentDiscountRequest;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupSchool;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Owns the approval boundary between School Fee Setup and the Promotion engine. */
final class CentralFinanceStudentDiscountRequestService
{
    public function __construct(
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
        private readonly CentralFinancePromotionService $promotions,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    public function submit(CentralFinanceUser $actor, CentralFinanceStudentProfile $profile, StudentFeeAssignment $assignment, StudentFeeAssignmentItem $item): CentralFinanceStudentDiscountRequest
    {
        $this->promotions->assertCanCreateStudentSpecificDiscount($actor, (int) $profile->school_id);
        $type = (string) $item->student_discount_type;
        $value = CentralFinanceDecimal::normalize((string) $item->student_discount_value);
        $reason = trim((string) $item->student_discount_reason);
        $date = CarbonImmutable::parse((string) $item->student_discount_effective_date, 'Asia/Yangon')->startOfDay();
        if (!in_array($type, [CentralFinancePromotion::PERCENTAGE, CentralFinancePromotion::FIXED], true)
            || CentralFinanceDecimal::compare($value, '0') <= 0 || ($type === CentralFinancePromotion::PERCENTAGE && CentralFinanceDecimal::compare($value, '100') >= 0)
            || $reason === '' || CentralFinanceDecimal::compare((string) $item->amount_snapshot, '0') <= 0
            || ($type === CentralFinancePromotion::FIXED && CentralFinanceDecimal::compare($value, (string) $item->amount_snapshot) >= 0)) {
            throw ValidationException::withMessages(['student_discounts' => __('Student-specific Discount request details are invalid.')]);
        }
        $groupId = $this->singleGroupFor($actor, (int) $profile->school_id);
        $key = hash('sha256', implode('|', ['student-discount-request', $profile->school_id, $profile->id, $assignment->uuid, $item->uuid, $type, $value, $reason, $date->toDateString()]));

        return DB::connection('mysql')->transaction(function () use ($actor, $profile, $assignment, $item, $type, $value, $reason, $date, $groupId, $key): CentralFinanceStudentDiscountRequest {
            $request = CentralFinanceStudentDiscountRequest::on('mysql')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($request !== null) return $request;
            $request = CentralFinanceStudentDiscountRequest::on('mysql')->create([
                'idempotency_key' => $key, 'group_id' => $groupId, 'school_id' => $profile->school_id,
                'student_profile_id' => $profile->id, 'fees_class_type_id' => $item->fees_class_type_id,
                'tenant_assignment_uuid' => $assignment->uuid, 'tenant_assignment_item_uuid' => $item->uuid,
                'requested_by' => $actor->id, 'discount_type' => $type, 'discount_value' => $value,
                'gross_amount_snapshot' => $item->amount_snapshot, 'currency_snapshot' => $item->currency_snapshot,
                'reason' => $reason, 'effective_date' => $date->toDateString(), 'status' => CentralFinanceStudentDiscountRequest::PENDING,
            ]);
            $audit = [
                'school_id' => $profile->school_id, 'document_type' => 'student_discount_request',
                'document_id' => $request->id, 'action' => 'requested', 'actor_id' => $actor->id, 'reason' => $reason,
                'before_values' => null, 'after_values' => ['request_uuid' => $request->request_uuid, 'student_profile_id' => $profile->id, 'fees_class_type_id' => $item->fees_class_type_id, 'discount_type' => $type, 'discount_value' => $value, 'effective_date' => $date->toDateString()],
            ];
            if (Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id')) $audit['group_id'] = $groupId;
            CentralFinanceDocumentAudit::on('mysql')->create($audit);
            $this->dataIsolation->inheritWorkflowClassification(
                $actor,
                (int) $profile->school_id,
                'student_discount_request',
                (int) $request->id,
            );
            return $request;
        });
    }

    public function approve(CentralFinanceUser $actor, CentralFinanceStudentDiscountRequest $request): CentralFinanceStudentDiscountRequest
    {
        $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $request->group_id);
        return DB::connection('mysql')->transaction(function () use ($actor, $request): CentralFinanceStudentDiscountRequest {
            $request = CentralFinanceStudentDiscountRequest::on('mysql')->lockForUpdate()->findOrFail($request->id);
            if ($request->status === CentralFinanceStudentDiscountRequest::APPROVED) return $request;
            if ($request->status !== CentralFinanceStudentDiscountRequest::PENDING) throw new AuthorizationException('Only a pending student Discount request may be approved.');
            $profile = CentralFinanceStudentProfile::on('mysql')->where(['id' => $request->student_profile_id, 'school_id' => $request->school_id])->firstOrFail();
            $promotion = $this->promotions->defineApprovedStudentSpecificForFeeSetupRequest($actor, (int) $request->group_id, $profile, (int) $request->fees_class_type_id, [
                'discount_type' => $request->discount_type, 'discount_value' => (string) $request->discount_value,
                'reason' => $request->reason, 'effective_date' => CarbonImmutable::parse($request->effective_date, 'Asia/Yangon')->startOfDay(),
            ], hash('sha256', 'approved-student-discount-request:'.$request->request_uuid));
            $request->update(['status' => CentralFinanceStudentDiscountRequest::APPROVED, 'decision_by' => $actor->id, 'decided_at' => now(), 'promotion_id' => $promotion->id]);
            $audit = ['school_id' => $request->school_id, 'document_type' => 'student_discount_request', 'document_id' => $request->id, 'action' => 'approved', 'actor_id' => $actor->id, 'reason' => $request->reason, 'before_values' => ['status' => 'pending'], 'after_values' => ['status' => 'approved', 'promotion_id' => $promotion->id, 'approved_exact_request' => true]];
            if (Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id')) $audit['group_id'] = $request->group_id;
            CentralFinanceDocumentAudit::on('mysql')->create($audit);
            return $request->fresh();
        });
    }

    public function reject(CentralFinanceUser $actor, CentralFinanceStudentDiscountRequest $request, string $reason): CentralFinanceStudentDiscountRequest
    {
        $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $request->group_id);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) throw ValidationException::withMessages(['rejection_reason' => __('A rejection reason is required.')]);
        return DB::connection('mysql')->transaction(function () use ($actor, $request, $reason): CentralFinanceStudentDiscountRequest {
            $request = CentralFinanceStudentDiscountRequest::on('mysql')->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== CentralFinanceStudentDiscountRequest::PENDING) throw new AuthorizationException('Only a pending student Discount request may be rejected.');
            $request->update(['status' => CentralFinanceStudentDiscountRequest::REJECTED, 'decision_by' => $actor->id, 'decided_at' => now(), 'rejection_reason' => $reason]);
            $audit = ['school_id' => $request->school_id, 'document_type' => 'student_discount_request', 'document_id' => $request->id, 'action' => 'rejected', 'actor_id' => $actor->id, 'reason' => $reason, 'before_values' => ['status' => 'pending'], 'after_values' => ['status' => 'rejected']];
            if (Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id')) $audit['group_id'] = $request->group_id;
            CentralFinanceDocumentAudit::on('mysql')->create($audit);
            return $request->fresh();
        });
    }

    private function singleGroupFor(CentralFinanceUser $actor, int $schoolId): int
    {
        $groups = FinanceGroupSchool::on('mysql')->where('school_id', $schoolId)->where('status', 'active')->whereIn('group_id', DB::connection('mysql')->table('finance_group_users')->where('central_user_id', $actor->id)->where('status', 'active')->pluck('group_id'))->pluck('group_id')->unique()->values();
        if ($groups->count() !== 1) throw new AuthorizationException('The current Front Desk identity has no unambiguous active Finance Group for this Student.');
        return (int) $groups->sole();
    }
}
