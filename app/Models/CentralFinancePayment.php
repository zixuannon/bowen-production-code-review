<?php
namespace App\Models;
use App\Support\CentralFinanceCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
class CentralFinancePayment extends Model {
    protected $connection='mysql';
    protected $fillable=['payment_uuid','school_id','receivable_id','fund_account_id','idempotency_key','payment_reference','payment_method','currency','amount','paid_at','received_by'];
    protected $casts=['amount'=>'decimal:4','paid_at'=>'datetime'];
    protected static function booted(): void { static::creating(function (self $payment): void { $payment->currency = CentralFinanceCurrency::normalize((string) $payment->currency); }); static::updating(static fn(): never => throw new RuntimeException('Central Finance payments are immutable.')); static::deleting(static fn(): never => throw new RuntimeException('Central Finance payments are immutable.')); }
    public function receivable(): BelongsTo { return $this->belongsTo(CentralFinanceReceivable::class, 'receivable_id'); }
    public function receipt(): HasOne { return $this->hasOne(CentralFinanceReceipt::class, 'payment_id'); }
    public function refunds(): HasMany { return $this->hasMany(CentralFinancePaymentRefund::class, 'payment_id'); }
    public function fundAccount(): BelongsTo { return $this->belongsTo(CentralFinanceFundAccount::class, 'fund_account_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(CentralFinanceUser::class, 'received_by'); }
}
