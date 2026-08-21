<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only evidence for the Central Fund Account opening baseline. */
final class CentralFinanceFundAccountOpeningBalanceAudit extends Model
{
    public const INITIAL = 'initial';
    public const ADJUSTMENT = 'adjustment';

    protected $connection = 'mysql';

    protected $fillable = [
        'fund_account_id', 'change_type', 'old_opening_balance', 'new_opening_balance',
        'effective_date', 'reason', 'created_by',
    ];

    protected $casts = [
        'old_opening_balance' => 'decimal:4',
        'new_opening_balance' => 'decimal:4',
        'effective_date' => 'date',
    ];
}
