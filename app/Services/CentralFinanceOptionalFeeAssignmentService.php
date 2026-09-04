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
    ) {}

    /** @return Collection<int, object> */
    public function eligible(CentralFinanceUser $actor, CentralFinanceStudentProfile $profile): Collection
    {
        return $this->withinTenant($actor, $profile, function (Students $student): Collection {
            $student->loadMissing('session_year');
            return $this->assignments->availableAdditionalItems($student)->map(fn ($item) => (object) [
                'id' => (int) $item->id,
                'name' => (string) ($item->fee?->name ?: 'Optional fee'),
                'fee_type' => (string) ($item->fees_type?->name ?: ''),
                'amount' => (float) $item->amount,
                'currency' => strtoupper((string) ($item->fee_currency ?: $item->fee?->currency ?: 'MMK')),
                'academic_year_id' => (int) $student->session_year_id,
                'academic_year' => (string) ($student->session_year?->name ?: $student->session_year_id),
                'class_id' => (int) $student->class_id,
            ])->values();
        });
    }

    /** @param list<mixed> $requestedTemplateIds @return Collection<int, CentralFinanceReceivable> */
    public function add(CentralFinanceUser $actor, CentralFinanceStudentProfile $profile, array $requestedTemplateIds): Collection
    {
        $selected = collect($requestedTemplateIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['optional_fee_ids' => __('Select at least one eligible optional item.')]);
        }

        $sourceIds = $this->withinTenant($actor, $profile, function (Students $student) use ($selected): array {
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
                $draft = $this->assignments->saveAdditionalDraft($student, $student->user, $new->all());
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

        return collect($sourceIds)->map(fn (string $id) => $rows->get($id))->values();
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
