<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

final class CentralFinanceDataClassificationAudit extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'mysql';
    protected $fillable = [
        'audit_uuid', 'classification_id', 'school_id', 'subject_type', 'subject_id',
        'before_classification', 'after_classification', 'reason', 'actor_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $audit): void {
            $audit->audit_uuid ??= (string) Str::uuid();
        });
        static::updating(static fn (): never => throw new RuntimeException('Central Finance classification audits are append-only.'));
        static::deleting(static fn (): never => throw new RuntimeException('Central Finance classification audits are append-only.'));
    }
}
