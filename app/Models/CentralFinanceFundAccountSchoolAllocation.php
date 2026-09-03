<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CentralFinanceFundAccountSchoolAllocation extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $connection = 'mysql';

    protected $fillable = [
        'fund_account_id', 'school_id', 'opening_allocation_amount',
        'effective_from', 'effective_to', 'status', 'is_active', 'assigned_by',
        'assignment_reason',
    ];

    protected $casts = [
        'opening_allocation_amount' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function fundAccount(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceFundAccount::class, 'fund_account_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    /** @param Builder<self> $query */
    public function scopeEffective(Builder $query, ?string $onDate = null): Builder
    {
        $onDate ??= now()->toDateString();
        return $query->where('is_active', true)->where('status', self::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $onDate)
            ->where(fn (Builder $dates) => $dates->whereNull('effective_to')->orWhereDate('effective_to', '>=', $onDate));
    }
}
