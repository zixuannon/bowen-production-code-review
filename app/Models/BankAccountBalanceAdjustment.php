<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccountBalanceAdjustment extends Model
{
    protected $fillable = [
        'bank_account_id',
        'old_opening_balance',
        'new_opening_balance',
        'old_opening_balance_date',
        'new_opening_balance_date',
        'changed_by',
        'reason',
    ];

    protected $casts = [
        'old_opening_balance' => 'decimal:2',
        'new_opening_balance' => 'decimal:2',
        'old_opening_balance_date' => 'date',
        'new_opening_balance_date' => 'date',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
