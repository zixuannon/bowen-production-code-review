<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Prevents a confirmed or in-flight Central import row from being replayed. */
class CentralFinanceImportRowReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_RELEASED = 'released';

    protected $connection = 'mysql';

    protected $fillable = ['batch_id', 'school_id', 'import_type', 'row_number', 'idempotency_key', 'status'];
}
