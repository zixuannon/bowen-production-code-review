<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceGroupSchool extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'group_id',
        'school_id',
        'status',
        'active_from',
        'active_to',
    ];

    protected $casts = [
        'active_from' => 'date',
        'active_to' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinanceGroup::class, 'group_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id')->withTrashed();
    }
}
