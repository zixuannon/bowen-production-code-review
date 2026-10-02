<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateFinanceCollectionV2;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class FinanceCollectionV2MigrationRunnerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;
    /** @var array<string, mixed> */
    private array $schoolConnection;
    private string $database;
    private string $schoolDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->database = tempnam(sys_get_temp_dir(), 'collection-v2-runner-');
        $this->schoolDatabase = tempnam(sys_get_temp_dir(), 'collection-v2-school-');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        Config::set('database.connections.school', [
            'driver' => 'sqlite', 'database' => $this->schoolDatabase, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('school');

        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('database_name')->unique();
            $table->integer('status')->default(1);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        DB::purge('school');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        Config::set('database.connections.school', $this->schoolConnection);
        @unlink($this->database);
        @unlink($this->schoolDatabase);
        parent::tearDown();
    }

    public function test_numeric_status_registry_excludes_inactive_schools_without_mysql_literal_coercion(): void
    {
        DB::connection('mysql')->table('schools')->insert([
            ['code' => 'SCH20261', 'database_name' => 'inactive_demo', 'status' => 0],
            ['code' => 'MMBOWEN01', 'database_name' => 'active_zixuan', 'status' => 1],
        ]);

        $method = new ReflectionMethod(MigrateFinanceCollectionV2::class, 'trustedTenants');
        $method->setAccessible(true);
        /** @var array<string, string> $tenants */
        $tenants = $method->invoke(app(MigrateFinanceCollectionV2::class));

        $this->assertSame(['MMBOWEN01' => 'active_zixuan'], $tenants);
    }

    public function test_promotion_selection_migration_is_additive_and_reversible_for_a_tenant_snapshot_table(): void
    {
        Schema::connection('school')->create('student_fee_assignment_items', function ($table): void {
            $table->id();
            $table->unsignedInteger('quantity_snapshot')->default(1);
        });

        $migration = require database_path('migrations/schools/2026_09_30_000001_add_student_fee_assignment_promotion_selection.php');
        $migration->up();
        $this->assertTrue(Schema::connection('school')->hasColumn('student_fee_assignment_items', 'selected_promotion_id'));
        $this->assertTrue(collect(Schema::connection('school')->getIndexes('student_fee_assignment_items'))
            ->contains(fn (array $index): bool => ($index['name'] ?? '') === 'sfa_item_selected_promotion_idx'));

        $migration->down();
        $this->assertFalse(Schema::connection('school')->hasColumn('student_fee_assignment_items', 'selected_promotion_id'));
    }

    public function test_student_specific_discount_draft_migration_is_additive_and_reversible(): void
    {
        Schema::connection('school')->create('student_fee_assignment_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('selected_promotion_id')->nullable();
        });

        $migration = require database_path('migrations/schools/2026_10_02_000001_add_student_specific_discount_drafts.php');
        $migration->up();
        $this->assertTrue(Schema::connection('school')->hasColumns('student_fee_assignment_items', [
            'student_discount_type', 'student_discount_value', 'student_discount_reason', 'student_discount_effective_date',
        ]));

        $migration->down();
        $this->assertFalse(Schema::connection('school')->hasColumn('student_fee_assignment_items', 'student_discount_type'));
    }
}
