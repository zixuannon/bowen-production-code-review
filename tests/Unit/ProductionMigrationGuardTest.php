<?php

namespace Tests\Unit;

use App\Services\ProductionMigrationGuard;
use RuntimeException;
use Tests\TestCase;

class ProductionMigrationGuardTest extends TestCase
{
    public function test_generic_and_legacy_school_migrations_are_blocked_in_production(): void
    {
        $guard = new ProductionMigrationGuard();
        foreach (['migrate', 'migrate:school', 'migrate:fresh', 'migrate:school:rollback'] as $command) {
            try {
                $guard->assertAllowed($command, $command, [], false, true);
                $this->fail("{$command} was not blocked.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('disabled', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_exact_file_from_allowlisted_runner_is_the_only_production_exception(): void
    {
        $guard = new ProductionMigrationGuard();
        $path = database_path('migrations/schools/2026_08_11_000001_create_bank_account_user_table.php');
        $guard->assertAllowed('migrate', 'finance:p2-p3-migration-safety', [$path], true, true);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $guard->assertAllowed('migrate', 'finance:p2-p3-migration-safety', [database_path('migrations/schools')], true, true);
    }

    public function test_round4_currency_history_runner_is_exact_path_allowlisted(): void
    {
        $path = database_path('migrations/schools/2026_09_11_000001_add_financial_currency_history_integrity.php');
        (new ProductionMigrationGuard())->assertAllowed(
            'migrate',
            'finance:migrate-currency-history',
            [$path],
            true,
            true,
        );
        $this->addToAssertionCount(1);
    }

    public function test_guard_does_not_change_local_or_test_migration_behavior(): void
    {
        (new ProductionMigrationGuard())->assertAllowed('migrate', 'migrate', [], false, false);
        $this->addToAssertionCount(1);
    }
}
