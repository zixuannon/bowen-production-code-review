<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

final class CentralFinanceQaRunRecord extends Model
{
    protected $connection = 'mysql';
    public $timestamps = false;

    protected $fillable = [
        'qa_run_id', 'school_id', 'subject_scope', 'subject_type', 'subject_id', 'source_identity',
    ];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new RuntimeException('QA Run membership is immutable.'));
        static::deleting(static fn (): never => throw new RuntimeException('QA Run membership is retained permanently.'));
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceQaRun::class, 'qa_run_id');
    }
}
