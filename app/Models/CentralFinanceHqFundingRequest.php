<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Support\CentralFinanceCurrency;

class CentralFinanceHqFundingRequest extends Model
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const HQ_TO_SCHOOL = 'hq_to_school';
    public const SCHOOL_TO_HQ = 'school_to_hq';

    protected $connection = 'mysql';

    protected $fillable = [
        'funding_uuid', 'school_id', 'source_account_id', 'destination_account_id',
        'direction', 'idempotency_key', 'reference_no', 'funding_date', 'currency',
        'amount', 'status', 'requested_by', 'resolved_by', 'resolved_at',
        'resolution_reason', 'internal_transfer_id',
    ];

    protected $casts = ['funding_date' => 'date', 'resolved_at' => 'datetime', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::creating(function (self $funding): void {
            $funding->funding_uuid ??= (string) Str::uuid();
            $funding->currency = CentralFinanceCurrency::normalize((string) $funding->currency);
        });
    }

    /** The two canonical accounts are retained for read-only statement context. */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceFundAccount::class, 'source_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceFundAccount::class, 'destination_account_id');
    }
}
