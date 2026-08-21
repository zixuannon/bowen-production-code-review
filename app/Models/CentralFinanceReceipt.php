<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
class CentralFinanceReceipt extends Model {
    protected $connection='mysql';
    protected $fillable=['receipt_uuid','school_id','payment_id','receipt_no','issued_at','issued_by'];
    protected $casts=['issued_at'=>'datetime'];
    protected static function booted(): void { static::updating(static fn(): never => throw new RuntimeException('Central Finance receipts are immutable.')); static::deleting(static fn(): never => throw new RuntimeException('Central Finance receipts are immutable.')); }
}
