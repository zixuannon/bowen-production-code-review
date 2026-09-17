<?php

namespace App\Console\Commands;

use App\Services\CentralChartOfAccountsSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Central-only, all-targets-first preflight. Never runs a migration directory. */
final class MigrateCentralChartOfAccounts extends Command
{
    public const MIGRATIONS = [
        '2026_09_18_000001_add_central_chart_of_accounts',
        '2026_09_18_000002_add_owner_holder_to_central_finance_fund_accounts',
    ];
    private const PRODUCTION_DATABASE = 'sql_43_160_241_126';

    protected $signature = 'finance:migrate-central-chart-of-accounts
        {--execute : Apply only the two reviewed additive Central schema paths}';
    protected $description = 'Read-only preflight or exact-path Central Chart of Accounts and Fund Account holder migration';

    public function handle(): int
    {
        try {
            // Validate EVERY path/schema/registry state before the first DDL.
            $states = $this->states();
            foreach ($states as $migration => $state) $this->line("{$migration}={$state}");
            if (!$this->option('execute')) return self::SUCCESS;
            if (app()->environment('production') && !MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path())) {
                throw new RuntimeException('Production execution requires an immutable release path.');
            }
            foreach ($states as $migration => $state) {
                if ($state === 'complete') continue;
                $exit = Artisan::call('migrate', [
                    '--database' => 'mysql', '--path' => database_path('migrations/'.$migration.'.php'),
                    '--realpath' => true, '--force' => true,
                ]);
                $this->output->write(Artisan::output());
                if ($exit !== self::SUCCESS || $this->states()[$migration] !== 'complete') {
                    throw new RuntimeException('Exact migration failed verification: '.$migration.'; stopped before subsequent paths.');
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Central Chart of Accounts preflight/migration mismatch: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    /** @return array<string,string> */
    private function states(): array
    {
        $db = DB::connection('mysql');
        if (app()->environment('production') && ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== self::PRODUCTION_DATABASE)) {
            throw new RuntimeException('Production Central database target does not match the approved registry.');
        }
        foreach (self::MIGRATIONS as $migration) {
            $path = database_path('migrations/'.$migration.'.php');
            if (!is_file($path) || is_link($path)) throw new RuntimeException('Missing or indirect exact migration path: '.$migration);
        }
        $schema = Schema::connection('mysql');
        foreach ([
            'migrations' => ['id', 'migration', 'batch'],
            'schools' => ['id'], 'finance_groups' => ['id'],
            'central_finance_categories' => ['id', 'category_uuid', 'school_id', 'category_code', 'type', 'name', 'is_active'],
            'central_finance_fund_accounts' => ['id', 'account_uuid', 'group_id', 'school_id', 'owner_type', 'account_code', 'currency', 'opening_balance'],
        ] as $table => $columns) {
            if (!$schema->hasTable($table) || !$schema->hasColumns($table, $columns)) {
                throw new RuntimeException('Required Central schema missing: '.$table);
            }
        }
        $states = [];
        foreach (self::MIGRATIONS as $index => $migration) {
            $count = $db->table('migrations')->where('migration', $migration)->count();
            if ($count > 1) throw new RuntimeException('Duplicate migration registry entries: '.$migration);
            if ($index === 0) {
                $hasAny = $schema->hasColumn('central_finance_categories', 'group_id')
                    || $schema->hasTable('central_finance_category_school_allocations')
                    || $schema->hasTable('central_finance_category_audits');
                $school = collect($schema->getColumns('central_finance_categories'))->firstWhere('name', 'school_id');
                if ($count === 0 && !$hasAny && !($school['nullable'] ?? true)) {
                    $states[$migration] = 'eligible';
                    continue;
                }
                if ($count !== 1 || !$hasAny) throw new RuntimeException('Chart of Accounts registry/schema partial state. Zero migration permitted.');
                CentralChartOfAccountsSchema::assertComplete();
            } else {
                $holder = collect($schema->getColumns('central_finance_fund_accounts'))->firstWhere('name', 'owner_holder');
                if ($count === 0 && $holder === null) {
                    $states[$migration] = 'eligible';
                    continue;
                }
                if ($count !== 1 || $holder === null || !($holder['nullable'] ?? false)
                    || !in_array($schema->getColumnType('central_finance_fund_accounts', 'owner_holder'), ['string', 'varchar'], true)
                    || ($db->getDriverName() === 'mysql' && strtolower($holder['type'] ?? '') !== 'varchar(191)')) {
                    throw new RuntimeException('Fund Account holder registry/schema partial state. Zero migration permitted.');
                }
            }
            $states[$migration] = 'complete';
        }
        return $states;
    }
}
