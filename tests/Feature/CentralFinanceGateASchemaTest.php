<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Gate A schema rehearsal: all approved migrations are additive and reversible. */
class CentralFinanceGateASchemaTest extends TestCase
{
    private array $mysql;
    private array $school;
    private string $central;
    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = config('database.connections.mysql');
        $this->school = config('database.connections.school');
        $this->central = tempnam(sys_get_temp_dir(), 'cf_gate_a_c_');
        $this->tenant = tempnam(sys_get_temp_dir(), 'cf_gate_a_t_');
        Config::set('database.connections.mysql', $this->sqlite($this->central));
        Config::set('database.connections.school', $this->sqlite($this->tenant));
        DB::purge('mysql'); DB::purge('school');
        Schema::connection('school')->create('students', function ($table): void { $table->increments('id'); });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql'); DB::purge('school');
        Config::set('database.connections.mysql', $this->mysql);
        Config::set('database.connections.school', $this->school);
        @unlink($this->central); @unlink($this->tenant);
        parent::tearDown();
    }

    public function test_gate_a_migrations_apply_rollback_and_reapply_without_financial_rows(): void
    {
        $central = $this->centralMigrations();
        $tenant = require base_path('database/migrations/schools/2026_08_20_000002_add_central_finance_source_uuid_to_students_table.php');

        foreach ($central as $migration) { $migration->up(); }
        $tenant->up();
        $this->assertTrue(Schema::connection('school')->hasColumn('students', 'central_finance_source_uuid'));
        foreach ($this->centralTables() as $table) { $this->assertTrue(Schema::connection('mysql')->hasTable($table), $table); }
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());

        $tenant->down();
        foreach (array_reverse($central) as $migration) { $migration->down(); }
        $this->assertFalse(Schema::connection('school')->hasColumn('students', 'central_finance_source_uuid'));
        foreach ($this->centralTables() as $table) { $this->assertFalse(Schema::connection('mysql')->hasTable($table), $table); }

        foreach ($central as $migration) { $migration->up(); }
        $tenant->up();
        foreach ($this->centralTables() as $table) { $this->assertTrue(Schema::connection('mysql')->hasTable($table), $table); }
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    /** @return list<object> */
    private function centralMigrations(): array
    {
        return array_map(static fn (string $file): object => require base_path('database/migrations/'.$file), [
            '2026_08_20_000003_create_central_finance_student_sync_tables.php',
            '2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php',
            '2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php',
            '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php',
            '2026_08_21_000002_create_central_finance_operating_documents.php',
            '2026_08_21_000003_create_central_finance_internal_transfer_documents.php',
            '2026_08_21_000004_create_central_finance_legacy_migration_records.php',
        ]);
    }

    /** @return list<string> */
    private function centralTables(): array
    {
        return ['central_finance_student_profiles', 'central_finance_sync_events', 'central_finance_fund_accounts', 'central_finance_fund_account_users', 'central_finance_ledger_entries', 'central_finance_user_school_scopes', 'central_finance_receivables', 'central_finance_payments', 'central_finance_receipts', 'central_finance_categories', 'central_finance_expenses', 'central_finance_other_incomes', 'central_finance_reimbursement_requests', 'central_finance_document_audits', 'central_finance_internal_transfers', 'central_finance_fund_handovers', 'central_finance_hq_funding_requests', 'central_finance_legacy_migration_records'];
    }

    private function sqlite(string $database): array
    {
        return ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true];
    }
}
