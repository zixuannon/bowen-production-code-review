<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Central Fund Account scope is explicit. Roles alone do not silently grant
 * operational custody of a Fund Account.
 */
final class CentralFinanceFundAccountScopeService
{
    /** @return Builder<CentralFinanceFundAccount> */
    public function visibleAccounts(User $actor): Builder
    {
        return CentralFinanceFundAccount::on('mysql')->whereHas('authorizedUsers', function (Builder $query) use ($actor): void {
            $query->where('users.id', $actor->id)->where('central_finance_fund_account_users.can_view', true);
        });
    }

    public function assertCanView(User $actor, CentralFinanceFundAccount $account): void
    {
        $this->assertAssignment($actor, $account, false);
    }

    public function assertCanOperate(User $actor, CentralFinanceFundAccount $account): void
    {
        $this->assertAssignment($actor, $account, true);
    }

    private function assertAssignment(User $actor, CentralFinanceFundAccount $account, bool $operate): void
    {
        $account = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($account->id);
        $assignment = $account->authorizedUsers()->where('users.id', $actor->id)->first();
        if ($assignment === null || !$assignment->pivot->can_view || ($operate && !$assignment->pivot->can_operate)) {
            throw new AuthorizationException('The central actor is not authorized for this Fund Account.');
        }
    }
}
