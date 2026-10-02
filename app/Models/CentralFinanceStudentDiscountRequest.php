<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/** Immutable Front Desk request; only Head Finance may make its one decision. */
final class CentralFinanceStudentDiscountRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

    protected $connection = 'mysql';
    protected $fillable = [
        'request_uuid', 'idempotency_key', 'group_id', 'school_id', 'student_profile_id', 'fees_class_type_id',
        'tenant_assignment_uuid', 'tenant_assignment_item_uuid', 'requested_by', 'discount_type', 'discount_value',
        'gross_amount_snapshot', 'currency_snapshot', 'reason', 'effective_date', 'status', 'decision_by', 'decided_at',
        'rejection_reason', 'promotion_id',
    ];
    protected $casts = ['discount_value' => 'decimal:4', 'gross_amount_snapshot' => 'decimal:4', 'effective_date' => 'date', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->request_uuid ??= (string) Str::uuid();
            if (!in_array($request->status, self::STATUSES, true)) throw new RuntimeException('Invalid student Discount request status.');
        });
        static::updating(function (self $request): void {
            if ($request->getRawOriginal('status') !== self::PENDING) {
                throw new RuntimeException('A decided student Discount request is immutable.');
            }
            $changed = array_keys($request->getDirty());
            $allowed = ['status', 'decision_by', 'decided_at', 'rejection_reason', 'promotion_id', 'updated_at'];
            if (array_diff($changed, $allowed) !== []) {
                throw new RuntimeException('A student Discount request may not change after submission.');
            }
            if (!in_array($request->status, [self::APPROVED, self::REJECTED], true)) {
                throw new RuntimeException('A pending student Discount request requires an explicit decision.');
            }
        });
        static::deleting(static fn (): never => throw new RuntimeException('Student Discount requests cannot be deleted.'));
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceStudentProfile::class, 'student_profile_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }
}
