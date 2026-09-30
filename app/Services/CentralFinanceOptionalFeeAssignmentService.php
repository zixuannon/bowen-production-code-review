<?php

namespace App\Services;

use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupUser;
use App\Models\School;
use App\Models\StudentFeeAssignment;
use App\Models\Students;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * School-Finance adapter for optional Fee Setup items.  Tenant Fee Assignment
 * remains the source of truth; Central Receivables remain its projection.
 * This class deliberately accepts only FeesClassType ids and never an amount,
 * currency, tenant id, or database name from the browser.
 */
final class CentralFinanceOptionalFeeAssignmentService
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceSchoolStaffIdentityService $schoolStaff,
        private readonly FinanceGroupScopeService $groups,
        private readonly StudentFeeAssignmentService $assignments,
        private readonly CentralFinanceReceivableSyncService $receivables,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
        private readonly CentralFinancePromotionService $promotions,
    ) {}

    /** @return Collection<int, object> */
    public function eligible(CentralFinanceUser $actor, CentralFinanceStudentProfile $profile): Collection
    {
        $items = $this->withinTenant($actor, $profile, function (Students $student): Collection {
            $student->loadMissing('session_year');
            $qaSchool = $this->dataIsolation->isQaTestSchool((int) $student->school_id);
            return $this->assignments->availableAdditionalItems($student)
                // The source adapter has already constrained the rows to the
                // trusted tenant and class.  Zixuan's explicit QA School may
                // use only its own QA fixtures; every other School stays
                // Official-only.  Do not apply the old Official predicate a
                // second time and make a legitimate QA item disappear.
                ->filter(fn ($item): bool => ($qaSchool
                        ? $this->dataIsolation->isTenantMetadataWorkflowWritable('fee_item', (int) $student->school_id, (int) $item->id)
                        : $this->dataIsolation->isTenantMetadataProduction('fee_item', (int) $student->school_id, (int) $item->id))
                    && ($qaSchool
                        ? $this->dataIsolation->isTenantMetadataWorkflowWritable('fee', (int) $student->school_id, (int) $item->fees_id)
                        : $this->dataIsolation->isTenantMetadataProduction('fee', (int) $student->school_id, (int) $item->fees_id)))
                ->map(fn ($item) => (object) [
                'id' => (int) $item->id,
                'name' => (string) ($item->fee?->name ?: 'Optional fee'),
                'fee_type' => (string) ($item->fees_type?->name ?: ''),
                'amount' => (float) $item->amount,
                'currency' => strtoupper((string) ($item->fee_currency ?: $item->fee?->currency ?: 'MMK')),
                'academic_year_id' => (int) $student->session_year_id,
                'academic_year' => (string) ($student->session_year?->name ?: $student->session_year_id),
                'class_id' => (int) $student->class_id,
                'quantity_enabled' => (bool) ($item->quantity_enabled ?? false),
            ])->values();
        });
        $effectiveDate = \Carbon\CarbonImmutable::now('Asia/Yangon');
        return $items->map(function (object $item) use ($actor, $profile, $effectiveDate): object {
            $item->promotions = $this->promotions->eligibleForFeeSetup($actor, (int) $profile->school_id, (int) $item->id, $effectiveDate)
                ->map(fn ($promotion) => (object) ['id' => (int) $promotion->id, 'name' => (string) $promotion->name, 'code' => (string) $promotion->code, 'type' => (string) $promotion->discount_type, 'value' => (string) $promotion->discount_value])
                ->values();
            return $item;
        })->values();
    }

    /** @param list<mixed> $requestedTemplateIds @return Collection<int, CentralFinanceReceivable> */
    public function add(CentralFinanceUser $actor, CentralFinanceStudentProfile $profile, array $requestedTemplateIds, array $requestedQuantities = [], array $requestedPromotions = []): Collection
    {
        $selected = collect($requestedTemplateIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['optional_fee_ids' => __('Select at least one eligible optional item.')]);
        }

        $promotions = $this->canonicalPromotionSelection($selected, $requestedPromotions);
        $sourceIds = $this->withinTenant($actor, $profile, function (Students $student) use ($selected, $requestedQuantities): array {
            $configured = $this->assignments->configuredAdditionalItems($student)->keyBy('id');
            if ($selected->diff($configured->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['optional_fee_ids' => __('Selected optional items are not valid for this Student.')]);
            }

            $locked = DB::connection('school')->table('student_fee_assignment_source_locks')
                ->where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $student->session_year_id)
                ->where('source_type', 'fees_class_type')
                ->whereIn('source_id', $selected->map(fn (int $id) => (string) $id)->all())
                ->pluck('source_id')->map(fn ($id) => (string) $id);
            $new = $selected->map(fn (int $id) => (string) $id)->diff($locked)->map(fn (string $id) => (int) $id)->values();

            if ($new->isNotEmpty()) {
                $quantities = collect($requestedQuantities)->only($new->map(fn (int $id) => (string) $id)->all())->all();
                $draft = $this->assignments->saveAdditionalDraft($student, $student->user, $new->all(), $quantities);
                $this->assignments->confirm($student, $student->user, $draft->uuid);
            }

            return $selected->map(fn (int $id) => (string) $id)->all();
        });

        // The tenant and Central databases intentionally do not form a
        // distributed transaction.  Force an immediate canonical projection
        // and report a failure rather than claiming the item is payable. A
        // later retry uses the immutable tenant source and is idempotent.
        $profile = CentralFinanceStudentProfile::on('mysql')->where('school_id', $profile->school_id)->findOrFail($profile->id);
        $this->receivables->syncProfile($profile);
        $rows = CentralFinanceReceivable::on('mysql')->where([
            'school_id' => $profile->school_id,
            'student_profile_id' => $profile->id,
            'source_type' => CentralFinanceReceivableSyncService::SOURCE_TYPE,
        ])->whereIn('source_id', $sourceIds)->get()->keyBy(fn (CentralFinanceReceivable $row) => (string) $row->source_id);
        if ($rows->count() !== count($sourceIds)) {
            throw ValidationException::withMessages(['optional_fee_ids' => __('The optional item was saved but is awaiting Central Receivable synchronization. Retry this action after synchronization succeeds.')]);
        }

        $effectiveDate = \Carbon\CarbonImmutable::now('Asia/Yangon');
        foreach ($promotions as $sourceId => $promotionId) {
            $receivable = $rows->get((string) $sourceId);
            if ($receivable === null) throw ValidationException::withMessages(['promotions' => __('Selected Promotion is awaiting Central Receivable synchronization.')]);
            $this->promotions->applyFromFeeSetup($actor, (int) $receivable->id, $promotionId, (int) $sourceId, $effectiveDate, $effectiveDate, 'fee-setup:'.$profile->school_id.':'.$profile->id.':'.$sourceId.':'.$promotionId);
        }
        return collect($sourceIds)->map(fn (string $id) => $rows->get($id)?->fresh())->filter()->values();
    }

    /** @param Collection<int,int> $selected @param array<mixed> $requested @return array<int,int> */
    private function canonicalPromotionSelection(Collection $selected, array $requested): array
    {
        $allowed = $selected->map(fn (int $id) => (string) $id)->flip();
        $result = [];
        foreach ($requested as $sourceId => $promotionId) {
            // The modal renders a `No Promotion` option for every visible
            // optional Fee Item, including rows the operator did not select.
            // An empty value is therefore an explicit no-op, not an attempt
            // to apply a Promotion to that unselected row.  Only a non-empty
            // Promotion selection is subject to the selected-item guard.
            if ($promotionId === null || $promotionId === '') continue;
            $sourceId = (int) $sourceId;
            if ($sourceId < 1 || !$allowed->has((string) $sourceId)) throw ValidationException::withMessages(['promotions' => __('A Promotion may be selected only for an item included in this Fee Setup.')]);
            $promotionId = (int) $promotionId;
            if ($promotionId < 1) throw ValidationException::withMessages(['promotions' => __('The selected Promotion is invalid.')]);
            $result[$sourceId] = $promotionId;
        }
        return $result;
    }

    /** @template T @param callable(Students):T $operation @return T */
    private function withinTenant(CentralFinanceUser $actor, CentralFinanceStudentProfile $profile, callable $operation): mixed
    {
        $school = $this->workspace->currentSchool($actor);
        if ($school === null) throw new AuthorizationException('Select an authorized School before adding optional items.');
        try {
            $this->workspace->assertCanOperateSchool($actor, (int) $school->id);
        } catch (AuthorizationException) {
            // Front Desk has a deliberately narrow submit-only grant. It may
            // use this canonical Fee Setup adapter, but cannot collect money
            // or operate any other Finance document.
            $this->workspace->assertCanSubmitCollectionsSchool($actor, (int) $school->id);
        }
        if ((int) $school->id !== (int) $profile->school_id) {
            throw new AuthorizationException('The Student does not belong to the active School.');
        }
        $this->cutovers->assertCentralWritesAllowed($school->id);

        return $this->executeAsTrustedTenant($actor, $school, function ($tenant) use ($profile, $operation) {
            $this->dataIsolation->assertTenantProduction('student', (int) $profile->school_id, (int) $profile->tenant_student_id);
            $student = Students::on('school')->where([
                'id' => $profile->tenant_student_id,
                'school_id' => $profile->school_id,
            ])->with('user')->firstOrFail();
            if ($student->user === null) {
                throw new AuthorizationException('The Student tenant identity is unavailable.');
            }
            return $operation($student);
        });
    }

    /** @template T @param callable(\App\Models\User):T $operation @return T */
    private function executeAsTrustedTenant(CentralFinanceUser $actor, School $school, callable $operation): mixed
    {
        if ($this->workspace->isSchoolStaffPrincipal($actor)) {
            return $this->schoolStaff->executeAsTenantIdentity($actor, $school, $operation);
        }

        $groupUser = FinanceGroupUser::on('mysql')->where('central_user_id', $actor->id)->where('status', 'active')->get()
            ->first(fn (FinanceGroupUser $candidate): bool => $this->groups->canAccessSchool($candidate, $school->id, 'operate_finance'));
        if ($groupUser === null) {
            throw new AuthorizationException('The Central Finance actor has no operating Group scope for this School.');
        }
        return $this->groups->executeOperatingFinanceAsTenantIdentity($groupUser, $school->id, fn ($tenant) => $operation($tenant));
    }
}
