<?php

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Central permission checks for finance entry points.
 *
 * Permissions express what a user may request. FinanceAccountAccessService
 * and FundHandoverService still enforce tenant, account-scope, and custody
 * rules after a permission has been granted.
 */
class FinanceAuthorizationService
{
    public function assert(User $user, string $permission): void
    {
        if (!$user->can($permission)) {
            throw new AccessDeniedHttpException('You are not authorized for this finance operation.');
        }
    }

    public function can(User $user, string $permission): bool
    {
        return $user->can($permission);
    }
}
