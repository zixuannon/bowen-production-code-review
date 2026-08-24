<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupUser;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Resolves the Central Finance operating context without entering a tenant
 * database or replacing the authenticated central identity.
 */
final class CentralFinanceWorkspaceService
{
    public const SESSION_SCHOOL_KEY = 'central_finance_operating_school_id';

    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly FinanceGroupScopeService $groups,
        private readonly CentralFinanceSchoolStaffIdentityService $staffIdentities,
    ) {}

    public function actor(User $authenticated): CentralFinanceUser
    {
        $actor = CentralFinanceUser::on('mysql')->find($authenticated->id);
        // Read the raw value: User's legacy school_id accessor may inspect
        // tenant roles for Guardian browser flows, which Central Finance must
        // never trigger.
        if ($actor === null) {
            throw new AuthorizationException('A Central Finance identity is required.');
        }
        $type = $actor->getRawOriginal('central_finance_principal_type') ?? 'central_user';
        if (($type === 'central_user' && $actor->getRawOriginal('school_id') !== null)
            || ($type === CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE && !$this->staffIdentities->isActivePrincipal($actor))
            || !in_array($type, ['central_user', CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE], true)) {
            throw new AuthorizationException('A Central Finance identity is required.');
        }
        return $actor;
    }

    /** @return Collection<int, School> */
    public function accessibleSchools(CentralFinanceUser $actor): Collection
    {
        // A Central Finance school scope is necessary but deliberately not
        // sufficient. The School must also be reachable through an active
        // Group membership with the explicit read capability. This makes the
        // Group configuration the first boundary and prevents a stray scope
        // row (or a Super Admin identity) from becoming finance authority.
        $connection = DB::connection('mysql');
        $scopeSchoolIds = $connection->table('central_finance_user_school_scopes')
            ->where('user_id', $actor->id)
            ->where('can_view', true)
            ->pluck('school_id');
        $groupSchoolIds = $this->groupUsers($actor)
            ->flatMap(fn (FinanceGroupUser $groupUser) => $this->groups->accessibleSchools($groupUser, 'view_reports')->pluck('school_id'))
            ->unique()
            ->values();

        return School::on('mysql')->whereIn('id', $scopeSchoolIds->intersect($groupSchoolIds)->values())
            ->orderBy('name')->get(['id', 'name', 'code']);
    }

    public function enterSchool(CentralFinanceUser $actor, int $schoolId): School
    {
        $school = $this->accessibleSchools($actor)->firstWhere('id', $schoolId);
        if ($school === null) {
            throw new AuthorizationException('The Central Finance actor cannot enter this School.');
        }
        Session::put(self::SESSION_SCHOOL_KEY, $school->id);
        return $school;
    }

    public function exitSchool(): void
    {
        Session::forget(self::SESSION_SCHOOL_KEY);
    }

    public function currentSchool(CentralFinanceUser $actor): ?School
    {
        $schoolId = Session::get(self::SESSION_SCHOOL_KEY);
        if (!is_int($schoolId) && !ctype_digit((string) $schoolId)) {
            return null;
        }
        return $this->accessibleSchools($actor)->firstWhere('id', (int) $schoolId);
    }

    public function requireOperatingSchool(CentralFinanceUser $actor): School
    {
        $school = $this->currentSchool($actor);
        if ($school === null) {
            throw new AuthorizationException('Select an authorized School before operating Central Finance.');
        }
        return $this->assertCanOperateSchool($actor, (int) $school->id);
    }

    /**
     * The School is re-resolved from the central registry and then checked
     * against both Central School scope and active Group operating scope.
     * Import services use this rather than accepting a tenant database name.
     */
    public function assertCanOperateSchool(CentralFinanceUser $actor, int $schoolId): School
    {
        $school = $this->accessibleSchools($actor)->firstWhere('id', $schoolId);
        if ($school === null) {
            throw new AuthorizationException('The Central Finance actor cannot operate this School.');
        }
        $this->schools->assertCanOperate($actor, $school->id);
        if (!$this->groupUsers($actor)->contains(fn (FinanceGroupUser $groupUser): bool => $this->groups->canAccessSchool($groupUser, $school->id, 'operate_finance'))) {
            throw new AuthorizationException('The Central Finance actor has no operating Group scope for this School.');
        }
        return $school;
    }

    /** @return Collection<int, CentralFinanceFundAccount> */
    public function accessibleAccounts(CentralFinanceUser $actor, ?int $schoolId = null): Collection
    {
        $query = $this->accounts->visibleAccounts($actor)->active()->orderBy('account_name');
        if ($schoolId !== null) {
            $query->where(function ($query) use ($schoolId): void {
                $query->where('school_id', $schoolId)->orWhere('owner_type', CentralFinanceFundAccount::OWNER_HQ);
            });
        }
        return $query->get();
    }

    public function idempotencyReference(string $prefix): string
    {
        return $prefix.'-'.Str::uuid();
    }

    /** @return Collection<int, FinanceGroupUser> */
    private function groupUsers(CentralFinanceUser $actor): Collection
    {
        return FinanceGroupUser::on('mysql')->where('central_user_id', $actor->id)
            ->where('status', 'active')
            ->whereHas('group', fn ($query) => $query->where('status', 'active'))
            ->get();
    }
}
