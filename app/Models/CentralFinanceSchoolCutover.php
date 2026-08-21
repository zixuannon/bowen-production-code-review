<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CentralFinanceSchoolCutover extends Model
{
    public const LEGACY = 'legacy';
    public const READY = 'ready';
    public const CENTRAL = 'central';

    protected $connection = 'mysql';

    protected $fillable = ['school_id', 'status', 'cutover_at'];

    protected $casts = ['cutover_at' => 'immutable_datetime'];
}
