<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CentralFinanceReceivableSyncEvent extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'school_id', 'student_profile_id', 'source_type', 'source_id',
        'source_version', 'idempotency_key', 'payload_hash', 'status',
        'attempts', 'processed_at', 'error_code',
    ];

    protected $casts = ['processed_at' => 'datetime'];
}
