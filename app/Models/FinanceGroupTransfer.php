<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceGroupTransfer extends Model
{
    public const DIRECTION_HQ_TO_SCHOOL = 'HQ_TO_SCHOOL';
    public const DIRECTION_SCHOOL_TO_HQ = 'SCHOOL_TO_HQ';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'mysql';

    protected $fillable = [
        'group_id', 'school_id', 'hq_account_id', 'tenant_bank_account_id', 'direction', 'purpose', 'amount',
        'transfer_date', 'reference_no', 'notes', 'status', 'requested_by_group_user_id', 'requested_at',
        'confirmed_by_group_user_id', 'confirmed_at', 'rejected_by_group_user_id', 'rejected_at', 'rejection_reason',
        'cancelled_by_group_user_id', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'transfer_date' => 'date', 'requested_at' => 'datetime', 'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function group(): BelongsTo { return $this->belongsTo(FinanceGroup::class, 'group_id'); }
    public function school(): BelongsTo { return $this->belongsTo(School::class, 'school_id')->withTrashed(); }
    public function hqAccount(): BelongsTo { return $this->belongsTo(FinanceGroupHqAccount::class, 'hq_account_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(FinanceGroupUser::class, 'requested_by_group_user_id'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(FinanceGroupUser::class, 'confirmed_by_group_user_id'); }
    public function scopeConfirmed($query) { return $query->where('status', self::STATUS_CONFIRMED); }
}
