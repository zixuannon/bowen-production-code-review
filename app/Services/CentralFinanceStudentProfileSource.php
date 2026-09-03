<?php

namespace App\Services;

use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Reads the minimum tenant-owned student reference projection. The school
 * database is always re-resolved from the central registry; callers cannot
 * choose a database name through a request, CLI option, or model mutation.
 */
final class CentralFinanceStudentProfileSource
{
    /** @return list<CentralFinanceStudentProfilePayload> */
    public function forSchool(School $requestedSchool): array
    {
        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $database = (string) $school->getRawOriginal('database_name');

        if ($database === '' || !$this->isSafeRegisteredDatabase($database)) {
            throw new AuthorizationException('The School registry does not contain a valid tenant database.');
        }

        return $this->onTrustedSchoolConnection($database, function () use ($school): array {
            return $this->payloadQuery($school)->orderBy('students.id')->get()
                ->map(fn (object $student): CentralFinanceStudentProfilePayload => $this->payload($school, $student))->all();
        });
    }

    public function forStudent(School $requestedSchool, int $tenantStudentId): CentralFinanceStudentProfilePayload
    {
        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $database = (string) $school->getRawOriginal('database_name');
        if ($database === '' || !$this->isSafeRegisteredDatabase($database) || $tenantStudentId < 1) {
            throw new AuthorizationException('The School registry or tenant student identity is invalid.');
        }

        return $this->onTrustedSchoolConnection($database, function () use ($school, $tenantStudentId): CentralFinanceStudentProfilePayload {
            $student = $this->payloadQuery($school)->where('students.id', $tenantStudentId)->first();
            if ($student === null) {
                throw new RuntimeException("Tenant student {$tenantStudentId} was not found for Central Finance sync.");
            }
            return $this->payload($school, $student);
        });
    }

    public function backfillMissingSourceUuids(School $requestedSchool): int
    {
        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $database = (string) $school->getRawOriginal('database_name');
        if ($database === '' || !$this->isSafeRegisteredDatabase($database)) {
            throw new AuthorizationException('The School registry does not contain a valid tenant database.');
        }

        return $this->onTrustedSchoolConnection($database, function (): int {
            $studentIds = DB::connection('school')->table('students')->whereNull('central_finance_source_uuid')->orderBy('id')->pluck('id');
            foreach ($studentIds as $studentId) {
                DB::connection('school')->table('students')->where('id', $studentId)->whereNull('central_finance_source_uuid')->update([
                    'central_finance_source_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'updated_at' => now(),
                ]);
            }
            return $studentIds->count();
        });
    }

    private function payloadQuery(School $school): \Illuminate\Database\Query\Builder
    {
        $query = DB::connection('school')->table('students')
            ->leftJoin('users as student_users', 'student_users.id', '=', 'students.user_id')
            ->leftJoin('users as guardians', 'guardians.id', '=', 'students.guardian_id')
            ->leftJoin('class_sections', 'class_sections.id', '=', 'students.class_section_id')
            ->leftJoin('classes', 'classes.id', '=', 'class_sections.class_id')
            ->leftJoin('sections', 'sections.id', '=', 'class_sections.section_id')
            ->select([
                'students.id', 'students.admission_no', 'students.central_finance_source_uuid',
                'students.application_status', 'students.class_section_id', 'students.updated_at', 'students.deleted_at',
                'student_users.first_name as student_first_name', 'student_users.last_name as student_last_name', 'student_users.status as tenant_user_status',
                'guardians.first_name as guardian_first_name', 'guardians.last_name as guardian_last_name', 'guardians.email as guardian_email', 'guardians.mobile as guardian_mobile',
                'class_sections.class_id', 'classes.name as class_name', 'sections.name as section_name',
            ]);
        if (Schema::connection('school')->hasTable('student_import_identities')) {
            $query->leftJoin('student_import_identities', 'student_import_identities.student_id', '=', 'students.id')
                ->addSelect('student_import_identities.student_code');
        } else {
            $query->addSelect(DB::raw('NULL as student_code'));
        }

        return $query;
    }

    private function payload(School $school, object $student): CentralFinanceStudentProfilePayload
    {
        if (!$student->central_finance_source_uuid) {
            throw new RuntimeException("Student {$student->id} has no Central Finance source UUID.");
        }
        $studentName = $this->name($student->student_first_name, $student->student_last_name);
        $guardianName = $this->name($student->guardian_first_name, $student->guardian_last_name);

        return new CentralFinanceStudentProfilePayload(
            schoolId: (int) $school->id,
            tenantStudentId: (int) $student->id,
            sourceUuid: (string) $student->central_finance_source_uuid,
            admissionNo: $student->admission_no ? (string) $student->admission_no : null,
            studentName: $studentName,
            enrollmentStatus: $student->application_status !== null ? (string) $student->application_status : null,
            sourceUpdatedAt: CarbonImmutable::parse($student->updated_at ?? now()),
            sourceDeletedAt: $student->deleted_at ? CarbonImmutable::parse($student->deleted_at) : null,
            classId: $student->class_id ? (int) $student->class_id : null,
            classSectionId: $student->class_section_id ? (int) $student->class_section_id : null,
            className: $student->class_name ? (string) $student->class_name : null,
            sectionName: $student->section_name ? (string) $student->section_name : null,
            guardianName: $guardianName,
            guardianEmail: $student->guardian_email ? (string) $student->guardian_email : null,
            guardianMobile: $student->guardian_mobile ? (string) $student->guardian_mobile : null,
            tenantUserStatus: $student->tenant_user_status !== null ? (string) $student->tenant_user_status : null,
            studentCode: $student->student_code ? (string) $student->student_code : null,
        );
    }

    private function name(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(implode(' ', array_filter([$firstName, $lastName])));
        return $name !== '' ? $name : null;
    }

    private function isSafeRegisteredDatabase(string $database): bool
    {
        // This rejects control characters and path-like values before any
        // connection configuration is changed. The actual trust boundary is
        // the re-read central School registry above, not this syntax check.
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $database)) {
            return true;
        }

        // Local PHPUnit fixtures use a real SQLite file path. This exception
        // is constrained by the configured school driver and the OS temp
        // directory; production MySQL tenant names cannot use it.
        return config('database.connections.school.driver') === 'sqlite'
            && realpath(dirname($database)) === realpath(sys_get_temp_dir())
            && is_file($database);
    }

    /** @template T @param callable():T $callback @return T */
    private function onTrustedSchoolConnection(string $database, callable $callback): mixed
    {
        $original = config('database.connections.school.database');
        $default = DB::getDefaultConnection();

        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            return $callback();
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $original);
            DB::setDefaultConnection($default);
        }
    }
}
