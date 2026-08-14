<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class FinanceAccountAccessService
{
    public function canManageAll(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'School Admin', 'Head Finance']);
    }

    public function scope(User $user): Builder
    {
        $query = BankAccount::query()->where('school_id', $user->school_id);
        if (!$this->canManageAll($user)) {
            $query->whereHas('authorized_users', fn (Builder $users) => $users->whereKey($user->id));
        }
        return $query;
    }

    public function accessibleAccounts(User $user): Builder
    {
        return $this->scope($user);
    }

    public function authorize(User $user, int $accountId): BankAccount
    {
        return $this->scope($user)->whereKey($accountId)->firstOrFail();
    }

    /**
     * Resolve an account that may receive a new financial movement.
     *
     * The normal Eloquent soft-delete scope is intentionally retained here;
     * an inactive account is also not a valid source or destination even if a
     * legacy pivot assignment still exists for it.
     */
    public function authorizeActive(User $user, int $accountId): BankAccount
    {
        return $this->scope($user)->active()->whereKey($accountId)->firstOrFail();
    }

    public function canAccessAccount(User $user, BankAccount $account): bool
    {
        return $account->school_id === $user->school_id && $this->scope($user)->whereKey($account->id)->exists();
    }

    public function canManageAccountAssignments(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'School Admin', 'Head Finance']);
    }

    /**
     * Creating, editing, or retiring a Fund Account changes the finance control
     * plane. It is deliberately the same elevated capability as managing its
     * user assignments; a Cashier may view an assigned account but may not
     * administer it.
     */
    public function canManageAccounts(User $user): bool
    {
        return $this->canManageAccountAssignments($user);
    }

    public function canModifyOpeningBalance(User $user): bool
    {
        return $this->mayChangeOpeningBalance($user);
    }

    public function mayChangeOpeningBalance(User $user): bool
    {
        return $this->canManageAll($user);
    }
}
