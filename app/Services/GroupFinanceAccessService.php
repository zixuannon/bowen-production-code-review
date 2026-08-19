<?php

namespace App\Services;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Resolves the central control-plane identity that may use Group Finance.
 *
 * A central user never receives tenant access merely because they have a
 * similarly named tenant role.  Every Group Finance request still requires
 * an active FinanceGroupUser and an explicit capability/school scope.
 */
class GroupFinanceAccessService
{
    public function __construct(private readonly FinanceGroupScopeService $scope)
    {
    }

    /** @return Collection<int, FinanceGroupUser> */
    public function reportUsersFor(User $authenticated): Collection
    {
        if ($authenticated->school_id !== null) {
            return collect();
        }

        $central = User::on('mysql')->find($authenticated->id);
        if (!$central || $central->school_id !== null) {
            return collect();
        }

        return FinanceGroupUser::query()
            ->with('group')
            ->where('central_user_id', $central->id)
            ->where('status', 'active')
            ->whereHas('group', fn ($query) => $query->where('status', 'active'))
            ->get()
            ->filter(fn (FinanceGroupUser $groupUser) => $this->scope->accessibleSchools($groupUser, 'view_reports')->isNotEmpty())
            ->values();
    }

    public function hasReportAccess(User $authenticated): bool
    {
        return $this->reportUsersFor($authenticated)->isNotEmpty();
    }

    public function firstReportGroup(User $authenticated): ?FinanceGroup
    {
        return $this->reportUsersFor($authenticated)->first()?->group;
    }

    public function reportUserFor(User $authenticated, FinanceGroup $group): FinanceGroupUser
    {
        $groupUser = $this->reportUsersFor($authenticated)
            ->first(fn (FinanceGroupUser $candidate) => $candidate->group_id === $group->id);

        if (!$groupUser) {
            throw new AuthorizationException('This central user has no Group Finance reporting scope.');
        }

        return $groupUser;
    }
}
