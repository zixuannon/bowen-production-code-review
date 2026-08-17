<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BankAccountCreateFormViewTest extends TestCase
{
    public function test_create_form_uses_its_own_submit_binding_and_normalizes_boolean_checkboxes(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/bank-account/index.blade.php');

        $this->assertStringContainsString('id="bank-account-create-form"', $view);
        $this->assertStringContainsString("action=\"{{ route('bank-accounts.store') }}\" method=\"POST\"", $view);
        $this->assertStringContainsString("$('#bank-account-create-form').on('submit'", $view);
        $this->assertStringContainsString("formData.set('is_active', $('#bank-account-create-form input[name=\"is_active\"]').is(':checked') ? '1' : '0')", $view);
        $this->assertStringContainsString("formData.set('is_default', $('#bank-account-create-form input[name=\"is_default\"]').is(':checked') ? '1' : '0')", $view);
        $this->assertStringNotContainsString('<form class="pt-3" id="create-form"', $view);
    }
}
