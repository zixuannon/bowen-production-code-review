<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/** Tenant-local, append-only audit evidence for academic and master-data lifecycle actions. */
final class SchoolRecordLifecycleAudit extends Model
{
    protected $connection = 'school';

    public const DEACTIVATE = 'deactivate';
    public const REACTIVATE = 'reactivate';
    public const WITHDRAW = 'withdraw';
    public const ARCHIVE = 'archive';

    public $timestamps = false;

    protected $fillable = [
        'uuid', 'subject_type', 'subject_id', 'subject_uuid', 'action', 'reason',
        'actor_user_id', 'actor_user_uuid', 'metadata', 'created_at',
    ];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $audit): void {
            $audit->uuid ??= (string) Str::uuid();
        });
        static::updating(static fn (): never => throw new RuntimeException('School lifecycle audits are append-only.'));
        static::deleting(static fn (): never => throw new RuntimeException('School lifecycle audits are append-only.'));
    }
}
