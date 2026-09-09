<?php

namespace App\Services;

use App\Models\CentralFinanceSchoolStaffIdentity;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroup;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
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
    private const FRONT_DESK_ROLE_NAMES = ['Front Desk', 'Admissions & Collection', 'Front Desk / Admissions & Collection'];

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

    /** A Front Desk identity may submit Pending Collections, never operate Finance generally. */
    public function grantSchoolFrontDesk(FinanceGroup $group, int $schoolId, int $tenantUserId): CentralFinanceUser
    {
        return $this->grantSchoolStaffFinanceAccess($group, $schoolId, $tenantUserId, false, true);
    }

    /** Provision (or locate) the tenant login for an existing Central staff identity. */
    public function provisionTenantFrontDesk(FinanceGroup $group, int $schoolId, int $centralUserId): int
    {
        $school = School::on('mysql')->whereKey($schoolId)->where('installed', 1)->firstOrFail();
        abort_unless($group->schools()->where(['school_id' => $schoolId, 'status' => 'active'])->exists(), 422);
        $central = CentralFinanceUser::on('mysql')->findOrFail($centralUserId);
        $tenantId = $this->inSchool($school, function () use ($central, $school): int {
            $db = DB::connection('school');
            $query = $db->table('users');
            $centralAttributes = $central->getAttributes();
            $authState = [];
            foreach (['two_factor_enabled', 'two_factor_secret', 'two_factor_expires_at'] as $column) {
                if (Schema::connection('school')->hasColumn('users', $column)
                    && array_key_exists($column, $centralAttributes)) {
                    // The tenant login is the same linked person. Keep its
                    // canonical authentication state aligned without ever
                    // exposing the underlying secret in logs or audit data.
                    $authState[$column] = $central->getRawOriginal($column);
                }
            }
            $uuid = (string) ($central->getRawOriginal('central_finance_source_uuid') ?? '');
            if (!Str::isUuid($uuid)) {
                // Deterministic UUID keeps repeated provisioning tied to the
                // same person and School without adding a second identity row.
                $hex = sha1('eschool:tenant-staff:'.$school->id.':'.$central->id);
                $hex[12] = '5'; $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
                $uuid = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
            }
            $tenant = Schema::connection('school')->hasColumn('users', 'central_finance_source_uuid')
                ? $query->where('central_finance_source_uuid', $uuid)->first()
                : null;
            if (!$tenant && $central->email) $tenant = $query->where('email', $central->email)->first();
            if ($tenant && !empty($tenant->central_finance_source_uuid)
                && $tenant->central_finance_source_uuid !== $uuid) {
                throw ValidationException::withMessages(['central_user_id' => [__('The selected Central identity conflicts with an existing linked School Staff identity.')]]);
            }
            if (!$tenant) {
                // Provisioning links the same person's credentials; it must
                // never invent an inaccessible password hash as a workaround.
                if (!is_string($central->password) || trim($central->password) === '') {
                    throw ValidationException::withMessages(['central_user_id' => [__('The selected Central identity must have an active credential before it can be linked to a School Staff login.')] ]);
                }
                $data = array_merge(['first_name' => $central->first_name ?: 'Front Desk', 'last_name' => $central->last_name ?: 'QA', 'email' => $central->email, 'password' => $central->password, 'status' => 1, 'created_at' => now(), 'updated_at' => now()], $authState);
                if (Schema::connection('school')->hasColumn('users', 'school_id')) $data['school_id'] = $school->id;
                if (Schema::connection('school')->hasColumn('users', 'central_finance_source_uuid')) $data['central_finance_source_uuid'] = $uuid;
                $tenantId = (int) $query->insertGetId($data);
            } else {
                $tenantId = (int) $tenant->id;
                if (Schema::connection('school')->hasColumn('users', 'central_finance_source_uuid') && empty($tenant->central_finance_source_uuid)) $query->where('id', $tenantId)->update(['central_finance_source_uuid' => $uuid]);
                if ($authState !== []) {
                    $query->where('id', $tenantId)->where('central_finance_source_uuid', $uuid)->update(array_merge($authState, ['updated_at' => now()]));
                }
            }
            $staffId = $db->table('staffs')->where('user_id', $tenantId)->value('id');
            if (!$staffId) {
                $staffData = ['user_id' => $tenantId];
                if (Schema::connection('school')->hasColumn('staffs', 'created_at')) { $staffData['created_at'] = now(); $staffData['updated_at'] = now(); }
                $db->table('staffs')->insert($staffData);
            }
            $role = $db->table('roles')->where('name', 'Front Desk / Admissions & Collection')->first();
            if (!$role) {
                $roleData = ['name' => 'Front Desk / Admissions & Collection', 'guard_name' => 'web'];
                if (Schema::connection('school')->hasColumn('roles', 'school_id')) $roleData['school_id'] = $school->id;
                if (Schema::connection('school')->hasColumn('roles', 'created_at')) { $roleData['created_at'] = now(); $roleData['updated_at'] = now(); }
                $roleId = (int) $db->table('roles')->insertGetId($roleData);
            } else $roleId = (int) $role->id;
            $exists = $db->table('model_has_roles')->where(['role_id' => $roleId, 'model_id' => $tenantId, 'model_type' => User::class])->exists();
            if (!$exists) $db->table('model_has_roles')->insert(['role_id' => $roleId, 'model_id' => $tenantId, 'model_type' => User::class]);
            return $tenantId;
        });
        CentralFinanceDocumentAudit::on('mysql')->create([
            'school_id' => $schoolId, 'document_type' => 'central_finance_staff_identity',
            'document_id' => $centralUserId, 'action' => 'provisioned', 'actor_id' => auth()->id() ?: $centralUserId,
            'reason' => 'Tenant Staff identity provision/link', 'before_values' => [],
            'after_values' => ['tenant_user_id' => $tenantId, 'school_id' => $schoolId],
        ]);
        return $tenantId;
    }

    private function grantSchoolStaffFinanceAccess(FinanceGroup $group, int $schoolId, int $tenantUserId, bool $requestedOperate, bool $requestedCollectionSubmit = false): CentralFinanceUser
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
        $isFrontDesk = $this->hasAnyRole($staff->role_names ?? [], self::FRONT_DESK_ROLE_NAMES);
        if (($requestedOperate && !$isAccountant) || ($requestedCollectionSubmit && !$isFrontDesk) || (!$requestedOperate && !$requestedCollectionSubmit && !$isPrincipal)) {
            throw ValidationException::withMessages(['tenant_user_id' => [__('The selected Staff member does not hold the required School role for this Central Finance access.')]]);
        }

        // A multi-role Staff member may receive more than one *explicit*
        // Central grant over time. This specific action remains authoritative:
        // a Principal grant is always read-only, while an Accountant grant is
        // the separate, explicit act that adds operating authority. A School
        // role by itself must never silently escalate the Central scope.
        $canOperate = $requestedOperate;

        return DB::connection('mysql')->transaction(function () use ($group, $school, $staff, $canOperate, $requestedCollectionSubmit): CentralFinanceUser {
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
            $scopeValues = ['can_view' => true, 'can_operate' => $canOperate, 'can_approve_reimbursements' => false, 'can_confirm_funding' => false, 'created_at' => now(), 'updated_at' => now()];
            // Keeps existing migration-rehearsal fixtures compatible while
            // Production always receives the additive column before the new
            // Front Desk grant can be used.
            if (Schema::connection('mysql')->hasColumn('central_finance_user_school_scopes', 'can_submit_collections')) {
                $scopeValues['can_submit_collections'] = $requestedCollectionSubmit;
            }
            DB::connection('mysql')->table('central_finance_user_school_scopes')->updateOrInsert(
                ['user_id' => $principal->id, 'school_id' => $school->id],
                $scopeValues
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

    /**
     * Run one internal School-Finance operation as the tenant Staff member
     * behind an already verified School Staff Central principal.  Request data
     * never supplies either a tenant id or a database name.
     *
     * @template T
     * @param callable(User, School):T $operation
     * @return T
     */
    public function executeAsTenantIdentity(CentralFinanceUser $principal, School $school, callable $operation): mixed
    {
        $principal = CentralFinanceUser::on('mysql')->findOrFail($principal->id);
        // Workspace scope lists intentionally select presentation-safe School
        // columns. Rehydrate from the trusted Central registry before any
        // tenant connection is configured; the browser never supplies this
        // database identity.
        $school = School::on('mysql')->whereKey($school->id)->where('installed', 1)->firstOrFail();
        if (!$this->isActivePrincipal($principal)
            || (int) $principal->getRawOriginal('school_id') !== (int) $school->id) {
            throw new AuthorizationException('The School Staff Finance identity is not authorized.');
        }

        $identity = CentralFinanceSchoolStaffIdentity::on('mysql')->where([
            'central_user_id' => $principal->id,
            'school_id' => $school->id,
            'status' => 'active',
        ])->first();
        if ($identity === null) {
            throw new AuthorizationException('The School Staff Finance identity is not authorized.');
        }

        return $this->inSchool($school, function () use ($identity, $school, $operation) {
            try {
                $tenant = User::on('school')->where([
                    'school_id' => $school->id,
                    'central_finance_source_uuid' => $identity->tenant_user_uuid,
                ])->whereNull('deleted_at')->first();
            } catch (QueryException $exception) {
                // Do not let a schema-introspection cache cause a valid mapped
                // School Accountant to be treated as unauthorised. Missing
                // tenant UUID schema still fails closed through the canonical
                // query, rather than falling back to another connection.
                if (str_contains(strtolower($exception->getMessage()), 'central_finance_source_uuid')) {
                    throw new AuthorizationException('This School requires the Central Finance Staff UUID migration.');
                }

                throw $exception;
            }
            if ($tenant === null) {
                throw new AuthorizationException('The mapped School Staff user is unavailable.');
            }

            return $operation($tenant, $school);
        });
    }

    /** @template T @param callable():T $callback @return T */
    private function inSchool(School $school, callable $callback): mixed
    {
        $original = Config::get('database.connections.school.database');
        $default = DB::getDefaultConnection();
        $connection = session('db_connection_name');
        try {
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');
            session(['db_connection_name' => 'school']);
            return $callback();
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $original);
            DB::setDefaultConnection($default);
            if ($connection === null) session()->forget('db_connection_name'); else session(['db_connection_name' => $connection]);
        }
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
