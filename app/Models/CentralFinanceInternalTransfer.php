<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use RuntimeException;
use App\Support\CentralFinanceCurrency;

/** A confirmed canonical Central Finance internal movement. */
class CentralFinanceInternalTransfer extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'transfer_uuid', 'school_id', 'source_account_id', 'destination_account_id',
        'source_type', 'source_id', 'reference_no', 'transfer_date', 'currency',
        'amount', 'status', 'created_by', 'confirmed_by', 'confirmed_at',
        'reversal_of_transfer_id', 'reversal_reason', 'reversed_at', 'reversed_by_central_user_id',
    ];

    protected $casts = ['transfer_date' => 'date', 'confirmed_at' => 'datetime', 'reversed_at' => 'datetime', 'amount' => 'decimal:4'];

    public function reversalOfTransfer(): BelongsTo { return $this->belongsTo(self::class, 'reversal_of_transfer_id'); }
    public function reversalTransfer(): HasOne { return $this->hasOne(self::class, 'reversal_of_transfer_id'); }

    /** Read-only presentation relation; never a transfer authorization path. */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceFundAccount::class, 'source_account_id');
    }

    /** Read-only presentation relation; never a transfer authorization path. */
    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceFundAccount::class, 'destination_account_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $transfer): void {
            $transfer->transfer_uuid ??= (string) Str::uuid();
            $transfer->currency = CentralFinanceCurrency::normalize((string) $transfer->currency);
        });
        static::updating(static fn (): never => throw new RuntimeException('Central internal transfers are immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('Central internal transfers are append-only.'));
    }
}
