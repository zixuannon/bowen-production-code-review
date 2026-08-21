<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
class CentralFinancePayment extends Model {
    protected $connection='mysql';
    protected $fillable=['payment_uuid','school_id','receivable_id','fund_account_id','idempotency_key','payment_reference','payment_method','currency','amount','paid_at','received_by'];
    protected $casts=['amount'=>'decimal:4','paid_at'=>'datetime'];
    protected static function booted(): void { static::updating(static fn(): never => throw new RuntimeException('Central Finance payments are immutable.')); static::deleting(static fn(): never => throw new RuntimeException('Central Finance payments are immutable.')); }
}
