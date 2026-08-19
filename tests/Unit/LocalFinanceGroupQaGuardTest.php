<?php

namespace Tests\Unit;

use App\Console\Commands\LocalFinanceGroupQa;
use App\Support\LocalQaTenantGuard;
use LogicException;
use Tests\TestCase;

class LocalFinanceGroupQaGuardTest extends TestCase
{
    public function test_fixed_group_qa_allowlist_accepts_only_the_three_local_tenants(): void
    {
        LocalQaTenantGuard::assertEnvironment('local', 'http://127.0.0.1:8000', 'eschool_local', LocalFinanceGroupQa::TENANTS);
        foreach (LocalFinanceGroupQa::TENANTS as $code => $database) LocalQaTenantGuard::assertTenant($code, $database, LocalFinanceGroupQa::TENANTS);
        $this->assertCount(3, LocalFinanceGroupQa::TENANTS);
    }

    public function test_group_qa_guard_rejects_production_staging_and_arbitrary_tenants(): void
    {
        foreach ([['production','http://127.0.0.1:8000','eschool_local'], ['local','https://school.mmbowen.com','eschool_local'], ['local','http://127.0.0.1:8000','eschool_saas_15_zixuan']] as [$env,$url,$database]) {
            try { LocalQaTenantGuard::assertEnvironment($env,$url,$database,LocalFinanceGroupQa::TENANTS); $this->fail('Unsafe environment accepted.'); } catch (LogicException) { $this->assertTrue(true); }
        }
        $this->expectException(LogicException::class);
        LocalQaTenantGuard::assertTenant('GROUP_QA_SCHOOL_A','eschool_saas_15_zixuan',LocalFinanceGroupQa::TENANTS);
    }
}
