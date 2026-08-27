<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use App\Support\CentralFinanceCurrency;

/**
 * Central Ledger is append-only. Corrections belong to a future approved
 * adjustment/reversal document, never a silent update/delete of this row.
 */
class CentralFinanceLedgerEntry extends Model
{
    use HasFactory;

    public const TYPE_OPERATING_INCOME = 'operating_income';
    public const TYPE_OPERATING_EXPENSE = 'operating_expense';
    public const TYPE_OPERATING_INCOME_REVERSAL = 'operating_income_reversal';
    public const TYPE_OPERATING_EXPENSE_REVERSAL = 'operating_expense_reversal';
    public const TYPE_INTERNAL_TRANSFER = 'internal_transfer';

    protected $connection = 'mysql';

    protected $fillable = [
        'entry_uuid', 'school_id', 'fund_account_id', 'entry_date', 'occurred_at',
        'source_type', 'source_id', 'source_line', 'reference_no',
        'transaction_type', 'currency', 'money_in', 'money_out',
        'operating_income', 'operating_expense', 'memo', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'occurred_at' => 'datetime',
        'money_in' => 'decimal:4',
        'money_out' => 'decimal:4',
        'operating_income' => 'decimal:4',
        'operating_expense' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void { $entry->currency = CentralFinanceCurrency::normalize((string) $entry->currency); });
        static::updating(static fn (): never => throw new RuntimeException('Central Finance Ledger entries are append-only.'));
        static::deleting(static fn (): never => throw new RuntimeException('Central Finance Ledger entries are append-only.'));
    }

    public function fundAccount(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceFundAccount::class, 'fund_account_id');
    }
}
