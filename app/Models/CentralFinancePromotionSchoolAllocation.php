<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

final class CentralFinancePromotionSchoolAllocation extends Model
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    protected $connection = 'mysql';
    protected $fillable = ['promotion_id', 'school_id', 'status'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id')->withTrashed();
    }

    protected static function booted(): void
    {
        static::deleting(static fn (): never => throw new RuntimeException('Promotion School allocations are retained for audit.'));
    }
}
