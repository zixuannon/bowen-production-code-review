<?php

namespace App\Http\Controllers;

use App\Models\FinanceGroup;
use App\Models\School;
use App\Models\User;
use App\Models\CentralFinanceDocumentAudit;
use App\Services\FinanceGroupScopeService;
use App\Services\CentralFinanceSchoolStaffIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Central control-plane configuration only. Tenant Finance roles do not gain
 * Group authority by name or by a tenant route request.
 */
class FinanceGroupController extends Controller
{
    public function __construct(private readonly FinanceGroupScopeService $groups, private readonly CentralFinanceSchoolStaffIdentityService $staffIdentities)
    {
    }

    public function index(): View
    {
        $this->assertCentralSuperAdmin();

        return view('finance-groups.index', [
            'groups' => FinanceGroup::query()->with(['schools.school'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->assertCentralSuperAdmin();

        return view('finance-groups.create', [
            // Schools come only from the central registry; database names are
            // deliberately not selected or rendered.
            'schools' => School::on('mysql')->orderBy('name')->get(['id', 'name', 'code', 'status']),
        ]);
    }

    public function show(FinanceGroup $financeGroup): View
    {
        $this->assertCentralSuperAdmin();

        return view('finance-groups.show', $this->configurationData($financeGroup));
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
            // Keep this HTTP allowlist aligned with FinanceGroupScopeService.
            // Operating context requires an explicit grant; a Super Admin is
            // never implicitly allowed to operate a School's Finance data.
            'capability' => ['required', 'in:view_reports,export_reports,operate_finance,manage_configuration,manage_hq_accounts,request_group_transfers,confirm_group_transfers'],
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

    /** Configure Central Finance School scope; Super Admin configures but never receives it implicitly. */
    public function storeCentralSchoolScope(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate([
            'central_user_id' => ['required', 'integer'], 'school_id' => ['nullable', 'integer'],
            'grant_type' => ['nullable', 'in:head_finance_all,school_accountant,custom'],
            'can_view' => ['nullable', 'boolean'], 'can_operate' => ['nullable', 'boolean'],
            'can_approve_reimbursements' => ['nullable', 'boolean'], 'can_confirm_funding' => ['nullable', 'boolean'],
        ]);
        $grantType = $data['grant_type'] ?? 'custom';
        $centralUser = User::on('mysql')->findOrFail((int) $data['central_user_id']);
        $groupUser = $this->groups->addUser($financeGroup, (int) $data['central_user_id']);
        if ($grantType === 'head_finance_all') {
            abort_unless($this->groups->isCentralHeadFinance($groupUser), 422);
            $this->groups->grantScope($groupUser, 'view_reports', 'GROUP');
            $this->groups->grantScope($groupUser, 'operate_finance', 'GROUP');
            foreach ($financeGroup->schools()->where('status', 'active')->pluck('school_id') as $schoolId) {
                $this->upsertCentralScope((int) $centralUser->id, (int) $schoolId, true, true, true, true);
            }
        } else {
            $schoolId = (int) ($data['school_id'] ?? 0);
            abort_unless($schoolId > 0 && $financeGroup->schools()->where(['school_id' => $schoolId, 'status' => 'active'])->exists(), 422);
            $this->assertCentralScopePrincipalForSchool($centralUser, $schoolId);
            if ($grantType === 'school_accountant') {
                abort_unless(!$this->groups->isCentralHeadFinance($groupUser), 422);
                $otherSchoolScopeExists = DB::connection('mysql')->table('central_finance_user_school_scopes')
                    ->where('user_id', $centralUser->id)->where('school_id', '!=', $schoolId)->where('can_view', true)->exists();
                abort_unless(!$otherSchoolScopeExists, 422);
                $this->groups->grantScope($groupUser, 'view_reports', 'SCHOOL', $schoolId);
                $this->groups->grantScope($groupUser, 'operate_finance', 'SCHOOL', $schoolId);
                $this->upsertCentralScope((int) $centralUser->id, $schoolId, true, true, false, false);
            } else {
                $canView = (bool) ($data['can_view'] ?? false);
                $canOperate = (bool) ($data['can_operate'] ?? false);
                // The edit form must not turn a School Accountant into a
                // multi-School user. Head Finance is the only role that may
                // keep more than one active Central Finance School scope.
                if (!$this->groups->isCentralHeadFinance($groupUser) && $canView) {
                    $otherSchoolScopeExists = DB::connection('mysql')->table('central_finance_user_school_scopes')
                        ->where('user_id', $centralUser->id)->where('school_id', '!=', $schoolId)->where('can_view', true)->exists();
                    abort_unless(!$otherSchoolScopeExists, 422);
                }
                abort_unless($canView && (!$canOperate || $this->groups->canAccessSchool($groupUser, $schoolId, 'operate_finance')), 422);
                abort_unless($this->groups->canAccessSchool($groupUser, $schoolId, 'view_reports'), 422);
                $this->upsertCentralScope((int) $centralUser->id, $schoolId, $canView, $canOperate, $canOperate && (bool) ($data['can_approve_reimbursements'] ?? false), $canOperate && (bool) ($data['can_confirm_funding'] ?? false));
            }
        }
        return redirect()->route('finance-groups.index')->with('success', __('Central Finance School scope saved.'));
    }

    public function disableCentralSchoolScope(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate(['central_user_id' => ['required', 'integer'], 'school_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        abort_unless($financeGroup->schools()->where(['school_id' => (int) $data['school_id'], 'status' => 'active'])->exists(), 422);
        $centralUser = User::on('mysql')->findOrFail((int) $data['central_user_id']);
        $this->assertCentralScopePrincipalForSchool($centralUser, (int) $data['school_id']);
        $before = DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id' => (int) $data['central_user_id'], 'school_id' => (int) $data['school_id']])->first();
        $this->upsertCentralScope((int) $data['central_user_id'], (int) $data['school_id'], false, false, false, false);
        CentralFinanceDocumentAudit::on('mysql')->create([
            'school_id' => (int) $data['school_id'], 'document_type' => 'central_finance_school_scope', 'document_id' => (int) $data['central_user_id'],
            'action' => 'revoked', 'actor_id' => Auth::id(), 'reason' => trim($data['reason']),
            'before_values' => $before ? (array) $before : [], 'after_values' => ['can_view' => false, 'can_operate' => false, 'can_approve_reimbursements' => false, 'can_confirm_funding' => false],
        ]);
        return redirect()->route('finance-groups.index')->with('success', __('Central Finance School scope disabled.'));
    }

    public function storeSchoolStaffAccountant(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate(['school_id' => ['required', 'integer'], 'tenant_user_id' => ['required', 'integer']]);
        $this->staffIdentities->grantSchoolAccountant($financeGroup, (int) $data['school_id'], (int) $data['tenant_user_id']);
        return redirect()->route('finance-groups.index')->with('success', __('School Staff granted Accountant Finance access.'));
    }

    /** Grant a trusted School Principal a read-only Central Finance scope. */
    public function storeSchoolStaffPrincipal(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate(['school_id' => ['required', 'integer'], 'tenant_user_id' => ['required', 'integer']]);
        $this->staffIdentities->grantSchoolPrincipal($financeGroup, (int) $data['school_id'], (int) $data['tenant_user_id']);

        return redirect()->route('finance-groups.index')->with('success', __('School Principal granted read-only Finance access.'));
    }

    /** Grant a trusted School Front Desk user pending-collection access only. */
    public function storeSchoolStaffFrontDesk(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate(['school_id' => ['required', 'integer'], 'tenant_user_id' => ['required', 'integer']]);
        $this->staffIdentities->grantSchoolFrontDesk($financeGroup, (int) $data['school_id'], (int) $data['tenant_user_id']);

        return redirect()->route('finance-groups.index')->with('success', __('School Front Desk granted pending collection access.'));
    }

    public function provisionSchoolStaffFrontDesk(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $this->assertCentralSuperAdmin();
        $data = $request->validate(['school_id' => ['required', 'integer'], 'central_user_id' => ['required', 'integer']]);
        $tenantId = $this->staffIdentities->provisionTenantFrontDesk($financeGroup, (int) $data['school_id'], (int) $data['central_user_id']);
        return redirect()->route('finance-groups.index')->with('success', __('Tenant Front Desk identity provisioned (#'.$tenantId.').'));
    }

    private function upsertCentralScope(int $userId, int $schoolId, bool $view, bool $operate, bool $approve, bool $confirm, bool $submitCollections = false): void
    {
        $values = [
            'can_view' => $view, 'can_operate' => $operate, 'can_approve_reimbursements' => $operate && $approve, 'can_confirm_funding' => $operate && $confirm, 'created_at' => now(), 'updated_at' => now(),
        ];
        if (Schema::connection('mysql')->hasColumn('central_finance_user_school_scopes', 'can_submit_collections')) {
            $values['can_submit_collections'] = $view && $submitCollections;
        }
        DB::connection('mysql')->table('central_finance_user_school_scopes')->updateOrInsert(
            ['user_id' => $userId, 'school_id' => $schoolId],
            $values,
        );
    }

    /**
     * Central staff identities are single-School principals. They may be
     * configured only for their own trusted School; ordinary Central users
     * remain global-directory identities with no tenant school_id. This keeps
     * the configuration surface aligned with the runtime authorization model.
     */
    private function assertCentralScopePrincipalForSchool(User $centralUser, int $schoolId): void
    {
        $type = $centralUser->getRawOriginal('central_finance_principal_type') ?? 'central_user';
        $isCentralDirectoryUser = $type === 'central_user' && $centralUser->getRawOriginal('school_id') === null;
        $isSchoolStaffPrincipal = $type === CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE
            && (int) $centralUser->getRawOriginal('school_id') === $schoolId;

        abort_unless($isCentralDirectoryUser || $isSchoolStaffPrincipal, 422);
    }

    /** @return array<string, mixed> */
    private function configurationData(FinanceGroup $financeGroup): array
    {
        $group = $financeGroup->load(['schools.school', 'users.centralUser.roles', 'users.scopes.school', 'users.tenantIdentities.school']);
        $schoolIds = $group->schools->where('status', 'active')->pluck('school_id')->all();

        return [
            'group' => $group,
            'schools' => School::on('mysql')->orderBy('name')->get(['id', 'name', 'code', 'status']),
            'centralUsers' => User::on('mysql')->with('roles')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email', 'school_id', 'central_finance_principal_type']),
            'centralScopes' => DB::connection('mysql')->table('central_finance_user_school_scopes as scopes')
                ->join('users as users', 'users.id', '=', 'scopes.user_id')
                ->join('schools as schools', 'schools.id', '=', 'scopes.school_id')
                ->whereIn('scopes.school_id', $schoolIds)
                ->select(['scopes.*', 'users.first_name', 'users.last_name', 'users.email', 'schools.name as school_name'])
                ->orderBy('users.first_name')->orderBy('schools.name')->get(),
            'schoolStaff' => $this->staffIdentities->availableStaff($group),
        ];
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
