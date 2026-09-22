<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BankAccountCreateFormViewTest extends TestCase
{
    public function test_legacy_create_form_is_hidden_by_the_read_only_retirement_gate(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/bank-account/index.blade.php');

        $this->assertStringContainsString('@php($historicalLegacyWorkspace = true)', $view);
        $this->assertStringContainsString('@unless($historicalLegacyWorkspace)', $view);
        $this->assertStringContainsString('central-finance-legacy-historical-notice', $view);
    }
}
