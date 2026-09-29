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
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->database = tempnam(sys_get_temp_dir(), 'collection-v2-runner-');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');

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
        Config::set('database.connections.mysql', $this->mysqlConnection);
        @unlink($this->database);
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
}
