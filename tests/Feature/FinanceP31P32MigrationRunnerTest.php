<?php

namespace Tests\Feature;

use App\Console\Commands\FinanceMigrateP31P32;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceP31P32MigrationRunnerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;

    /** @var array<string, mixed> */
    private array $schoolConnection;

    /** @var array<string, string> */
    private array $trustedTenants;

    private string $centralDatabase;
    private string $tenantOne;
    private string $tenantTwo;

    /** @var array<int, string> */
    private array $databaseFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->centralDatabase = $this->temporaryDatabase();
        $this->tenantOne = $this->temporaryDatabase();
        $this->tenantTwo = $this->temporaryDatabase();
        $this->trustedTenants = ['QA001' => $this->tenantOne, 'QA002' => $this->tenantTwo];

        $sqlite = static fn (string $database): array => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
        ];
        Config::set('database.connections.mysql', $sqlite($this->centralDatabase));
        Config::set('database.connections.school', $sqlite($this->tenantOne));
        Config::set('finance_release.p31_p32_tenants', $this->trustedTenants);
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('database_name')->unique();
            $table->timestamp('deleted_at')->nullable();
        });
        foreach ($this->trustedTenants as $code => $database) {
            DB::connection('mysql')->table('schools')->insert(['code' => $code, 'database_name' => $database]);
            $this->createTenantBaseSchema($database);
        }
        Config::set('database.connections.school.database', $this->tenantOne);
        DB::purge('school');
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        Config::set('database.connections.school', $this->schoolConnection);
        Config::set('finance_release.p31_p32_tenants', null);
        DB::setDefaultConnection('mysql');
        foreach ($this->databaseFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_fixed_allowlist_has_only_the_two_new_migrations_and_excludes_legacy_and_prior_finance_migrations(): void
    {
        $this->assertSame([
            '2026_08_12_000002_create_expense_import_batches_table',
            '2026_08_13_000001_create_other_incomes_table',
        ], FinanceMigrateP31P32::MIGRATIONS);
        foreach (FinanceMigrateP31P32::paths() as $path) {
            $this->assertFileExists($path);
        }
        $source = file_get_contents(app_path('Console/Commands/FinanceMigrateP31P32.php'));
        $this->assertStringNotContainsString('2026_08_05_000001', $source);
        $this->assertStringNotContainsString('2026_08_06_000001', $source);
        $this->assertStringNotContainsString('migrate:fresh', $source);
        $this->assertStringNotContainsString('CREATE DATABASE', $source);
    }

    public function test_default_invocation_is_verification_only_and_writes_nothing(): void
    {
        $this->artisan('finance:migrate-p31-p32')->assertExitCode(0);

        $this->assertSame(0, $this->migrationCount($this->tenantOne));
        $this->assertSame(0, $this->migrationCount($this->tenantTwo));
        $this->assertFalse($this->tableExists($this->tenantOne, 'expense_import_batches'));
        $this->assertFalse($this->tableExists($this->tenantOne, 'other_incomes'));
    }

    public function test_raw_database_name_and_unknown_school_code_are_refused(): void
    {
        $this->artisan('finance:migrate-p31-p32', ['--tenant' => [$this->tenantOne]])->assertExitCode(1);
        $this->artisan('finance:migrate-p31-p32', ['--tenant' => ['UNKNOWN']])->assertExitCode(1);
        $this->assertSame(0, $this->migrationCount($this->tenantOne));
    }

    public function test_partial_or_history_only_schema_states_abort_without_running_the_second_migration(): void
    {
        $this->onTenant($this->tenantOne, function (): void {
            Schema::connection('school')->table('expenses', fn ($table) => $table->string('payment_method')->nullable());
        });

        $this->artisan('finance:migrate-p31-p32', ['--tenant' => ['QA001'], '--execute' => true])->assertExitCode(1);
        $this->assertFalse($this->tableExists($this->tenantOne, 'expense_import_batches'));
        $this->assertFalse($this->tableExists($this->tenantOne, 'other_incomes'));

        $this->onTenant($this->tenantTwo, function (): void {
            DB::connection('school')->table('migrations')->insert([
                'migration' => FinanceMigrateP31P32::MIGRATIONS[0], 'batch' => 99,
            ]);
        });
        $this->artisan('finance:migrate-p31-p32', ['--tenant' => ['QA002']])->assertExitCode(1);
        $this->assertFalse($this->tableExists($this->tenantTwo, 'other_incomes'));
    }

    public function test_explicit_execute_runs_only_the_two_migrations_verifies_each_and_is_idempotent(): void
    {
        $original = config('database.connections.school.database');

        $this->artisan('finance:migrate-p31-p32', ['--tenant' => ['QA001'], '--execute' => true])->assertExitCode(0);
        $this->assertTrue($this->tableExists($this->tenantOne, 'expense_import_batches'));
        $this->assertTrue($this->tableExists($this->tenantOne, 'other_incomes'));
        $this->assertTrue($this->columnExists($this->tenantOne, 'expenses', 'payment_method'));
        $this->assertSame(FinanceMigrateP31P32::MIGRATIONS, $this->migrations($this->tenantOne));
        $this->assertSame($original, config('database.connections.school.database'));

        $this->artisan('finance:migrate-p31-p32', ['--tenant' => ['QA001'], '--execute' => true])->assertExitCode(0);
        $this->assertSame(FinanceMigrateP31P32::MIGRATIONS, $this->migrations($this->tenantOne));
        $this->assertFalse($this->tableExists($this->tenantTwo, 'expense_import_batches'));
    }

    public function test_all_known_tenants_are_independently_processed_and_connection_is_restored_after_failure(): void
    {
        $original = config('database.connections.school.database');
        $this->artisan('finance:migrate-p31-p32', ['--execute' => true])->assertExitCode(0);
        $this->assertSame($original, config('database.connections.school.database'));
        $this->assertSame(FinanceMigrateP31P32::MIGRATIONS, $this->migrations($this->tenantOne));
        $this->assertSame(FinanceMigrateP31P32::MIGRATIONS, $this->migrations($this->tenantTwo));

        $this->onTenant($this->tenantTwo, function (): void {
            Schema::connection('school')->drop('other_incomes');
        });
        $this->artisan('finance:migrate-p31-p32')->assertExitCode(1);
        $this->assertSame($this->tenantTwo, config('database.connections.school.database'));
    }

    public function test_state_machine_requires_both_migrations_to_be_absent_or_both_applied(): void
    {
        $this->assertSame('eligible', FinanceMigrateP31P32::pairState(['expense_import' => 'absent', 'other_income' => 'absent']));
        $this->assertSame('complete', FinanceMigrateP31P32::pairState(['expense_import' => 'applied', 'other_income' => 'applied']));
        $this->assertSame('partial', FinanceMigrateP31P32::pairState(['expense_import' => 'applied', 'other_income' => 'absent']));
        $this->assertSame('partial', FinanceMigrateP31P32::pairState(['expense_import' => 'partial', 'other_income' => 'absent']));
        $this->assertSame('inconsistent', FinanceMigrateP31P32::pairState(['expense_import' => 'inconsistent', 'other_income' => 'absent']));
    }

    private function createTenantBaseSchema(string $database): void
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        Schema::connection('school')->create('migrations', function ($table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::connection('school')->create('schools', fn ($table) => $table->increments('id'));
        Schema::connection('school')->create('users', fn ($table) => $table->increments('id'));
        Schema::connection('school')->create('bank_accounts', fn ($table) => $table->increments('id'));
        Schema::connection('school')->create('expenses', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('bank_account_id')->nullable();
        });
    }

    private function onTenant(string $database, callable $callback): void
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        $callback();
    }

    private function temporaryDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eschool-p31p32-');
        $this->databaseFiles[] = $path;
        return $path;
    }

    private function tableExists(string $database, string $table): bool
    {
        return $this->onTenantResult($database, fn (): bool => Schema::connection('school')->hasTable($table));
    }

    private function columnExists(string $database, string $table, string $column): bool
    {
        return $this->onTenantResult($database, fn (): bool => Schema::connection('school')->hasColumn($table, $column));
    }

    /** @return array<int, string> */
    private function migrations(string $database): array
    {
        return $this->onTenantResult($database, fn (): array => DB::connection('school')->table('migrations')->whereIn('migration', FinanceMigrateP31P32::MIGRATIONS)->orderBy('migration')->pluck('migration')->all());
    }

    private function migrationCount(string $database): int
    {
        return count($this->migrations($database));
    }

    private function onTenantResult(string $database, callable $callback): mixed
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        return $callback();
    }
}
