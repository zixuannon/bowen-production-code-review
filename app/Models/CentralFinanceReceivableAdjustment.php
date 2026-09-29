<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo; use Illuminate\Database\Eloquent\Relations\HasOne; use RuntimeException;
class CentralFinanceReceivableAdjustment extends Model {
    protected $connection='mysql';
    protected $fillable=['adjustment_uuid','school_id','receivable_id','adjustment_type','amount_delta','amount_before','amount_after','idempotency_key','reason','adjusted_at','effective_date','adjusted_by'];
    protected $casts=['amount_delta'=>'decimal:4','amount_before'=>'decimal:4','amount_after'=>'decimal:4','adjusted_at'=>'datetime','effective_date'=>'date'];
    protected static function booted(): void { static::updating(static fn():never=>throw new RuntimeException('Central Finance receivable adjustments are immutable.'));static::deleting(static fn():never=>throw new RuntimeException('Central Finance receivable adjustments are immutable.')); }
    public function receivable():BelongsTo{return $this->belongsTo(CentralFinanceReceivable::class,'receivable_id');}
    public function promotionApplication():HasOne{return $this->hasOne(CentralFinancePromotionApplication::class,'adjustment_id');}
}
