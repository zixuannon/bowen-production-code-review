<?php

namespace App\Models;

use App\Traits\DateFormatTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class BankTransfer extends Model
{
    use HasFactory, SoftDeletes, DateFormatTrait;

    protected $fillable = [
        'school_id',
        'from_account_id',
        'to_account_id',
        'amount',
        'transfer_date',
        'reference_no',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'transfer_date' => 'date',
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
     * Only completed transfers.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function from_account()
    {
        return $this->belongsTo(BankAccount::class, 'from_account_id');
    }

    public function to_account()
    {
        return $this->belongsTo(BankAccount::class, 'to_account_id');
    }
}
