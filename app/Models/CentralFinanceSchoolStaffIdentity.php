<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CentralFinanceSchoolStaffIdentity extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['identity_uuid', 'school_id', 'tenant_user_uuid', 'central_user_id', 'status'];

    public function principal(): BelongsTo { return $this->belongsTo(CentralFinanceUser::class, 'central_user_id'); }
    public function school(): BelongsTo { return $this->belongsTo(School::class, 'school_id'); }
}
