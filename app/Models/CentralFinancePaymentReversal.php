<?php

namespace App\Models;

use App\Support\CentralFinanceCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** A full, append-only correction of one invalid confirmed Payment. */
final class CentralFinancePaymentReversal extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['reversal_uuid', 'school_id', 'payment_id', 'original_receipt_id', 'fund_account_id', 'idempotency_key', 'reversal_reference', 'amount', 'currency', 'reason', 'effective_date', 'reversed_at', 'reversed_by'];
    protected $casts = ['amount' => 'decimal:4', 'effective_date' => 'date', 'reversed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $reversal): void { $reversal->currency = CentralFinanceCurrency::normalize((string) $reversal->currency); });
        static::updating(static fn (): never => throw new RuntimeException('Central Finance payment reversals are immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('Central Finance payment reversals are immutable.'));
    }

    public function payment(): BelongsTo { return $this->belongsTo(CentralFinancePayment::class, 'payment_id'); }
    public function originalReceipt(): BelongsTo { return $this->belongsTo(CentralFinanceReceipt::class, 'original_receipt_id'); }
    public function fundAccount(): BelongsTo { return $this->belongsTo(CentralFinanceFundAccount::class, 'fund_account_id'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(CentralFinanceUser::class, 'reversed_by'); }
}
