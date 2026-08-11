<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BankAccountOpeningBalanceViewTest extends TestCase
{
    public function test_edit_form_conditionally_collects_opening_balance_audit_reason(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/bank-account/index.blade.php');

        $this->assertStringContainsString('id="adjustment_reason"', $view);
        $this->assertStringContainsString('isOpeningBalanceAdjustment', $view);
        $this->assertStringContainsString("formData.append('adjustment_reason', adjustmentReason)", $view);
        $this->assertStringContainsString("$('#adjustment_reason').prop('required', show)", $view);
        $this->assertStringContainsString("'click .edit-data': function(e, value, row)", $view);
        $this->assertStringContainsString("var display = normalized.match(/^(\\d{2})-(\\d{2})-(\\d{4})$/)", $view);
        $this->assertStringContainsString('opening-balance-adjustment-reason-group', $view);
    }
}
