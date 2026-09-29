<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/** Immutable Front Desk allocation proposal, later materialized exactly once. */
final class CentralFinancePendingCollectionAllocation extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'allocation_uuid', 'pending_collection_id', 'receivable_id', 'school_id', 'student_profile_id',
        'description_snapshot', 'unit_price_snapshot', 'quantity_snapshot', 'gross_amount_snapshot',
        'promotion_amount_snapshot', 'net_due_snapshot', 'paid_before_snapshot',
        'outstanding_before_snapshot', 'amount', 'currency',
    ];

    protected $casts = [
        'unit_price_snapshot' => 'decimal:4', 'quantity_snapshot' => 'integer',
        'gross_amount_snapshot' => 'decimal:4', 'promotion_amount_snapshot' => 'decimal:4',
        'net_due_snapshot' => 'decimal:4', 'paid_before_snapshot' => 'decimal:4',
        'outstanding_before_snapshot' => 'decimal:4', 'amount' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            $allocation->allocation_uuid ??= (string) Str::uuid();
        });
        static::updating(static fn (): never => throw new RuntimeException('Pending collection allocation proposals are immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('Pending collection allocation proposals are immutable.'));
    }

    public function pendingCollection(): BelongsTo { return $this->belongsTo(CentralFinancePendingCollection::class, 'pending_collection_id'); }
    public function receivable(): BelongsTo { return $this->belongsTo(CentralFinanceReceivable::class, 'receivable_id'); }
}
