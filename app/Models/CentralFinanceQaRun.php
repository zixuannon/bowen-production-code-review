<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

final class CentralFinanceQaRun extends Model
{
    public const PREPARING = 'preparing';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const ARCHIVED = 'archived';

    public const STATUSES = [self::PREPARING, self::ACTIVE, self::COMPLETED, self::ARCHIVED];

    protected $connection = 'mysql';

    protected $fillable = [
        'run_uuid', 'school_id', 'run_number', 'label', 'status', 'created_by',
        'activated_at', 'completed_at', 'completed_by', 'completion_summary',
        'archived_at', 'archived_by', 'archive_reason',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
        'completion_summary' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->run_uuid ??= (string) Str::uuid();
            if (!in_array($run->status, self::STATUSES, true)) {
                throw new RuntimeException('Invalid QA Run status.');
            }
        });

        static::updating(function (self $run): void {
            if (!in_array($run->status, self::STATUSES, true)) {
                throw new RuntimeException('Invalid QA Run status.');
            }
            foreach (['run_uuid', 'school_id', 'run_number', 'created_by'] as $immutable) {
                if ($run->isDirty($immutable)) {
                    throw new RuntimeException('QA Run identity is immutable.');
                }
            }
            if ($run->isDirty('status')) {
                $allowed = [
                    self::PREPARING => self::ACTIVE,
                    self::ACTIVE => self::COMPLETED,
                    self::COMPLETED => self::ARCHIVED,
                ];
                if (($allowed[(string) $run->getOriginal('status')] ?? null) !== $run->status) {
                    throw new RuntimeException('QA Run lifecycle transition is invalid.');
                }
            }
        });

        static::deleting(static fn (): never => throw new RuntimeException('QA Runs are retained permanently.'));
    }

    public function records(): HasMany
    {
        return $this->hasMany(CentralFinanceQaRunRecord::class, 'qa_run_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }
}
