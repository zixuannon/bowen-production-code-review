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

    public function test_bahan_timecity_school_code_runner_is_exact_path_allowlisted(): void
    {
        $guard = new ProductionMigrationGuard();
        $path = database_path('migrations/2026_09_14_000002_canonicalize_bahan_timecity_school_codes.php');

        $guard->assertAllowed(
            'migrate',
            'centralization:migrate-school-codes',
            [$path],
            true,
            true,
        );
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $guard->assertAllowed(
            'migrate',
            'centralization:migrate-school-codes',
            [database_path('migrations/2026_09_11_000001_finalize_school_code_identity.php')],
            true,
            true,
        );
    }

    public function test_kindergarten_school_code_runner_is_exact_path_allowlisted(): void
    {
        $guard = new ProductionMigrationGuard();
        $path = database_path('migrations/2026_09_18_000003_canonicalize_kindergarten_school_code.php');
        $guard->assertAllowed('migrate', 'centralization:migrate-kindergarten-school-code', [$path], true, true);
        $this->addToAssertionCount(1);
    }

    public function test_data_isolation_runner_is_exact_path_allowlisted(): void
    {
        $guard = new ProductionMigrationGuard();
        $path = database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php');

        $guard->assertAllowed('migrate', 'finance:migrate-data-isolation', [$path], true, true);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $guard->assertAllowed(
            'migrate',
            'finance:migrate-data-isolation',
            [database_path('migrations')],
            true,
            true,
        );
    }

    public function test_guard_does_not_change_local_or_test_migration_behavior(): void
    {
        (new ProductionMigrationGuard())->assertAllowed('migrate', 'migrate', [], false, false);
        $this->addToAssertionCount(1);
    }

    public function test_student_import_v2_runner_allows_its_v3_exact_path_in_production(): void
    {
        (new ProductionMigrationGuard())->assertAllowed(
            'migrate',
            'student-import-v2:migrate',
            [database_path('migrations/schools/2026_09_18_000001_add_enrollment_metadata_to_student_import_identities.php')],
            true,
            true,
        );

        $this->assertTrue(true);
    }

    public function test_student_import_v2_runner_rejects_an_unlisted_path_in_production(): void
    {
        $this->expectException(RuntimeException::class);

        (new ProductionMigrationGuard())->assertAllowed(
            'migrate',
            'student-import-v2:migrate',
            [database_path('migrations/schools/2026_06_25_000001_create_bank_transfers_table.php')],
            true,
            true,
        );
    }
}
