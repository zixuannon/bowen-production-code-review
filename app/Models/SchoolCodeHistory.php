<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit-only School Code history. Never use this table for runtime identity. */
final class SchoolCodeHistory extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'school_id',
        'legacy_code',
        'canonical_code',
        'change_reason',
        'changed_at',
    ];

    protected $casts = ['changed_at' => 'immutable_datetime'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
