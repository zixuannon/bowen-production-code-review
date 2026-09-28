<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralFinancePaymentCorrections;
use App\Services\ProductionMigrationGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CentralFinancePaymentCorrectionMigrationRunnerTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'payment_corrections_runner_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('migrations', fn (Blueprint $table) => [$table->id(), $table->string('migration'), $table->integer('batch')]);
        foreach (['schools', 'users', 'central_finance_receipts', 'central_finance_fund_accounts', 'central_finance_ledger_entries'] as $table) {
            Schema::connection('mysql')->create($table, fn (Blueprint $table) => $table->id());
        }
        Schema::connection('mysql')->create('central_finance_payments', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->decimal('amount', 20, 4); $table->string('currency', 3);
        });
        Schema::connection('mysql')->create('central_finance_payment_refunds', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('payment_id'); $table->decimal('amount', 20, 4); $table->string('reason');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_exact_path_runner_preflights_applies_and_idempotently_rechecks_payment_correction_schema(): void
    {
        $before = $this->snapshot();
        $this->artisan('finance:migrate-payment-corrections')->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());

        $this->artisan('finance:migrate-payment-corrections', ['--execute' => true])->assertExitCode(0);
        $this->assertTrue(Schema::connection('mysql')->hasColumns('central_finance_payment_refunds', ['refund_method', 'effective_date']));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_payment_reversals'));
        $this->assertDatabaseHas('migrations', ['migration' => MigrateCentralFinancePaymentCorrections::MIGRATION], 'mysql');

        $complete = $this->snapshot();
        $this->artisan('finance:migrate-payment-corrections', ['--execute' => true])->assertExitCode(0);
        $this->assertSame($complete, $this->snapshot());
        app(ProductionMigrationGuard::class)->assertAllowed(
            'migrate',
            'finance:migrate-payment-corrections',
            [database_path('migrations/'.MigrateCentralFinancePaymentCorrections::MIGRATION.'.php')],
            true,
            true,
        );
    }

    public function test_partial_payment_correction_schema_fails_closed_without_registry_or_schema_write(): void
    {
        Schema::connection('mysql')->table('central_finance_payment_refunds', fn (Blueprint $table) => $table->string('refund_method', 40)->nullable());
        $before = $this->snapshot();

        $this->artisan('finance:migrate-payment-corrections', ['--execute' => true])->assertExitCode(1);

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_payment_reversals'));
        $this->assertDatabaseMissing('migrations', ['migration' => MigrateCentralFinancePaymentCorrections::MIGRATION], 'mysql');
    }

    public function test_registry_claim_without_complete_schema_fails_closed(): void
    {
        DB::connection('mysql')->table('migrations')->insert(['migration' => MigrateCentralFinancePaymentCorrections::MIGRATION, 'batch' => 1]);
        $before = $this->snapshot();

        $this->artisan('finance:migrate-payment-corrections', ['--execute' => true])->assertExitCode(1);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_wrong_production_database_fails_closed_without_any_schema_write(): void
    {
        $this->app['env'] = 'production';
        $before = $this->snapshot();

        $this->artisan('finance:migrate-payment-corrections', ['--execute' => true])->assertExitCode(1);

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_payment_reversals'));
    }

    private function snapshot(): array
    {
        return [
            json_encode(Schema::connection('mysql')->getTables()),
            json_encode(DB::connection('mysql')->table('migrations')->orderBy('id')->get()->toArray()),
        ];
    }
}
