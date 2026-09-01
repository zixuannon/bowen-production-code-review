<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Metadata/audit container only; it never represents a financial document. */
class CentralFinanceImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISCARDED = 'discarded';

    protected $connection = 'mysql';

    protected $fillable = [
        'batch_uuid', 'token', 'import_type', 'template_version', 'school_id',
        'uploaded_by', 'confirmed_by', 'file_name', 'file_hash', 'preview_data',
        'summary', 'status', 'total_rows', 'valid_rows', 'error_rows', 'expires_at',
        'confirmed_at', 'failure_reason',
    ];

    protected $casts = [
        'preview_data' => 'array', 'summary' => 'array', 'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->batch_uuid ??= (string) Str::uuid();
            $batch->token ??= (string) Str::uuid();
        });
    }
}
