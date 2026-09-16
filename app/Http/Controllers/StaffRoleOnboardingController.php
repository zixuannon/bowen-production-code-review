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

        $query = User::on('school')
            ->where('school_id', $actor->school_id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereHas('staff')
            ->with('roles')
            ->orderBy('first_name')
            ->orderBy('last_name');
        app(CentralFinanceDataIsolationService::class)
            ->applyTenant($query, 'staff', (int) $actor->school_id, false, 'users.id');

        return view('staff.finance-onboarding', [
            'staff' => $query->get(['id', 'first_name', 'last_name', 'email', 'school_id', 'status']),
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

        $staff = User::on('school')
            ->where('school_id', $actor->school_id)
            ->whereHas('staff')
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
}
