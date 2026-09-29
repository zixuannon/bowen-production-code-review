<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/** Immutable later identification/settlement of an Unidentified Deposit. */
final class CentralFinanceUnidentifiedDepositAllocation extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'allocation_uuid', 'unidentified_deposit_id', 'school_id', 'student_profile_id',
        'receivable_id', 'idempotency_key', 'amount', 'currency', 'matched_at', 'matched_by', 'reason',
    ];

    protected $casts = ['amount' => 'decimal:4', 'matched_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void { $allocation->allocation_uuid ??= (string) Str::uuid(); });
        static::updating(static fn (): never => throw new RuntimeException('Unidentified deposit allocations are immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('Unidentified deposit allocations are immutable.'));
    }

    public function deposit(): BelongsTo { return $this->belongsTo(CentralFinanceUnidentifiedDeposit::class, 'unidentified_deposit_id'); }
    public function receivable(): BelongsTo { return $this->belongsTo(CentralFinanceReceivable::class, 'receivable_id'); }
}
