<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'currency', 'amount', 'description', 'created_by', 'updated_by',
        'edit_reason', 'deleted_by', 'delete_reason',
    ];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::creating(function (self $expense): void {
            $expense->expense_uuid ??= (string) Str::uuid();
            if ((float) $expense->amount <= 0 || !preg_match('/^[A-Z]{3}$/', (string) $expense->currency)) {
                throw new InvalidArgumentException('Central Expense amount or currency is invalid.');
            }
        });
    }
}
