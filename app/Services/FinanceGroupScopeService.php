<?php

namespace App\Services;

use App\Exceptions\FinanceGroupTenantUnavailableException;
use App\Models\FinanceGroup;
use App\Models\FinanceGroupSchool;
use App\Models\FinanceGroupUser;
use App\Models\FinanceGroupUserScope;
use App\Models\FinanceGroupUserTenantIdentity;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Central, explicit authorization configuration for future Group Finance.
 *
 * This service deliberately has no Finance source write operation. It only
 * configures trusted central membership/scope records and validates a tenant
 * identity mapping through a controlled connection lifecycle.
 */
class FinanceGroupScopeService
{
    public const CAPABILITIES = [
        'view_reports',
        'export_reports',
        'operate_finance',
        'manage_configuration',
        'manage_hq_accounts',
        'request_group_transfers',
        'confirm_group_transfers',
    ];

    public const SCOPE_TYPES = [
        'GROUP',
        'SCHOOL',
        'HQ',
    ];

    /** @param array<string, mixed> $attributes */
    public function createGroup(array $attributes): FinanceGroup
    {
        $month = (int) ($attributes['fiscal_year_start_month'] ?? 1);
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => [__('Group name is required.')],
            ]);
        }
        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages([
                'fiscal_year_start_month' => [__('Fiscal year start month must be between 1 and 12.')],
            ]);
        }

        $code = $this->nullableCode($attributes['code'] ?? null);
        if ($code !== null && FinanceGroup::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => [__('This Group code is already in use.')],
            ]);
        }

        return FinanceGroup::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => $attributes['status'] ?? 'draft',
            'reporting_currency' => strtoupper((string) ($attributes['reporting_currency'] ?? 'MMK')),
            'fiscal_year_start_month' => $month,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateGroup(FinanceGroup $group, array $attributes): FinanceGroup
    {
        $month = (int) ($attributes['fiscal_year_start_month'] ?? $group->fiscal_year_start_month);
        $name = trim((string) ($attributes['name'] ?? $group->name));
        $code = $this->nullableCode($attributes['code'] ?? $group->code);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => [__('Group name is required.')],
            ]);
        }
        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages([
                'fiscal_year_start_month' => [__('Fiscal year start month must be between 1 and 12.')],
            ]);
        }
        if ($code !== null && FinanceGroup::query()->where('code', $code)->where('id', '!=', $group->id)->exists()) {
            throw ValidationException::withMessages([
                'code' => [__('This Group code is already in use.')],
            ]);
        }

        $group->update([
            'code' => $code,
            'name' => $name,
            'status' => $attributes['status'] ?? $group->status,
            'reporting_currency' => strtoupper((string) ($attributes['reporting_currency'] ?? $group->reporting_currency)),
            'fiscal_year_start_month' => $month,
        ]);

        return $group->fresh();
    }

    /**
     * Reconcile configuration membership without deleting history. An empty
     * selection is valid for a draft Group and revokes current membership.
     *
     * @param array<int, mixed> $schoolIds
     * @return Collection<int, FinanceGroupSchool>
     */
    public function syncSchools(FinanceGroup $group, array $schoolIds): Collection
    {
        $schoolIds = collect($schoolIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $registeredIds = School::on('mysql')->whereIn('id', $schoolIds)->pluck('id')->map(static fn ($id) => (int) $id);
        if ($registeredIds->count() !== $schoolIds->count()) {
            throw ValidationException::withMessages([
                'school_ids' => [__('Every Group School must exist in the trusted School registry.')],
            ]);
        }

        foreach ($schoolIds as $schoolId) {
            $this->addSchool($group, $schoolId);
        }

        FinanceGroupSchool::query()
            ->where('group_id', $group->id)
            ->whereNotIn('school_id', $schoolIds->all())
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'active_to' => now()->toDateString()]);

        return FinanceGroupSchool::query()->where('group_id', $group->id)->orderBy('school_id')->get();
    }

    public function addSchool(FinanceGroup $group, int $schoolId, ?string $activeFrom = null): FinanceGroupSchool
    {
        $this->centralSchool($schoolId);

        return FinanceGroupSchool::query()->updateOrCreate(
            ['group_id' => $group->id, 'school_id' => $schoolId],
            ['status' => 'active', 'active_from' => $activeFrom, 'active_to' => null],
        );
    }

    public function revokeSchool(FinanceGroup $group, int $schoolId, ?string $activeTo = null): FinanceGroupSchool
    {
        $membership = FinanceGroupSchool::query()
            ->where('group_id', $group->id)
            ->where('school_id', $schoolId)
            ->firstOrFail();

        $membership->update(['status' => 'revoked', 'active_to' => $activeTo ?? now()->toDateString()]);

        return $membership->fresh();
    }

    public function addUser(FinanceGroup $group, int $centralUserId): FinanceGroupUser
    {
        User::on('mysql')->findOrFail($centralUserId);

        return FinanceGroupUser::query()->firstOrCreate(
            ['group_id' => $group->id, 'central_user_id' => $centralUserId],
            ['status' => 'active'],
        );
    }

    public function grantScope(
        FinanceGroupUser $groupUser,
        string $capability,
        string $scopeType,
        ?int $schoolId = null,
        ?string $activeFrom = null,
        ?string $activeTo = null,
    ): FinanceGroupUserScope {
        $capability = strtolower($capability);
        $scopeType = strtoupper($scopeType);
        $this->assertAllowedCapability($capability);
        $this->assertAllowedScopeType($scopeType);

        if ($scopeType === 'SCHOOL') {
            if (!$schoolId || !$this->activeMembershipExists($groupUser->group_id, $schoolId)) {
                throw ValidationException::withMessages([
                    'school_id' => [__('A School scope requires an active Group member School.')],
                ]);
            }
        } elseif ($schoolId !== null) {
            throw ValidationException::withMessages([
                'school_id' => [__('Only a School scope may target a School.')],
            ]);
        }

        $scopeKey = $scopeType === 'SCHOOL' ? 'school:' . $schoolId : strtolower($scopeType);

        return FinanceGroupUserScope::query()->updateOrCreate(
            [
                'group_user_id' => $groupUser->id,
                'scope_key' => $scopeKey,
                'capability' => $capability,
            ],
            [
                'school_id' => $schoolId,
                'scope_type' => $scopeType,
                'status' => 'active',
                'active_from' => $activeFrom,
                'active_to' => $activeTo,
            ],
        );
    }

    /** @return Collection<int, FinanceGroupSchool> */
    public function accessibleSchools(FinanceGroupUser $groupUser, string $capability = 'view_reports'): Collection
    {
        $capability = strtolower($capability);
        $this->assertAllowedCapability($capability);

        // Revoking a Group participant takes effect immediately, independent
        // of the status of any scopes that remain for audit/history.
        if ($groupUser->status !== 'active') {
            return new Collection();
        }

        $scopes = $this->activeScopes($groupUser, $capability);
        if ($scopes->contains('scope_type', 'GROUP')) {
            return $this->activeMemberships($groupUser->group_id);
        }

        $schoolIds = $scopes->where('scope_type', 'SCHOOL')->pluck('school_id')->filter()->all();

        return $this->activeMemberships($groupUser->group_id)
            ->whereIn('school_id', $schoolIds)
            ->values();
    }

    public function bindTenantIdentity(FinanceGroupUser $groupUser, int $schoolId, int $tenantUserId): FinanceGroupUserTenantIdentity
    {
        if (!$this->activeMembershipExists($groupUser->group_id, $schoolId)) {
            throw ValidationException::withMessages([
                'school_id' => [__('The School is not an active member of this Group.')],
            ]);
        }

        $school = $this->centralSchool($schoolId);
        $tenantUser = $this->inTenant($school, function () use ($tenantUserId, $school): ?User {
            return User::on('school')
                ->whereKey($tenantUserId)
                ->where('school_id', $school->id)
                ->first();
        });

        if (!$tenantUser) {
            throw ValidationException::withMessages([
                'tenant_user_id' => [__('The tenant user does not belong to the selected School.')],
            ]);
        }

        return FinanceGroupUserTenantIdentity::query()->updateOrCreate(
            ['group_user_id' => $groupUser->id, 'school_id' => $schoolId],
            ['tenant_user_id' => $tenantUser->id, 'status' => 'active'],
        );
    }

    /**
     * A Group transfer can only name a tenant account after a trusted central
     * School lookup, an explicit Group scope check, and a re-check through the
     * mapped tenant user's normal Fund Account scope. It returns scalar data so
     * callers cannot retain a tenant model after the connection is restored.
     *
     * @return array{id:int, school_id:int, account_name:string, currency:string}
     */
    public function authorizeActiveTenantAccountForGroupUser(FinanceGroupUser $groupUser, int $schoolId, int $accountId, string $capability = 'request_group_transfers'): array
    {
        if (!$this->canAccessSchool($groupUser, $schoolId, $capability)) {
            throw new AuthorizationException('This Group user is not authorized for the requested School.');
        }

        $identity = FinanceGroupUserTenantIdentity::query()
            ->where('group_user_id', $groupUser->id)
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->first();
        if (!$identity) {
            throw new FinanceGroupTenantUnavailableException('No active tenant identity is configured for this Group School.');
        }

        $school = $this->centralSchool($schoolId);
        return $this->inTenant($school, function () use ($identity, $school, $accountId): array {
            $tenantUser = User::on('school')->whereKey($identity->tenant_user_id)->where('school_id', $school->id)->first();
            if (!$tenantUser) {
                throw new FinanceGroupTenantUnavailableException('The configured tenant identity no longer belongs to this School.');
            }
            $account = app(FinanceAccountAccessService::class)->authorizeActive($tenantUser, $accountId);
            return [
                'id' => (int) $account->id,
                'school_id' => (int) $account->school_id,
                'account_name' => (string) $account->account_name,
                'currency' => (string) $account->currency,
            ];
        });
    }

    /** @return array<int, array{id:int,account_name:string,currency:string}> */
    public function accessibleActiveTenantAccountsForGroupUser(FinanceGroupUser $groupUser, int $schoolId, string $capability = 'request_group_transfers'): array
    {
        if (!$this->canAccessSchool($groupUser, $schoolId, $capability)) {
            throw new AuthorizationException('This Group user is not authorized for the requested School.');
        }
        $identity = FinanceGroupUserTenantIdentity::query()->where('group_user_id', $groupUser->id)->where('school_id', $schoolId)->where('status', 'active')->first();
        if (!$identity) {
            throw new FinanceGroupTenantUnavailableException('No active tenant identity is configured for this Group School.');
        }
        $school = $this->centralSchool($schoolId);
        return $this->inTenant($school, function () use ($identity, $school): array {
            $tenantUser = User::on('school')->whereKey($identity->tenant_user_id)->where('school_id', $school->id)->first();
            if (!$tenantUser) throw new FinanceGroupTenantUnavailableException('The configured tenant identity no longer belongs to this School.');
            return app(FinanceAccountAccessService::class)->accessibleAccounts($tenantUser)->active()->orderBy('account_name')
                ->get(['id', 'account_name', 'currency'])->map(fn ($account) => ['id'=>(int)$account->id, 'account_name'=>(string)$account->account_name, 'currency'=>(string)$account->currency])->all();
        });
    }

    /**
     * Resolve the current balance through the same isolated tenant context
     * used for Fund Account authorization. This is a read-only precondition
     * for School-to-HQ confirmation; no central status is changed unless the
     * School account can actually fund the remittance.
     */
    public function tenantAccountHasSufficientBalanceForGroupUser(FinanceGroupUser $groupUser, int $schoolId, int $accountId, float $amount): bool
    {
        $this->authorizeActiveTenantAccountForGroupUser($groupUser, $schoolId, $accountId);
        $identity = FinanceGroupUserTenantIdentity::query()
            ->where('group_user_id', $groupUser->id)
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->firstOrFail();
        $school = $this->centralSchool($schoolId);

        return $this->inTenant($school, function () use ($identity, $school, $accountId, $amount): bool {
            $tenantUser = User::on('school')->whereKey($identity->tenant_user_id)->where('school_id', $school->id)->firstOrFail();
            $account = app(FinanceAccountAccessService::class)->authorizeActive($tenantUser, $accountId);
            return app(FundAccountBalanceService::class)->currentBalance($account) + 0.0001 >= $amount;
        });
    }

    public function canAccessSchool(FinanceGroupUser $groupUser, int $schoolId, string $capability): bool
    {
        if ($groupUser->status !== 'active' || !$this->activeMembershipExists($groupUser->group_id, $schoolId)) {
            return false;
        }
        $scopes = $this->activeScopes($groupUser, $capability);
        return $scopes->contains('scope_type', 'GROUP')
            || $scopes->where('scope_type', 'SCHOOL')->pluck('school_id')->contains($schoolId);
    }

    /**
     * Resolve an active mapped tenant identity without exposing the tenant
     * database name. The short-lived tenant connection is restored before the
     * scalar identifiers are returned.
     *
     * @return array{school_id:int,tenant_user_id:int}
     */
    public function resolveTrustedTenantIdentity(FinanceGroupUser $groupUser, int $schoolId, string $capability): array
    {
        if (!$this->canAccessSchool($groupUser, $schoolId, $capability)) {
            throw new AuthorizationException('This Group user is not authorized for the requested School.');
        }

        $identity = FinanceGroupUserTenantIdentity::query()
            ->where('group_user_id', $groupUser->id)
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->first();
        if (!$identity) {
            throw new FinanceGroupTenantUnavailableException('No active tenant identity is configured for this Group School.');
        }

        $school = $this->centralSchool($schoolId);
        $tenantUserId = $this->inTenant($school, function () use ($identity, $school): int {
            $tenantUser = User::on('school')
                ->whereKey($identity->tenant_user_id)
                ->where('school_id', $school->id)
                ->first();
            if (!$tenantUser) {
                throw new FinanceGroupTenantUnavailableException('The configured tenant identity no longer belongs to this School.');
            }

            return (int) $tenantUser->id;
        });

        return ['school_id' => (int) $school->id, 'tenant_user_id' => $tenantUserId];
    }

    public function canManageAllHqAccounts(FinanceGroupUser $groupUser): bool
    {
        if ($groupUser->status !== 'active') {
            return false;
        }
        return $this->activeScopes($groupUser, 'manage_hq_accounts')
            ->contains(fn (FinanceGroupUserScope $scope) => in_array($scope->scope_type, ['GROUP', 'HQ'], true));
    }

    /**
     * HQ account balance/status/assignment control is deliberately stricter
     * than ordinary HQ-account use: the central user must be Head Finance and
     * have the explicit Group-level capability. A configured scope by itself
     * can never elevate an HQ Accountant into this control plane.
     */
    public function canControlHqAccounts(FinanceGroupUser $groupUser): bool
    {
        if ($groupUser->status !== 'active') return false;
        $central = User::on('mysql')->find($groupUser->central_user_id);
        if (!$central || !$central->hasRole('Head Finance')) return false;
        return $this->activeScopes($groupUser, 'manage_hq_accounts')->contains('scope_type', 'GROUP');
    }

    public function canConfirmGroupTransfers(FinanceGroupUser $groupUser): bool
    {
        if ($groupUser->status !== 'active') {
            return false;
        }

        // Confirmation makes a cross-school balance movement effective. A
        // capability row is necessary but not sufficient: only the central
        // Head Finance identity may exercise that control-plane action.
        $central = User::on('mysql')->find($groupUser->central_user_id);
        if (!$central || !$central->hasRole('Head Finance')) {
            return false;
        }

        return $this->activeScopes($groupUser, 'confirm_group_transfers')->contains('scope_type', 'GROUP');
    }

    /**
     * Read Ledger V1 in the selected tenant using the explicit Group-user
     * identity mapped for that School. This intentionally exposes no generic
     * tenant callback, so Group-report code cannot turn it into a write path.
     * Callers never receive a raw database name or choose a connection.
     *
     * @param array<string, mixed> $filters
     * @return array{rows: \Illuminate\Support\Collection<int, array<string, mixed>>, summary: array<string, float>}
     */
    public function readLedgerAsTenantIdentity(FinanceGroupUser $groupUser, FinanceGroupSchool $membership, FinanceLedgerV1Service $ledger, array $filters = []): array
    {
        $allowed = $this->accessibleSchools($groupUser, 'view_reports');
        if (!$allowed->contains(fn (FinanceGroupSchool $item) => $item->id === $membership->id)) {
            throw new AuthorizationException('This Group user is not authorized for the requested School.');
        }

        $identity = FinanceGroupUserTenantIdentity::query()
            ->where('group_user_id', $groupUser->id)
            ->where('school_id', $membership->school_id)
            ->where('status', 'active')
            ->first();
        if (!$identity) {
            throw new FinanceGroupTenantUnavailableException('No active tenant identity is configured for this Group School.');
        }

        $school = $this->centralSchool($membership->school_id);

        return $this->inTenant($school, function () use ($identity, $school, $ledger, $filters): array {
            $tenantUser = User::on('school')
                ->whereKey($identity->tenant_user_id)
                ->where('school_id', $school->id)
                ->first();
            if (!$tenantUser) {
                throw new FinanceGroupTenantUnavailableException('The configured tenant identity no longer belongs to this School.');
            }

            return $ledger->register($tenantUser, $filters);
        });
    }

    private function centralSchool(int $schoolId): School
    {
        return School::on('mysql')->findOrFail($schoolId);
    }

    private function activeMembershipExists(int $groupId, int $schoolId): bool
    {
        return $this->activeMemberships($groupId)->where('school_id', $schoolId)->isNotEmpty();
    }

    /** @return Collection<int, FinanceGroupSchool> */
    private function activeMemberships(int $groupId): Collection
    {
        $today = now()->toDateString();

        return FinanceGroupSchool::query()
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('active_from')->orWhereDate('active_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('active_to')->orWhereDate('active_to', '>=', $today))
            ->orderBy('school_id')
            ->get();
    }

    /** @return Collection<int, FinanceGroupUserScope> */
    private function activeScopes(FinanceGroupUser $groupUser, string $capability): Collection
    {
        $today = now()->toDateString();

        return FinanceGroupUserScope::query()
            ->where('group_user_id', $groupUser->id)
            ->where('capability', $capability)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('active_from')->orWhereDate('active_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('active_to')->orWhereDate('active_to', '>=', $today))
            ->get();
    }

    /** @template T
     * @param callable(): T $callback
     * @return T
     */
    private function inTenant(School $school, callable $callback)
    {
        $previousDefault = DB::getDefaultConnection();
        $previousDatabase = Config::get('database.connections.school.database');
        $previousConnectionName = session('db_connection_name');

        try {
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');
            // User::getConnectionName() is session-aware. Keep mapped tenant
            // role and bank_account_user lookups on the same trusted School
            // connection without changing the authenticated central User.
            session(['db_connection_name' => 'school']);

            return $callback();
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $previousDatabase);
            DB::setDefaultConnection($previousDefault);
            if ($previousConnectionName === null) {
                session()->forget('db_connection_name');
            } else {
                session(['db_connection_name' => $previousConnectionName]);
            }
        }
    }

    private function assertAllowedCapability(string $capability): void
    {
        if (!in_array($capability, self::CAPABILITIES, true)) {
            throw ValidationException::withMessages([
                'capability' => [__('Unsupported Group Finance capability.')],
            ]);
        }
    }

    private function assertAllowedScopeType(string $scopeType): void
    {
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw ValidationException::withMessages([
                'scope_type' => [__('Unsupported Group Finance scope type.')],
            ]);
        }
    }

    private function nullableCode(mixed $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }
}
