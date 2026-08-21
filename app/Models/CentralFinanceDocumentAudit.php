<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class CentralFinanceDocumentAudit extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'audit_uuid', 'school_id', 'document_type', 'document_id', 'action',
        'actor_id', 'reason', 'before_values', 'after_values',
    ];

    protected $casts = ['before_values' => 'array', 'after_values' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $audit): void {
            $audit->audit_uuid ??= (string) Str::uuid();
        });
        static::updating(static fn (): never => throw new RuntimeException('Central Finance audits are append-only.'));
        static::deleting(static fn (): never => throw new RuntimeException('Central Finance audits are append-only.'));
    }
}
