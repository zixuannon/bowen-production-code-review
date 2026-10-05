<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralFinanceDataIsolation;
use App\Console\Commands\MigrateFinanceClassificationActors;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** SQLite by default; opt-in fresh localhost MySQL rehearsal uses its own database. */
final class FinanceClassificationActorMigrationTest extends TestCase
{
    private const RECORDS = 'central_finance_data_classifications';
    private const AUDITS = 'central_finance_data_classification_audits';
    private ?string $temporaryFile = null;
    private ?string $rehearsalDatabase = null;
    private array $legacyColumns = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLASSIFICATION_ACTOR_MYSQL_REHEARSAL') === '1') {
            $connection = config('database.connections.mysql');
            if (!app()->environment('testing')
                || !in_array($connection['host'], ['127.0.0.1', 'localhost'], true)
                || $connection['database'] !== 'eschool_testing') {
                $this->fail('Classification rehearsal requires the localhost eschool_testing configuration.');
            }
            config(['database.connections.classification_rehearsal_admin' => $connection]);
            $database = 'eschool_actor_rehearsal_'.getmypid().'_'.bin2hex(random_bytes(4));
            DB::connection('classification_rehearsal_admin')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->rehearsalDatabase = $database;
            $connection['database'] = $database;
        } else {
            $this->temporaryFile = tempnam(sys_get_temp_dir(), 'classification_actor_');
            $connection = ['driver' => 'sqlite', 'database' => $this->temporaryFile, 'prefix' => '', 'foreign_key_constraints' => true];
        }
        config(['database.connections.mysql' => $connection]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        foreach (['schools', 'users', 'central_finance_fund_accounts', 'central_finance_ledger_entries'] as $name) {
            Schema::connection('mysql')->create($name, fn (Blueprint $table) => $table->id());
        }
        (require database_path('migrations/'.MigrateCentralFinanceDataIsolation::MIGRATION.'.php'))->up();
        DB::connection('mysql')->table('migrations')->insert(['migration' => MigrateCentralFinanceDataIsolation::MIGRATION, 'batch' => 1]);
        DB::connection('mysql')->table('schools')->insert(['id' => 15]);
        DB::connection('mysql')->table('users')->insert(['id' => 1]);
        DB::connection('mysql')->table(self::RECORDS)->insert($this->classificationRow(1));
        DB::connection('mysql')->table(self::AUDITS)->insert($this->auditRow(1));
        foreach ([self::RECORDS, self::AUDITS] as $table) {
            $this->legacyColumns[$table] = Schema::connection('mysql')->getColumnListing($table);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        if ($this->temporaryFile) {
            @unlink($this->temporaryFile);
        }
        if ($this->rehearsalDatabase) {
            DB::connection('classification_rehearsal_admin')->statement("DROP DATABASE `{$this->rehearsalDatabase}`");
            DB::purge('classification_rehearsal_admin');
        }
        parent::tearDown();
    }

    public function test_exact_runner_preserves_historical_values_and_supports_central_and_tenant_actors(): void
    {
        $historical = $this->historicalSnapshot();
        $foreignKeys = $this->foreignKeys();
        $tables = Schema::connection('mysql')->getTableListing();
        $this->artisan('finance:migrate-classification-actors')->expectsOutput('central_classification_actors=eligible')->assertExitCode(0);
        $this->assertFalse(Schema::connection('mysql')->hasColumn(self::AUDITS, 'actor_scope'));
        $this->assertSame($historical, $this->historicalSnapshot());

        $this->apply();
        $this->assertSame($historical, $this->historicalSnapshot());
        $this->assertSame($tables, Schema::connection('mysql')->getTableListing());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertSame(2, DB::connection('mysql')->table('migrations')->count());
        foreach ([self::RECORDS, self::AUDITS] as $table) {
            $historicalRow = DB::connection('mysql')->table($table)->where('id', 1)->first();
            $this->assertNull($historicalRow->actor_scope);
            $this->assertNull($historicalRow->actor_school_id);
            $this->assertNull($historicalRow->actor_tenant_user_id);
        }
        $this->assertNull(DB::connection('mysql')->table(self::AUDITS)->value('action'));
        DB::connection('mysql')->table(self::RECORDS)->insert($this->classificationRow(2));
        DB::connection('mysql')->table(self::AUDITS)->insert($this->auditRow(2));
        $tenantActor = ['actor_scope' => 'tenant', 'actor_school_id' => 15, 'actor_tenant_user_id' => 900001];
        DB::connection('mysql')->table(self::RECORDS)->insert(array_replace($this->classificationRow(3), $tenantActor, ['classified_by' => null]));
        DB::connection('mysql')->table(self::AUDITS)->insert(array_replace($this->auditRow(3), $tenantActor, ['actor_id' => null, 'action' => 'inherit_school']));
        $this->assertSame(3, DB::connection('mysql')->table(self::AUDITS)->count());
        $this->assertNull(DB::connection('mysql')->table(self::AUDITS)->where('id', 3)->value('actor_id'));
        $this->artisan('finance:migrate-classification-actors', ['--execute' => true])->expectsOutput('central_classification_actors=complete')->assertExitCode(0);
        $this->assertSame(2, DB::connection('mysql')->table('migrations')->count());
    }

    public function test_unused_extension_rolls_back_and_reapplies_without_changing_history(): void
    {
        $historical = $this->historicalSnapshot();
        $foreignKeys = $this->foreignKeys();
        $this->apply();
        $this->sqliteAlterCompatibility(fn () => $this->migration()->down());
        DB::connection('mysql')->table('migrations')->where('migration', MigrateFinanceClassificationActors::MIGRATION)->delete();
        foreach ([self::RECORDS => 'classified_by', self::AUDITS => 'actor_id'] as $table => $actor) {
            $this->assertFalse(Schema::connection('mysql')->hasColumn($table, 'actor_scope'));
            $column = collect(Schema::connection('mysql')->getColumns($table))->keyBy('name')->get($actor);
            $this->assertFalse($column['nullable']);
        }
        $this->assertSame($historical, $this->historicalSnapshot());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        if (DB::connection('mysql')->getDriverName() === 'mysql') {
            $this->assertSame(1, (int) DB::connection('mysql')->selectOne('SELECT @@SESSION.foreign_key_checks AS checks')->checks);
        }
        $this->apply();
        $this->assertSame($historical, $this->historicalSnapshot());
    }

    public function test_rollback_checks_audits_before_mutating_either_table(): void
    {
        $this->apply();
        DB::connection('mysql')->table(self::AUDITS)->insert(array_replace($this->auditRow(2), [
            'classification_id' => 1, 'actor_id' => null, 'actor_scope' => 'tenant',
            'actor_school_id' => 15, 'actor_tenant_user_id' => 900001, 'action' => 'inherit_school',
        ]));
        $before = $this->completeSnapshot();
        try {
            $this->migration()->down();
            $this->fail('Rollback must preserve tenant actor history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-fix', $exception->getMessage());
        }
        $this->assertSame($before, $this->completeSnapshot());
        $this->artisan('finance:migrate-classification-actors')->assertExitCode(0);
    }

    public function test_central_actor_foreign_keys_remain_enforced(): void
    {
        $this->apply();
        foreach ([self::RECORDS => ['classified_by', $this->classificationRow(2)], self::AUDITS => ['actor_id', array_replace($this->auditRow(2), ['classification_id' => 1])]] as $table => [$actor, $row]) {
            try {
                DB::connection('mysql')->table($table)->insert(array_replace($row, [$actor => 900001]));
                $this->fail('Tenant-local ID must not satisfy the Central users foreign key.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rollback_preserves_new_central_action_history_too(): void
    {
        $this->apply();
        DB::connection('mysql')->table(self::AUDITS)->where('id', 1)->update(['action' => 'classify']);
        $before = $this->completeSnapshot();
        try {
            $this->migration()->down();
            $this->fail('Rollback must preserve the new Central action history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-fix', $exception->getMessage());
        }
        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_rollback_preserves_tenant_attribution_on_classification_without_an_audit(): void
    {
        $this->apply();
        DB::connection('mysql')->table(self::RECORDS)->where('id', 1)->update([
            'classified_by' => null, 'actor_scope' => 'tenant', 'actor_school_id' => 15, 'actor_tenant_user_id' => 900001,
        ]);
        $before = $this->completeSnapshot();
        try {
            $this->migration()->down();
            $this->fail('Rollback must preserve classification attribution.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-fix', $exception->getMessage());
        }
        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_partial_schema_and_missing_prerequisite_history_fail_without_writes(): void
    {
        Schema::connection('mysql')->table(self::RECORDS, fn (Blueprint $table) => $table->string('actor_scope', 20)->nullable());
        $before = $this->completeSnapshot();
        $this->artisan('finance:migrate-classification-actors', ['--execute' => true])->assertExitCode(1);
        $this->assertSame($before, $this->completeSnapshot());
        DB::connection('mysql')->table('migrations')->where('migration', MigrateCentralFinanceDataIsolation::MIGRATION)->delete();
        $before = $this->completeSnapshot();
        $this->artisan('finance:migrate-classification-actors', ['--execute' => true])->assertExitCode(1);
        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_recorded_migration_with_wrong_actor_column_type_fails_closed(): void
    {
        $this->apply();
        Schema::connection('mysql')->table(self::AUDITS, fn (Blueprint $table) => $table->string('actor_tenant_user_id', 64)->nullable()->change());
        $before = $this->completeSnapshot();
        $this->artisan('finance:migrate-classification-actors', ['--execute' => true])->assertExitCode(1);
        $this->assertSame($before, $this->completeSnapshot());
    }

    private function apply(): void
    {
        $this->sqliteAlterCompatibility(function (): void {
            $exit = Artisan::call('finance:migrate-classification-actors', ['--execute' => true]);
            $this->assertSame(0, $exit, Artisan::output());
        });
    }

    private function sqliteAlterCompatibility(callable $callback): void
    {
        // Doctrine implements SQLite ALTER by rebuilding the referenced table.
        // Only the SQLite test adapter needs this; MySQL rehearsal keeps FKs ON.
        $sqlite = DB::connection('mysql')->getDriverName() === 'sqlite';
        if ($sqlite) {
            Schema::connection('mysql')->disableForeignKeyConstraints();
        }
        try {
            $callback();
        } finally {
            if ($sqlite) {
                Schema::connection('mysql')->enableForeignKeyConstraints();
                $this->assertSame([], DB::connection('mysql')->select('PRAGMA foreign_key_check'));
            }
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/'.MigrateFinanceClassificationActors::MIGRATION.'.php');
    }

    private function historicalSnapshot(): string
    {
        $rows = [];
        foreach ($this->legacyColumns as $table => $columns) {
            $rows[$table] = DB::connection('mysql')->table($table)->select($columns)->orderBy('id')->get()->all();
        }
        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    private function foreignKeys(): array
    {
        $keys = [
            self::RECORDS => Schema::connection('mysql')->getForeignKeys(self::RECORDS),
            self::AUDITS => Schema::connection('mysql')->getForeignKeys(self::AUDITS),
        ];
        if (DB::connection('mysql')->getDriverName() === 'sqlite') {
            // Doctrine normalizes SQLite RESTRICT to immediate NO ACTION during
            // table rebuilds. MySQL rehearsal compares the exact original FKs.
            foreach ($keys as &$tableKeys) {
                foreach ($tableKeys as &$key) {
                    if ($key['on_delete'] === 'restrict') {
                        $key['on_delete'] = 'no action';
                    }
                }
            }
        }
        return $keys;
    }

    private function completeSnapshot(): string
    {
        $state = [];
        foreach ([self::RECORDS, self::AUDITS, 'migrations'] as $table) {
            $state[$table] = [Schema::connection('mysql')->getColumns($table), DB::connection('mysql')->table($table)->orderBy('id')->get()->all()];
        }
        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    private function classificationRow(int $id): array
    {
        return ['id' => $id, 'classification_uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'school_id' => 15, 'subject_scope' => 'tenant:15', 'subject_type' => 'user', 'subject_id' => $id,
            'classification' => 'test', 'reason' => 'Historical QA reason 保留', 'classified_by' => 1,
            'created_at' => '2026-09-14 12:34:56', 'updated_at' => '2026-09-14 12:34:56'];
    }

    private function auditRow(int $id): array
    {
        return ['id' => $id, 'audit_uuid' => sprintf('10000000-0000-4000-8000-%012d', $id),
            'classification_id' => $id, 'school_id' => 15, 'subject_scope' => 'tenant:15', 'subject_type' => 'user', 'subject_id' => $id,
            'before_classification' => null, 'after_classification' => 'test', 'reason' => 'Historical QA reason 保留',
            'actor_id' => 1, 'created_at' => '2026-09-14 12:34:56'];
    }
}
