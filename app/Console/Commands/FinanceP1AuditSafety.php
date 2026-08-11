<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceP1AuditSafety extends Command
{
    private const TENANTS = [
        'eschool_saas_1_demo',
        'eschool_saas_15_zixuan',
        'eschool_saas_17_bahan',
        'eschool_saas_19_timecitys',
        'eschool_saas_20_',
        'eschool_saas_21_',
        'eschool_saas_31_zixuanyang',
        'eschool_saas_32_',
    ];

    private const MIGRATIONS = [
        '2026_08_10_000001_add_soft_deletes_to_expenses',
        '2026_08_10_000002_add_updated_by_to_expenses',
        '2026_08_10_000003_add_soft_delete_audit_to_fee_tables',
        '2026_08_10_000004_add_audit_fields_to_bank_accounts',
        '2026_08_10_000005_create_expense_change_logs_table',
        '2026_08_10_000006_create_bank_account_balance_adjustments_table',
    ];

    private const REQUIRED_BASE_TABLES = [
        'users',
        'expenses',
        'compulsory_fees',
        'optional_fees',
        'bank_accounts',
    ];

    protected $signature = 'finance:p1-audit-safety
        {--tenant=* : One or more exact tenant database names; defaults to the approved eight}
        {--execute : Apply only the six P1 migrations after reporting the preflight state}
        {--rollback-batch= : Roll back an exact, verified P1-only batch before any P1 audit write exists}';

    protected $description = 'Preflight or apply the Finance P1 audit-safety migrations to the approved tenant databases only';

    public function handle(): int
    {
        if ($this->option('execute') && $this->option('rollback-batch')) {
            $this->error('Choose either --execute or --rollback-batch, not both.');
            return self::FAILURE;
        }

        $tenants = $this->option('tenant') ?: self::TENANTS;
        $invalid = array_diff($tenants, self::TENANTS);

        if ($invalid || count($tenants) !== count(array_unique($tenants))) {
            $this->error('Tenant selection must contain unique names from the approved P1 tenant list only.');
            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            if (!$this->configureTenant($tenant)) {
                return self::FAILURE;
            }

            if (!$this->baseSchemaPresent()) {
                $this->error("[{$tenant}] required base finance schema is missing; stop before migration.");
                return self::FAILURE;
            }

            $before = $this->migrationState();
            $this->line("[{$tenant}] P1 migrations: " . count($before) . '/6 applied');

            if ($this->option('rollback-batch')) {
                if (!$this->rollback((int) $this->option('rollback-batch'), $tenant)) {
                    return self::FAILURE;
                }
                continue;
            }

            if ($this->option('execute')) {
                if (count($before) > 0 && count($before) < count(self::MIGRATIONS)) {
                    $this->error("[{$tenant}] partial P1 state; stop and forward-fix before any further migration.");
                    return self::FAILURE;
                }
                $exitCode = Artisan::call('migrate', [
                    '--database' => 'school',
                    '--path' => $this->migrationPaths(),
                    '--realpath' => true,
                    '--force' => true,
                ]);
                $this->output->write(Artisan::output());

                if ($exitCode !== self::SUCCESS) {
                    $this->error("[{$tenant}] targeted P1 migration failed; stop before the next tenant.");
                    return self::FAILURE;
                }
            }

            $after = $this->migrationState();
            if ($this->option('execute') && count($after) !== count(self::MIGRATIONS)) {
                $this->error("[{$tenant}] incomplete P1 migration state; do not deploy P1 application code.");
                return self::FAILURE;
            }

            if ($this->option('execute') && count(array_unique($after)) === count(self::MIGRATIONS)) {
                $batches = DB::connection('school')->table('migrations')
                    ->whereIn('migration', self::MIGRATIONS)->pluck('batch')->unique()->values();
                if ($batches->count() !== 1) {
                    $this->error("[{$tenant}] P1 migrations were not recorded in one batch; do not use rollback.");
                    return self::FAILURE;
                }
                $this->info("[{$tenant}] P1 migrations recorded in batch {$batches->first()}.");
            }

            if ($this->option('execute') && !$this->schemaComplete()) {
                $this->error("[{$tenant}] P1 schema verification failed; do not deploy P1 application code.");
                return self::FAILURE;
            }

            if (!$this->option('execute')) {
                $this->line("[{$tenant}] schema: " . ($this->schemaComplete() ? 'complete' : 'not complete'));
            }
        }

        return self::SUCCESS;
    }

    private function configureTenant(string $tenant): bool
    {
        try {
            Config::set('database.connections.school.database', $tenant);
            DB::purge('school');
            DB::connection('school')->getPdo();
            return true;
        } catch (\Throwable $exception) {
            $this->error("[{$tenant}] unable to connect to the tenant database: {$exception->getMessage()}");
            return false;
        }
    }

    private function migrationPaths(): array
    {
        return array_map(
            fn (string $migration) => database_path("migrations/schools/{$migration}.php"),
            self::MIGRATIONS
        );
    }

    private function migrationState(): array
    {
        if (!Schema::connection('school')->hasTable('migrations')) {
            return [];
        }

        return DB::connection('school')->table('migrations')
            ->whereIn('migration', self::MIGRATIONS)
            ->pluck('migration')->all();
    }

    private function schemaComplete(): bool
    {
        return Schema::connection('school')->hasColumns('expenses', ['deleted_at', 'deleted_by', 'delete_reason', 'updated_by'])
            && Schema::connection('school')->hasColumns('compulsory_fees', ['deleted_by', 'delete_reason'])
            && Schema::connection('school')->hasColumns('optional_fees', ['deleted_by', 'delete_reason'])
            && Schema::connection('school')->hasColumns('bank_accounts', ['created_by', 'updated_by'])
            && Schema::connection('school')->hasTable('expense_change_logs')
            && Schema::connection('school')->hasTable('bank_account_balance_adjustments');
    }

    private function baseSchemaPresent(): bool
    {
        foreach (self::REQUIRED_BASE_TABLES as $table) {
            if (!Schema::connection('school')->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function rollback(int $batch, string $tenant): bool
    {
        if ($batch < 1) {
            $this->error("[{$tenant}] --rollback-batch must be a positive batch number.");
            return false;
        }

        $migrationsInBatch = DB::connection('school')->table('migrations')
            ->where('batch', $batch)->orderBy('migration')->pluck('migration')->all();
        $expected = self::MIGRATIONS;
        sort($expected);

        if ($migrationsInBatch !== $expected) {
            $this->error("[{$tenant}] batch {$batch} is not exactly the six P1 migrations; rollback refused.");
            return false;
        }

        if ($this->hasP1AuditWrites()) {
            $this->error("[{$tenant}] P1 audit data already exists; schema rollback is unsafe. Use a forward-fix or code rollback.");
            return false;
        }

        $exitCode = Artisan::call('migrate:rollback', [
            '--database' => 'school',
            '--path' => $this->migrationPaths(),
            '--realpath' => true,
            '--batch' => $batch,
            '--force' => true,
        ]);
        $this->output->write(Artisan::output());

        if ($exitCode !== self::SUCCESS || count($this->migrationState()) !== 0) {
            $this->error("[{$tenant}] P1 rollback did not complete; stop and investigate before the next tenant.");
            return false;
        }

        $this->info("[{$tenant}] P1 batch {$batch} rolled back.");
        return true;
    }

    private function hasP1AuditWrites(): bool
    {
        $school = DB::connection('school');

        return $school->table('expense_change_logs')->exists()
            || $school->table('bank_account_balance_adjustments')->exists()
            || $school->table('expenses')->whereNotNull('deleted_at')->exists()
            || $school->table('expenses')->whereNotNull('deleted_by')->exists()
            || $school->table('expenses')->whereNotNull('updated_by')->exists()
            || $school->table('compulsory_fees')->whereNotNull('deleted_by')->exists()
            || $school->table('optional_fees')->whereNotNull('deleted_by')->exists()
            || $school->table('bank_accounts')->whereNotNull('created_by')->exists()
            || $school->table('bank_accounts')->whereNotNull('updated_by')->exists();
    }
}
