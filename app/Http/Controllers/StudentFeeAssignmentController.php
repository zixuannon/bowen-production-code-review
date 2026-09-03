<?php

namespace App\Http\Controllers;

use App\Models\Students;
use App\Models\StudentFeeAssignment;
use App\Services\ResponseService;
use App\Services\CentralFinanceStudentReadBridge;
use App\Services\CentralFinanceWorkspaceService;
use App\Services\CentralFinanceSchoolCutoverService;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\StudentFeeAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class StudentFeeAssignmentController extends Controller
{
    public function __construct(private readonly StudentFeeAssignmentService $assignments, private readonly CentralFinanceStudentReadBridge $finance, private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceSchoolCutoverService $cutovers) {}

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
        $canCollect = $this->canCollectForStudent($student);
        return view('students.fee-assignment', [
            'student' => $student,
            'availableItems' => $this->assignments->availableItems($student),
            'availableAdditionalItems' => $this->assignments->availableAdditionalItems($student),
            'draft' => $this->assignments->latestDraft($student),
            'confirmedAssignments' => $confirmed,
            'finance' => $finance,
            'assignmentSync' => $assignmentSync,
            'canCollect' => $canCollect,
        ]);
    }

    public function summary(int $studentId): View
    {
        ResponseService::noAnyPermissionThenRedirect(['student-list', 'student-create', 'student-edit', 'fees-create']);
        $student = $this->student($studentId);
        $student->load(['user', 'class', 'class_section', 'studentImportIdentity', 'feeAssignments.items']);
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
        $request->validate(['optional_fee_ids' => ['nullable', 'array'], 'optional_fee_ids.*' => ['integer']]);
        $assignment = $this->assignments->saveDraft($this->student($studentId), Auth::user(), $request->input('optional_fee_ids', []));
        return redirect()->route('students.fee-assignment.show', $studentId)->with('success', 'Fee assignment draft saved.')->with('assignment_uuid', $assignment->uuid);
    }

    public function confirm(Request $request, int $studentId): RedirectResponse
    {
        ResponseService::noPermissionThenRedirect('fees-create');
        $request->validate(['assignment_uuid' => ['required', 'uuid']]);
        $assignment = $this->assignments->confirm($this->student($studentId), Auth::user(), (string) $request->assignment_uuid);
        return redirect()->route('students.fee-assignment.show', $studentId)->with('success', 'Fee assignment confirmed. Central Receivable sync has been requested.')->with('assignment_uuid', $assignment->uuid);
    }

    public function addFee(Request $request, int $studentId): RedirectResponse
    {
        ResponseService::noPermissionThenRedirect('fees-create');
        $request->validate(['optional_fee_ids' => ['required', 'array'], 'optional_fee_ids.*' => ['integer']]);
        $assignment = $this->assignments->saveAdditionalDraft($this->student($studentId), Auth::user(), $request->input('optional_fee_ids', []));
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
}
