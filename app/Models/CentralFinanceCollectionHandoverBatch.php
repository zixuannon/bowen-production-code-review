<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class CentralFinanceCollectionHandoverBatch extends Model
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under_review';
    public const HELD = 'held';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const CONFIRMED = 'confirmed';

    protected $connection = 'mysql';
    protected $fillable = [
        'handover_batch_uuid', 'school_id', 'collector_id', 'currency', 'payment_channel',
        'handover_type', 'expected_amount', 'actual_handed_over_amount', 'difference_amount',
        'status', 'idempotency_key', 'reference', 'submitted_by', 'submitted_at', 'reviewed_by',
        'reviewed_at', 'confirmed_by', 'confirmed_at', 'held_by', 'held_at', 'held_reason',
        'rejected_by', 'rejected_at', 'rejected_reason', 'cancelled_by', 'cancelled_at',
        'cancelled_reason', 'note',
    ];
    protected $casts = [
        'expected_amount' => 'decimal:4', 'actual_handed_over_amount' => 'decimal:4',
        'difference_amount' => 'decimal:4', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
        'confirmed_at' => 'datetime', 'held_at' => 'datetime', 'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->handover_batch_uuid ??= (string) Str::uuid();
        });
        static::updating(function (self $batch): void {
            if ($batch->getRawOriginal('status') === self::CONFIRMED) {
                throw new \RuntimeException('Confirmed handover batches are immutable.');
            }
        });
        static::deleting(static fn (): never => throw new \RuntimeException('Handover batches cannot be deleted.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(CentralFinanceCollectionHandoverItem::class, 'handover_batch_id');
    }
}
