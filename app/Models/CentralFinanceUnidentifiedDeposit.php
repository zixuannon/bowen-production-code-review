<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

/** Group-level physical cash receipt awaiting School/Student identification. */
final class CentralFinanceUnidentifiedDeposit extends Model
{
    public const UNIDENTIFIED = 'unidentified';
    public const PARTIALLY_APPLIED = 'partially_applied';
    public const APPLIED = 'applied';
    public const REVERSED = 'reversed';

    protected $connection = 'mysql';

    protected $fillable = [
        'deposit_uuid', 'group_id', 'fund_account_id', 'idempotency_key', 'bank_reference',
        'description', 'known_payer', 'amount', 'currency', 'status', 'received_date',
        'recorded_at', 'recorded_by', 'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = ['amount' => 'decimal:4', 'received_date' => 'date', 'recorded_at' => 'datetime', 'reversed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $deposit): void { $deposit->deposit_uuid ??= (string) Str::uuid(); });
        static::updating(function (self $deposit): void {
            // The original physical-receipt facts are immutable. Matching can
            // advance only its lifecycle status; a future reversal may add
            // its own immutable reversal facts without reopening the deposit.
            $allowed = ['status', 'reversed_at', 'reversed_by', 'reversal_reason', 'updated_at'];
            if (array_diff(array_keys($deposit->getDirty()), $allowed) !== []) {
                throw new RuntimeException('Unidentified deposits cannot be edited after recording.');
            }
        });
        static::deleting(static fn (): never => throw new RuntimeException('Unidentified deposits are immutable accounting documents.'));
    }

    public function fundAccount(): BelongsTo { return $this->belongsTo(CentralFinanceFundAccount::class, 'fund_account_id'); }
    public function allocations(): HasMany { return $this->hasMany(CentralFinanceUnidentifiedDepositAllocation::class, 'unidentified_deposit_id'); }
}
