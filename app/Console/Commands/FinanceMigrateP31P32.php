<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Narrow release runner for the two additive Finance P3.1/P3.2 migrations.
 * It intentionally has no arbitrary database, migration, or path option.
 */
class FinanceMigrateP31P32 extends Command
{
    private const ACTIVE_PRODUCTION_ROOT = '/www/wwwroot/43.160.241.126';
    private const PRODUCTION_RELEASES_ROOT = '/www/wwwroot/releases';

    public const MIGRATIONS = [
        '2026_08_12_000002_create_expense_import_batches_table',
        '2026_08_13_000001_create_other_incomes_table',
    ];

    private const PRODUCTION_TENANTS = [
        'SCH20261' => 'eschool_saas_1_demo',
        'SCH202615' => 'eschool_saas_15_zixuan',
        'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys',
        'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_',
        'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];

    private const REQUIRED_BASE_TABLES = ['migrations', 'schools', 'users', 'expenses', 'bank_accounts'];

    protected $signature = 'finance:migrate-p31-p32
        {--tenant=* : Exact trusted school code(s), e.g. SCH202615; defaults to every trusted registry tenant}
        {--execute : Apply only the two fixed P3.1/P3.2 migration files}';

    protected $description = 'Verify or apply only the Finance P3.1/P3.2 expense-import and Other Income migrations';

    public function handle(): int
    {
        $originalDatabase = config('database.connections.school.database');

        try {
            $trusted = $this->trustedTenants();
            if (!$this->validateRegistry($trusted)) {
                return self::FAILURE;
            }

            $selected = $this->selectedTenantCodes($trusted);
            if ($selected === null) {
                return self::FAILURE;
            }

            if ($this->option('execute') && !$this->executionEnvironmentIsTrusted($trusted)) {
                return $this->fail('Execution guard refused this environment or trusted registry. Verification remains available.');
            }

            foreach ($selected as $code) {
                $database = $trusted[$code];
                if (!$this->connect($database) || !$this->baseSchemaPresent()) {
                    return self::FAILURE;
                }

                $states = $this->states();
                $pair = self::pairState($states);
                $this->line("[{$code}] {$database}: {$pair}");

                if (in_array($pair, ['partial', 'inconsistent'], true)) {
                    return $this->fail("[{$code}] {$pair} P3.1/P3.2 state; no migration was run.");
                }

                if (!$this->option('execute') || $pair === 'complete') {
                    continue;
                }

                if (!$this->migrateAndVerify(self::MIGRATIONS[0], 'expense_import')) {
                    return self::FAILURE;
                }
                if (!$this->migrateAndVerify(self::MIGRATIONS[1], 'other_income')) {
                    return self::FAILURE;
                }
                $this->info("[{$code}] both P3.1/P3.2 migrations verified.");
            }

            return self::SUCCESS;
        } finally {
            Config::set('database.connections.school.database', $originalDatabase);
            DB::purge('school');
        }
    }

    /** @return array<string, string> */
    public function trustedTenants(): array
    {
        /** @var array<string, string> $tenants */
        $tenants = config('finance_release.p31_p32_tenants', self::PRODUCTION_TENANTS);

        return $tenants;
    }

    /** @param array<string, string> $trusted */
    private function validateRegistry(array $trusted): bool
    {
        try {
            $rows = DB::connection('mysql')->table('schools')
                ->whereNull('deleted_at')->whereNotNull('database_name')
                ->orderBy('code')->get(['code', 'database_name']);
        } catch (\Throwable) {
            return $this->fail('Trusted central schools registry is unavailable.');
        }

        $actual = [];
        foreach ($rows as $row) {
            if (isset($actual[$row->code])) {
                return $this->fail('Trusted central schools registry has an ambiguous school code.');
            }
            $actual[$row->code] = $row->database_name;
        }

        if ($actual !== $trusted) {
            return $this->fail('Trusted central schools registry does not match the approved tenant mapping.');
        }

        return true;
    }

    /** @param array<string, string> $trusted
     * @return array<int, string>|null */
    private function selectedTenantCodes(array $trusted): ?array
    {
        $codes = $this->option('tenant') ?: array_keys($trusted);
        if ($codes === [] || count($codes) !== count(array_unique($codes)) || array_diff($codes, array_keys($trusted))) {
            $this->error('Tenant selection must contain unique trusted school codes only; raw database names are refused.');
            return null;
        }

        return $codes;
    }

    /** @param array<string, string> $trusted */
    private function executionEnvironmentIsTrusted(array $trusted): bool
    {
        if (app()->environment('production')) {
            return self::isAllowedProductionExecutionPath(base_path())
                && $trusted === self::PRODUCTION_TENANTS;
        }

        // Test/local execution must still use a declared synthetic registry.
        return app()->environment(['local', 'testing']);
    }

    public static function isAllowedProductionExecutionPath(string $basePath): bool
    {
        return $basePath === self::ACTIVE_PRODUCTION_ROOT
            || self::isVettedReleaseDirectory($basePath, self::PRODUCTION_RELEASES_ROOT);
    }

    /** Runtime always supplies the fixed releases root; parameters support local characterization only. */
    public static function isVettedReleaseDirectory(string $basePath, string $releasesRoot): bool
    {
        $resolvedBasePath = realpath($basePath);
        $resolvedReleasesRoot = realpath($releasesRoot);

        if ($resolvedBasePath === false || $resolvedReleasesRoot === false
            || !str_starts_with($resolvedBasePath, $resolvedReleasesRoot . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $marker = $resolvedBasePath . DIRECTORY_SEPARATOR . '.release-commit';
        if (!is_file($marker) || is_link($marker) || !is_readable($marker)) {
            return false;
        }

        $commit = file_get_contents($marker);

        return $commit !== false && preg_match('/\\A[0-9a-f]{40}\\n?\\z/', $commit) === 1;
    }

    private function connect(string $database): bool
    {
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            $connection = DB::connection('school');
            $connection->getPdo();

            if ($connection->getDatabaseName() !== $database) {
                return $this->fail('Tenant connection did not resolve to the registry-selected database.');
            }

            return true;
        } catch (\Throwable) {
            return $this->fail('Tenant connection failed.');
        }
    }

    private function baseSchemaPresent(): bool
    {
        foreach (self::REQUIRED_BASE_TABLES as $table) {
            if (!Schema::connection('school')->hasTable($table)) {
                return $this->fail("Required base table missing: {$table}");
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function states(): array
    {
        return [
            'expense_import' => $this->migrationState(self::MIGRATIONS[0], $this->expenseImportSchemaComplete(), $this->expenseImportSchemaPresent()),
            'other_income' => $this->migrationState(self::MIGRATIONS[1], $this->otherIncomeSchemaComplete(), $this->otherIncomeSchemaPresent()),
        ];
    }

    public static function pairState(array $states): string
    {
        if (in_array('partial', $states, true)) {
            return 'partial';
        }
        if (in_array('inconsistent', $states, true)) {
            return 'inconsistent';
        }
        if ($states === ['expense_import' => 'absent', 'other_income' => 'absent']) {
            return 'eligible';
        }
        if ($states === ['expense_import' => 'applied', 'other_income' => 'applied']) {
            return 'complete';
        }

        return 'partial';
    }

    private function migrationState(string $migration, bool $schemaComplete, bool $schemaPresent): string
    {
        $recorded = DB::connection('school')->table('migrations')->where('migration', $migration)->exists();
        if ($schemaComplete && $recorded) {
            return 'applied';
        }
        if (!$schemaPresent && !$recorded) {
            return 'absent';
        }
        if (!$schemaComplete && $schemaPresent) {
            return 'partial';
        }

        return 'inconsistent';
    }

    private function migrateAndVerify(string $migration, string $stateKey): bool
    {
        $exit = Artisan::call('migrate', [
            '--database' => 'school',
            '--path' => self::path($migration),
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->output->write(Artisan::output());
        if ($exit !== self::SUCCESS) {
            return $this->fail("Targeted migration {$migration} failed.");
        }

        $state = $this->states()[$stateKey];
        return $state === 'applied'
            ? true
            : $this->fail("Targeted migration {$migration} failed schema/history verification; second migration was not run.");
    }

    public static function paths(): array
    {
        return array_map(static fn (string $migration): string => self::path($migration), self::MIGRATIONS);
    }

    private static function path(string $migration): string
    {
        return database_path("migrations/schools/{$migration}.php");
    }

    private function expenseImportSchemaPresent(): bool
    {
        $schema = Schema::connection('school');
        return $schema->hasTable('expense_import_batches') || $schema->hasColumn('expenses', 'payment_method');
    }

    private function expenseImportSchemaComplete(): bool
    {
        $schema = Schema::connection('school');
        if (!$schema->hasColumn('expenses', 'payment_method') || !$schema->hasColumns('expense_import_batches', [
            'id', 'token', 'school_id', 'imported_by', 'file_name', 'file_hash', 'preview_data', 'imported_expense_ids', 'status',
            'total_rows', 'valid_rows', 'error_rows', 'imported_rows', 'expired_at', 'consumed_at', 'last_error', 'created_at', 'updated_at',
        ])) {
            return false;
        }

        return $this->hasIndex('expense_import_batches', 'expense_import_batches_token_unique', ['token'])
            && $this->hasIndex('expense_import_batches', 'expense_import_batches_school_file_hash_unique', ['school_id', 'file_hash'])
            && $this->hasIndex('expense_import_batches', 'expense_import_batches_school_id_status_index', ['school_id', 'status'])
            && $this->hasIndex('expense_import_batches', 'expense_import_batches_imported_by_index', ['imported_by'])
            && $this->hasForeignKey('expense_import_batches', 'school_id', 'schools');
    }

    private function otherIncomeSchemaPresent(): bool
    {
        return Schema::connection('school')->hasTable('other_incomes');
    }

    private function otherIncomeSchemaComplete(): bool
    {
        $schema = Schema::connection('school');
        if (!$schema->hasColumns('other_incomes', [
            'id', 'school_id', 'bank_account_id', 'date', 'payer', 'description', 'amount', 'payment_method', 'reference_no', 'remark', 'created_by', 'created_at', 'updated_at', 'deleted_at',
        ])) {
            return false;
        }

        return $this->hasIndex('other_incomes', 'other_incomes_school_id_index', ['school_id'])
            && $this->hasIndex('other_incomes', 'other_incomes_bank_account_id_index', ['bank_account_id'])
            && $this->hasIndex('other_incomes', 'other_incomes_date_index', ['date'])
            && $this->hasIndex('other_incomes', 'other_incomes_reference_no_index', ['reference_no'])
            && $this->hasIndex('other_incomes', 'other_incomes_created_by_index', ['created_by'])
            && $this->hasForeignKey('other_incomes', 'bank_account_id', 'bank_accounts');
    }

    private function hasIndex(string $table, string $name, array $columns): bool
    {
        try {
            $indexes = DB::connection('school')->getDoctrineSchemaManager()->listTableIndexes($table);
            return isset($indexes[$name]) && array_values($indexes[$name]->getColumns()) === $columns;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasForeignKey(string $table, string $column, string $referencedTable): bool
    {
        try {
            foreach (DB::connection('school')->getDoctrineSchemaManager()->listTableForeignKeys($table) as $foreignKey) {
                if ($foreignKey->getLocalColumns() === [$column] && $foreignKey->getForeignTableName() === $referencedTable) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
