<?php

namespace App\Models;

use App\Traits\DateFormatTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A non-fee receipt. Student fee collection remains represented exclusively
 * by FeesPaid/CompulsoryFee/OptionalFee and must never be routed here.
 */
class OtherIncome extends Model
{
    use HasFactory, SoftDeletes, DateFormatTrait;

    protected $fillable = [
        'school_id', 'bank_account_id', 'date', 'payer', 'description', 'amount',
        'transaction_currency', 'original_amount', 'exchange_rate_snapshot', 'amount_mmk',
        'payment_method', 'reference_no', 'remark', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'original_amount' => 'decimal:4',
        'exchange_rate_snapshot' => 'decimal:8',
        'amount_mmk' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(static function (OtherIncome $income): void {
            foreach (['amount', 'transaction_currency', 'original_amount', 'exchange_rate_snapshot', 'amount_mmk'] as $field) {
                if ($income->isDirty($field)) throw new \DomainException('Receipt amount and FX snapshot fields are immutable.');
            }
        });
    }

    public function bank_account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
