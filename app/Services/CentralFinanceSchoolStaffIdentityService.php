<?php

namespace App\Services;

use App\Models\CentralFinanceSchoolStaffIdentity;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroup;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Resolves an existing School staff login into a non-login Central Finance principal. */
final class CentralFinanceSchoolStaffIdentityService
{
    public const PRINCIPAL_TYPE = 'school_staff_identity';
    public const SESSION_KEY = 'central_finance_school_staff_actor';

    /** School-facing role labels may retain their existing internal names. */
    private const PRINCIPAL_ROLE_NAMES = ['Principal'];
    private const ACCOUNTANT_ROLE_NAMES = ['School Accountant', 'Accountant', 'Cashier'];

    public function __construct(private readonly FinanceGroupScopeService $groups) {}

    /** @return Collection<int, object> Existing tenant Staff, resolved only through the trusted registry. */
    public function availableStaff(FinanceGroup $group): Collection
    {
        return $group->schools()->where('status', 'active')->with('school')->get()
            ->flatMap(function ($member): Collection {
                $school = $member->school;
                if (!$school) return collect();

                return $this->inSchool($school, function () use ($school): Collection {
                    return DB::connection('school')->table('users')
                        ->join('staffs', 'staffs.user_id', '=', 'users.id')
                        ->where('users.school_id', $school->id)
                        ->whereNull('users.deleted_at')
                        ->orderBy('users.first_name')
                        ->get(['users.id as tenant_user_id', 'users.first_name', 'users.last_name', 'users.email'])
                        ->map(fn ($staff) => (object) [
                            'school_id' => (int) $school->id,
                            'tenant_user_id' => (int) $staff->tenant_user_id,
                            'name' => trim($staff->first_name.' '.$staff->last_name),
                            'email' => $staff->email,
                        ]);
                });
            })->values();
    }

    /**
     * A tenant numeric user ID is accepted only as a server-side selection
     * handle. The durable Central mapping always stores a stable tenant UUID.
     */
    public function grantSchoolAccountant(FinanceGroup $group, int $schoolId, int $tenantUserId): CentralFinanceUser
    {
        return $this->grantSchoolStaffFinanceAccess($group, $schoolId, $tenantUserId, true);
    }

    /**
     * Grant a Principal a read-only Central Finance identity for the current
     * School. Tenant role membership and Central scope stay deliberately
     * separate: both are required, and neither is inferred from the other.
     */
    public function grantSchoolPrincipal(FinanceGroup $group, int $schoolId, int $tenantUserId): CentralFinanceUser
    {
        return $this->grantSchoolStaffFinanceAccess($group, $schoolId, $tenantUserId, false);
    }

    private function grantSchoolStaffFinanceAccess(FinanceGroup $group, int $schoolId, int $tenantUserId, bool $requestedOperate): CentralFinanceUser
    {
        $school = School::on('mysql')->whereKey($schoolId)->firstOrFail();
        if (!$group->schools()->where(['school_id' => $school->id, 'status' => 'active'])->exists()) {
            throw ValidationException::withMessages(['school_id' => [__('The School is not an active Group member.')]]);
        }
        $staff = $this->inSchool($school, function () use ($tenantUserId, $school): ?object {
            $connection = DB::connection('school');
            if (!Schema::connection('school')->hasColumn('users', 'central_finance_source_uuid')) {
                throw ValidationException::withMessages(['tenant_user_id' => [__('This School requires the Central Finance Staff UUID migration.')]]);
            }
            $staff = $connection->table('users')
                ->join('staffs', 'staffs.user_id', '=', 'users.id')
                ->where('users.id', $tenantUserId)
                ->where('users.school_id', $school->id)->whereNull('users.deleted_at')
                ->select(['users.id', 'users.central_finance_source_uuid', 'users.first_name', 'users.last_name'])->first();
            if (!$staff) return null;
            $staff->role_names = $this->tenantRoleNames($connection, (int) $staff->id);
            if (!Str::isUuid((string) $staff->central_finance_source_uuid)) {
                $uuid = (string) Str::uuid();
                $connection->table('users')->where('id', $staff->id)->whereNull('central_finance_source_uuid')->update(['central_finance_source_uuid' => $uuid]);
                $staff->central_finance_source_uuid = $connection->table('users')->where('id', $staff->id)->value('central_finance_source_uuid');
            }
            return $staff;
        });
        if (!$staff || !Str::isUuid((string) $staff->central_finance_source_uuid)) throw ValidationException::withMessages(['tenant_user_id' => [__('Select an existing Staff member from this School.')]]);

        $isPrincipal = $this->hasAnyRole($staff->role_names ?? [], self::PRINCIPAL_ROLE_NAMES);
        $isAccountant = $this->hasAnyRole($staff->role_names ?? [], self::ACCOUNTANT_ROLE_NAMES);
        if (($requestedOperate && !$isAccountant) || (!$requestedOperate && !$isPrincipal)) {
            throw ValidationException::withMessages(['tenant_user_id' => [__('The selected Staff member does not hold the required School role for this Central Finance access.')]]);
        }

        // A multi-role Staff member may receive more than one *explicit*
        // Central grant over time. This specific action remains authoritative:
        // a Principal grant is always read-only, while an Accountant grant is
        // the separate, explicit act that adds operating authority. A School
        // role by itself must never silently escalate the Central scope.
        $canOperate = $requestedOperate;

        return DB::connection('mysql')->transaction(function () use ($group, $school, $staff, $canOperate): CentralFinanceUser {
            $identity = CentralFinanceSchoolStaffIdentity::on('mysql')->where(['school_id' => $school->id, 'tenant_user_uuid' => $staff->central_finance_source_uuid])->lockForUpdate()->first();
            if ($identity) {
                $principal = CentralFinanceUser::on('mysql')->findOrFail($identity->central_user_id);
                $identity->update(['status' => 'active']);
            } else {
                $principal = CentralFinanceUser::on('mysql')->create([
                    'first_name' => $staff->first_name, 'last_name' => $staff->last_name,
                    'email' => 'central-finance-staff-'.Str::uuid().'@identity.invalid',
                    'password' => Hash::make(Str::random(64)), 'school_id' => $school->id,
                    'status' => 0,
                ]);
                $principal->forceFill(['central_finance_principal_type' => self::PRINCIPAL_TYPE])->save();
                CentralFinanceSchoolStaffIdentity::on('mysql')->create([
                    'identity_uuid' => (string) Str::uuid(), 'school_id' => $school->id,
                    'tenant_user_uuid' => $staff->central_finance_source_uuid,
                    'central_user_id' => $principal->id, 'status' => 'active',
                ]);
            }
            $groupUser = $this->groups->addUser($group, $principal->id);
            if ($this->groups->isCentralHeadFinance($groupUser)) throw new AuthorizationException('A School Staff principal cannot become Head Finance.');
            $this->groups->grantScope($groupUser, 'view_reports', 'SCHOOL', $school->id);
            if ($canOperate) {
                $this->groups->grantScope($groupUser, 'operate_finance', 'SCHOOL', $school->id);
            }
            DB::connection('mysql')->table('central_finance_user_school_scopes')->updateOrInsert(
                ['user_id' => $principal->id, 'school_id' => $school->id],
                ['can_view' => true, 'can_operate' => $canOperate, 'can_approve_reimbursements' => false, 'can_confirm_funding' => false, 'created_at' => now(), 'updated_at' => now()]
            );
            return $principal;
        });
    }

    /** @param array{school_id:mixed,user_uuid:mixed}|null $context */
    public function resolveTrustedSession(?array $context): CentralFinanceUser
    {
        $schoolId = is_array($context) ? (int) ($context['school_id'] ?? 0) : 0;
        $uuid = is_array($context) ? (string) ($context['user_uuid'] ?? '') : '';
        if ($schoolId < 1 || !Str::isUuid($uuid)) throw new AuthorizationException('A trusted School Staff Finance identity is required.');
        $school = School::on('mysql')->whereKey($schoolId)->where('installed', 1)->first();
        $identity = $school ? CentralFinanceSchoolStaffIdentity::on('mysql')->where(['school_id' => $school->id, 'tenant_user_uuid' => $uuid, 'status' => 'active'])->first() : null;
        $principal = $identity ? CentralFinanceUser::on('mysql')->find($identity->central_user_id) : null;
        if (!$principal || $principal->getRawOriginal('central_finance_principal_type') !== self::PRINCIPAL_TYPE || (int) $principal->getRawOriginal('school_id') !== $schoolId) {
            throw new AuthorizationException('The School Staff Finance identity is not authorized.');
        }
        return $principal;
    }

    public function isActivePrincipal(CentralFinanceUser $principal): bool
    {
        return $principal->getRawOriginal('central_finance_principal_type') === self::PRINCIPAL_TYPE
            && CentralFinanceSchoolStaffIdentity::on('mysql')->where(['central_user_id' => $principal->id, 'school_id' => $principal->getRawOriginal('school_id'), 'status' => 'active'])->exists();
    }

    /** @template T @param callable():T $callback @return T */
    private function inSchool(School $school, callable $callback): mixed
    {
        $original = Config::get('database.connections.school.database');
        try { Config::set('database.connections.school.database', $school->database_name); DB::purge('school'); return $callback(); }
        finally { Config::set('database.connections.school.database', $original); DB::purge('school'); }
    }

    /** @return array<int, string> */
    private function tenantRoleNames($connection, int $tenantUserId): array
    {
        if (!Schema::connection('school')->hasTable('roles') || !Schema::connection('school')->hasTable('model_has_roles')) {
            throw ValidationException::withMessages(['tenant_user_id' => [__('This School requires role tables before Central Finance access can be granted.')]]);
        }

        return $connection->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_id', $tenantUserId)
            ->where('assignments.model_type', User::class)
            ->pluck('roles.name')
            ->map(static fn ($role): string => (string) $role)
            ->all();
    }

    /** @param array<int, string> $actual @param array<int, string> $expected */
    private function hasAnyRole(array $actual, array $expected): bool
    {
        return collect($actual)->contains(static fn (string $role): bool => in_array($role, $expected, true));
    }
}
