<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CentralFinanceSyncEvent extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'school_id', 'source_type', 'source_uuid', 'source_version',
        'idempotency_key', 'correlation_id', 'payload_hash', 'status',
        'attempts', 'processed_at', 'error_code',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
