<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseImportBatch extends Model
{
    protected $connection = 'school';
    protected $fillable = ['token', 'school_id', 'imported_by', 'file_name', 'file_hash', 'preview_data', 'imported_expense_ids', 'status', 'total_rows', 'valid_rows', 'error_rows', 'imported_rows', 'expired_at', 'consumed_at', 'last_error'];
    protected $casts = ['preview_data' => 'array', 'imported_expense_ids' => 'array', 'expired_at' => 'datetime', 'consumed_at' => 'datetime'];
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
}
