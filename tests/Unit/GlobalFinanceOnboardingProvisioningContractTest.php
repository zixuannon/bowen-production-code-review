<?php

namespace Tests\Unit;

use App\Services\TenantFinanceOnboardingRoleProvisioner;
use App\Services\TenantStaffRoleOnboardingService;
use PHPUnit\Framework\TestCase;

final class GlobalFinanceOnboardingProvisioningContractTest extends TestCase
{
    public function test_canonical_role_contract_and_new_school_hook_are_global(): void
    {
        $this->assertSame([
            'School Accountant',
            'Front Desk / Admissions & Collection',
            'Principal',
        ], TenantStaffRoleOnboardingService::roleNames());

        $source = file_get_contents(__DIR__.'/../../app/Services/SchoolDataService.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('app(TenantFinanceOnboardingRoleProvisioner::class)->provision(', $source);
        $this->assertStringContainsString("'new_school_setup'", $source);
        $this->assertStringNotContainsString('MMBOWEN01', file_get_contents(__DIR__.'/../../app/Services/TenantFinanceOnboardingRoleProvisioner.php'));
        $this->assertStringNotContainsString('MMBOWEN02', file_get_contents(__DIR__.'/../../app/Services/TenantFinanceOnboardingRoleProvisioner.php'));
        $this->assertStringNotContainsString('MMBOWEN03', file_get_contents(__DIR__.'/../../app/Services/TenantFinanceOnboardingRoleProvisioner.php'));
        $this->assertTrue(class_exists(TenantFinanceOnboardingRoleProvisioner::class));
    }
}
