<?php

namespace App\Http\Controllers;

use App\Models\Students;
use App\Models\StudentFeeAssignment;
use App\Models\CentralFinanceReceivable;
use App\Services\ResponseService;
use App\Services\CentralFinanceStudentReadBridge;
use App\Services\CentralFinanceWorkspaceService;
use App\Services\CentralFinanceSchoolCutoverService;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\StudentFeeAssignmentService;
use App\Services\TenantStudentFeeSetupPromotionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class StudentFeeAssignmentController extends Controller
{
    public function __construct(private readonly StudentFeeAssignmentService $assignments, private readonly CentralFinanceStudentReadBridge $finance, private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceSchoolCutoverService $cutovers, private readonly TenantStudentFeeSetupPromotionService $promotionSelections) {}

    public function show(int $studentId): View
    {
        ResponseService::noPermissionThenRedirect('fees-create');
        $student = $this->student($studentId);
        $student->load(['user', 'class', 'class_section', 'studentImportIdentity']);
        $confirmed = $student->feeAssignments()->with('items')->where('status', 'confirmed')->latest('confirmed_at')->get();
        $finance = $this->finance->forStudent($student);
        $assignmentSync = $confirmed->mapWithKeys(function ($assignment) use ($finance): array {
            if (!($finance['available'] ?? false)) return [$assignment->id => 'pending'];
            $sourceIds = $assignment->items->where('status', 'active')->pluck('source_id')->map(fn ($id) => (string) $id);
            $syncedIds = collect($finance['receivables'] ?? [])->where('source_type', 'tenant_fee_assignment')->pluck('source_id')->map(fn ($id) => (string) $id);
            return [$assignment->id => $sourceIds->diff($syncedIds)->isEmpty() ? 'synced' : 'pending'];
        });
        $promotionSync = $this->promotionSync($confirmed, $finance);
        $canCollect = $this->canCollectForStudent($student);
        $availableItems = $this->assignments->availableItems($student);
        $availableAdditionalItems = $this->assignments->availableAdditionalItems($student);
        return view('students.fee-assignment', [
            'student' => $student,
            'availableItems' => $availableItems,
            'availableAdditionalItems' => $availableAdditionalItems,
            'promotionChoices' => $this->promotionSelections->choices(Auth::user(), $student, $availableItems->merge($availableAdditionalItems)),
            'draft' => $this->assignments->latestDraft($student),
            'confirmedAssignments' => $confirmed,
            'finance' => $finance,
            'assignmentSync' => $assignmentSync,
            'promotionSync' => $promotionSync,
            'canCollect' => $canCollect,
        ]);
    }

    public function summary(int $studentId): View
    {
        ResponseService::noAnyPermissionThenRedirect(['student-list', 'student-create', 'student-edit', 'fees-create']);
        $student = $this->student($studentId);
        $student->load(['user.school', 'class', 'class_section', 'session_year', 'studentImportIdentity', 'feeAssignments.items']);
        $assignments = $student->feeAssignments()
            ->with(['items', 'student'])
            ->where('status', StudentFeeAssignment::CONFIRMED)
            ->latest('confirmed_at')
            ->get();
        $finance = $this->finance->forStudent($student);
        $canSetup = Auth::user()?->can('fees-create') ?? false;
        $canCollect = $this->canCollectForStudent($student);

        return view('students.finance-summary', compact('student', 'assignments', 'finance', 'canSetup', 'canCollect'));
    }

    public function saveDraft(Request $request, int $studentId): RedirectResponse
    {
        ResponseService::noPermissionThenRedirect('fees-create');
        $data = $request->validate([
            'optional_fee_ids' => ['nullable', 'array'], 'optional_fee_ids.*' => ['integer'],
            'optional_fee_quantities' => ['nullable', 'array'], 'optional_fee_quantities.*' => ['nullable', 'integer', 'min:1'],
            'promotions' => ['nullable', 'array'], 'promotions.*' => ['nullable', 'integer', 'min:1'],
        ]);
        $student = $this->student($studentId);
        $available = $this->assignments->availableItems($student);
        $optionalIds = collect($data['optional_fee_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique();
        $selected = $available->filter(fn ($item): bool => !(bool) $item->optional || $optionalIds->contains((int) $item->id));
        $promotions = $this->promotionSelections->validateSelections(Auth::user(), $student, $selected, $data['promotions'] ?? []);
        $assignment = $this->assignments->saveDraft($student, Auth::user(), $data['optional_fee_ids'] ?? [], $data['optional_fee_quantities'] ?? [], $promotions);
        return redirect()->route('students.fee-assignment.show', $studentId)->with('success', 'Fee assignment draft saved.')->with('assignment_uuid', $assignment->uuid);
    }

    public function confirm(Request $request, int $studentId): RedirectResponse
    {
        ResponseService::noPermissionThenRedirect('fees-create');
        $request->validate(['assignment_uuid' => ['required', 'uuid']]);
        $student = $this->student($studentId);
        $assignment = $this->assignments->confirm($student, Auth::user(), (string) $request->assignment_uuid);
        $promotionPending = false;
        try {
            $this->promotionSelections->applyConfirmedSelections(Auth::user(), $student, $assignment);
            $message = 'Fee assignment confirmed. Central Receivable sync has been requested.';
        } catch (\Throwable $exception) {
            // The immutable tenant assignment remains valid. Replaying this
            // confirmation retries the idempotent Central projection without
            // rebuilding a source snapshot or creating a manual discount.
            Log::warning('Student Fee Setup Promotion application is awaiting retry.', [
                'school_id' => $student->school_id, 'student_id' => $student->id,
                'assignment_uuid' => $assignment->uuid, 'error' => $exception::class,
            ]);
            $promotionPending = true;
            $message = 'Fee assignment confirmed. The selected Promotion is awaiting Central Finance synchronization; retry confirmation shortly.';
        }
        return redirect()->route('students.fee-assignment.show', $studentId)
            ->with($promotionPending ? 'error' : 'success', $message)
            ->with('assignment_uuid', $assignment->uuid);
    }

    public function addFee(Request $request, int $studentId): RedirectResponse
    {
        ResponseService::noPermissionThenRedirect('fees-create');
        $data = $request->validate([
            'optional_fee_ids' => ['required', 'array'], 'optional_fee_ids.*' => ['integer'],
            'optional_fee_quantities' => ['nullable', 'array'], 'optional_fee_quantities.*' => ['nullable', 'integer', 'min:1'],
            'promotions' => ['nullable', 'array'], 'promotions.*' => ['nullable', 'integer', 'min:1'],
        ]);
        $student = $this->student($studentId);
        $available = $this->assignments->availableAdditionalItems($student);
        $selectedIds = collect($data['optional_fee_ids'])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique();
        $promotions = $this->promotionSelections->validateSelections(Auth::user(), $student, $available->whereIn('id', $selectedIds), $data['promotions'] ?? []);
        $assignment = $this->assignments->saveAdditionalDraft($student, Auth::user(), $data['optional_fee_ids'], $data['optional_fee_quantities'] ?? [], $promotions);
        return redirect()->route('students.fee-assignment.show', $studentId)->with('success', 'Additional fee assignment draft saved.')->with('assignment_uuid', $assignment->uuid);
    }

    private function student(int $id): Students
    {
        return Students::query()->where('school_id', Auth::user()->school_id)->findOrFail($id);
    }

    private function canCollectForStudent(Students $student): bool
    {
        try {
            $actor = $this->workspace->actor(Auth::user());
            $school = $this->workspace->requireOperatingSchool($actor);
            return (int) $school->id === (int) $student->school_id && $this->cutovers->allowsCentralWrites($school->id);
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function promotionSync($assignments, array $finance): \Illuminate\Support\Collection
    {
        $profileId = (int) ($finance['profile']->id ?? 0);
        if ($profileId < 1) {
            return $assignments->mapWithKeys(fn ($assignment): array => [$assignment->id => $assignment->items->contains(fn ($item): bool => (int) ($item->selected_promotion_id ?? 0) > 0) ? 'pending' : 'none']);
        }
        $sourceIds = $assignments->flatMap(fn ($assignment) => $assignment->items
            ->filter(fn ($item): bool => $item->status === 'active' && (int) ($item->selected_promotion_id ?? 0) > 0)
            ->pluck('source_id'))->map(fn ($id): string => (string) $id)->unique();
        $receivables = $sourceIds->isEmpty() ? collect() : CentralFinanceReceivable::on('mysql')
            ->with('promotionApplication')->where('student_profile_id', $profileId)
            ->where('source_type', 'tenant_fee_assignment')->whereIn('source_id', $sourceIds->all())
            ->get()->keyBy(fn (CentralFinanceReceivable $row): string => (string) $row->source_id);

        return $assignments->mapWithKeys(function ($assignment) use ($receivables): array {
            $selected = $assignment->items->filter(fn ($item): bool => $item->status === 'active' && (int) ($item->selected_promotion_id ?? 0) > 0);
            if ($selected->isEmpty()) return [$assignment->id => 'none'];
            foreach ($selected as $item) {
                $application = $receivables->get((string) $item->source_id)?->promotionApplication;
                if ($application === null || (int) $application->promotion_id !== (int) $item->selected_promotion_id) {
                    return [$assignment->id => 'pending'];
                }
            }
            return [$assignment->id => 'applied'];
        });
    }
}
