<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CentralFinanceSchoolCutover extends Model
{
    public const LEGACY = 'legacy';
    public const READY = 'ready';
    public const CENTRAL = 'central';

    protected $connection = 'mysql';

    protected $fillable = ['school_id', 'status', 'ready_by', 'ready_at', 'cutover_at', 'approved_by'];

    protected $casts = ['ready_at' => 'immutable_datetime', 'cutover_at' => 'immutable_datetime'];
}
