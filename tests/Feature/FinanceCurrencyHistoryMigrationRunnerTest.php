<?php

namespace Tests\Feature;

use App\Console\Commands\FinanceMigrateCurrencyHistory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FinanceCurrencyHistoryMigrationRunnerTest extends TestCase
{
    private array $mysql;
    private array $school;
    private array $trusted;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = config('database.connections.mysql');
        $this->school = config('database.connections.school');
        $central = $this->database();
        $this->trusted = [
            'MMBOWEN01' => $this->database(),
            'SCH202616' => $this->database(),
            'SCH202619' => $this->database(),
        ];
        $sqlite = static fn (string $database): array => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
        ];
        Config::set('database.connections.mysql', $sqlite($central));
        Config::set('database.connections.school', $sqlite($this->trusted['MMBOWEN01']));
        Config::set('finance_release.currency_history_tenants', $this->trusted);
        Config::set('finance_release.currency_history_canary', 'MMBOWEN01');
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('database_name')->unique();
            $table->timestamp('deleted_at')->nullable();
        });
        foreach ($this->trusted as $code => $database) {
            DB::connection('mysql')->table('schools')->insert(['code' => $code, 'database_name' => $database]);
            $this->on($database, fn () => $this->createBaseSchema());
        }
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysql);
        Config::set('database.connections.school', $this->school);
        Config::set('finance_release.currency_history_tenants', null);
        Config::set('finance_release.currency_history_canary', null);
        DB::setDefaultConnection('mysql');
        foreach ($this->files as $file) @unlink($file);
        parent::tearDown();
    }

    public function test_runner_has_the_fixed_non_demo_allowlist_and_exact_migration(): void
    {
        $this->assertSame(
            ['MMBOWEN01', 'SCH202616', 'SCH202619', 'SCH202620', 'SCH202621', 'SCH202631', 'SCH202632'],
            array_keys(FinanceMigrateCurrencyHistory::PRODUCTION_TENANTS),
        );
        $this->assertArrayNotHasKey('SCH20261', FinanceMigrateCurrencyHistory::PRODUCTION_TENANTS);
        $this->assertSame(
            '2026_09_11_000001_add_financial_currency_history_integrity',
            FinanceMigrateCurrencyHistory::MIGRATION,
        );
    }

    public function test_default_is_read_only_and_raw_database_or_unknown_tenant_is_refused(): void
    {
        $this->artisan('finance:migrate-currency-history')->assertExitCode(0);
        foreach ($this->trusted as $database) $this->assertFalse($this->complete($database));
        $this->artisan('finance:migrate-currency-history', ['--tenant' => [$this->trusted['MMBOWEN01']]])->assertExitCode(1);
        $this->artisan('finance:migrate-currency-history', ['--tenant' => ['UNKNOWN']])->assertExitCode(1);
    }

    public function test_execute_is_canary_first_then_exact_remaining_tenants(): void
    {
        $this->artisan('finance:migrate-currency-history', [
            '--tenant' => ['SCH202616', 'SCH202619'], '--execute' => true,
        ])->assertExitCode(1);
        $this->assertFalse($this->complete($this->trusted['SCH202616']));

        $this->artisan('finance:migrate-currency-history', [
            '--tenant' => ['MMBOWEN01'], '--execute' => true,
        ])->assertExitCode(0);
        $this->assertTrue($this->complete($this->trusted['MMBOWEN01']));

        $this->artisan('finance:migrate-currency-history', [
            '--tenant' => ['SCH202616', 'SCH202619'], '--execute' => true,
        ])->assertExitCode(0);
        $this->assertTrue($this->complete($this->trusted['SCH202616']));
        $this->assertTrue($this->complete($this->trusted['SCH202619']));
    }

    public function test_partial_schema_fails_closed_without_recording_migration(): void
    {
        $database = $this->trusted['MMBOWEN01'];
        $this->on($database, fn () => Schema::connection('school')->create('fee_payment_fx_snapshots', fn ($table) => $table->increments('id')));

        $this->artisan('finance:migrate-currency-history', ['--tenant' => ['MMBOWEN01']])->assertExitCode(1);

        $recorded = $this->on($database, fn () => DB::connection('school')->table('migrations')
            ->where('migration', FinanceMigrateCurrencyHistory::MIGRATION)->exists());
        $this->assertFalse($recorded);
    }

    private function createBaseSchema(): void
    {
        Schema::connection('school')->create('migrations', function ($table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::connection('school')->create('fees_paids', fn ($table) => $table->increments('id'));
        Schema::connection('school')->create('bank_accounts', fn ($table) => $table->increments('id'));
        foreach (['compulsory_fees', 'optional_fees'] as $name) {
            Schema::connection('school')->create($name, function ($table): void {
                $table->increments('id');
                $table->unsignedBigInteger('bank_account_id')->nullable();
            });
        }
        Schema::connection('school')->create('other_incomes', function ($table): void {
            $table->increments('id');
            $table->decimal('amount', 20, 4);
        });
        Schema::connection('school')->create('student_fee_assignment_items', function ($table): void {
            $table->increments('id');
            $table->string('currency_snapshot', 3);
        });
    }

    private function database(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'currency-history-runner-');
        $this->files[] = $file;
        return $file;
    }

    private function on(string $database, callable $callback): mixed
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        return $callback();
    }

    private function complete(string $database): bool
    {
        return $this->on($database, fn (): bool => Schema::connection('school')->hasTable('fee_payment_fx_snapshots')
            && Schema::connection('school')->hasColumn('compulsory_fees', 'fee_payment_fx_snapshot_id')
            && Schema::connection('school')->hasColumn('optional_fees', 'fee_payment_fx_snapshot_id')
            && Schema::connection('school')->hasColumn('other_incomes', 'transaction_currency')
            && Schema::connection('school')->hasColumn('student_fee_assignment_items', 'exchange_rate_snapshot'));
    }
}
