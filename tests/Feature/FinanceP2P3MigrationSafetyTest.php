<?php

namespace Tests\Feature;

use App\Console\Commands\FinanceP2P3MigrationSafety;
use Tests\TestCase;

class FinanceP2P3MigrationSafetyTest extends TestCase
{
    public function test_tenant_allowlist_rejects_unknown_and_duplicate_tenants(): void
    {
        $this->assertTrue(FinanceP2P3MigrationSafety::validTenantSelection(['eschool_saas_1_demo']));
        $this->assertFalse(FinanceP2P3MigrationSafety::validTenantSelection(['unknown']));
        $this->assertFalse(FinanceP2P3MigrationSafety::validTenantSelection(['eschool_saas_1_demo', 'eschool_saas_1_demo']));
    }
    public function test_migration_path_allowlist_is_exactly_the_two_p2_p3_files(): void
    {
        $this->assertSame(['2026_08_11_000001_create_bank_account_user_table', '2026_08_12_000001_create_fund_handovers_table'], FinanceP2P3MigrationSafety::MIGRATIONS);
        foreach (FinanceP2P3MigrationSafety::paths() as $path) $this->assertFileExists($path);
    }
    public function test_partial_and_already_applied_states_are_distinguished_without_unrelated_migrations(): void
    {
        $this->assertSame('none', FinanceP2P3MigrationSafety::classify([]));
        $this->assertSame('partial', FinanceP2P3MigrationSafety::classify([FinanceP2P3MigrationSafety::MIGRATIONS[0], 'unrelated_migration']));
        $this->assertSame('both', FinanceP2P3MigrationSafety::classify([FinanceP2P3MigrationSafety::MIGRATIONS[0], FinanceP2P3MigrationSafety::MIGRATIONS[1], 'unrelated_migration']));
    }

    public function test_clean_none_state_is_a_valid_verification_state_before_cutover(): void
    {
        $this->assertSame('none', FinanceP2P3MigrationSafety::classify([]));
    }
}
