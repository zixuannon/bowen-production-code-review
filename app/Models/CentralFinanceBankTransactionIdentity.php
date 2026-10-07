<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** A permanent claim on one physical bank receipt, including reversed receipts. */
final class CentralFinanceBankTransactionIdentity extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'fund_account_id', 'currency', 'identity_hash', 'identity_namespace',
        'normalized_identity', 'manual_reason', 'source_type', 'source_id',
        'amount', 'payload_hash',
    ];

    protected $casts = ['amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new RuntimeException('Bank transaction identities are immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('Bank transaction identities cannot be released.'));
    }
}
