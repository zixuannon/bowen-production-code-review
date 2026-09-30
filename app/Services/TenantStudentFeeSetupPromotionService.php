<?php

namespace App\Services;

use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Models\FeesClassType;
use App\Models\StudentFeeAssignment;
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
        } catch (AuthorizationException) {
            // A blank list is safe for a tenant user without a Finance Staff
            // identity.  The POST path fails closed if a Promotion is sent.
            return collect();
        }

        $date = CarbonImmutable::now('Asia/Yangon');
        return collect($items)->mapWithKeys(function (FeesClassType $item) use ($principal, $student, $date): array {
            $choices = $this->promotions->eligibleForFeeSetup($principal, (int) $student->school_id, (int) $item->id, $date)
                ->map(fn ($promotion): object => (object) [
                    'id' => (int) $promotion->id,
                    'code' => (string) $promotion->code,
                    'name' => (string) $promotion->name,
                ])->values();

            return [(int) $item->id => $choices];
        });
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
        $this->profile($student);
        $date = CarbonImmutable::now('Asia/Yangon');
        foreach ($result as $sourceId => $promotionId) {
            $eligible = $this->promotions->eligibleForFeeSetup($principal, (int) $student->school_id, $sourceId, $date);
            if (!$eligible->contains('id', $promotionId)) {
                throw ValidationException::withMessages(['promotions' => __('The selected Promotion is not active or applicable to this Fee Item.')]);
            }
        }

        return $result;
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
        return $items->mapWithKeys(function ($item) use ($principal, $student, $date): array {
            try {
                return [(int) $item->id => $this->promotions->previewForFeeSetup(
                    $principal,
                    (int) $student->school_id,
                    (int) $item->fees_class_type_id,
                    (int) $item->selected_promotion_id,
                    CentralFinanceDecimal::normalize((string) $item->amount_snapshot),
                    $date,
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

        $date = CarbonImmutable::now('Asia/Yangon');
        foreach ($items as $item) {
            $receivable = $rows->get((string) $item->source_id);
            if ($receivable === null) {
                throw ValidationException::withMessages(['promotions' => __('The Fee Assignment is confirmed, but its Central Receivable is awaiting synchronization. Retry confirmation shortly.')]);
            }
            $this->promotions->applyFromFeeSetup(
                $principal,
                (int) $receivable->id,
                (int) $item->selected_promotion_id,
                (int) $item->fees_class_type_id,
                $date,
                $date,
                'tenant-fee-setup:'.(int) $student->school_id.':'.$item->uuid.':'.(int) $item->selected_promotion_id,
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
