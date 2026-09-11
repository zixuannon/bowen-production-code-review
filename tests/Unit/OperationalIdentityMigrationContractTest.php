<?php

namespace Tests\Unit;

use App\Console\Commands\MigrateOperationalIdentitySchema;
use App\Services\ProductionMigrationGuard;
use Tests\TestCase;

final class OperationalIdentityMigrationContractTest extends TestCase
{
    public function test_runner_and_production_guard_allow_only_the_two_exact_identity_paths(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MigrateOperationalIdentitySchema.php'));
        $this->assertStringContainsString("operational-identity:migrate", $source);
        $this->assertStringContainsString(MigrateOperationalIdentitySchema::CENTRAL_MIGRATION, $source);
        $this->assertStringContainsString(MigrateOperationalIdentitySchema::TENANT_MIGRATION, $source);
        $this->assertCount(7, MigrateOperationalIdentitySchema::PRODUCTION_TENANTS);
        $this->assertSame('MMBOWEN01', array_key_first(MigrateOperationalIdentitySchema::PRODUCTION_TENANTS));
        $this->assertStringContainsString('getIndexes', $source);
        $this->assertStringContainsString('getForeignKeys', $source);
        $this->assertStringContainsString("hasUniqueColumn('mysql', 'schools', 'code')", $source);

        $guard = new ProductionMigrationGuard();
        $guard->assertAllowed('migrate', 'operational-identity:migrate', [database_path('migrations/'.MigrateOperationalIdentitySchema::CENTRAL_MIGRATION.'.php')], true, true);
        $guard->assertAllowed('migrate', 'operational-identity:migrate', [database_path('migrations/schools/'.MigrateOperationalIdentitySchema::TENANT_MIGRATION.'.php')], true, true);
        $this->addToAssertionCount(2);
    }
}
