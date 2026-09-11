<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StudentFeeAssignmentItem extends Model
{
    public const ACTIVE = 'active';
    public const CANCELLED = 'cancelled';
    public const FEES_CLASS_TYPE = 'fees_class_type';

    protected $fillable = ['uuid', 'student_fee_assignment_id', 'fee_id', 'fees_class_type_id', 'fees_type_id', 'description_snapshot', 'due_date_snapshot', 'amount_snapshot', 'currency_snapshot', 'exchange_rate_snapshot', 'amount_mmk_snapshot', 'optional_snapshot', 'source_type', 'source_id', 'status'];
    protected $casts = ['optional_snapshot' => 'boolean', 'amount_snapshot' => 'float', 'exchange_rate_snapshot' => 'float', 'amount_mmk_snapshot' => 'float', 'due_date_snapshot' => 'date'];

    protected static function booted(): void
    {
        $assertDraft = static function (StudentFeeAssignmentItem $item): void {
            if ($item->assignment()->where('status', StudentFeeAssignment::CONFIRMED)->exists()) {
                throw new \DomainException('Confirmed student receivable snapshots are immutable; use an explicit adjustment.');
            }
        };
        static::updating($assertDraft);
        static::deleting($assertDraft);
    }

    public function assignment()
    {
        return $this->belongsTo(StudentFeeAssignment::class, 'student_fee_assignment_id');
    }
}
