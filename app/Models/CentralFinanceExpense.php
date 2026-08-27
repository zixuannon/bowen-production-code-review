<?php

namespace App\Models;
use App\Support\CentralFinanceCurrency;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CentralFinanceExpense extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $fillable = [
        'expense_uuid', 'school_id', 'category_id', 'fund_account_id',
        'idempotency_key', 'reference_no', 'payment_method', 'expense_date',
        'currency', 'amount', 'description', 'reimbursed_by', 'created_by', 'updated_by',
        'edit_reason', 'deleted_by', 'delete_reason',
    ];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::creating(function (self $expense): void {
            $expense->expense_uuid ??= (string) Str::uuid();
            if ((float) $expense->amount <= 0) {
                throw new InvalidArgumentException('Central Expense amount or currency is invalid.');
            }
            $expense->currency = CentralFinanceCurrency::normalize((string) $expense->currency);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceCategory::class, 'category_id');
    }
}
