<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralFundAccountV2;
use App\Services\ProductionMigrationGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CentralFundAccountV2MigrationRunnerTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'fund_account_v2_runner_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('migrations', fn (Blueprint $table) => [$table->id(), $table->string('migration'), $table->integer('batch')]);
        Schema::connection('mysql')->create('central_finance_document_audits', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id')->index(); $table->string('document_type');
        });
        Schema::connection('mysql')->create('central_finance_fund_accounts', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('central_finance_fund_account_school_allocations', fn (Blueprint $table) => $table->id());
        Schema::connection('mysql')->create('central_finance_ledger_entries', fn (Blueprint $table) => $table->id());
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_exact_path_runner_applies_only_nullable_group_audit_context_and_is_idempotent(): void
    {
        $this->artisan('finance:migrate-fund-account-v2')->assertExitCode(0);
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id'));

        $this->artisan('finance:migrate-fund-account-v2', ['--execute' => true])->assertExitCode(0);

        $this->assertTrue(Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id'));
        $school = collect(Schema::connection('mysql')->getColumns('central_finance_document_audits'))->firstWhere('name', 'school_id');
        $this->assertTrue((bool) ($school['nullable'] ?? false));
        $this->assertDatabaseHas('migrations', ['migration' => MigrateCentralFundAccountV2::MIGRATION], 'mysql');
        $this->artisan('finance:migrate-fund-account-v2', ['--execute' => true])->assertExitCode(0);

        app(ProductionMigrationGuard::class)->assertAllowed(
            'migrate',
            'finance:migrate-fund-account-v2',
            [database_path('migrations/'.MigrateCentralFundAccountV2::MIGRATION.'.php')],
            true,
            true,
        );
    }

    public function test_partial_schema_fails_closed_without_recording_or_further_schema_write(): void
    {
        Schema::connection('mysql')->table('central_finance_document_audits', fn (Blueprint $table) => $table->unsignedBigInteger('group_id')->nullable());
        $before = Schema::connection('mysql')->getColumns('central_finance_document_audits');

        $this->artisan('finance:migrate-fund-account-v2', ['--execute' => true])->assertExitCode(1);

        $this->assertSame($before, Schema::connection('mysql')->getColumns('central_finance_document_audits'));
        $this->assertDatabaseMissing('migrations', ['migration' => MigrateCentralFundAccountV2::MIGRATION], 'mysql');
    }
}
