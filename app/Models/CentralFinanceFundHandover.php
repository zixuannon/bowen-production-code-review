<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Support\CentralFinanceCurrency;

class CentralFinanceFundHandover extends Model
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $connection = 'mysql';

    protected $fillable = [
        'handover_uuid', 'school_id', 'source_account_id', 'destination_account_id',
        'sender_user_id', 'receiver_user_id', 'idempotency_key', 'reference_no',
        'handover_date', 'currency', 'amount', 'status', 'requested_by',
        'resolved_by', 'resolved_at', 'resolution_reason', 'internal_transfer_id',
    ];

    protected $casts = ['handover_date' => 'date', 'resolved_at' => 'datetime', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::creating(function (self $handover): void {
            $handover->handover_uuid ??= (string) Str::uuid();
            $handover->currency = CentralFinanceCurrency::normalize((string) $handover->currency);
        });
    }
}
