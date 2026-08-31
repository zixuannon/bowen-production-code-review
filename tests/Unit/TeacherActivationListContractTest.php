<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TeacherActivationListContractTest extends TestCase
{
    public function test_teacher_management_list_excludes_role_only_users_without_staff_profiles(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/TeacherController.php');

        $this->assertStringContainsString(
            "->role('Teacher')->whereHas('staff')->with('staff','staff.staffSalary','extra_student_details.form_field')",
            $controller,
        );
    }

    public function test_activation_and_inactive_list_continue_to_use_the_same_user_status_and_soft_delete_contract(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/TeacherController.php');

        $this->assertStringContainsString("->where('status',0)->onlyTrashed()", $controller);
        $this->assertStringContainsString("['status' => \$newStatus,'deleted_at' => \$teacher->status == 1 ? now() : null]", $controller);
    }
}
