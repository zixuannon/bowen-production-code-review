<?php

namespace Tests\Unit;

use App\Services\BootstrapTableService;
use Tests\TestCase;

class BootstrapTableServiceTest extends TestCase
{
    public function test_expense_delete_action_uses_the_reason_dialog_marker(): void
    {
        $button = BootstrapTableService::deleteButtonWithReason('/expense/42');

        $this->assertStringContainsString('href="/expense/42"', $button);
        $this->assertStringContainsString('delete-form-reason', $button);
        $this->assertStringContainsString('fa fa-trash', $button);
    }
}
