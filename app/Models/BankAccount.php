<?php

namespace App\Models;

use App\Traits\DateFormatTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes, DateFormatTrait;

    protected $fillable = [
        'school_id',
        'account_name',
        'account_number',
        'bank_name',
        'account_type',
        'currency',
        'opening_balance',
        'opening_balance_date',
        'is_active',
        'is_default',
        'notes',
    ];

    protected $casts = [
        'opening_balance'       => 'decimal:2',
        'is_active'             => 'boolean',
        'is_default'            => 'boolean',
        'opening_balance_date'  => 'date',
    ];

    /**
     * Scope by school_id for multi-tenant isolation.
     */
    public function scopeOwner($query)
    {
        if (Auth::user()) {
            if (Auth::user()->hasRole('Super Admin')) {
                return $query;
            }

            if (Auth::user()->school_id) {
                return $query->where('school_id', Auth::user()->school_id);
            }
        }

        return $query;
    }

    /**
     * Only active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Compulsory fee payments into this account.
     */
    public function compulsory_fees()
    {
        return $this->hasMany(CompulsoryFee::class, 'bank_account_id');
    }

    /**
     * Optional fee payments into this account.
     */
    public function optional_fees()
    {
        return $this->hasMany(OptionalFee::class, 'bank_account_id');
    }

    /**
     * Expenses paid from this account.
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'bank_account_id');
    }

    /**
     * Transfers out of this account.
     */
    public function transfers_out()
    {
        return $this->hasMany(BankTransfer::class, 'from_account_id');
    }

    /**
     * Transfers into this account.
     */
    public function transfers_in()
    {
        return $this->hasMany(BankTransfer::class, 'to_account_id');
    }

    public function getCreatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('created_at'));
    }

    public function getUpdatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('updated_at'));
    }
}
