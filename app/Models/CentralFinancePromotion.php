<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

final class CentralFinancePromotion extends Model
{
    public const DRAFT = 'draft';
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const EXPIRED = 'expired';
    public const STATUSES = [self::DRAFT, self::ACTIVE, self::INACTIVE, self::EXPIRED];
    public const PERCENTAGE = 'percentage';
    public const FIXED = 'fixed';
    public const GENERAL = 'general';
    public const STUDENT_SPECIFIC = 'student_specific';
    public const SCOPES = [self::GENERAL, self::STUDENT_SPECIFIC];

    protected $connection = 'mysql';
    protected $fillable = [
        'promotion_uuid', 'group_id', 'student_profile_id', 'scope', 'creation_idempotency_key', 'name', 'code', 'description', 'student_discount_reason', 'discount_type', 'discount_value',
        'valid_from', 'valid_until', 'status', 'fee_scope', 'created_by', 'updated_by',
    ];
    protected $casts = ['discount_value' => 'decimal:4', 'valid_from' => 'date', 'valid_until' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (self $promotion): void {
            $promotion->promotion_uuid ??= (string) Str::uuid();
            if (!in_array($promotion->status, self::STATUSES, true)) {
                throw new RuntimeException('Invalid promotion status.');
            }
        });
        static::deleting(static fn (): never => throw new RuntimeException('Promotions cannot be deleted; deactivate them instead.'));
    }

    public function allocations(): HasMany { return $this->hasMany(CentralFinancePromotionSchoolAllocation::class, 'promotion_id'); }
    public function applications(): HasMany { return $this->hasMany(CentralFinancePromotionApplication::class, 'promotion_id'); }
    public function studentProfile() { return $this->belongsTo(CentralFinanceStudentProfile::class, 'student_profile_id'); }
}
