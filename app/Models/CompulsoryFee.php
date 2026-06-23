<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use App\Traits\DateFormatTrait;

class CompulsoryFee extends Model
{
    use HasFactory, SoftDeletes, DateFormatTrait;

    protected $fillable = [
        'student_id',
        'class_id',
        'payment_transaction_id',
        'type',
        'installment_id',
        'mode',
        'cheque_no',
        'amount',
        'due_charges',
        'fees_paid_id',
        'status',
        'date',
        'session_year_id',
        'school_id',
        'bank_account_id',
        'created_at',
        'updated_at'
    ];
    protected $appends = ['mode_name'];

    public function scopeOwner($query)
    {
        if(Auth::user()){
            if (Auth::user()->hasRole('Super Admin')) {
                return $query;
            }

            if (Auth::user()->hasRole('School Admin') || Auth::user()->hasRole('Teacher')) {
                return $query->where('school_id', Auth::user()->school_id);
            }

            if (Auth::user()->hasRole('Student')) {
                return $query->where('school_id', Auth::user()->school_id);
            }
        }

        return $query;
    }

    public function fees_paid() {
        return $this->belongsTo(FeesPaid::class, 'fees_paid_id')->withTrashed();
    }

    public function student(){
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }

    public function installment_fee(){
        return $this->belongsTo(FeesInstallment::class, 'installment_id');
    }

    public function advance_fees(){
        return $this->hasMany(FeesAdvance::class);
    }

    protected static $modeMap = [
        '1' => 'Cash',
        '2' => 'Cheque',
        '3' => 'Online',
        'Cash' => 'Cash',
        'Cheque' => 'Cheque',
        'Online' => 'Online',
        'KBZ Pay' => 'KBZ Pay',
        'Quick Pay' => 'Quick Pay',
        'KBZ Bank' => 'KBZ Bank',
        'AYA Bank' => 'AYA Bank',
        'YOMA BANK' => 'YOMA BANK',
        'CB Bank' => 'CB Bank',
        'Wechat Pay' => 'Wechat Pay',
        'Ali Pay' => 'Ali Pay',
    ];

    public function getModeNameAttribute(){
        return self::$modeMap[$this->mode] ?? $this->mode;
    }

    /**
     * Get the payment_transaction that owns the CompulsoryFee
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function payment_transaction()
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * Get the bank_account that owns the CompulsoryFee
     */
    public function bank_account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function getCreatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('created_at'));
    }

    public function getUpdatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('updated_at'));
    }

    public function getDateAttribute($value)
    {
        return $this->formatDateValue($value);
    }
    
}
