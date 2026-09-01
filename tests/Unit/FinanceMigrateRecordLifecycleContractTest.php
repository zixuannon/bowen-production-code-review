<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../app/Console/Commands/FinanceMigrateRecordLifecycle.php';

use App\Console\Commands\FinanceMigrateRecordLifecycle;

class FinanceMigrateRecordLifecycleContractTest extends TestCase
{
    public function test_runner_has_a_fixed_active_school_and_migration_allowlist(): void
    {
        self::assertSame([
            '2026_09_01_000001_create_school_record_lifecycle_audits_table',
            '2026_09_01_000002_add_deleted_at_to_fees_class_types',
        ], FinanceMigrateRecordLifecycle::MIGRATIONS);
        self::assertSame([
            'SCH202615', 'SCH202616', 'SCH202619', 'SCH202620', 'SCH202621', 'SCH202631', 'SCH202632',
        ], array_keys(FinanceMigrateRecordLifecycle::PRODUCTION_TENANTS));
        self::assertArrayNotHasKey('SCH20261', FinanceMigrateRecordLifecycle::PRODUCTION_TENANTS);
    }

    public function test_runner_refuses_arbitrary_migration_and_database_input(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Console/Commands/FinanceMigrateRecordLifecycle.php');

        self::assertStringNotContainsString('{--migration', $source);
        self::assertStringNotContainsString('{--database', $source);
        self::assertStringContainsString('Demo, unknown Schools, and database names are refused', $source);
        self::assertStringContainsString('SCH202615 alone for canary', $source);
        self::assertStringContainsString('remaining six active Schools', $source);
    }
}
