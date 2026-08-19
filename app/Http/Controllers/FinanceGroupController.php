<?php

namespace App\Http\Controllers;

use App\Models\FinanceGroup;
use App\Models\School;
use App\Models\User;
use App\Services\FinanceGroupScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Central control-plane configuration only. Tenant Finance roles do not gain
 * Group authority by name or by a tenant route request.
 */
class FinanceGroupController extends Controller
{
    public function __construct(private readonly FinanceGroupScopeService $groups)
    {
    }

    public function index(): View
    {
        $this->assertCentralSuperAdmin();

        return view('finance-groups.index', [
            'groups' => FinanceGroup::query()->with(['schools.school', 'users.centralUser', 'users.scopes.school', 'users.tenantIdentities.school'])->orderBy('name')->get(),
            // Schools come only from the central registry; database names are
            // deliberately not selected or rendered.
            'schools' => School::on('mysql')->orderBy('name')->get(['id', 'name', 'code', 'status']),
            'centralUsers' => User::on('mysql')->whereNull('school_id')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $attributes = $this->validated($request);

        DB::connection('mysql')->transaction(function () use ($attributes): void {
            $group = $this->groups->createGroup($attributes);
            $this->groups->syncSchools($group, $attributes['school_ids'] ?? []);
        });

        return redirect()->route('finance-groups.index')->with('success', __('Finance Group saved.'));
    }

    public function update(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $attributes = $this->validated($request);

        DB::connection('mysql')->transaction(function () use ($financeGroup, $attributes): void {
            $group = $this->groups->updateGroup($financeGroup, $attributes);
            $this->groups->syncSchools($group, $attributes['school_ids'] ?? []);
        });

        return redirect()->route('finance-groups.index')->with('success', __('Finance Group updated.'));
    }

    /** Add/update one explicit central Group user scope; no tenant role changes. */
    public function storeUserScope(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate([
            'central_user_id' => ['required', 'integer'],
            'capability' => ['required', 'in:view_reports,export_reports,manage_configuration,manage_hq_accounts,request_group_transfers,confirm_group_transfers'],
            'scope_type' => ['required', 'in:GROUP,SCHOOL,HQ'],
            'school_id' => ['nullable', 'integer'],
        ]);
        DB::connection('mysql')->transaction(function () use ($data, $financeGroup): void {
            $groupUser = $this->groups->addUser($financeGroup, (int) $data['central_user_id']);
            $this->groups->grantScope($groupUser, $data['capability'], $data['scope_type'], isset($data['school_id']) ? (int) $data['school_id'] : null);
        });
        return redirect()->route('finance-groups.index')->with('success', __('Group user scope saved.'));
    }

    /** Bind an existing central Group user to an existing tenant user safely. */
    public function storeTenantIdentity(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate(['group_user_id' => ['required', 'integer'], 'school_id' => ['required', 'integer'], 'tenant_user_id' => ['required', 'integer']]);
        $groupUser = $financeGroup->users()->whereKey($data['group_user_id'])->firstOrFail();
        $this->groups->bindTenantIdentity($groupUser, (int) $data['school_id'], (int) $data['tenant_user_id']);
        return redirect()->route('finance-groups.index')->with('success', __('Tenant identity saved.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'status' => ['required', 'in:draft,active,inactive'],
            'reporting_currency' => ['required', 'string', 'size:3', 'alpha'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'school_ids' => ['nullable', 'array'],
            'school_ids.*' => ['integer', 'distinct'],
        ]);
    }

    private function assertCentralSuperAdmin(): void
    {
        $authenticated = Auth::user();
        abort_unless($authenticated && $authenticated->school_id === null, 403);

        // Re-resolve both identity and roles on mysql. This avoids any tenant
        // role relation cached by earlier middleware being treated as Group
        // configuration authority.
        $centralUser = User::on('mysql')->find($authenticated->id);
        abort_unless($centralUser && $centralUser->school_id === null && $centralUser->hasRole('Super Admin'), 403);
    }
}
