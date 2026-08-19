<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceGroupHqAccountAdjustment extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['hq_account_id', 'amount', 'balance_before', 'balance_after', 'adjustment_date', 'reason', 'created_by_group_user_id'];
    protected $casts = ['amount'=>'decimal:2', 'balance_before'=>'decimal:2', 'balance_after'=>'decimal:2', 'adjustment_date'=>'date'];
    public function hqAccount(): BelongsTo { return $this->belongsTo(FinanceGroupHqAccount::class, 'hq_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(FinanceGroupUser::class, 'created_by_group_user_id'); }
}
