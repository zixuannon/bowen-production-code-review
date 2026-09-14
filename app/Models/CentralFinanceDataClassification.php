<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

final class CentralFinanceDataClassification extends Model
{
    public const PRODUCTION = 'production';
    public const QA_TEST = 'qa_test';
    public const ARCHIVED = 'archived';
    public const VALUES = [self::PRODUCTION, self::QA_TEST, self::ARCHIVED];

    protected $connection = 'mysql';
    protected $fillable = [
        'classification_uuid', 'school_id', 'subject_scope', 'subject_type', 'subject_id',
        'classification', 'reason', 'classified_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->classification_uuid ??= (string) Str::uuid();
            if (!in_array($record->classification, self::VALUES, true)) {
                throw new RuntimeException('Invalid Central Finance data classification.');
            }
        });
        static::updating(function (self $record): void {
            if (!in_array($record->classification, self::VALUES, true)) {
                throw new RuntimeException('Invalid Central Finance data classification.');
            }
            foreach (['school_id', 'subject_scope', 'subject_type', 'subject_id', 'classification_uuid'] as $immutable) {
                if ($record->isDirty($immutable)) {
                    throw new RuntimeException('Central Finance classification identity is immutable.');
                }
            }
        });
        static::deleting(static fn (): never => throw new RuntimeException('Central Finance data classifications cannot be deleted.'));
    }

    public function audits(): HasMany
    {
        return $this->hasMany(CentralFinanceDataClassificationAudit::class, 'classification_id');
    }
}
