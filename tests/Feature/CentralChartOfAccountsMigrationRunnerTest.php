<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralChartOfAccounts;
use App\Services\CentralChartOfAccountsSchema;
use App\Services\ProductionMigrationGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CentralChartOfAccountsMigrationRunnerTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'coa_runner_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql'); DB::setDefaultConnection('mysql');
        Schema::create('migrations', function (Blueprint $table): void { $table->id(); $table->string('migration'); $table->integer('batch'); });
        Schema::create('schools', fn (Blueprint $table) => $table->id());
        Schema::create('finance_groups', fn (Blueprint $table) => $table->id());
        Schema::create('central_finance_categories', function (Blueprint $table): void {
            $table->id(); $table->uuid('category_uuid')->unique(); $table->unsignedBigInteger('school_id');
            $table->string('category_code', 80); $table->string('type', 16); $table->string('name', 120); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('central_finance_fund_accounts', function (Blueprint $table): void {
            $table->id(); $table->uuid('account_uuid'); $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('school_id')->nullable(); $table->string('owner_type');
            $table->string('account_code'); $table->string('currency', 3); $table->decimal('opening_balance', 20, 4);
        });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql'); @unlink($this->database); parent::tearDown();
    }

    public function test_read_only_default_then_exact_paths_and_idempotent_repeat(): void
    {
        $before = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts')->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])->assertExitCode(0);
        CentralChartOfAccountsSchema::assertComplete();
        $this->assertTrue(Schema::hasColumn('central_finance_fund_accounts', 'owner_holder'));
        $this->assertSame(MigrateCentralChartOfAccounts::MIGRATIONS, DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        $complete = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])->assertExitCode(0);
        $this->assertSame($complete, $this->snapshot());
        app(ProductionMigrationGuard::class)->assertAllowed('migrate', 'finance:migrate-central-chart-of-accounts', array_map(
            fn ($migration) => database_path('migrations/'.$migration.'.php'), MigrateCentralChartOfAccounts::MIGRATIONS,
        ), true, true);
    }

    public function test_second_path_partial_schema_stops_before_first_migration_and_has_zero_writes(): void
    {
        Schema::table('central_finance_fund_accounts', fn (Blueprint $table) => $table->string('owner_holder')->nullable());
        $before = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])
            ->expectsOutputToContain('mismatch')->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::hasColumn('central_finance_categories', 'group_id'));
    }

    public function test_registry_claim_without_schema_is_nonzero_and_zero_write(): void
    {
        DB::table('migrations')->insert(['migration' => MigrateCentralChartOfAccounts::MIGRATIONS[0], 'batch' => 1]);
        $before = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_central_table_is_nonzero_and_zero_write(): void
    {
        Schema::drop('finance_groups');
        $before = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_recorded_partial_constraints_cannot_be_skipped_as_complete(): void
    {
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])->assertExitCode(0);
        Schema::table('central_finance_category_school_allocations', fn (Blueprint $table) => $table->dropUnique('coa_allocation_unique'));
        $before = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_wrong_production_database_is_rejected_even_in_read_only_mode(): void
    {
        $this->app['env'] = 'production';
        $before = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts')
            ->expectsOutputToContain('approved registry')->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_duplicate_registry_is_rejected_without_any_schema_write(): void
    {
        DB::table('migrations')->insert([
            ['migration' => MigrateCentralChartOfAccounts::MIGRATIONS[1], 'batch' => 1],
            ['migration' => MigrateCentralChartOfAccounts::MIGRATIONS[1], 'batch' => 2],
        ]);
        $before = $this->snapshot();
        $this->artisan('finance:migrate-central-chart-of-accounts', ['--execute' => true])->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_allowlist_refuses_directory_or_unrelated_migration(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ProductionMigrationGuard::class)->assertAllowed('migrate', 'finance:migrate-central-chart-of-accounts', [database_path('migrations')], true, true);
    }

    private function snapshot(): array
    {
        return [
            json_encode(DB::select("SELECT type,name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")),
            json_encode(DB::table('migrations')->orderBy('id')->get()->toArray()),
            DB::table('central_finance_categories')->count(), DB::table('central_finance_fund_accounts')->count(),
        ];
    }
}
