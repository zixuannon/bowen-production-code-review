<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CentralFinancePreGoLiveResetManifest extends Model
{
    protected $connection = 'mysql';

    protected $guarded = [];

    protected $casts = [
        'pre_reset_counts' => 'array',
        'deleted_counts' => 'array',
        'executed_at' => 'datetime',
    ];
}
