<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CentralFinanceCollectionHandoverItem extends Model
{
    public const ATTACHED = 'attached';
    public const CONFIRMED = 'confirmed';
    protected $connection = 'mysql';
    protected $fillable = [
        'handover_batch_id', 'pending_collection_id', 'expected_amount_snapshot',
        'currency_snapshot', 'status', 'confirmed_payment_id', 'failure_reason',
    ];
    protected $casts = ['expected_amount_snapshot' => 'decimal:4'];

    protected static function booted(): void
    {
        static::deleting(static fn (): never => throw new \RuntimeException('Handover items cannot be deleted.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceCollectionHandoverBatch::class, 'handover_batch_id');
    }

    public function pendingCollection(): BelongsTo
    {
        return $this->belongsTo(CentralFinancePendingCollection::class, 'pending_collection_id');
    }

    public function confirmedPayment(): BelongsTo
    {
        return $this->belongsTo(CentralFinancePayment::class, 'confirmed_payment_id');
    }
}
