<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceGroupHqAccount extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'group_id', 'account_name', 'account_number', 'account_type', 'currency',
        'opening_balance', 'opening_balance_date', 'is_active', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo { return $this->belongsTo(FinanceGroup::class, 'group_id'); }
    public function authorizedGroupUsers(): BelongsToMany { return $this->belongsToMany(FinanceGroupUser::class, 'finance_group_hq_account_users', 'hq_account_id', 'group_user_id')->withTimestamps(); }
    public function transfers(): HasMany { return $this->hasMany(FinanceGroupTransfer::class, 'hq_account_id'); }
    public function adjustments(): HasMany { return $this->hasMany(FinanceGroupHqAccountAdjustment::class, 'hq_account_id'); }
    public function scopeActive($query) { return $query->where('is_active', true); }
}
