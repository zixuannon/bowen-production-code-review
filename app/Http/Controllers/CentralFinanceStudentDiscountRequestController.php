<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceStudentDiscountRequest;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceStudentDiscountRequestService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class CentralFinanceStudentDiscountRequestController extends Controller
{
    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceConfigurationAuthorizationService $configuration, private readonly CentralFinanceStudentDiscountRequestService $requests, private readonly CentralFinanceDataIsolationService $dataIsolation) {}

    public function index(): View
    {
        $actor = $this->actor();
        $groups = $this->configuration->configurableGroups($actor);
        abort_unless($groups->isNotEmpty(), 403);
        $includeQaTest = $this->dataIsolation->includeQaTest(request(), $actor);
        $query = CentralFinanceStudentDiscountRequest::on('mysql')->with(['studentProfile', 'school'])
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('status', CentralFinanceStudentDiscountRequest::PENDING);
        $requests = $this->dataIsolation->apply($query, 'student_discount_request', $includeQaTest)->latest()->get();
        return view('central-finance.student-discount-requests', compact('requests', 'includeQaTest'));
    }

    public function approve(int $request): RedirectResponse
    {
        $this->requests->approve($this->actor(), CentralFinanceStudentDiscountRequest::on('mysql')->findOrFail($request));
        return back()->with('success', __('Student-specific Discount approved.'));
    }

    public function reject(Request $http, int $request): RedirectResponse
    {
        $data = $http->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $this->requests->reject($this->actor(), CentralFinanceStudentDiscountRequest::on('mysql')->findOrFail($request), $data['rejection_reason']);
        return back()->with('success', __('Student-specific Discount rejected.'));
    }

    private function actor()
    {
        abort_unless(Auth::user(), 403);
        return $this->workspace->actor(Auth::user());
    }
}
