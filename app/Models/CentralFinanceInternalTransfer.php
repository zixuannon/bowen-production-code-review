<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/** A confirmed canonical Central Finance internal movement. */
class CentralFinanceInternalTransfer extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'transfer_uuid', 'school_id', 'source_account_id', 'destination_account_id',
        'source_type', 'source_id', 'reference_no', 'transfer_date', 'currency',
        'amount', 'status', 'created_by', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = ['transfer_date' => 'date', 'confirmed_at' => 'datetime', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::creating(function (self $transfer): void { $transfer->transfer_uuid ??= (string) Str::uuid(); });
        static::updating(static fn (): never => throw new RuntimeException('Central internal transfers are immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('Central internal transfers are append-only.'));
    }
}
