<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralFinanceDataIsolation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Opt-in disposable local MySQL rehearsal for the exact central metadata DDL. */
final class CentralFinanceDataIsolationMysqlRehearsalTest extends TestCase
{
    private string $database;
    private array $originalConnection;
    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DATA_ISOLATION_MYSQL_REHEARSAL') !== '1') {
            $this->markTestSkipped('Set DATA_ISOLATION_MYSQL_REHEARSAL=1 for disposable local MySQL rehearsal.');
        }
        $host = (string) config('database.connections.mysql.host');
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $this->fail("Disposable rehearsal refuses non-local MySQL host: {$host}");
        }
        $this->database = 'eschool_data_isolation_'.getmypid().'_'.bin2hex(random_bytes(4));
        if (!preg_match('/\Aeschool_data_isolation_[a-z0-9_]+\z/D', $this->database)) {
            $this->fail('Unsafe disposable database name.');
        }
        $this->originalConnection = config('database.connections.mysql');
        DB::connection('mysql')->statement("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->created = true;
        $connection = $this->originalConnection;
        $connection['database'] = $this->database;
        Config::set('database.connections.mysql', $connection);
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        if (isset($this->originalConnection)) {
            Config::set('database.connections.mysql', $this->originalConnection);
            if ($this->created) {
                DB::connection('mysql')->statement("DROP DATABASE `{$this->database}`");
            }
        }
        parent::tearDown();
    }

    public function test_exact_additive_migration_and_constraints_on_fresh_mysql(): void
    {
        Schema::connection('mysql')->create('migrations', fn (Blueprint $table) => [
            $table->increments('id'), $table->string('migration'), $table->integer('batch'),
        ]);
        Schema::connection('mysql')->create('schools', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('users', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('central_finance_fund_accounts', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('central_finance_ledger_entries', fn (Blueprint $table) => $table->id());

        $this->artisan('finance:migrate-data-isolation')->assertExitCode(0);
        $this->artisan('finance:migrate-data-isolation', ['--execute'=>true])->assertExitCode(0);

        $this->assertTrue(Schema::connection('mysql')->hasColumns('central_finance_data_classifications', [
            'classification_uuid', 'school_id', 'subject_scope', 'subject_type', 'subject_id', 'classification', 'reason', 'classified_by',
        ]));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_data_classification_audits'));
        $indexes = collect(Schema::connection('mysql')->getIndexes('central_finance_data_classifications'));
        $this->assertTrue((bool) $indexes->firstWhere('name', 'cf_data_classification_subject_unique')['unique']);
        $this->assertDatabaseHas('migrations', ['migration'=>MigrateCentralFinanceDataIsolation::MIGRATION], 'mysql');
    }
}
