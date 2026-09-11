<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateRound5IntegritySchema;
use App\Services\LegacySchemaIntegrityService;
use App\Services\ProductionMigrationGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class Round5SchemaIntegrityContractTest extends TestCase
{
    private array $schoolConnection;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolConnection = config('database.connections.school');
        $this->database = tempnam(sys_get_temp_dir(), 'round5-schema-');
        Config::set('database.connections.school', ['driver'=>'sqlite','database'=>$this->database,'prefix'=>'','foreign_key_constraints'=>true]);
        DB::purge('school');
    }

    protected function tearDown(): void
    {
        DB::purge('school'); Config::set('database.connections.school', $this->schoolConnection); @unlink($this->database);
        parent::tearDown();
    }

    public function test_existing_partial_bank_table_is_not_treated_as_migration_success(): void
    {
        Schema::connection('school')->create('bank_accounts', fn (Blueprint $table) => $table->id());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partial schema');
        (require database_path('migrations/schools/2026_06_22_000001_create_bank_accounts_table.php'))->up();
    }

    public function test_existing_partial_identity_table_is_not_treated_as_migration_success(): void
    {
        Schema::connection('school')->create('student_import_identities', fn (Blueprint $table) => $table->id());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partial schema');
        (require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'))->up();
    }

    public function test_existing_partial_transfer_table_is_not_treated_as_migration_success(): void
    {
        Schema::connection('school')->create('bank_transfers', fn (Blueprint $table) => $table->id());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partial schema');
        (require database_path('migrations/schools/2026_06_25_000001_create_bank_transfers_table.php'))->up();
    }

    public function test_runner_is_fixed_exact_path_and_production_allowlisted(): void
    {
        $this->assertCount(7, MigrateRound5IntegritySchema::TENANTS);
        $this->assertSame([
            '2026_09_03_000001_create_student_import_identities_table',
            LegacySchemaIntegrityService::ROUND5_MIGRATION,
        ], MigrateRound5IntegritySchema::MIGRATIONS);
        foreach (MigrateRound5IntegritySchema::paths() as $path) $this->assertFileExists($path);
        $this->assertTrue(MigrateRound5IntegritySchema::validTenantSelection(['SCH202615','SCH202632']));
        $this->assertFalse(MigrateRound5IntegritySchema::validTenantSelection(['SCH202615','SCH202615']));
        $this->assertFalse(MigrateRound5IntegritySchema::validTenantSelection(['unknown']));

        (new ProductionMigrationGuard())->assertAllowed('migrate', 'schema:round5-integrity', MigrateRound5IntegritySchema::paths(), true, true);
        $this->addToAssertionCount(1);
    }

    public function test_constraint_migration_contains_orphan_preflight_and_exact_schema_verification(): void
    {
        $source = file_get_contents(database_path('migrations/schools/'.LegacySchemaIntegrityService::ROUND5_MIGRATION.'.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('assertPreflightClean()', $source);
        $this->assertStringContainsString('round5SchemaComplete()', $source);
        $service = file_get_contents(app_path('Services/LegacySchemaIntegrityService.php'));
        foreach (array_merge(LegacySchemaIntegrityService::INDEXES, LegacySchemaIntegrityService::FOREIGN_KEYS, LegacySchemaIntegrityService::CHECKS) as $name) {
            $this->assertStringContainsString("'$name'", $service);
        }
    }
}
