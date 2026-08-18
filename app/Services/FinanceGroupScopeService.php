<?php

namespace App\Services;

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
        'manage_configuration',
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

        try {
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');

            return $callback();
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $previousDatabase);
            DB::setDefaultConnection($previousDefault);
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
