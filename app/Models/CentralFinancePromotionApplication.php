<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

final class CentralFinancePromotionApplication extends Model
{
    protected $connection = 'mysql';
    protected $fillable = [
        'application_uuid', 'school_id', 'receivable_id', 'promotion_id', 'promotion_scope_snapshot', 'student_profile_id_snapshot', 'fees_class_type_id_snapshot', 'adjustment_id', 'idempotency_key',
        'promotion_name_snapshot', 'promotion_code_snapshot', 'discount_type_snapshot', 'discount_value_snapshot',
        'gross_amount_snapshot', 'discount_amount', 'net_amount_snapshot', 'effective_date', 'reason', 'applied_by', 'applied_by_role_snapshot', 'applied_at',
    ];
    protected $casts = [
        'discount_value_snapshot' => 'decimal:4', 'gross_amount_snapshot' => 'decimal:4',
        'discount_amount' => 'decimal:4', 'net_amount_snapshot' => 'decimal:4', 'effective_date' => 'date', 'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $application): void { $application->application_uuid ??= (string) Str::uuid(); });
        static::updating(static fn (): never => throw new RuntimeException('Promotion applications are immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('Promotion applications are immutable.'));
    }

    public function promotion(): BelongsTo { return $this->belongsTo(CentralFinancePromotion::class, 'promotion_id'); }
    public function receivable(): BelongsTo { return $this->belongsTo(CentralFinanceReceivable::class, 'receivable_id'); }
    public function adjustment(): BelongsTo { return $this->belongsTo(CentralFinanceReceivableAdjustment::class, 'adjustment_id'); }
}
