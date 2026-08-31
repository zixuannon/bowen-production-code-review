<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TeacherXiaobailongLifecycleContractTest extends TestCase
{
    private function controller(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/TeacherController.php');
    }

    public function test_teacher_delete_emits_a_departed_lifecycle_event_only_after_commit(): void
    {
        $controller = $this->controller();

        $this->assertStringContainsString("ResponseService::noPermissionThenSendJson('teacher-delete')", $controller);
        $this->assertStringContainsString('$teacher = $this->user->findTrashedById($id);', $controller);
        $this->assertStringContainsString('$teacher->forceDelete();', $controller);
        $this->assertStringContainsString("app(XiaobailongLifecycleNotifier::class)->deferStatus(\$schoolId, (int) \$id, 'left');", $controller);
    }

    public function test_single_teacher_status_change_notifies_the_committed_status(): void
    {
        $controller = $this->controller();

        $this->assertStringContainsString("ResponseService::noPermissionThenRedirect('teacher-delete')", $controller);
        $this->assertStringContainsString('$newStatus = $teacher->status == 0 ? 1 : 0;', $controller);
        $this->assertStringContainsString("\$newStatus === 1 ? 'active' : 'disabled'", $controller);
        $this->assertMatchesRegularExpression(
            '/DB::commit\(\);\s+app\(XiaobailongLifecycleNotifier::class\)->deferStatus\(/',
            $controller,
        );
    }

    public function test_bulk_status_change_collects_each_lifecycle_event_then_notifies_after_commit(): void
    {
        $controller = $this->controller();

        $this->assertStringContainsString('$lifecycleChanges = [];', $controller);
        $this->assertStringContainsString('$lifecycleChanges[] = [(int) $teacher->school_id, (int) $userId, $newStatus === 1 ? \'active\' : \'disabled\'];', $controller);
        $this->assertStringContainsString('foreach ($lifecycleChanges as [$schoolId, $teacherId, $accountStatus])', $controller);
        $this->assertStringContainsString('app(XiaobailongLifecycleNotifier::class)->deferStatus($schoolId, $teacherId, $accountStatus);', $controller);
    }
}
