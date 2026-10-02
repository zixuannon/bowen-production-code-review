<?php

namespace App\Services;

use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Models\FeesClassType;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Models\Students;
use App\Models\User;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Connects the tenant Student Fee Setup screen to the existing Central
 * Promotion engine.  It deliberately accepts only server-resolved fee-item
 * identifiers and uses the trusted School Staff session identity; the
 * browser never supplies a Central actor, School, amount, or discount.
 */
final class TenantStudentFeeSetupPromotionService
{
    public function __construct(
        private readonly CentralFinanceSchoolStaffIdentityService $staffIdentities,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinancePromotionService $promotions,
        private readonly CentralFinanceReceivableSyncService $receivables,
    ) {}

    /** @param iterable<FeesClassType> $items @return Collection<int, Collection<int, object>> */
    public function choices(User $actor, Students $student, iterable $items): Collection
    {
        try {
            $principal = $this->principal($actor, $student);
            $this->cutovers->assertCentralWritesAllowed((int) $student->school_id);
            $profile = $this->profile($student);
        } catch (AuthorizationException) {
            // A blank list is safe for a tenant user without a Finance Staff
            // identity.  The POST path fails closed if a Promotion is sent.
            return collect();
        }

        $date = CarbonImmutable::now('Asia/Yangon');
        return collect($items)->mapWithKeys(function (FeesClassType $item) use ($principal, $student, $date, $profile): array {
            $choices = $this->promotions->eligibleForFeeSetup($principal, (int) $student->school_id, (int) $profile->id, (int) $item->id, $date)
                ->map(fn ($promotion): object => (object) [
                    'id' => (int) $promotion->id,
                    'code' => (string) $promotion->code,
                    'name' => (string) $promotion->name,
                ])->values();

            return [(int) $item->id => $choices];
        });
    }

    /** Render-only capability probe; writes re-assert the same scope. */
    public function canCreateStudentSpecificDiscounts(User $actor, Students $student): bool
    {
        try {
            $principal = $this->principal($actor, $student);
            $this->cutovers->assertCentralWritesAllowed((int) $student->school_id);
            $this->promotions->assertCanCreateStudentSpecificDiscount($principal, (int) $student->school_id);
            $this->profile($student);
            return true;
        } catch (AuthorizationException|\RuntimeException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return false;
        }
    }

    /**
     * Canonicalise only selections for fee items that will be written into
     * this draft, and prove their current School/item/date applicability.
     *
     * @param iterable<FeesClassType> $selectedItems
     * @param array<mixed> $requested
     * @return array<int, int>
     */
    public function validateSelections(User $actor, Students $student, iterable $selectedItems, array $requested): array
    {
        $selected = collect($selectedItems)->keyBy(fn (FeesClassType $item): int => (int) $item->id);
        $result = [];
        foreach ($requested as $sourceId => $promotionId) {
            if ($promotionId === null || $promotionId === '') {
                continue;
            }
            if (!is_scalar($sourceId) || !preg_match('/^[1-9][0-9]*$/', (string) $sourceId)
                || !is_scalar($promotionId) || !preg_match('/^[1-9][0-9]*$/', (string) $promotionId)) {
                throw ValidationException::withMessages(['promotions' => __('The selected Promotion is invalid.')]);
            }
            $sourceId = (int) $sourceId;
            if (!$selected->has($sourceId)) {
                throw ValidationException::withMessages(['promotions' => __('A Promotion may be selected only for an included Fee Item.')]);
            }
            $result[$sourceId] = (int) $promotionId;
        }
        if ($result === []) {
            return [];
        }
        if (!Schema::connection('school')->hasColumn('student_fee_assignment_items', 'selected_promotion_id')) {
            throw ValidationException::withMessages(['promotions' => __('Student Fee Setup Promotion selection is not available until this School completes its schema update.')]);
        }

        $principal = $this->principal($actor, $student);
        $this->cutovers->assertCentralWritesAllowed((int) $student->school_id);
        $profile = $this->profile($student);
        $date = CarbonImmutable::now('Asia/Yangon');
        foreach ($result as $sourceId => $promotionId) {
            $eligible = $this->promotions->eligibleForFeeSetup($principal, (int) $student->school_id, (int) $profile->id, $sourceId, $date);
            if (!$eligible->contains('id', $promotionId)) {
                throw ValidationException::withMessages(['promotions' => __('The selected Promotion is not active or applicable to this Fee Item.')]);
            }
        }

        return $result;
    }

    /**
     * Canonicalise Front Desk-created student-specific discounts.  The browser
     * provides only a draft value and reason; the trusted tenant session
     * supplies the School, Student profile, Fee Item and Central actor.
     *
     * @param iterable<FeesClassType> $selectedItems
     * @param array<mixed> $requested
     * @return array<int, array{discount_type:string,discount_value:string,reason:string,effective_date:string}>
     */
    public function validateStudentDiscounts(User $actor, Students $student, iterable $selectedItems, array $requested): array
    {
        $selected = collect($selectedItems)->keyBy(fn (FeesClassType $item): int => (int) $item->id);
        $normalized = [];
        foreach ($requested as $sourceId => $discount) {
            if (!is_scalar($sourceId) || !preg_match('/^[1-9][0-9]*$/', (string) $sourceId) || !is_array($discount)) {
                throw ValidationException::withMessages(['student_discounts' => __('The student-specific Discount is invalid.')]);
            }
            $sourceId = (int) $sourceId;
            $enabled = $discount['enabled'] ?? null;
            if ($enabled === null || $enabled === '' || $enabled === '0' || $enabled === 0 || $enabled === false) {
                continue;
            }
            if ($enabled !== '1' && $enabled !== 1 && $enabled !== true) {
                throw ValidationException::withMessages(['student_discounts' => __('The student-specific Discount selection is invalid.')]);
            }
            if (!$selected->has($sourceId)) {
                throw ValidationException::withMessages(['student_discounts' => __('A student-specific Discount may be created only for an included Fee Item.')]);
            }
            $type = (string) ($discount['discount_type'] ?? '');
            $value = CentralFinanceDecimal::normalize((string) ($discount['discount_value'] ?? '0'));
            $reason = trim((string) ($discount['reason'] ?? ''));
            $date = trim((string) ($discount['effective_date'] ?? ''));
            $parsed = $date === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Yangon');
            if (!in_array($type, [\App\Models\CentralFinancePromotion::PERCENTAGE, \App\Models\CentralFinancePromotion::FIXED], true)
                || CentralFinanceDecimal::compare($value, '0') <= 0
                || ($type === \App\Models\CentralFinancePromotion::PERCENTAGE && CentralFinanceDecimal::compare($value, '100') >= 0)
                || $reason === '' || mb_strlen($reason) > 2000 || $parsed === false || $parsed === null || $parsed->format('Y-m-d') !== $date) {
                throw ValidationException::withMessages(['student_discounts' => __('A Discount type, valid value, reason, and business date are required.')]);
            }
            $normalized[$sourceId] = ['discount_type' => $type, 'discount_value' => $value, 'reason' => $reason, 'effective_date' => $date];
        }
        if ($normalized === []) {
            return [];
        }
        if (!Schema::connection('school')->hasColumns('student_fee_assignment_items', ['student_discount_type', 'student_discount_value', 'student_discount_reason', 'student_discount_effective_date'])) {
            throw ValidationException::withMessages(['student_discounts' => __('Student-specific Discounts are not available until this School completes its approved schema update.')]);
        }
        $principal = $this->principal($actor, $student);
        $this->cutovers->assertCentralWritesAllowed((int) $student->school_id);
        $this->promotions->assertCanCreateStudentSpecificDiscount($principal, (int) $student->school_id);
        $this->profile($student);
        return $normalized;
    }

    /**
     * Turn a validated draft request into an idempotent Central Promotion
     * definition before preview. The draft remains mutable; only confirmation
     * creates the immutable Promotion Application/Receivable adjustment.
     */
    public function materializeDraftStudentDiscounts(User $actor, Students $student, StudentFeeAssignment $assignment): StudentFeeAssignment
    {
        $assignment->loadMissing('items');
        $items = $assignment->items->where('status', StudentFeeAssignmentItem::ACTIVE)
            ->filter(fn ($item): bool => trim((string) ($item->student_discount_type ?? '')) !== '');
        if ($items->isEmpty()) {
            return $assignment;
        }
        if (!Schema::connection('school')->hasColumns('student_fee_assignment_items', ['student_discount_type', 'student_discount_value', 'student_discount_reason', 'student_discount_effective_date'])) {
            throw new \RuntimeException('Student-specific Discount draft schema is not installed.');
        }
        $principal = $this->principal($actor, $student);
        $this->cutovers->assertCentralWritesAllowed((int) $student->school_id);
        $this->promotions->assertCanCreateStudentSpecificDiscount($principal, (int) $student->school_id);
        $profile = $this->profile($student);
        foreach ($items as $item) {
            $effectiveDate = CarbonImmutable::parse((string) $item->student_discount_effective_date, 'Asia/Yangon')->startOfDay();
            if ($item->student_discount_type === \App\Models\CentralFinancePromotion::FIXED
                && CentralFinanceDecimal::compare((string) $item->student_discount_value, (string) $item->amount_snapshot) >= 0) {
                throw ValidationException::withMessages(['student_discounts' => __('A student-specific Discount must leave a positive net amount.')]);
            }
            $key = hash('sha256', implode('|', [
                'student-fee-setup-discount', (int) $student->school_id, (int) $profile->id, (string) $assignment->uuid,
                (int) $item->fees_class_type_id, (string) $item->student_discount_type, (string) $item->student_discount_value,
                (string) $item->student_discount_reason, $effectiveDate->toDateString(),
            ]));
            $promotion = $this->promotions->defineStudentSpecificForFeeSetup($principal, $profile, (int) $item->fees_class_type_id, [
                'discount_type' => (string) $item->student_discount_type,
                'discount_value' => (string) $item->student_discount_value,
                'reason' => (string) $item->student_discount_reason,
                'effective_date' => $effectiveDate,
            ], $key);
            if ((int) $item->selected_promotion_id !== (int) $promotion->id) {
                $item->update(['selected_promotion_id' => $promotion->id]);
            }
        }
        return $assignment->fresh('items');
    }

    /**
     * Read-only preview for saved draft lines. It reuses the Central
     * Promotion engine's eligibility and exact-decimal calculation; only
     * confirmation may persist a Promotion Application.
     *
     * @return Collection<int, array{promotion:string,discount:string,net:string}>
     */
    public function previewDraft(User $actor, Students $student, ?StudentFeeAssignment $assignment): Collection
    {
        if ($assignment === null) {
            return collect();
        }

        $items = $assignment->items->where('status', 'active')
            ->filter(fn ($item): bool => (int) ($item->selected_promotion_id ?? 0) > 0);
        if ($items->isEmpty()) {
            return collect();
        }

        try {
            $principal = $this->principal($actor, $student);
            $this->cutovers->assertCentralWritesAllowed((int) $student->school_id);
        } catch (AuthorizationException) {
            return collect();
        }

        $date = CarbonImmutable::now('Asia/Yangon');
        $profile = $this->profile($student);
        return $items->mapWithKeys(function ($item) use ($principal, $student, $date, $profile): array {
            try {
                $effectiveDate = trim((string) ($item->student_discount_effective_date ?? '')) !== ''
                    ? CarbonImmutable::parse((string) $item->student_discount_effective_date, 'Asia/Yangon')->startOfDay()
                    : $date;
                return [(int) $item->id => $this->promotions->previewForFeeSetup(
                    $principal,
                    (int) $student->school_id,
                    (int) $item->fees_class_type_id,
                    (int) $item->selected_promotion_id,
                    CentralFinanceDecimal::normalize((string) $item->amount_snapshot),
                    $effectiveDate,
                    (int) $profile->id,
                )];
            } catch (AuthorizationException|\InvalidArgumentException) {
                // Do not invent a discount if a saved definition later
                // expires; confirmation will revalidate and fail closed.
                return [(int) $item->id => [
                    'promotion' => __('Promotion requires revalidation'),
                    'discount' => CentralFinanceDecimal::normalize('0'),
                    'net' => CentralFinanceDecimal::normalize((string) $item->amount_snapshot),
                ]];
            }
        });
    }

    /**
     * The tenant assignment was already confirmed before Central projection.
     * A retry of the same confirmation is safe: each application key is tied
     * to the immutable tenant item UUID and Central applies it exactly once.
     */
    public function applyConfirmedSelections(User $actor, Students $student, StudentFeeAssignment $assignment): void
    {
        $assignment->loadMissing('items');
        $items = $assignment->items->filter(fn ($item): bool => $item->status === 'active' && (int) ($item->selected_promotion_id ?? 0) > 0);
        if ($items->isEmpty()) {
            return;
        }
        if (!Schema::connection('school')->hasColumn('student_fee_assignment_items', 'selected_promotion_id')) {
            throw new \RuntimeException('Student Fee Setup Promotion selection schema is not installed.');
        }

        $principal = $this->principal($actor, $student);
        $this->cutovers->assertCentralWritesAllowed((int) $student->school_id);
        $profile = $this->profile($student);
        $this->receivables->syncProfile($profile);
        $rows = CentralFinanceReceivable::on('mysql')->where([
            'school_id' => $profile->school_id,
            'student_profile_id' => $profile->id,
            'source_type' => CentralFinanceReceivableSyncService::SOURCE_TYPE,
        ])->whereIn('source_id', $items->pluck('source_id')->map(fn ($id): string => (string) $id)->all())
            ->get()->keyBy(fn (CentralFinanceReceivable $row): string => (string) $row->source_id);

        $recordedAt = CarbonImmutable::now('Asia/Yangon');
        foreach ($items as $item) {
            $receivable = $rows->get((string) $item->source_id);
            if ($receivable === null) {
                throw ValidationException::withMessages(['promotions' => __('The Fee Assignment is confirmed, but its Central Receivable is awaiting synchronization. Retry confirmation shortly.')]);
            }
            $effectiveDate = trim((string) ($item->student_discount_effective_date ?? '')) !== ''
                ? CarbonImmutable::parse((string) $item->student_discount_effective_date, 'Asia/Yangon')->startOfDay()
                : $recordedAt;
            $this->promotions->applyFromFeeSetup(
                $principal,
                (int) $receivable->id,
                (int) $item->selected_promotion_id,
                (int) $item->fees_class_type_id,
                $effectiveDate,
                $recordedAt,
                'tenant-fee-setup:'.(int) $student->school_id.':'.$item->uuid.':'.(int) $item->selected_promotion_id,
                trim((string) ($item->student_discount_reason ?? '')) ?: null,
            );
        }
    }

    private function principal(User $actor, Students $student): CentralFinanceUser
    {
        $context = session(CentralFinanceSchoolStaffIdentityService::SESSION_KEY);
        $principal = $this->staffIdentities->resolveTrustedSession(is_array($context) ? $context : null);
        $actorUuid = (string) $actor->getRawOriginal('central_finance_source_uuid');
        if ((int) $principal->getRawOriginal('school_id') !== (int) $student->school_id
            || $actorUuid === ''
            || !hash_equals((string) ($context['user_uuid'] ?? ''), $actorUuid)) {
            throw new AuthorizationException('The current tenant Staff identity is not authorized for Promotion selection.');
        }

        return $principal;
    }

    private function profile(Students $student): CentralFinanceStudentProfile
    {
        return CentralFinanceStudentProfile::on('mysql')->where([
            'school_id' => $student->school_id,
            'tenant_student_id' => $student->id,
        ])->firstOrFail();
    }
}
