<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class CentralFinancePaymentRefund extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['refund_uuid','school_id','payment_id','original_receipt_id','fund_account_id','idempotency_key','refund_reference','amount','currency','reason','refunded_at','refunded_by'];
    protected $casts = ['amount' => 'decimal:4', 'refunded_at' => 'datetime'];
    protected static function booted(): void { static::updating(static fn (): never => throw new RuntimeException('Central Finance payment refunds are immutable.')); static::deleting(static fn (): never => throw new RuntimeException('Central Finance payment refunds are immutable.')); }
    public function payment(): BelongsTo { return $this->belongsTo(CentralFinancePayment::class, 'payment_id'); }
    public function originalReceipt(): BelongsTo { return $this->belongsTo(CentralFinanceReceipt::class, 'original_receipt_id'); }
    public function fundAccount(): BelongsTo { return $this->belongsTo(CentralFinanceFundAccount::class, 'fund_account_id'); }
}
