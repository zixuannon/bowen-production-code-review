<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceGroupUser extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'group_id',
        'central_user_id',
        'status',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinanceGroup::class, 'group_id');
    }

    public function centralUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'central_user_id')->withTrashed();
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(FinanceGroupUserScope::class, 'group_user_id');
    }

    public function tenantIdentities(): HasMany
    {
        return $this->hasMany(FinanceGroupUserTenantIdentity::class, 'group_user_id');
    }
}
