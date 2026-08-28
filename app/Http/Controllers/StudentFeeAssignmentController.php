<?php

namespace App\Http\Controllers;

use App\Models\Students;
use App\Services\ResponseService;
use App\Services\StudentFeeAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class StudentFeeAssignmentController extends Controller
{
    public function __construct(private readonly StudentFeeAssignmentService $assignments) {}

    public function show(int $studentId): View
    {
        ResponseService::noPermissionThenRedirect('fees-create');
        $student = $this->student($studentId);
        return view('students.fee-assignment', [
            'student' => $student->load(['user', 'class', 'class_section']),
            'availableItems' => $this->assignments->availableItems($student),
            'draft' => $this->assignments->latestDraft($student),
            'confirmedAssignments' => $student->feeAssignments()->with('items')->where('status', 'confirmed')->latest('confirmed_at')->get(),
        ]);
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

    private function student(int $id): Students
    {
        return Students::query()->where('school_id', Auth::user()->school_id)->findOrFail($id);
    }
}
