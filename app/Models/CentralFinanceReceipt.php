<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
class CentralFinanceReceipt extends Model {
    protected $connection='mysql';
    protected $fillable=['receipt_uuid','school_id','payment_id','receipt_no','issued_at','issued_by'];
    protected $casts=[];
    /** Receipt issuance is an audit event, displayed in the business timezone. */
    protected function issuedAt(): Attribute { return Attribute::make(
        get: fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value, 'Asia/Yangon'),
        set: fn ($value) => $value === null ? null : CarbonImmutable::parse((string) $value, 'Asia/Yangon')->format('Y-m-d H:i:s'),
    ); }
    protected static function booted(): void { static::updating(static fn(): never => throw new RuntimeException('Central Finance receipts are immutable.')); static::deleting(static fn(): never => throw new RuntimeException('Central Finance receipts are immutable.')); }
    public function payment(): BelongsTo { return $this->belongsTo(CentralFinancePayment::class, 'payment_id'); }
    public function refunds(): HasMany { return $this->hasMany(CentralFinancePaymentRefund::class, 'original_receipt_id'); }
    public function reversal(): HasOne { return $this->hasOne(CentralFinancePaymentReversal::class, 'original_receipt_id'); }
}
