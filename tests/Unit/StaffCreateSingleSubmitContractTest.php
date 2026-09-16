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
}
