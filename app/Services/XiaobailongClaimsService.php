<?php

namespace App\Services;

use App\Models\School;
use App\Models\SessionYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class XiaobailongClaimsService
{
    public const CLAIMS_VERSION = 2;
    public const MAX_AUTHORITATIVE_CLASSES = 100;

    /**
     * Build the complete teacher contract consumed by Xiaobailong.
     *
     * The class payload deliberately contains no student rows, names,
     * admission numbers, contact details, attendance, marks, or submissions.
     */
    public function teacherClaims(School $school, User $teacher): array
    {
        $accountStatus = self::accountStatusFromFlags(
            (int) $school->status === 1,
            (int) $teacher->status === 1,
            $teacher->trashed(),
            self::teacherIsEligible($teacher)
        );

        $sessionYear = SessionYear::on('school')
            ->where('school_id', $school->id)
            ->where('default', 1)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $classes = $accountStatus === 'active'
            ? $this->authoritativeClasses((int) $school->id, (int) $teacher->id)
            : [];

        $displayName = trim((string) $teacher->first_name . ' ' . (string) $teacher->last_name);

        return [
            'claims_version' => self::CLAIMS_VERSION,
            'school_code' => (string) $school->code,
            'school_name' => (string) $school->name,
            'teacher_id' => (string) $teacher->id,
            'display_name' => $displayName !== '' ? $displayName : 'Bowen 教师',
            'role' => 'Teacher',
            'account_status' => $accountStatus,
            // The current deployment has one school tenant per campus. Keep
            // campus identity explicit so the contract can evolve later.
            'campus_code' => (string) $school->code,
            'campus_name' => (string) $school->name,
            'academic_year' => $sessionYear ? (string) $sessionYear->name : '',
            'classes_authoritative' => true,
            'classes' => $classes,
        ];
    }

    public function departedTeacherClaims(School $school, string $teacherId): array
    {
        return [
            'claims_version' => self::CLAIMS_VERSION,
            'school_code' => (string) $school->code,
            'school_name' => (string) $school->name,
            'teacher_id' => $teacherId,
            'display_name' => 'Bowen 教师',
            'role' => 'Teacher',
            'account_status' => 'left',
            'campus_code' => (string) $school->code,
            'campus_name' => (string) $school->name,
            'academic_year' => '',
            'classes_authoritative' => true,
            'classes' => [],
        ];
    }

    public static function accountStatusFromFlags(
        bool $schoolActive,
        bool $teacherActive,
        bool $teacherDeleted,
        bool $teacherAuthorized
    ): string {
        // In eSchool a soft-deleted teacher with status=0 is restorable and
        // therefore disabled, not permanently departed. A hard delete is
        // delivered as an explicit `left` lifecycle event by the controller.
        if (!$teacherActive) {
            return 'disabled';
        }

        if ($teacherDeleted) {
            return 'left';
        }

        return $schoolActive && $teacherAuthorized ? 'active' : 'disabled';
    }

    public static function formatClassClaim(array $row, array $assignmentKinds): array
    {
        $className = trim((string) ($row['class_name'] ?? ''));
        $sectionName = trim((string) ($row['section_name'] ?? ''));
        $name = trim($className . ($sectionName !== '' ? ' ' . $sectionName : ''));

        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => $name,
            'class_id' => (string) ($row['class_id'] ?? ''),
            'class_name' => $className,
            'section_id' => isset($row['section_id']) ? (string) $row['section_id'] : '',
            'section_name' => $sectionName,
            'medium' => trim((string) ($row['medium_name'] ?? '')),
            'teacher_assignments' => array_values(array_unique($assignmentKinds)),
        ];
    }

    /**
     * Single, fail-closed eligibility rule shared by the menu, launch and
     * exchange endpoints. Keeping this in one place prevents a user from
     * being offered a launch that the exchange endpoint will later reject.
     */
    public static function teacherIsEligible(?User $teacher): bool
    {
        if (!$teacher || (int) $teacher->status !== 1 || $teacher->trashed()) {
            return false;
        }

        try {
            return $teacher->hasRole('Teacher') && $teacher->can('xiaobailong-use');
        } catch (Throwable) {
            // Permission storage is part of the security boundary. If it
            // cannot be read, never infer access from the account alone.
            return false;
        }
    }

    private function authoritativeClasses(int $schoolId, int $teacherId): array
    {
        $classTeacherIds = DB::connection('school')->table('class_teachers')
            ->where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->pluck('class_section_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $subjectTeacherIds = DB::connection('school')->table('subject_teachers')
            ->where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->whereNull('deleted_at')
            ->pluck('class_section_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $classSectionIds = array_values(array_unique(array_merge($classTeacherIds, $subjectTeacherIds)));
        sort($classSectionIds, SORT_NUMERIC);
        if (count($classSectionIds) > self::MAX_AUTHORITATIVE_CLASSES) {
            // Never mark a silently truncated list as authoritative.
            throw new RuntimeException('xiaobailong_class_assignment_limit_exceeded');
        }
        if ($classSectionIds === []) {
            return [];
        }

        $classTeacherSet = array_fill_keys($classTeacherIds, true);
        $subjectTeacherSet = array_fill_keys($subjectTeacherIds, true);
        $rows = DB::connection('school')->table('class_sections as cs')
            ->join('classes as c', 'c.id', '=', 'cs.class_id')
            ->leftJoin('sections as s', 's.id', '=', 'cs.section_id')
            ->leftJoin('mediums as m', 'm.id', '=', 'cs.medium_id')
            ->where('cs.school_id', $schoolId)
            ->whereIn('cs.id', $classSectionIds)
            ->whereNull('cs.deleted_at')
            ->whereNull('c.deleted_at')
            ->select([
                'cs.id',
                'cs.class_id',
                'cs.section_id',
                'c.name as class_name',
                's.name as section_name',
                'm.name as medium_name',
            ])
            ->orderBy('cs.id')
            ->get();

        return $rows->map(static function ($row) use ($classTeacherSet, $subjectTeacherSet) {
            $data = (array) $row;
            $id = (int) $data['id'];
            $kinds = [];
            if (isset($classTeacherSet[$id])) {
                $kinds[] = 'class_teacher';
            }
            if (isset($subjectTeacherSet[$id])) {
                $kinds[] = 'subject_teacher';
            }
            return self::formatClassClaim($data, $kinds);
        })->values()->all();
    }
}
