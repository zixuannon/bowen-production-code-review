<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceGroupUserScope extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'group_user_id',
        'school_id',
        'scope_type',
        'capability',
        'scope_key',
        'status',
        'active_from',
        'active_to',
    ];

    protected $casts = [
        'active_from' => 'date',
        'active_to' => 'date',
    ];

    public function groupUser(): BelongsTo
    {
        return $this->belongsTo(FinanceGroupUser::class, 'group_user_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id')->withTrashed();
    }
}
