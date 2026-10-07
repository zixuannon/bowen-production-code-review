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

    public function test_collection_v2_runner_allows_only_the_exact_student_discount_migrations_in_production(): void
    {
        $guard = new ProductionMigrationGuard();
        $paths = [
            database_path('migrations/2026_10_02_000001_add_student_scope_to_central_finance_promotions.php'),
            database_path('migrations/schools/2026_10_02_000001_add_student_specific_discount_drafts.php'),
        ];

        $guard->assertAllowed('migrate', 'finance:migrate-collection-v2', $paths, true, true);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $guard->assertAllowed(
            'migrate',
            'finance:migrate-collection-v2',
            [database_path('migrations/schools')],
            true,
            true,
        );
    }

    public function test_guard_does_not_change_local_or_test_migration_behavior(): void
    {
        (new ProductionMigrationGuard())->assertAllowed('migrate', 'migrate', [], false, false);
        $this->addToAssertionCount(1);
    }

    public function test_classification_actor_runner_allows_only_its_exact_central_extension(): void
    {
        $guard = new ProductionMigrationGuard();
        $guard->assertAllowed('migrate', 'finance:migrate-classification-actors', [
            database_path('migrations/2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php'),
        ], true, true);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $guard->assertAllowed('migrate', 'finance:migrate-classification-actors', [
            database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php'),
        ], true, true);
    }

    public function test_qa_run_runner_allows_only_the_pinned_exact_central_migration(): void
    {
        $guard = new ProductionMigrationGuard();
        $migration = database_path('migrations/2026_10_05_000001_create_central_finance_qa_runs.php');
        $guard->assertAllowed('migrate', 'finance:qa-runs-migrate', [$migration], true, true);
        $this->addToAssertionCount(1);

        foreach ([
            database_path('migrations/2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php'),
            database_path('migrations/schools/2026_10_05_000001_create_central_finance_qa_runs.php'),
            database_path('migrations/2026_10_06_999999_unknown.php'),
        ] as $unapproved) {
            try {
                $guard->assertAllowed('migrate', 'finance:qa-runs-migrate', [$unapproved], true, true);
                $this->fail('Unrelated or tenant migration was accepted by the QA Run runner.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not in the selected runner allowlist', $exception->getMessage());
            }
        }
    }

    public function test_p1a_due_date_runner_allows_only_its_exact_tenant_migration_in_production(): void
    {
        $guard = new ProductionMigrationGuard();
        $migration = database_path('migrations/schools/2026_10_05_000001_make_fee_due_date_nullable.php');

        $guard->assertAllowed('migrate', 'fees:due-date-schema', [$migration], true, true);
        $this->addToAssertionCount(1);

        foreach ([
            database_path('migrations/schools/2026_10_05_000001_create_central_finance_qa_runs.php'),
            database_path('migrations/schools'),
            $migration,
        ] as $index => $unapproved) {
            try {
                $guard->assertAllowed(
                    'migrate',
                    $index === 2 ? 'finance:qa-runs-migrate' : 'fees:due-date-schema',
                    [$unapproved],
                    true,
                    true,
                );
                $this->fail('An unrelated path, directory, or runner was accepted by the P1-A due-date guard.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not in the selected runner allowlist', $exception->getMessage());
            }
        }
    }

    public function test_qa_run_migration_hash_must_match_the_pinned_file_identity(): void
    {
        $relative = 'database/migrations/2026_10_05_000001_create_central_finance_qa_runs.php';
        $real = base_path($relative);
        $this->assertTrue(ProductionMigrationGuard::matchesPinnedMigration($relative, $real, base_path()));
        $this->assertFalse(ProductionMigrationGuard::matchesPinnedMigration($relative, __FILE__, base_path()));
        $this->assertFalse(ProductionMigrationGuard::matchesPinnedMigration('database/migrations/unknown.php', $real, base_path()));

        $root = sys_get_temp_dir().'/qa-run-migration-hash-'.bin2hex(random_bytes(5));
        $copy = $root.'/'.$relative;
        mkdir(dirname($copy), 0777, true);
        file_put_contents($copy, file_get_contents($real)."\n// altered identity\n");
        try {
            $this->assertFalse(ProductionMigrationGuard::matchesPinnedMigration($relative, $copy, $root));
        } finally {
            unlink($copy);
            rmdir(dirname($copy));
            rmdir(dirname(dirname($copy)));
            rmdir(dirname(dirname(dirname($copy))));
        }
    }

    public function test_qa_run_command_requires_the_exact_approved_production_baseline(): void
    {
        $this->assertTrue(\App\Console\Commands\MigrateCentralFinanceQaRuns::hasApprovedProductionBaseline([
            'accepted_production_sha' => '7d6e73c12f6de23c24e5dd62312df53fcef8d497',
        ]));
        $this->assertFalse(\App\Console\Commands\MigrateCentralFinanceQaRuns::hasApprovedProductionBaseline([
            'accepted_production_sha' => '6b9ec7feec56e2b96907558a9d5e984fc60a0e21',
        ]));
    }

    public function test_qa_run_staged_migration_requires_exact_active_baseline_and_candidate_release_identity(): void
    {
        $command = \App\Console\Commands\MigrateCentralFinanceQaRuns::class;
        $baseline = '7d6e73c12f6de23c24e5dd62312df53fcef8d497';
        $active = 'b87bac3a2bc6eaf32cada9cdbfa19c565d5e61b2';
        $candidate = 'd06f31d06833ca702d647999168d5ef085ec9218';

        $this->assertTrue($command::hasExpectedActiveProductionIdentity([
            'commit_sha' => $active, 'baseline_sha' => $baseline,
        ], $active));
        $this->assertFalse($command::hasExpectedActiveProductionIdentity([
            'commit_sha' => '74d7a3608e23ceebe4a624562a55a562e7d53ff4', 'baseline_sha' => $baseline,
        ], '74d7a3608e23ceebe4a624562a55a562e7d53ff4'));
        $this->assertFalse($command::hasExpectedActiveProductionIdentity([
            'commit_sha' => $active, 'baseline_sha' => '6b9ec7feec56e2b96907558a9d5e984fc60a0e21',
        ], $active));

        $this->assertTrue($command::hasApprovedCandidateIdentity([
            'commit_sha' => $candidate, 'baseline_sha' => $baseline,
        ], $candidate));
        $this->assertFalse($command::hasApprovedCandidateIdentity([
            'commit_sha' => $candidate, 'baseline_sha' => '6b9ec7feec56e2b96907558a9d5e984fc60a0e21',
        ], $candidate));
        $this->assertFalse($command::hasApprovedCandidateIdentity([
            'commit_sha' => $active, 'baseline_sha' => $baseline,
        ], $active));
    }

    public function test_qa_run_deployment_attestation_requires_runtime_user_and_root_parent(): void
    {
        $command = \App\Console\Commands\MigrateCentralFinanceQaRuns::class;
        $candidate = 'e9d0be2efe82bd0882377799d17ea9fb5d6efbfe';
        $this->assertTrue($command::hasTrustedDeploymentProcess($candidate, $candidate, 1002, 1002, 0));
        $this->assertFalse($command::hasTrustedDeploymentProcess($candidate, str_repeat('0', 40), 1002, 1002, 0));
        $this->assertFalse($command::hasTrustedDeploymentProcess($candidate, $candidate, 1002, 1002, 1002));
        $this->assertFalse($command::hasTrustedDeploymentProcess($candidate, $candidate, 1001, 1002, 0));
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
