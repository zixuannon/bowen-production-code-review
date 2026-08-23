<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Central Finance account assignment always resolves the central identity
 * directory, even if a legacy tenant connection happens to be the request's
 * default connection.
 */
class CentralFinanceUser extends User
{
    protected $connection = 'mysql';

    protected $table = 'users';

    public function authorizedFundAccounts(): BelongsToMany
    {
        return $this->belongsToMany(CentralFinanceFundAccount::class, 'central_finance_fund_account_users', 'user_id', 'fund_account_id')
            ->withPivot(['can_view', 'can_operate'])->withTimestamps();
    }
}
