<?php

namespace App\Models;
use App\Support\CentralFinanceCurrency;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CentralFinanceOtherIncome extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $fillable = [
        'income_uuid', 'school_id', 'category_id', 'fund_account_id',
        'idempotency_key', 'reference_no', 'payment_method', 'income_date',
        'payer', 'currency', 'amount', 'description', 'created_by', 'updated_by',
        'edit_reason', 'deleted_by', 'delete_reason',
    ];

    protected $casts = ['income_date' => 'date', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::creating(function (self $income): void {
            $income->income_uuid ??= (string) Str::uuid();
            if ((float) $income->amount <= 0) {
                throw new InvalidArgumentException('Central Other Income amount or currency is invalid.');
            }
            $income->currency = CentralFinanceCurrency::normalize((string) $income->currency);
        });
    }
    public function category(): BelongsTo { return $this->belongsTo(CentralFinanceCategory::class, 'category_id'); }
}
