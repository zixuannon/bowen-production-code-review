<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceSchoolStaffIdentity;
use App\Models\FinanceGroupUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Central Fund Account authorization.
 *
 * Normal Group accounts are authorized by the intersection of an active
 * account allocation, active School scope, and active Finance Group scope.
 * The legacy central_finance_fund_account_users pivot is retained only for
 * compatibility/audit and is deliberately not an authorization prerequisite.
 */
final class CentralFinanceFundAccountScopeService
{
    public function __construct(private readonly FinanceGroupScopeService $groups) {}

    /** @return Builder<CentralFinanceFundAccount> */
    public function visibleAccounts(User $actor, ?int $schoolId = null, bool $operate = false): Builder
    {
        $query = CentralFinanceFundAccount::on('mysql')->active();
        $contexts = $this->authorizedContexts($actor, $operate)
            ->when($schoolId !== null, fn (Collection $rows) => $rows->where('school_id', $schoolId))
            ->values();

        if ($contexts->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $accounts) use ($contexts): void {
            foreach ($contexts as $context) {
                $accounts->orWhere(function (Builder $candidate) use ($context): void {
                    $candidate->where('group_id', $context['group_id'])
                        ->whereHas('schoolAllocations', fn (Builder $allocations) => $allocations
                            ->where('school_id', $context['school_id'])
                            ->effective());
                });
            }
        });
    }

    public function assertCanView(User $actor, CentralFinanceFundAccount $account, int $schoolId): void
    {
        $this->assertCanonicalScope($actor, $account, $schoolId, false);
    }

    public function assertCanOperate(User $actor, CentralFinanceFundAccount $account, int $schoolId): void
    {
        $this->assertCanonicalScope($actor, $account, $schoolId, true);
    }

    public function canView(User $actor, CentralFinanceFundAccount $account, int $schoolId): bool
    {
        return $this->canUse($actor, $account, $schoolId, false);
    }

    public function canOperate(User $actor, CentralFinanceFundAccount $account, int $schoolId): bool
    {
        return $this->canUse($actor, $account, $schoolId, true);
    }

    private function assertCanonicalScope(User $actor, CentralFinanceFundAccount $account, int $schoolId, bool $operate): void
    {
        if (!$this->canUse($actor, $account, $schoolId, $operate)) {
            throw new AuthorizationException('The central actor is not authorized for this Fund Account and School allocation.');
        }
    }

    private function canUse(User $actor, CentralFinanceFundAccount $account, int $schoolId, bool $operate): bool
    {
        $activeAccount = CentralFinanceFundAccount::on('mysql')->active()->find($account->id);
        if ($activeAccount === null) return false;

        return $this->authorizedContexts($actor, $operate)->contains(
            fn (array $context): bool => $context['group_id'] === (int) $activeAccount->group_id
                && $context['school_id'] === $schoolId
                && $activeAccount->schoolAllocations()->where('school_id', $schoolId)->effective()->exists()
        );
    }

    /** @return Collection<int, array{group_id:int,school_id:int}> */
    private function authorizedContexts(User $actor, bool $operate): Collection
    {
        foreach (['central_finance_user_school_scopes', 'finance_group_users', 'finance_group_user_scopes', 'finance_group_schools'] as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) return collect();
        }

        $scopeQuery = DB::connection('mysql')->table('central_finance_user_school_scopes')
            ->where('user_id', $actor->id)
            ->where('can_view', true);
        if ($operate) $scopeQuery->where('can_operate', true);
        $schoolIds = $scopeQuery->pluck('school_id')->map(fn ($id): int => (int) $id);
        if ($schoolIds->isEmpty()) return collect();

        $principalType = $actor->getRawOriginal('central_finance_principal_type') ?? 'central_user';
        if ($principalType === CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE) {
            if (!Schema::connection('mysql')->hasTable('central_finance_school_staff_identities')) return collect();
            $identitySchoolIds = CentralFinanceSchoolStaffIdentity::on('mysql')
                ->where('central_user_id', $actor->id)
                ->where('status', 'active')
                ->pluck('school_id')
                ->map(fn ($id): int => (int) $id);
            $schoolIds = $schoolIds->intersect($identitySchoolIds)->values();
        } elseif ($principalType !== 'central_user' || $actor->getRawOriginal('school_id') !== null) {
            return collect();
        }

        $capability = $operate ? 'operate_finance' : 'view_reports';
        $contexts = collect();
        $groupUsers = FinanceGroupUser::on('mysql')
            ->where('central_user_id', $actor->id)
            ->where('status', 'active')
            ->whereHas('group', fn (Builder $group) => $group->where('status', 'active'))
            ->get();

        foreach ($groupUsers as $groupUser) {
            foreach ($schoolIds as $scopedSchoolId) {
                if ($this->groups->canAccessSchool($groupUser, $scopedSchoolId, $capability)) {
                    $contexts->push(['group_id' => (int) $groupUser->group_id, 'school_id' => $scopedSchoolId]);
                }
            }
        }

        return $contexts->unique(fn (array $context): string => $context['group_id'].':'.$context['school_id'])->values();
    }
}
