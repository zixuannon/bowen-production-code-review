<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceGroupUserTenantIdentity extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'group_user_id',
        'school_id',
        'tenant_user_id',
        'status',
    ];

    protected $casts = [
        'tenant_user_id' => 'integer',
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
