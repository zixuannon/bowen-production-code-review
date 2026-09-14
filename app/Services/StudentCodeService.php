<?php

namespace App\Services;

use App\Models\StudentImportIdentity;
use App\Models\Students;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns the tenant-local School + Student Code identity. */
final class StudentCodeService
{
    public const MAX_NUMBER = 999999;

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

    public function normalizeImportReference(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $reference = trim((string) $value);
        if (mb_strlen($reference) > 100 || preg_match('/[\p{C}]/u', $reference)) {
            throw ValidationException::withMessages([
                'import_reference' => __('Import Reference must be valid text.'),
            ]);
        }
        return $reference;
    }

    public function existsByImportReference(int $schoolId, mixed $value): bool
    {
        $reference = $this->normalizeImportReference($value);
        return $reference !== null && StudentImportIdentity::query()
            ->where('school_id', $schoolId)->where('import_reference', $reference)->exists();
    }

    /**
     * Allocate the next immutable tenant Student Code under a School-scoped
     * row lock. The sequence is consumed in the caller's Student transaction.
     */
    public function assignGenerated(Students $student, ?User $actor = null, mixed $importReference = null): StudentImportIdentity
    {
        $schoolId = (int) $student->school_id;
        if ($schoolId < 1 || (int) $student->user_id < 1) {
            throw ValidationException::withMessages(['student_code' => __('A valid School Student is required.')]);
        }
        $reference = $this->normalizeImportReference($importReference);

        return DB::connection('school')->transaction(function () use ($student, $actor, $schoolId, $reference): StudentImportIdentity {
            // Lock order is always sequence first, identity second. This also
            // makes the first two simultaneous Students for a newly-created
            // School serialize without an insert-gap deadlock.
            if (!DB::connection('school')->table('student_code_sequences')->where('school_id', $schoolId)->exists()) {
                DB::connection('school')->table('student_code_sequences')->insertOrIgnore([
                    'school_id' => $schoolId,
                    'next_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $sequence = DB::connection('school')->table('student_code_sequences')
                ->where('school_id', $schoolId)->lockForUpdate()->first();
            if ($sequence === null) throw new \RuntimeException('Student Code sequence could not be locked.');

            $existing = StudentImportIdentity::query()->where('student_id', $student->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($reference !== null && $existing->import_reference !== null && !hash_equals((string) $existing->import_reference, $reference)) {
                    throw ValidationException::withMessages(['import_reference' => __('Import Reference cannot be changed after it is assigned.')]);
                }
                return $existing;
            }
            if ($reference !== null && $this->existsByImportReference($schoolId, $reference)) {
                throw ValidationException::withMessages(['import_reference' => __('This Import Reference is already in use for this School.')]);
            }

            $number = (int) $sequence->next_number;
            while ($number <= self::MAX_NUMBER && $this->exists($schoolId, $this->format($number))) $number++;
            if ($number > self::MAX_NUMBER) {
                throw ValidationException::withMessages(['student_code' => __('The six-digit Student Code range is exhausted for this School.')]);
            }
            $code = $this->format($number);
            DB::connection('school')->table('student_code_sequences')->where('school_id', $schoolId)->update([
                'next_number' => $number + 1,
                'updated_at' => now(),
            ]);

            try {
                return StudentImportIdentity::query()->create([
                    'school_id' => $schoolId,
                    'student_code' => $code,
                    'import_reference' => $reference,
                    'student_id' => (int) $student->id,
                    'user_id' => (int) $student->user_id,
                    'created_by' => $actor?->id,
                ]);
            } catch (QueryException $exception) {
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                if ($driverCode === 1062 || str_contains(strtolower($exception->getMessage()), 'unique constraint')) {
                    throw ValidationException::withMessages([
                        $reference === null ? 'student_code' : 'import_reference' => $reference === null
                            ? __('Student Code allocation conflicted with another request. Please retry.')
                            : __('This Import Reference is already in use for this School.'),
                    ]);
                }
                throw $exception;
            }
        });
    }

    public function format(int $number): string
    {
        if ($number < 1 || $number > self::MAX_NUMBER) {
            throw ValidationException::withMessages(['student_code' => __('Student Code number is outside the six-digit range.')]);
        }
        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
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

        try {
            return StudentImportIdentity::query()->create([
                'school_id' => (int) $student->school_id,
                'student_code' => $code,
                'student_id' => (int) $student->id,
                'user_id' => (int) $student->user_id,
                'created_by' => (int) $actor->id,
            ]);
        } catch (QueryException $exception) {
            // The database unique constraint is the final concurrency gate.
            // Convert only duplicate-key races into the same closed validation
            // result as a normal replay; other database failures remain fatal.
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($driverCode === 1062 || str_contains(strtolower($exception->getMessage()), 'unique constraint')) {
                throw ValidationException::withMessages([
                    'student_code' => __('This Student Code is already in use for this School.'),
                ]);
            }
            throw $exception;
        }
    }
}
