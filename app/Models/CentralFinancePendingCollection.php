<?php

namespace App\Models;

use App\Support\CentralFinanceCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CentralFinancePendingCollection extends Model
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const HELD = 'held';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const CONFIRMED = 'confirmed';

    protected $connection = 'mysql';
    protected $fillable = [
        'pending_collection_uuid', 'school_id', 'student_profile_id', 'receivable_id',
        'intended_fund_account_id', 'confirmed_payment_id', 'idempotency_key', 'acknowledgement_no',
        'status', 'amount', 'currency', 'payment_method', 'payment_reference', 'note', 'collected_at',
        'collected_by', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_reason',
        'confirmed_by', 'confirmed_at',
    ];
    protected $casts = ['amount' => 'decimal:4', 'collected_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'confirmed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $collection): void {
            $collection->pending_collection_uuid ??= (string) Str::uuid();
            $collection->currency = CentralFinanceCurrency::normalize((string) $collection->currency);
            if ((float) $collection->amount <= 0) throw new InvalidArgumentException('Pending collection amount is invalid.');
        });
        static::updating(function (self $collection): void {
            if ($collection->getRawOriginal('status') === self::CONFIRMED) {
                throw new \RuntimeException('Confirmed pending collections are immutable.');
            }
        });
        static::deleting(static fn (): never => throw new \RuntimeException('Pending collections are lifecycle documents and cannot be deleted.'));
    }

    public function receivable(): BelongsTo { return $this->belongsTo(CentralFinanceReceivable::class, 'receivable_id'); }
    public function studentProfile(): BelongsTo { return $this->belongsTo(CentralFinanceStudentProfile::class, 'student_profile_id'); }
    public function intendedFundAccount(): BelongsTo { return $this->belongsTo(CentralFinanceFundAccount::class, 'intended_fund_account_id'); }
    public function confirmedPayment(): BelongsTo { return $this->belongsTo(CentralFinancePayment::class, 'confirmed_payment_id'); }
}
