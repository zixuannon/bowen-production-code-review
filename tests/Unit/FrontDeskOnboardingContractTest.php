<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FrontDeskOnboardingContractTest extends TestCase
{
    public function test_front_desk_role_is_exposed_without_implicit_finance_grants(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/app/Http/Controllers/StaffController.php');
        $view = file_get_contents($root . '/resources/views/staff/index.blade.php');
        $command = file_get_contents($root . '/app/Console/Commands/ProvisionFrontDeskRole.php');

        self::assertStringContainsString('Front Desk / Admissions & Collection', $controller);
        self::assertStringContainsString("name=\"role_ids[]\"", $view);
        self::assertStringContainsString("submit_collections", file_get_contents($root . '/app/Http/Controllers/FinanceGroupController.php'));
        self::assertStringContainsString('school:provision-front-desk-role {school_code}', $command);
        self::assertStringNotContainsString('operate_finance', $command);
    }
}
