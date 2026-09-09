<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontDeskOnboardingContractTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_staff_create_edit_and_provisioning_expose_front_desk_without_implicit_finance_access(): void
    {
        $controller = $this->source('app/Http/Controllers/StaffController.php');
        $view = $this->source('resources/views/staff/index.blade.php');
        $service = $this->source('app/Services/SchoolDataService.php');
        $command = $this->source('app/Console/Commands/ProvisionFrontDeskRole.php');

        self::assertSame(2, substr_count($view, 'name="role_ids[]"'));
        self::assertStringContainsString('Front Desk / Admissions & Collection', $controller);
        self::assertStringContainsString('withoutGlobalScopes()', $controller);
        self::assertStringContainsString('role_ids.*', $controller);
        self::assertStringContainsString('assertAssignableStaffRoles', $controller);
        self::assertStringContainsString("if (Auth::user()->school_id) {\n                \$leave_permission", $controller);
        self::assertStringContainsString('Front Desk / Admissions & Collection', $service);
        self::assertStringContainsString('school:provision-front-desk-role {school_code}', $command);
        self::assertStringNotContainsString('operate_finance', $command);
    }

    public function test_central_grant_remains_a_separate_explicit_scope(): void
    {
        $controller = $this->source('app/Http/Controllers/FinanceGroupController.php');
        $identity = $this->source('app/Services/CentralFinanceSchoolStaffIdentityService.php');
        $routes = $this->source('routes/web.php');

        self::assertStringContainsString('grantSchoolFrontDesk', $controller);
        self::assertStringContainsString('can_submit_collections', $identity);
        self::assertStringContainsString('school-staff-front-desks', $routes);
        self::assertStringContainsString('provisionTenantFrontDesk', $identity);
        self::assertStringContainsString('school-staff-front-desks/provision', $routes);
        self::assertStringContainsString("'can_operate' => \$canOperate", $identity);
        self::assertStringNotContainsString('->whereKey($tenantId)', $identity);
    }

}
