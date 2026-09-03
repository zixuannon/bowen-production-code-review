<?php

namespace App\Services;

use App\Models\StudentImportIdentity;
use App\Models\Students;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Owns the tenant-local School + Student Code identity. */
final class StudentCodeService
{
    public function normalize(mixed $value): string
    {
        $code = trim((string) $value);
        if ($code === '' || mb_strlen($code) > 100 || preg_match('/[\p{C}]/u', $code)) {
            throw ValidationException::withMessages([
                'student_code' => __('Student Code is required and must be valid text.'),
            ]);
        }

        return $code;
    }

    public function exists(int $schoolId, string $code): bool
    {
        return StudentImportIdentity::query()->where('school_id', $schoolId)
            ->where('student_code', $this->normalize($code))->exists();
    }

    public function assertAvailable(int $schoolId, mixed $value): string
    {
        $code = $this->normalize($value);
        if ($this->exists($schoolId, $code)) {
            throw ValidationException::withMessages([
                'student_code' => __('This Student Code is already in use for this School.'),
            ]);
        }

        return $code;
    }

    /** Student Code is stable once it has been assigned to a Student. */
    public function assign(Students $student, User $actor, mixed $value): StudentImportIdentity
    {
        $code = $this->normalize($value);
        $existing = StudentImportIdentity::query()->where('student_id', $student->id)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->student_code !== $code) {
                throw ValidationException::withMessages([
                    'student_code' => __('Student Code cannot be changed after it is assigned.'),
                ]);
            }
            return $existing;
        }

        $this->assertAvailable((int) $student->school_id, $code);

        return StudentImportIdentity::query()->create([
            'school_id' => (int) $student->school_id,
            'student_code' => $code,
            'student_id' => (int) $student->id,
            'user_id' => (int) $student->user_id,
            'created_by' => (int) $actor->id,
        ]);
    }
}
