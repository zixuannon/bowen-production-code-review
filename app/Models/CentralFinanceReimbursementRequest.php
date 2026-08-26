<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class CentralFinanceReimbursementRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const CANCELLED = 'cancelled';

    protected $connection = 'mysql';

    protected $fillable = [
        'request_uuid', 'school_id', 'category_id', 'idempotency_key',
        'reference_no', 'currency', 'amount', 'description', 'submission_reason', 'status',
        'requested_by', 'approved_by', 'approved_at', 'approval_reason', 'expense_id',
        'rejected_by', 'rejected_at', 'rejection_reason', 'withdrawn_by', 'withdrawn_at',
        'withdrawal_reason', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = ['amount' => 'decimal:4', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'withdrawn_at' => 'datetime', 'cancelled_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->request_uuid ??= (string) Str::uuid();
            if ((float) $request->amount <= 0 || !preg_match('/^[A-Z]{3}$/', (string) $request->currency)) {
                throw new InvalidArgumentException('Central reimbursement amount or currency is invalid.');
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceCategory::class, 'category_id');
    }
}
