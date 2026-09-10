<?php

namespace App\Http\Middleware;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\SubjectTeacher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireTeacherFileUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $abilities = is_object($token) ? (array) ($token->abilities ?? []) : [];

        if (!$user || !$user->hasRole('Teacher') || !in_array('teacher-files:update', $abilities, true)) {
            return response()->json(['error' => true, 'message' => 'Forbidden.'], 403);
        }
        if (!$this->ownsUploadTarget($request, (int) $user->id, (int) $user->school_id)) {
            abort(404);
        }

        return $next($request);
    }

    private function ownsUploadTarget(Request $request, int $teacherId, int $schoolId): bool
    {
        $action = $request->route()?->getActionMethod();
        if (!$action && $request->is('api/teacher/update-file')) {
            // updateFile performs the File + morph ownership check after validation.
            $action = 'updateFile';
        }

        return match ($action) {
            'createAssignment', 'createLesson', 'sendAnnouncement' => $this->ownsRequestedClasses($request, $teacherId, $schoolId),
            'updateAssignment' => Assignment::query()->where('school_id', $schoolId)->owner()
                    ->whereKey($request->integer('assignment_id'))->exists()
                && $this->ownsRequestedClasses($request, $teacherId, $schoolId),
            'updateLesson' => Lesson::query()->where('school_id', $schoolId)->owner()
                ->whereKey($request->integer('lesson_id'))->exists(),
            'createTopic' => Lesson::query()->where('school_id', $schoolId)->owner()
                ->whereKey($request->integer('lesson_id'))->exists(),
            'updateTopic' => LessonTopic::query()->where('school_id', $schoolId)->owner()
                ->whereKey($request->integer('topic_id'))->exists(),
            'updateAnnouncement' => Announcement::query()->where('school_id', $schoolId)->owner()
                    ->whereKey($request->integer('announcement_id'))->exists()
                && $this->ownsRequestedClasses($request, $teacherId, $schoolId),
            'updateFile' => true,
            default => false,
        };
    }

    private function ownsRequestedClasses(Request $request, int $teacherId, int $schoolId): bool
    {
        $sections = collect($request->input('class_section_id', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $classSubjectId = $request->integer('class_subject_id');
        if ($sections->isEmpty() || !$classSubjectId) {
            return false;
        }

        $ownedSectionCount = SubjectTeacher::query()
            ->where('teacher_id', $teacherId)
            ->where('school_id', $schoolId)
            ->where('class_subject_id', $classSubjectId)
            ->whereIn('class_section_id', $sections)
            ->distinct()
            ->count('class_section_id');

        return $ownedSectionCount === $sections->count();
    }
}
