<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, central-only runner for the Fund Account V2 audit context. */
final class MigrateCentralFundAccountV2 extends Command
{
    public const MIGRATION = '2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits';

    protected $signature = 'finance:migrate-fund-account-v2
        {--execute : Apply only the exact additive Fund Account V2 migration}';
    protected $description = 'Preflight or apply the Fund Account V2 central audit-context schema';

    public function handle(): int
    {
        try {
            $state = $this->state();
            $this->line("central_fund_account_v2={$state}");
            if ($state === 'unexpected') {
                return $this->reject('Fund Account V2 migration/schema mismatch; zero migration was run.');
            }
            if (!$this->option('execute') || $state === 'complete') {
                return self::SUCCESS;
            }
            if (app()->environment('production') && !MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path())) {
                return $this->reject('Production execution is allowed only from an immutable release path.');
            }

            $exit = Artisan::call('migrate', [
                '--database' => 'mysql',
                '--path' => database_path('migrations/'.self::MIGRATION.'.php'),
                '--realpath' => true,
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS || $this->state() !== 'complete') {
                return $this->reject('Fund Account V2 migration did not verify.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->reject('Fund Account V2 preflight failed: '.get_class($exception));
        }
    }

    private function state(): string
    {
        foreach (['migrations', 'central_finance_document_audits', 'central_finance_fund_accounts', 'central_finance_fund_account_school_allocations', 'central_finance_ledger_entries'] as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                return 'unexpected';
            }
        }

        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::MIGRATION)->exists();
        $hasGroup = Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id');
        $schoolNullable = $this->isNullable('central_finance_document_audits', 'school_id');
        if (!$recorded && !$hasGroup && !$schoolNullable) {
            return 'eligible';
        }
        if (!$recorded || !$hasGroup || !$schoolNullable) {
            return 'unexpected';
        }

        return 'complete';
    }

    private function isNullable(string $table, string $column): bool
    {
        try {
            foreach (Schema::connection('mysql')->getColumns($table) as $definition) {
                if (($definition['name'] ?? null) === $column) {
                    return (bool) ($definition['nullable'] ?? false);
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function reject(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
