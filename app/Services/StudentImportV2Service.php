<?php

namespace App\Services;

use App\Models\ClassSection;
use App\Models\FeesClassType;
use App\Models\School;
use App\Models\SessionYear;
use App\Models\Students;
use App\Models\User;
use App\Repositories\Subscription\SubscriptionInterface;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

/**
 * Preview-first, Zixuan-only student admission importer.
 *
 * Preview rows are kept in the cache only.  The identity table is written
 * during Confirm, inside the same tenant transaction as the Student and its
 * confirmed compulsory fee assignment.
 */
final class StudentImportV2Service
{
    private const CACHE_PREFIX = 'student-import-v2:';

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'student_code', 'first_name', 'last_name', 'gender', 'date_of_birth',
        'admission_date', 'current_address', 'permanent_address', 'guardian_email',
        'guardian_first_name', 'guardian_last_name', 'guardian_mobile', 'guardian_gender',
    ];

    /** @return array<string,mixed> */
    public function preview(UploadedFile $file, User $actor, int $sessionYearId, int $classSectionId): array
    {
        [$school, $sessionYear, $classSection] = $this->context($actor, $sessionYearId, $classSectionId);
        $rows = $this->readRows($file);
        $seen = [];
        $result = [];
        foreach ($rows as $line => $row) {
            if ($this->blank($row)) continue;
            $prepared = $this->prepareRow($row, $line + 2, $actor, $school, $sessionYear, $classSection, $seen);
            $seen[$prepared['student_code']] = true;
            $result[] = $prepared;
        }
        if ($result === []) throw ValidationException::withMessages(['file' => 'The workbook has no Student Import V2 data rows.']);

        $token = (string) Str::uuid();
        $payload = [
            'actor_id' => $actor->id,
            'school_id' => $actor->school_id,
            'central_school_id' => $school->id,
            'session_year_id' => $sessionYear->id,
            'class_section_id' => $classSection->id,
            'rows' => $result,
            'created_at' => now()->toIso8601String(),
        ];
        Cache::put(self::CACHE_PREFIX.$token, $payload, now()->addMinutes((int) config('student_import_v2.preview_ttl_minutes', 30)));

        return ['preview_token' => $token, 'rows' => $result, 'summary' => $this->summary($result)];
    }

    /** The V2 entry and template are restricted to the explicitly approved pilot School. */
    public function assertPilot(User $actor): School
    {
        if ((int) $actor->school_id < 1) throw new AuthorizationException('A trusted School Student identity is required.');
        $database = trim((string) config('database.connections.school.database'));
        $school = School::on('mysql')->where('database_name', $database)->first();
        if ($school === null || !hash_equals((string) config('student_import_v2.enabled_school_code'), (string) $school->code)) {
            throw new AuthorizationException('Student Import V2 is currently available only to the approved Zixuan School.');
        }
        return $school;
    }

    /** @return array<string,mixed> */
    public function confirm(string $token, User $actor): array
    {
        $payload = Cache::get(self::CACHE_PREFIX.$token);
        if (!is_array($payload) || (int) ($payload['actor_id'] ?? 0) !== (int) $actor->id || (int) ($payload['school_id'] ?? 0) !== (int) $actor->school_id) {
            throw ValidationException::withMessages(['preview_token' => 'The Student Import preview has expired or does not belong to this School session.']);
        }
        $rows = collect($payload['rows'] ?? []);
        if ($rows->contains(fn (array $row) => in_array($row['status'], ['error', 'conflict'], true))) {
            throw ValidationException::withMessages(['preview' => 'Resolve every Error and Conflict before confirming Student Import V2.']);
        }
        [$school, $sessionYear, $classSection] = $this->context($actor, (int) $payload['session_year_id'], (int) $payload['class_section_id']);
        $this->assertCentralReady($school);
        $this->assertCompulsorySetup($actor, $sessionYear, $classSection);

        try {
        $created = DB::transaction(function () use ($rows, $actor, $sessionYear, $classSection): array {
            $created = [];
            foreach ($rows->where('status', 'new') as $row) {
                // Re-check inside the write transaction: a stale preview can
                // never silently become a duplicate Student admission.
                if ($this->identityExists($actor, $row['student_code'])) continue;
                $this->assertStudentCapacity($actor);
                $guardian = User::query()->role('Guardian')->where('school_id', $actor->school_id)->where('email', $row['guardian_email'])->first();
                if ($guardian === null) {
                    $guardian = app(UserService::class)->createOrUpdateParent(
                        $row['guardian_first_name'], $row['guardian_last_name'], $row['guardian_email'],
                        $row['guardian_mobile'], $row['guardian_gender']
                    );
                }
                $admissionNo = $this->legacyAdmissionNo($actor, $sessionYear);
                $studentUser = app(UserService::class)->createStudentUser(
                    $row['first_name'], $row['last_name'], $admissionNo, $row['mobile'] ?: null,
                    $row['date_of_birth'], $row['gender'], null, $classSection->id, $row['admission_date'],
                    $row['current_address'], $row['permanent_address'], $sessionYear->id, $guardian->id, [], 1, false
                );
                $student = Students::query()->where('user_id', $studentUser->id)->lockForUpdate()->firstOrFail();
                app(StudentCodeService::class)->assign($student, $actor, $row['student_code']);
                $assignmentService = app(StudentFeeAssignmentService::class);
                $draft = $assignmentService->saveDraft($student, $actor, []);
                $confirmed = $assignmentService->confirm($student, $actor, $draft->uuid);
                $created[] = ['student_id' => $student->id, 'user_id' => $studentUser->id, 'student_code' => $row['student_code'], 'assignment_uuid' => $confirmed->uuid];
            }
            return $created;
        });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'student_import_identity')) {
                throw ValidationException::withMessages(['preview' => 'A Student Code was confirmed by another request. Re-preview the workbook before confirming again.']);
            }
            throw $exception;
        }
        Cache::forget(self::CACHE_PREFIX.$token);
        return ['created' => $created, 'created_count' => count($created), 'duplicate_count' => $rows->where('status', 'duplicate')->count()];
    }

    /** @return array{0:School,1:SessionYear,2:ClassSection} */
    private function context(User $actor, int $sessionYearId, int $classSectionId): array
    {
        if (!Schema::connection('school')->hasTable('student_import_identities')) {
            throw new \LogicException('Student Import V2 schema is not installed for this School.');
        }
        $school = $this->assertPilot($actor);
        $sessionYear = SessionYear::query()->where('school_id', $actor->school_id)->find($sessionYearId);
        $classSection = ClassSection::query()->where('school_id', $actor->school_id)->with('class')->find($classSectionId);
        if ($sessionYear === null || $classSection === null || $classSection->class === null || (int) $classSection->class->school_id !== (int) $actor->school_id) {
            throw ValidationException::withMessages(['class_section_id' => 'The academic year and Class Section must belong to the current School.']);
        }
        return [$school, $sessionYear, $classSection];
    }

    /** @return list<array<string,mixed>> */
    private function readRows(UploadedFile $file): array
    {
        $book = IOFactory::load($file->getRealPath());
        $sheet = $book->getSheet(0);
        $raw = $sheet->toArray(null, false, false, false);
        if ($raw === []) throw ValidationException::withMessages(['file' => 'The workbook is empty.']);
        $headers = array_map(fn ($value) => $this->header((string) $value), array_shift($raw));
        foreach (self::REQUIRED_HEADERS as $required) if (!in_array($required, $headers, true)) {
            throw ValidationException::withMessages(['file' => "Student Import V2 requires the {$required} column."]);
        }
        $rows = [];
        foreach ($raw as $values) {
            $row = [];
            foreach ($headers as $index => $header) if ($header !== '') $row[$header] = $values[$index] ?? null;
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $row @param array<string,bool> $seen @return array<string,mixed> */
    private function prepareRow(array $row, int $line, User $actor, School $school, SessionYear $sessionYear, ClassSection $classSection, array $seen): array
    {
        $errors = [];
        $warnings = [];
        $code = $this->text($row['student_code'] ?? null, 'Student Code', $errors, true);
        $mobile = $this->phone($row['mobile'] ?? null, 'Mobile', $errors, false);
        $guardianMobile = $this->phone($row['guardian_mobile'] ?? null, 'Guardian Mobile', $errors, true);
        $prepared = [
            'line' => $line, 'student_code' => $code, 'first_name' => $this->requiredText($row['first_name'] ?? null, 'First Name', $errors),
            'last_name' => $this->requiredText($row['last_name'] ?? null, 'Last Name', $errors), 'mobile' => $mobile,
            'gender' => $this->gender($row['gender'] ?? null, 'Gender', $errors),
            'date_of_birth' => $this->date($row['date_of_birth'] ?? null, 'Date of Birth', $errors),
            'admission_date' => $this->date($row['admission_date'] ?? null, 'Admission Date', $errors),
            'current_address' => $this->requiredText($row['current_address'] ?? null, 'Current Address', $errors),
            'permanent_address' => $this->requiredText($row['permanent_address'] ?? null, 'Permanent Address', $errors),
            'guardian_email' => strtolower(trim((string) ($row['guardian_email'] ?? ''))),
            'guardian_first_name' => $this->requiredText($row['guardian_first_name'] ?? null, 'Guardian First Name', $errors),
            'guardian_last_name' => $this->requiredText($row['guardian_last_name'] ?? null, 'Guardian Last Name', $errors),
            'guardian_mobile' => $guardianMobile, 'guardian_gender' => $this->gender($row['guardian_gender'] ?? null, 'Guardian Gender', $errors),
        ];
        if (!filter_var($prepared['guardian_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Guardian Email must be valid.';
        if ($code !== '' && isset($seen[$code])) $warnings[] = 'Duplicate Student Code in this workbook.';
        if ($code !== '' && $this->identityExists($actor, $code)) $warnings[] = 'Student Code already exists in this School.';
        if ($this->secondaryMatch($prepared, (int) $actor->school_id)) $warnings[] = 'A Student with the same name, date of birth, and Guardian email may already exist.';
        try {
            $this->assertCentralReady($school);
            $this->assertCompulsorySetup($actor, $sessionYear, $classSection);
        } catch (\Throwable $exception) {
            $prepared['status'] = 'conflict';
            $prepared['errors'] = [$exception->getMessage()];
            $prepared['warnings'] = $warnings;
            return $prepared;
        }
        $prepared['errors'] = $errors;
        $prepared['warnings'] = $warnings;
        $prepared['status'] = $errors !== [] ? 'error' : ($warnings !== [] && str_contains(implode(' ', $warnings), 'Student Code') ? 'duplicate' : 'new');
        return $prepared;
    }

    private function assertCentralReady(School $school): void
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $school->id);
    }

    private function assertCompulsorySetup(User $actor, SessionYear $year, ClassSection $section): void
    {
        $query = FeesClassType::query()->with('fee')->where('school_id', $actor->school_id)->where('class_id', $section->class_id)->where('optional', false);
        if (\Illuminate\Support\Facades\Schema::hasColumn('fees_class_types', 'deleted_at')) $query->whereNull('deleted_at');
        $exists = $query->get()->contains(fn (FeesClassType $item) => $item->fee !== null && (int) $item->fee->session_year_id === (int) $year->id);
        if (!$exists) throw ValidationException::withMessages(['fee_setup' => 'Compulsory Fee Setup is not ready for this Academic Year and Class.']);
    }

    private function identityExists(User $actor, string $code): bool
    {
        return app(StudentCodeService::class)->exists((int) $actor->school_id, $code)
            || Students::query()->where('school_id', $actor->school_id)->where('admission_no', $code)->exists();
    }

    /** @param array<string,mixed> $row */
    private function secondaryMatch(array $row, int $schoolId): bool
    {
        if ($row['first_name'] === '' || $row['last_name'] === '' || $row['date_of_birth'] === '' || $row['guardian_email'] === '') return false;
        return Students::query()->where('school_id', $schoolId)->whereHas('user', fn ($q) => $q->where('first_name', $row['first_name'])->where('last_name', $row['last_name'])->whereDate('dob', $row['date_of_birth']))
            ->whereHas('guardian', fn ($q) => $q->where('email', $row['guardian_email']))->exists();
    }

    private function legacyAdmissionNo(User $actor, SessionYear $year): string
    {
        $lastId = (int) (Students::withTrashed()->max('id') ?? 0);
        return $year->name.'0'.$actor->school_id.'0'.($lastId + 1);
    }

    private function assertStudentCapacity(User $actor): void
    {
        $today = now()->toDateString();
        $trial = app(SubscriptionInterface::class)->builder()->doesntHave('subscription_bill')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
            ->whereHas('package', fn ($query) => $query->where('is_trial', 1))->first();
        if ($trial !== null) {
            $limit = (int) (app(CachingService::class)->getSystemSettings()['student_limit'] ?? 0);
            if ($limit > 0 && User::query()->role('Student')->withTrashed()->count() >= $limit) {
                throw ValidationException::withMessages(['subscription' => "The free trial allows only {$limit} students."]);
            }
            return;
        }
        $subscription = app(SubscriptionService::class)->active_subscription((int) $actor->school_id);
        if ($subscription && (int) $subscription->package_type === 0 && !app(SubscriptionService::class)->check_user_limit((int) $actor->school_id, 'Students')) {
            throw ValidationException::withMessages(['subscription' => 'The Student subscription limit has been reached.']);
        }
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function summary(array $rows): array
    {
        return collect($rows)->countBy('status')->all();
    }

    /** @param array<string,mixed> $row */
    private function blank(array $row): bool
    {
        return collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty();
    }

    private function header(string $value): string
    {
        return Str::of(trim($value))->lower()->replace(['-', '/'], ' ')->squish()->replace(' ', '_')->toString();
    }

    /** @param list<string> $errors */
    private function text(mixed $value, string $label, array &$errors, bool $required): string
    {
        if ($value === null || $value === '') { if ($required) $errors[] = "{$label} is required."; return ''; }
        if (is_int($value) || is_float($value)) { $errors[] = "{$label} must be stored as Text to preserve leading zeroes."; return ''; }
        $text = trim((string) $value);
        if ($required && $text === '') $errors[] = "{$label} is required.";
        return $text;
    }

    /** @param list<string> $errors */
    private function requiredText(mixed $value, string $label, array &$errors): string { return $this->text($value, $label, $errors, true); }

    /** @param list<string> $errors */
    private function phone(mixed $value, string $label, array &$errors, bool $required): string
    {
        $phone = $this->text($value, $label, $errors, $required);
        if ($phone !== '' && !preg_match('/^\d{6,15}$/', $phone)) $errors[] = "{$label} must contain 6 to 15 digits.";
        return $phone;
    }

    /** @param list<string> $errors */
    private function gender(mixed $value, string $label, array &$errors): string
    {
        $gender = strtolower($this->text($value, $label, $errors, true));
        if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) $errors[] = "{$label} must be male or female.";
        return $gender;
    }

    /** @param list<string> $errors */
    private function date(mixed $value, string $label, array &$errors): string
    {
        if (is_numeric($value) && !is_string($value)) {
            try { return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d'); } catch (\Throwable) { $errors[] = "{$label} is invalid."; return ''; }
        }
        try { return CarbonImmutable::parse(trim((string) $value))->format('Y-m-d'); } catch (\Throwable) { $errors[] = "{$label} is invalid."; return ''; }
    }
}
