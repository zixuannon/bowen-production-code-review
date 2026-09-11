<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FeePaymentFxSnapshot extends Model
{
    public const COMPULSORY = 'compulsory';
    public const OPTIONAL = 'optional';

    protected $fillable = [
        'uuid', 'school_id', 'fees_paid_id', 'bank_account_id', 'payment_type',
        'transaction_currency', 'original_amount', 'exchange_rate_snapshot',
        'amount_mmk', 'paid_at',
    ];

    protected $casts = [
        'original_amount' => 'decimal:4',
        'exchange_rate_snapshot' => 'decimal:8',
        'amount_mmk' => 'decimal:4',
        'paid_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \DomainException('Fee payment FX snapshots are immutable.');
        });
        static::deleting(static function (): never {
            throw new \DomainException('Fee payment FX snapshots are immutable.');
        });
    }
}
