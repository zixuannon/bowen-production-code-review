<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class StudentFeeAssignment extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const INITIAL = 'initial';
    public const ADDITIONAL = 'additional';

    protected $fillable = ['uuid', 'school_id', 'student_id', 'academic_year_id', 'class_id', 'assignment_type', 'status', 'confirmed_at', 'confirmed_by'];
    protected $casts = ['confirmed_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(StudentFeeAssignmentItem::class);
    }

    public function student()
    {
        return $this->belongsTo(Students::class, 'student_id');
    }
}
