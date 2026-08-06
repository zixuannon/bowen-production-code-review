<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * FeeImportBatch
 *
 * Unified table for Excel import preview tokens and audit trails.
 *
 * Status lifecycle:
 *   pending    → processing (via lockForUpdate in confirm)
 *   processing → completed  (all financial writes committed)
 *   processing → failed     (error during processing)
 *   completed  → final (no transition, P0 does not retry)
 *   failed     → final (must re-upload)
 */
class FeeImportBatch extends Model
{
    use HasFactory;

    protected $connection = 'school';

    protected $table = 'fee_import_batches';

    protected $fillable = [
        'token',
        'school_id',
        'imported_by',
        'file_name',
        'file_hash',
        'preview_data',
        'status',
        'total_rows',
        'success_rows',
        'duplicate_rows',
        'error_rows',
        'imported_rows',
        'skipped_rows',
        'expired_at',
        'consumed_at',
        'last_error',
    ];

    protected $casts = [
        'preview_data' => 'array',
        'total_rows'    => 'integer',
        'success_rows'  => 'integer',
        'duplicate_rows'=> 'integer',
        'error_rows'    => 'integer',
        'imported_rows' => 'integer',
        'skipped_rows'  => 'integer',
        'expired_at'    => 'datetime',
        'consumed_at'   => 'datetime',
    ];

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';

    public function scopeOwner($query)
    {
        if (Auth::check()) {
            if (Auth::user()->hasRole('Super Admin')) {
                return $query;
            }
            if (Auth::user()->school_id) {
                return $query->where('school_id', Auth::user()->school_id);
            }
        }
        return $query;
    }

    public function importedBy()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function compulsoryFees()
    {
        return $this->hasMany(CompulsoryFee::class, 'import_batch_id');
    }
}
