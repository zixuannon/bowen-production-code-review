<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FundHandover extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'school_id', 'from_account_id', 'to_account_id', 'sender_id', 'receiver_id',
        'amount', 'handover_date', 'reference_no', 'notes', 'status', 'bank_transfer_id',
        'confirmed_at', 'confirmed_by', 'rejected_at', 'rejected_by', 'rejection_reason',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'handover_date' => 'date',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function from_account() { return $this->belongsTo(BankAccount::class, 'from_account_id'); }
    public function to_account() { return $this->belongsTo(BankAccount::class, 'to_account_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'receiver_id'); }
    public function bank_transfer() { return $this->belongsTo(BankTransfer::class, 'bank_transfer_id'); }
}
