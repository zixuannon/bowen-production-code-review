<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Releases the only tenant-side dependency of Central Finance School Staff
 * identities. It deliberately accepts School Codes, never database names.
 */
final class MigrateCentralFinanceStaffUuid extends Command
{
    public const MIGRATION = '2026_08_24_000003_add_central_finance_source_uuid_to_users_table';

    /** Demo is intentionally absent. Keep this exact production allowlist. */
    public const PRODUCTION_TENANTS = [
        'SCH202615' => 'eschool_saas_15_zixuan',
        'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys',
        'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_',
        'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];

    private const CANARY = 'SCH202615';
    private const ACTIVE_PRODUCTION_ROOT = '/www/wwwroot/43.160.241.126';
    private const PRODUCTION_RELEASES_ROOT = '/www/wwwroot/releases';

    protected $signature = 'finance:migrate-central-finance-staff-uuid
        {--tenant=* : Exact trusted School Code(s); database names are refused}
        {--execute : Run only the fixed Central Finance staff UUID migration}';

    protected $description = 'Verify or safely apply the Central Finance School Staff UUID migration for the fixed active-school allowlist';

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

            if ($this->option('execute') && !$this->validExecutionPhase($trusted, $selected)) {
                return self::FAILURE;
            }

            $states = [];
            foreach ($selected as $code) {
                if (!$this->connect($trusted[$code]) || !$this->baseSchemaPresent()) {
                    return self::FAILURE;
                }

                $state = $this->state();
                $this->line("[{$code}] {$trusted[$code]}: {$state}");
                if ($state === 'unexpected') {
                    return $this->fail("[{$code}] unexpected migration/schema state; no migration was run.");
                }
                $states[$code] = $state;
            }

            if (!$this->option('execute')) {
                return self::SUCCESS;
            }

            foreach ($selected as $code) {
                if ($states[$code] === 'complete') {
                    continue;
                }

                if (!$this->connect($trusted[$code])) {
                    return self::FAILURE;
                }
                if (!$this->migrateAndVerify()) {
                    return self::FAILURE;
                }
                $this->info("[{$code}] Central Finance Staff UUID schema verified.");
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
        $tenants = config('finance_release.central_finance_staff_uuid_tenants', self::PRODUCTION_TENANTS);

        return $tenants;
    }

    /** @param array<string, string> $trusted */
    private function validateRegistry(array $trusted): bool
    {
        if (app()->environment('production') && $trusted !== self::PRODUCTION_TENANTS) {
            return $this->reject('Production trusted tenant mapping differs from the fixed active-school allowlist.');
        }

        try {
            $rows = DB::connection('mysql')->table('schools')
                ->whereIn('code', array_keys($trusted))
                ->whereNull('deleted_at')->whereNotNull('database_name')
                ->orderBy('code')->get(['code', 'database_name']);
        } catch (\Throwable) {
            return $this->reject('Trusted central School registry is unavailable.');
        }

        $actual = [];
        foreach ($rows as $row) {
            if (isset($actual[$row->code])) {
                return $this->reject('Trusted central School registry has an ambiguous School Code.');
            }
            $actual[$row->code] = $row->database_name;
        }
        ksort($actual); $expected = $trusted; ksort($expected);

        return $actual === $expected
            ? true
            : $this->reject('Trusted central School registry does not match the approved tenant mapping.');
    }

    /** @param array<string, string> $trusted
     * @return list<string>|null */
    private function selectedTenantCodes(array $trusted): ?array
    {
        $codes = $this->option('tenant') ?: array_keys($trusted);
        if ($codes === [] || count($codes) !== count(array_unique($codes)) || array_diff($codes, array_keys($trusted))) {
            $this->error('Tenant selection must contain unique trusted active School Codes only; Demo and raw database names are refused.');
            return null;
        }

        return array_values($codes);
    }

    /** @param array<string, string> $trusted
     * @param list<string> $selected */
    private function validExecutionPhase(array $trusted, array $selected): bool
    {
        $canary = $this->canaryCode();
        $remaining = array_values(array_diff(array_keys($trusted), [$canary]));
        sort($remaining); $comparison = $selected; sort($comparison);

        if ($comparison === [$canary]) {
            return true;
        }
        if ($comparison !== $remaining) {
            return $this->reject("--execute requires {$canary} alone for the canary, then exactly the remaining six active School Codes after canary verification.");
        }

        if (!$this->connect($trusted[$canary]) || !$this->baseSchemaPresent()) {
            return $this->reject("Canary {$canary} is not schema-verified; remaining tenants were not touched.");
        }
        $canaryState = $this->state();
        if ($canaryState !== 'complete') {
            return $this->reject("Canary {$canary} is not schema-verified; remaining tenants were not touched.");
        }

        return true;
    }

    /** @param array<string, string> $trusted */
    private function executionEnvironmentIsTrusted(array $trusted): bool
    {
        if (app()->environment('production')) {
            return self::isAllowedProductionExecutionPath(base_path()) && $trusted === self::PRODUCTION_TENANTS;
        }

        return app()->environment(['local', 'testing']);
    }

    private function canaryCode(): string
    {
        return self::CANARY;
    }

    public static function isAllowedProductionExecutionPath(string $basePath): bool
    {
        return $basePath === self::ACTIVE_PRODUCTION_ROOT
            || FinanceMigrateP31P32::isVettedReleaseDirectory($basePath, self::PRODUCTION_RELEASES_ROOT);
    }

    private function connect(string $database): bool
    {
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            $connection = DB::connection('school');
            $connection->getPdo();

            return $connection->getDatabaseName() === $database
                ? true
                : $this->reject('Tenant connection did not resolve to the registry-selected database.');
        } catch (\Throwable) {
            return $this->reject('Tenant connection failed.');
        }
    }

    private function baseSchemaPresent(): bool
    {
        foreach (['migrations', 'users'] as $table) {
            if (!Schema::connection('school')->hasTable($table)) {
                return $this->reject("Required base table missing: {$table}");
            }
        }

        return true;
    }

    private function state(): string
    {
        $recorded = DB::connection('school')->table('migrations')->where('migration', self::MIGRATION)->count();
        $column = Schema::connection('school')->hasColumn('users', 'central_finance_source_uuid');
        $complete = $column && $this->hasUuidUniqueIndex();

        if ($recorded === 0 && !$column) {
            return 'eligible';
        }
        if ($recorded === 1 && $complete) {
            return 'complete';
        }

        return 'unexpected';
    }

    private function hasUuidUniqueIndex(): bool
    {
        try {
            $indexes = DB::connection('school')->getDoctrineSchemaManager()->listTableIndexes('users');
            $index = $indexes['users_central_finance_source_uuid_unique'] ?? null;

            return $index !== null && $index->isUnique() && array_values($index->getColumns()) === ['central_finance_source_uuid'];
        } catch (\Throwable) {
            return false;
        }
    }

    private function migrateAndVerify(): bool
    {
        $exit = Artisan::call('migrate', [
            '--database' => 'school',
            '--path' => database_path('migrations/schools/' . self::MIGRATION . '.php'),
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->output->write(Artisan::output());

        return $exit === self::SUCCESS && $this->state() === 'complete'
            ? true
            : $this->reject('Targeted Central Finance Staff UUID migration failed schema/history verification.');
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }

    private function reject(string $message): bool
    {
        $this->error($message);

        return false;
    }
}
