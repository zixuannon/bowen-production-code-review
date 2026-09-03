<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable external identity for Student Import V2.
 *
 * This is deliberately tenant-local.  It does not replace a Student UUID or
 * legacy admission number; it prevents an uploaded School + Student Code from
 * creating a second Student, Guardian, assignment, or receivable.
 */
final class StudentImportIdentity extends Model
{
    /** This identity is tenant-local and must never fall back to main/Central. */
    protected $connection = 'school';

    protected $fillable = [
        'school_id',
        'student_code',
        'student_id',
        'user_id',
        'created_by',
    ];

    public function student()
    {
        return $this->belongsTo(Students::class, 'student_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
