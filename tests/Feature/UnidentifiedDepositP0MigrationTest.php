<?php

namespace Tests\Feature;

use App\Services\UnidentifiedDepositP0Migration as Migration;
use App\Services\UnidentifiedDepositP0ReleaseGate;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class UnidentifiedDepositP0MigrationTest extends TestCase
{
    private $centralManager;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost', 'database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        $this->centralManager = DB::getFacadeRoot();
    }

    protected function tearDown(): void
    {
        $this->centralManager->purge('mysql');
        parent::tearDown();
    }

    public function test_exact_migration_remains_pinned_and_command_exposes_no_target_or_path_override(): void
    {
        $path = app(Migration::class)->migrationPath();
        $this->assertSame(Migration::HASH, hash_file('sha256', $path));
        $command = Artisan::all()['finance:unidentified-deposit-p0-migrate'];
        foreach (['path', 'database', 'school', 'force', 'deployment-verified'] as $option) $this->assertFalse($command->getDefinition()->hasOption($option));
        $this->assertTrue($command->getDefinition()->hasOption('preflight'));
    }

    public function test_sqlite_named_mysql_is_rejected_before_any_schema_or_history_write(): void
    {
        $this->artisan('finance:unidentified-deposit-p0-migrate', ['--execute' => true])->assertExitCode(1);
        $this->assertSame([], Schema::connection('mysql')->getTables());
    }

    #[DataProvider('targetCases')]
    public function test_mysql_target_identity_is_checked_without_connecting_to_tenants(string $environment, string $host, string $database, string $resolved, string $url, bool $valid): void
    {
        $this->app['env'] = $environment;
        config(['app.url' => $url]);
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('getDatabaseName')->andReturn($database);
        $connection->shouldReceive('getConfig')->with('host')->andReturn($host);
        $connection->shouldReceive('selectOne')->with('SELECT DATABASE() AS database_name')->andReturn((object) ['database_name' => $resolved]);
        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);
        if (!$valid) $this->expectException(RuntimeException::class);
        app(Migration::class)->assertTarget();
        if ($valid) $this->addToAssertionCount(1);
    }

    public static function targetCases(): array
    {
        return [
            ['testing', '127.0.0.1', 'p0_test', 'p0_test', 'http://localhost', true],
            ['production', 'central.internal', 'sql_43_160_241_126', 'sql_43_160_241_126', 'https://example.test', true],
            ['production', 'central.internal', 'school_8', 'school_8', 'https://example.test', false],
            ['testing', 'remote.internal', 'p0_test', 'p0_test', 'http://localhost', false],
            ['testing', '127.0.0.1', 'sql_43_160_241_126', 'sql_43_160_241_126', 'http://localhost', false],
            ['testing', '127.0.0.1', 'p0_test', 'school_8', 'http://localhost', false],
            ['testing', '127.0.0.1', 'p0_test', 'p0_test', 'https://localhost.attacker.test', false],
            ['staging', '127.0.0.1', 'p0_test', 'p0_test', 'http://localhost', false],
        ];
    }

    public function test_missing_prerequisites_are_not_ready_and_partial_history_is_unexpected(): void
    {
        $service = app(Migration::class);
        $this->assertSame('not_ready', $service->schemaState());
        $this->fixture();
        DB::connection('mysql')->table('migrations')->insert(['migration' => Migration::MIGRATION, 'batch' => 1]);
        $this->assertSame('unexpected', $service->schemaState());
        Schema::connection('mysql')->table('central_finance_payments', fn (Blueprint $t) => $t->string('request_hash', 64)->nullable());
        $this->assertSame('unexpected', $service->schemaState());
    }

    public function test_official_missing_reference_is_rejected_by_the_exact_inventory_before_ddl(): void
    {
        $this->fixture();
        DB::connection('mysql')->table('central_finance_fund_accounts')->insert(['id' => 1, 'account_type' => 'bank', 'currency' => 'MMK']);
        DB::connection('mysql')->table('central_finance_payments')->insert(['id' => 10, 'fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '1200', 'idempotency_key' => hash('sha256', 'official'), 'payment_reference' => '']);
        $service = Mockery::mock(Migration::class)->makePartial();
        $service->shouldReceive('assertTarget')->once(); // SELECT-only SQLite fixture; the real command rejects it.
        $service->shouldReceive('schemaState')->andReturn('eligible');
        $before = $service->financialSnapshot();
        try {
            $service->inspect();
            $this->fail('An official blank bank reference was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('no Bank Reference', $error->getMessage());
        }
        $this->assertSame($before, $service->financialSnapshot());
        $this->assertFalse(Schema::connection('mysql')->hasTable(Migration::IDENTITIES));
        $this->assertSame(0, DB::connection('mysql')->table('migrations')->count());
    }

    public function test_default_preflight_calls_no_release_gate_or_migration(): void
    {
        $service = Mockery::mock(Migration::class);
        $service->shouldReceive('inspect')->once()->andReturn($this->state('eligible'));
        $this->app->instance(Migration::class, $service);
        $gate = $this->fakeGate();
        Artisan::shouldReceive('call')->never();
        $this->assertSame(0, $this->runCommand());
        $this->assertSame([], $gate->calls);
    }

    public function test_complete_execute_is_a_verified_noop_without_migration_or_history_write(): void
    {
        $service = Mockery::mock(Migration::class);
        $service->shouldReceive('inspect')->once()->andReturn($this->state('complete'));
        $service->shouldReceive('assertMigrationPreserved')->once();
        $this->app->instance(Migration::class, $service);
        $gate = $this->fakeGate();
        Artisan::shouldReceive('call')->never();
        $this->assertSame(0, $this->runCommand(['--execute' => true]));
        $this->assertSame([true], $gate->calls);
    }

    public function test_execute_only_calls_exact_central_path_and_verifies_freeze(): void
    {
        $service = Mockery::mock(Migration::class);
        $path = database_path('migrations/'.Migration::MIGRATION.'.php');
        $service->shouldReceive('inspect')->times(3)->andReturn($this->state('eligible'), $this->state('eligible'), $this->state('complete'));
        $service->shouldReceive('migrationPath')->andReturn($path);
        $service->shouldReceive('financialSnapshot')->times(3)->andReturn(['payments' => 'unchanged']);
        $service->shouldReceive('historySnapshot')->twice()->andReturn([['id' => 1, 'migration' => 'older', 'batch' => 1]]);
        $service->shouldReceive('recordMigrationSuccess')->once()->with(['payments' => 'unchanged'], [['id' => 1, 'migration' => 'older', 'batch' => 1]]);
        $this->app->instance(Migration::class, $service);
        $gate = $this->fakeGate();
        Artisan::shouldReceive('call')->once()->with('migrate', ['--database' => 'mysql', '--path' => $path, '--realpath' => true, '--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('');
        $this->assertSame(0, $this->runCommand(['--execute' => true]));
        $this->assertSame([false, false], $gate->calls);
    }

    public function test_completed_schema_without_durable_preservation_evidence_cannot_mint_a_receipt(): void
    {
        $service = Mockery::mock(Migration::class);
        $service->shouldReceive('inspect')->once()->andReturn($this->state('complete'));
        $service->shouldReceive('assertMigrationPreserved')->once()->andThrow(new RuntimeException('Missing durable preservation receipt.'));
        $service->shouldReceive('recordMigrationSuccess')->never();
        $this->app->instance(Migration::class, $service);
        $this->fakeGate();
        Artisan::shouldReceive('call')->never();
        $this->assertSame(1, $this->runCommand(['--execute' => true]));
    }

    public function test_changed_financial_history_after_ddl_cannot_record_success(): void
    {
        $service = Mockery::mock(Migration::class);
        $service->shouldReceive('inspect')->twice()->andReturn($this->state('eligible'));
        $service->shouldReceive('migrationPath')->andReturn(database_path('migrations/'.Migration::MIGRATION.'.php'));
        $service->shouldReceive('financialSnapshot')->times(3)->andReturn(['payments' => 'original'], ['payments' => 'original'], ['payments' => 'changed']);
        $service->shouldReceive('historySnapshot')->once()->andReturn([]);
        $service->shouldReceive('recordMigrationSuccess')->never();
        $this->app->instance(Migration::class, $service);
        $this->fakeGate();
        Artisan::shouldReceive('call')->once()->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('');
        $this->assertSame(1, $this->runCommand(['--execute' => true]));
    }

    public function test_missing_write_gate_prevents_ddl(): void
    {
        $service = Mockery::mock(Migration::class);
        $service->shouldReceive('inspect')->once()->andReturn($this->state('eligible'));
        $this->app->instance(Migration::class, $service);
        $this->fakeGate(true);
        Artisan::shouldReceive('call')->never();
        $this->assertSame(1, $this->runCommand(['--execute' => true]));
    }

    public function test_freeze_preserves_receipts_ledger_balances_and_only_excludes_exact_additions(): void
    {
        $this->fixture();
        $service = app(Migration::class);
        $before = $service->financialSnapshot();
        foreach (['request_hash', 'manual_identity', 'manual_reason'] as $column) Schema::connection('mysql')->table('central_finance_unidentified_deposits', fn (Blueprint $t) => $t->string($column)->nullable());
        $this->assertSame($before, $service->financialSnapshot());
        foreach (['central_finance_receipts', 'central_finance_ledger_entries', 'central_finance_fund_accounts'] as $table) {
            DB::connection('mysql')->table($table)->insert(['id' => 17]);
            $this->assertNotSame($before[$table], $service->financialSnapshot()[$table]);
        }
    }

    public function test_pending_cash_destination_difference_is_allowed_but_bank_conflict_and_imports_block(): void
    {
        $this->fixture();
        $db = DB::connection('mysql');
        $db->table('central_finance_fund_accounts')->insert([['id' => 1, 'account_type' => 'cash', 'currency' => 'MMK'], ['id' => 2, 'account_type' => 'bank', 'currency' => 'MMK']]);
        $db->table('central_finance_payments')->insert(['id' => 9, 'fund_account_id' => 2, 'school_id' => 1, 'receivable_id' => 3, 'currency' => 'MMK', 'amount' => '100', 'idempotency_key' => hash('sha256', '1|3|pending-collection:synthetic'), 'payment_reference' => 'REF']);
        $db->table('central_finance_pending_collections')->insert(['id' => 1, 'pending_collection_uuid' => 'synthetic', 'status' => 'confirmed', 'confirmed_payment_id' => 9, 'intended_fund_account_id' => 1, 'school_id' => 1, 'receivable_id' => 3, 'currency' => 'MMK', 'amount' => '100', 'payment_reference' => null]);
        $service = app(Migration::class);
        $this->assertSame(0, array_sum($service->conflictCounts()));
        $db->table('central_finance_pending_collections')->update(['intended_fund_account_id' => 2]);
        $this->assertSame(1, $service->conflictCounts()['pending_link_conflicts']);
        $db->table('central_finance_import_batches')->insert(['id' => 1, 'status' => 'processing']);
        $db->table('central_finance_import_row_reservations')->insert(['id' => 1, 'status' => 'reserved']);
        $db->table('central_finance_group_import_batches')->insert(['id' => 1, 'status' => 'previewed']);
        $counts = $service->conflictCounts();
        $this->assertSame(1, $counts['import_inflight']);
        $this->assertSame(1, $counts['import_reserved']);
        $this->assertSame(1, $counts['group_import_inflight']);
    }

    #[DataProvider('schemaCases')]
    public function test_complete_schema_requires_exact_columns_indexes_foreign_keys_and_history(string $case, string $expected): void
    {
        $schema = Mockery::mock();
        $schema->shouldReceive('hasTable')->andReturn(true);
        $schema->shouldReceive('hasColumn')->andReturn(true);
        $columnSpecs = [
            Migration::IDENTITIES => ['id' => ['bigint unsigned', false], 'fund_account_id' => ['bigint unsigned', false], 'currency' => ['varchar(3)', false], 'identity_hash' => ['varchar(64)', false], 'identity_namespace' => ['varchar(24)', false], 'normalized_identity' => ['varchar(100)', false], 'manual_reason' => ['text', true], 'source_type' => ['varchar(32)', false], 'source_id' => ['varchar(64)', false], 'amount' => ['decimal(20,4)', false], 'payload_hash' => ['varchar(64)', true], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]],
            'central_finance_payments' => ['request_hash' => ['varchar(64)', true], 'unidentified_deposit_id' => ['bigint unsigned', true]],
            'central_finance_unidentified_deposits' => ['request_hash' => ['varchar(64)', true], 'manual_identity' => ['varchar(100)', true], 'manual_reason' => ['text', true]],
            'central_finance_unidentified_deposit_allocations' => ['request_hash' => ['varchar(64)', true], 'payment_id' => ['bigint unsigned', true]],
        ];
        if ($case === 'type') $columnSpecs['central_finance_payments']['unidentified_deposit_id'][0] = 'int unsigned';
        if ($case === 'nullable') $columnSpecs['central_finance_payments']['unidentified_deposit_id'][1] = false;
        if ($case === 'missing_column') unset($columnSpecs[Migration::IDENTITIES]['payload_hash']);
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('p0_test');
        $connection->shouldReceive('select')->andReturnUsing(function ($sql, $bindings) use ($columnSpecs, $case) {
            return collect($columnSpecs[$bindings[1]])->map(fn ($spec, $name) => (object) ['name' => $name, 'type' => $spec[0], 'nullable' => $spec[1] ? 'YES' : 'NO', 'default_value' => $case === 'default' && $name === 'request_hash' ? 'unsafe' : null, 'extra' => $name === 'id' ? 'auto_increment' : ''])->values()->all();
        });
        $history = Mockery::mock();
        $history->shouldReceive('where')->andReturnSelf();
        $rows = $case === 'history_absent' ? [] : [(object) ['batch' => $case === 'history_batch' ? 0 : 1]];
        if ($case === 'history_duplicate') $rows[] = (object) ['batch' => 2];
        $history->shouldReceive('get')->andReturn(collect($rows));
        $connection->shouldReceive('table')->with('migrations')->andReturn($history);
        $prefixes = Mockery::mock();
        $prefixes->shouldReceive('where', 'whereNotNull')->andReturnSelf();
        $prefixes->shouldReceive('exists')->andReturn($case === 'prefix_index');
        $connection->shouldReceive('table')->with('information_schema.STATISTICS')->andReturn($prefixes);
        $schema->shouldReceive('getIndexes')->andReturnUsing(function ($table) use ($case) {
            $index = fn ($name, $columns) => ['name' => $name, 'columns' => $columns, 'unique' => $case !== 'nonunique', 'type' => 'btree'];
            if ($table === Migration::IDENTITIES) return array_merge([$index('primary', ['id']), $index('cfbti_physical_identity_unique', $case === 'index_columns' ? ['identity_hash'] : ['fund_account_id', 'currency', 'identity_hash']), $index('cfbti_origin_unique', ['source_type', 'source_id'])], $case === 'extra_index' ? [$index('unsafe_extra', ['identity_hash'])] : []);
            return array_merge([$index('cfuda_payment_unique', ['payment_id'])], in_array($case, ['old_unique', 'renamed_old_unique']) ? [$index($case === 'old_unique' ? 'cfuda_deposit_receivable_unique' : 'renamed_old_unique', ['unidentified_deposit_id', 'receivable_id'])] : []);
        });
        $schema->shouldReceive('getForeignKeys')->andReturnUsing(function ($table) use ($case) {
            [$name, $column, $target] = match ($table) {
                Migration::IDENTITIES => ['cfbti_account_fk', 'fund_account_id', 'central_finance_fund_accounts'],
                'central_finance_payments' => ['cfp_unidentified_deposit_fk', 'unidentified_deposit_id', 'central_finance_unidentified_deposits'],
                default => ['cfuda_payment_fk', 'payment_id', 'central_finance_payments'],
            };
            return $case === 'fk_absent' ? [] : [['name' => $name, 'columns' => [$column], 'foreign_schema' => $case === 'fk_schema' ? 'tenant' : 'p0_test', 'foreign_table' => $target, 'foreign_columns' => ['id'], 'on_update' => 'restrict', 'on_delete' => $case === 'fk_cascade' ? 'cascade' : 'restrict']];
        });
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);
        $this->assertSame($expected, app(Migration::class)->schemaState());
    }

    public static function schemaCases(): array
    {
        $cases = [['valid', 'complete']];
        foreach (['type', 'nullable', 'missing_column', 'default', 'history_absent', 'history_batch', 'history_duplicate', 'prefix_index', 'nonunique', 'index_columns', 'extra_index', 'old_unique', 'renamed_old_unique', 'fk_absent', 'fk_schema', 'fk_cascade'] as $case) $cases[] = [$case, 'unexpected'];
        return $cases;
    }

    private function state(string $state): array { return ['state' => $state, 'identities' => [], 'conflicts' => []]; }

    private function runCommand(array $options = []): int
    {
        $command = new \App\Console\Commands\MigrateUnidentifiedDepositP0;
        $command->setLaravel($this->app);
        return $command->run(new \Symfony\Component\Console\Input\ArrayInput($options, $command->getDefinition()), new \Symfony\Component\Console\Output\BufferedOutput);
    }

    private function fakeGate(bool $deny = false): object
    {
        $this->app->instance(\App\Services\UnidentifiedDepositP0WriteGate::class, new class {
            public function withLock(callable $work): mixed { return $work(); }
            public function withClosedFence(callable $work): mixed { return $work(); }
            public function recordMigrationSuccess(array $before, array $history): void {}
            public function assertMigrationPreserved(): void {}
        });
        $gate = new class($deny) {
            public array $calls = [];
            public function __construct(private bool $deny) {}
            public function assertReadyForMigration(bool $complete = false): void
            {
                if ($this->deny) throw new RuntimeException('Writers are not quiescent.');
                $this->calls[] = $complete;
            }
        };
        $this->app->instance(UnidentifiedDepositP0ReleaseGate::class, $gate);
        return $gate;
    }

    private function fixture(): void
    {
        $schema = Schema::connection('mysql');
        $schema->create('migrations', fn (Blueprint $t) => [$t->id(), $t->string('migration'), $t->integer('batch')]);
        $schema->create('central_finance_fund_accounts', fn (Blueprint $t) => [$t->id(), $t->string('account_type')->nullable(), $t->string('currency')->nullable(), $t->decimal('opening_balance', 20, 4)->nullable()]);
        foreach (['central_finance_unidentified_deposits' => 'bank_reference', 'central_finance_payments' => 'payment_reference', 'central_finance_other_incomes' => 'reference_no'] as $name => $reference) {
            $schema->create($name, function (Blueprint $t) use ($reference) {
                $t->id(); $t->unsignedBigInteger('fund_account_id'); $t->unsignedBigInteger('school_id')->nullable(); $t->unsignedBigInteger('receivable_id')->nullable();
                $t->string('currency'); $t->decimal('amount', 20, 4); $t->string('idempotency_key', 64); $t->string($reference)->nullable();
            });
        }
        $schema->create('central_finance_unidentified_deposit_allocations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('unidentified_deposit_id'); $t->unsignedBigInteger('receivable_id');
            $t->unique(['unidentified_deposit_id', 'receivable_id'], 'cfuda_deposit_receivable_unique');
        });
        $schema->create('central_finance_pending_collections', function (Blueprint $t) {
            $t->id(); $t->string('pending_collection_uuid'); $t->string('status'); $t->unsignedBigInteger('confirmed_payment_id')->nullable(); $t->unsignedBigInteger('intended_fund_account_id')->nullable();
            $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('receivable_id'); $t->string('currency'); $t->decimal('amount', 20, 4); $t->string('payment_reference')->nullable();
        });
        foreach (['central_finance_import_batches', 'central_finance_import_row_reservations', 'central_finance_group_import_batches'] as $table) $schema->create($table, fn (Blueprint $t) => [$t->id(), $t->string('status')]);
        foreach (['central_finance_receipts', 'central_finance_receivables', 'central_finance_ledger_entries'] as $table) $schema->create($table, fn (Blueprint $t) => $t->id());
    }
}
