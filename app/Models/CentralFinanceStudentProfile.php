<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentralFinanceStudentProfile extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'school_id', 'tenant_student_id', 'source_uuid', 'class_id',
        'class_section_id', 'class_name', 'section_name', 'admission_no', 'student_code',
        'student_name', 'guardian_name', 'guardian_email', 'guardian_mobile',
        'enrollment_status', 'tenant_user_status', 'source_updated_at',
        'source_deleted_at', 'last_synced_at',
    ];

    protected $casts = [
        'source_updated_at' => 'datetime',
        'source_deleted_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function receivables(): HasMany
    {
        return $this->hasMany(CentralFinanceReceivable::class, 'student_profile_id');
    }
}
