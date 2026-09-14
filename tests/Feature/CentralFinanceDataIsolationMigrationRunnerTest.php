<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralFinanceDataIsolation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CentralFinanceDataIsolationMigrationRunnerTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_isolation_runner_');
        Config::set('database.connections.mysql', [
            'driver'=>'sqlite', 'database'=>$this->database, 'prefix'=>'', 'foreign_key_constraints'=>true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('migrations', fn (Blueprint $table) => [
            $table->id(), $table->string('migration'), $table->integer('batch'),
        ]);
        Schema::connection('mysql')->create('schools', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('users', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('central_finance_fund_accounts', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('central_finance_ledger_entries', fn (Blueprint $table) => $table->id());
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_exact_path_runner_preflights_and_applies_only_the_additive_metadata_schema(): void
    {
        $this->artisan('finance:migrate-data-isolation')->assertExitCode(0);
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_data_classifications'));

        $this->artisan('finance:migrate-data-isolation', ['--execute'=>true])->assertExitCode(0);
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_data_classifications'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_data_classification_audits'));
        $this->assertDatabaseHas('migrations', ['migration'=>MigrateCentralFinanceDataIsolation::MIGRATION], 'mysql');
        $this->artisan('finance:migrate-data-isolation')->assertExitCode(0);
    }

    public function test_partial_schema_fails_closed_without_any_additional_schema_write(): void
    {
        Schema::connection('mysql')->create('central_finance_data_classifications', fn (Blueprint $table) => $table->id());
        $beforeTables = Schema::connection('mysql')->getTables();

        $this->artisan('finance:migrate-data-isolation', ['--execute'=>true])->assertExitCode(1);

        $this->assertSame($beforeTables, Schema::connection('mysql')->getTables());
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_data_classification_audits'));
        $this->assertDatabaseMissing('migrations', ['migration'=>MigrateCentralFinanceDataIsolation::MIGRATION], 'mysql');
    }
}
