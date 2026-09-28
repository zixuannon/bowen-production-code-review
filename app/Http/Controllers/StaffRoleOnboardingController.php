<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\ResponseService;
use App\Services\TenantStaffRoleOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class StaffRoleOnboardingController extends Controller
{
    public function index(): View
    {
        $actor = $this->actor();
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenRedirect('staff-edit');

        return view('staff.finance-onboarding', [
            'staff' => $this->eligibleStaffQuery($actor)->get(['id', 'first_name', 'last_name', 'email', 'school_id', 'status']),
            'roleDescriptions' => TenantStaffRoleOnboardingService::roleDescriptions(),
        ]);
    }

    public function store(Request $request, TenantStaffRoleOnboardingService $onboarding): RedirectResponse
    {
        $actor = $this->actor();
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenRedirect('staff-edit');
        $data = $request->validate([
            'staff_id' => ['required', 'integer'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(TenantStaffRoleOnboardingService::roleNames())],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        // Use exactly the same classification-aware candidate set as the
        // rendered selector. This prevents a direct POST from onboarding a
        // Staff record that is hidden by the School's data-scope rules.
        $staff = $this->eligibleStaffQuery($actor)
            ->findOrFail((int) $data['staff_id']);
        $onboarding->assign($actor, $staff, $data['roles'], $data['reason']);

        return redirect()->route('staff.finance-onboarding.index')
            ->with('success', __('School onboarding roles assigned. Central Finance scope still requires Super Admin approval.'));
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor && $actor->school_id && $actor->hasRole('School Admin'), 403);

        return $actor;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function eligibleStaffQuery(User $actor): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::on('school')
            ->where('school_id', $actor->school_id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereHas('staff')
            ->with('roles')
            ->orderBy('first_name')
            ->orderBy('last_name');

        // Zixuan is the permanent QA School. Its staff workflows must use
        // the same explicit QA/Test visibility rule as Staff Management;
        // Official Schools retain the production-only rule.
        app(CentralFinanceDataIsolationService::class)
            ->applyTenantForSchoolWorkflow($query, 'staff', (int) $actor->school_id, 'users.id');

        return $query;
    }
}
