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
    /**
     * The legacy paid-fee permission is the established equivalent for these
     * read/create payment entry points. This preserves existing tenant role
     * grants while new tenants receive the named Finance permissions.
     *
     * @var array<string, array<int, string>>
     */
    private const LEGACY_EQUIVALENTS = [
        'finance-dashboard-view' => ['fees-paid'],
        'finance-payment-view' => ['fees-paid'],
        'finance-payment-create' => ['fees-paid'],
        'finance-expense-view' => ['expense-list'],
        'finance-expense-create' => ['expense-create'],
        // Bank Accounts historically used the established expense-list grant.
        // Retain that read-only compatibility for existing tenant roles while
        // keeping Fund Account management a separately named capability.
        'finance-fund-account-view' => ['expense-list'],
    ];

    public function assert(User $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new AccessDeniedHttpException('You are not authorized for this finance operation.');
        }
    }

    public function can(User $user, string $permission): bool
    {
        return $user->can($permission)
            || $user->canany(self::LEGACY_EQUIVALENTS[$permission] ?? []);
    }
}
