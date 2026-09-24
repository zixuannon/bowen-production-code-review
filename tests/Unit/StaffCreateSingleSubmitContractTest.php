<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StaffCreateSingleSubmitContractTest extends TestCase
{
    public function test_school_create_forms_have_only_the_shared_submit_handler(): void
    {
        $project = dirname(__DIR__, 2);
        $common = file_get_contents($project.'/public/assets/js/custom/common.js');
        $schoolFooter = file_get_contents($project.'/resources/views/layouts/school/footer_js.blade.php');
        $staffForm = file_get_contents($project.'/resources/views/staff/index.blade.php');

        $this->assertStringContainsString('id="create-form"', $staffForm);
        $this->assertStringContainsString("$('#create-form,.create-form,.create-form-without-reset').on('submit'", $common);
        $this->assertStringNotContainsString("$('#create-form').on('submit'", $schoolFooter);
        $this->assertStringNotContainsString("formAjaxRequest('POST', url, data, formElement, submitButtonElement, successCallback)", $schoolFooter);
    }

    public function test_committed_staff_creation_reports_email_delivery_as_a_warning_not_an_error(): void
    {
        $project = dirname(__DIR__, 2);
        $controller = file_get_contents($project.'/app/Http/Controllers/StaffController.php');
        $users = file_get_contents($project.'/app/Services/UserService.php');

        $this->assertStringContainsString('DB::commit();', $controller);
        $this->assertStringContainsString("ResponseService::warningResponse('Staff registered successfully. Invitation email was not sent.')", $controller);
        $this->assertStringContainsString('public function sendStaffRegistrationEmail($user): bool', $users);
        $this->assertStringContainsString('return false;', $users);

        $method = substr($users, strpos($users, 'public function sendStaffRegistrationEmail'));
        $method = substr($method, 0, strpos($method, 'private function replaceStaffPlaceholders'));
        $this->assertStringNotContainsString('ResponseService::errorResponse', $method);
        $this->assertStringNotContainsString('ResponseService::warningResponse', $method);
    }
}
