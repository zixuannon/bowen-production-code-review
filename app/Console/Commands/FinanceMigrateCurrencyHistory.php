<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Production-safe exact-path tenant runner for Round 4 currency history. */
final class FinanceMigrateCurrencyHistory extends Command
{
    public const MIGRATION = '2026_09_11_000001_add_financial_currency_history_integrity';

    /** Demo is intentionally absent. Keep this exact release allowlist. */
    public const PRODUCTION_TENANTS = [
        'SCH202615' => 'eschool_saas_15_zixuan', 'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys', 'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_', 'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];

    private const CANARY = 'SCH202615';

    protected $signature = 'finance:migrate-currency-history
        {--tenant=* : Exact trusted School Code(s); database names are refused}
        {--execute : Apply only the fixed Round 4 exact-path migration}';

    protected $description = 'Verify or safely apply Round 4 financial currency history schema';

    public function handle(): int
    {
        $original = config('database.connections.school.database');
        try {
            $trusted = $this->trustedTenants();
            if (!$this->validateRegistry($trusted) || !($selected = $this->selected($trusted))) {
                return self::FAILURE;
            }
            if ($this->option('execute') && (!$this->environmentTrusted($trusted) || !$this->validPhase($trusted, $selected))) {
                return self::FAILURE;
            }

            $states = [];
            foreach ($selected as $code) {
                if (!$this->connect($trusted[$code]) || !$this->baseSchema()) return self::FAILURE;
                $states[$code] = $this->state();
                $this->line("[{$code}] {$trusted[$code]}: {$states[$code]}");
                if ($states[$code] === 'unexpected') return $this->fail("[{$code}] unexpected migration/schema state; no migration was run.");
            }
            if (!$this->option('execute')) return self::SUCCESS;

            foreach ($selected as $code) {
                if ($states[$code] === 'complete') continue;
                if (!$this->connect($trusted[$code]) || !$this->migrateAndVerify()) return self::FAILURE;
                $this->info("[{$code}] Round 4 currency history schema verified.");
            }
            return self::SUCCESS;
        } finally {
            Config::set('database.connections.school.database', $original);
            DB::purge('school');
        }
    }

    /** @return array<string,string> */
    public function trustedTenants(): array
    {
        return config('finance_release.currency_history_tenants', self::PRODUCTION_TENANTS);
    }

    /** @param array<string,string> $trusted */
    private function validateRegistry(array $trusted): bool
    {
        if (app()->environment('production') && $trusted !== self::PRODUCTION_TENANTS) {
            return $this->reject('Production trusted tenant mapping differs from the fixed active-school allowlist.');
        }
        try {
            $rows = DB::connection('mysql')->table('schools')->whereIn('code', array_keys($trusted))
                ->whereNull('deleted_at')->whereNotNull('database_name')->orderBy('code')->get(['code', 'database_name']);
        } catch (\Throwable) {
            return $this->reject('Trusted central School registry is unavailable.');
        }
        $actual = [];
        foreach ($rows as $row) {
            if (isset($actual[$row->code])) return $this->reject('Trusted central School registry has an ambiguous School Code.');
            $actual[$row->code] = $row->database_name;
        }
        $expected = $trusted;
        ksort($actual);
        ksort($expected);
        return $actual === $expected ?: $this->reject('Trusted central School registry does not match the approved tenant mapping.');
    }

    /** @param array<string,string> $trusted @return list<string>|null */
    private function selected(array $trusted): ?array
    {
        $codes = $this->option('tenant') ?: array_keys($trusted);
        if ($codes === [] || count($codes) !== count(array_unique($codes)) || array_diff($codes, array_keys($trusted))) {
            $this->error('Tenant selection must contain unique trusted active School Codes only; Demo and raw database names are refused.');
            return null;
        }
        return array_values($codes);
    }

    /** @param array<string,string> $trusted @param list<string> $selected */
    private function validPhase(array $trusted, array $selected): bool
    {
        $canary = config('finance_release.currency_history_canary', self::CANARY);
        $remaining = array_values(array_diff(array_keys($trusted), [$canary]));
        sort($remaining);
        $comparison = $selected;
        sort($comparison);
        if ($comparison === [$canary]) return true;
        if ($comparison !== $remaining) {
            return $this->reject("--execute requires {$canary} alone for the canary, then exactly the remaining active School Codes after canary verification.");
        }
        return $this->connect($trusted[$canary]) && $this->baseSchema() && $this->state() === 'complete'
            ?: $this->reject("Canary {$canary} is not schema/history verified; remaining tenants were not touched.");
    }

    /** @param array<string,string> $trusted */
    private function environmentTrusted(array $trusted): bool
    {
        if (app()->environment('production')) {
            return MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path())
                && $trusted === self::PRODUCTION_TENANTS;
        }
        return app()->environment(['local', 'testing']);
    }

    private function connect(string $database): bool
    {
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            $connection = DB::connection('school');
            $connection->getPdo();
            return $connection->getDatabaseName() === $database
                ?: $this->reject('Tenant connection did not resolve to the registry-selected database.');
        } catch (\Throwable) {
            return $this->reject('Tenant connection failed.');
        }
    }

    private function baseSchema(): bool
    {
        foreach (['migrations', 'fees_paids', 'bank_accounts', 'compulsory_fees', 'optional_fees', 'other_incomes', 'student_fee_assignment_items'] as $table) {
            if (!Schema::connection('school')->hasTable($table)) return $this->reject("Required base table missing: {$table}");
        }
        return true;
    }

    private function state(): string
    {
        $recorded = DB::connection('school')->table('migrations')->where('migration', self::MIGRATION)->exists();
        if (!$recorded && !$this->schemaPresent()) return 'eligible';
        return $recorded && $this->schemaComplete() ? 'complete' : 'unexpected';
    }

    private function schemaPresent(): bool
    {
        return Schema::connection('school')->hasTable('fee_payment_fx_snapshots')
            || Schema::connection('school')->hasColumn('compulsory_fees', 'fee_payment_fx_snapshot_id')
            || Schema::connection('school')->hasColumn('optional_fees', 'fee_payment_fx_snapshot_id')
            || Schema::connection('school')->hasColumn('other_incomes', 'transaction_currency')
            || Schema::connection('school')->hasColumn('student_fee_assignment_items', 'exchange_rate_snapshot');
    }

    private function schemaComplete(): bool
    {
        if (!Schema::connection('school')->hasTable('fee_payment_fx_snapshots')) return false;
        foreach (['compulsory_fees', 'optional_fees'] as $table) {
            foreach (['fee_payment_fx_snapshot_id', 'transaction_currency', 'original_amount', 'exchange_rate_snapshot', 'amount_mmk'] as $column) {
                if (!Schema::connection('school')->hasColumn($table, $column)) return false;
            }
        }
        foreach (['transaction_currency', 'original_amount', 'exchange_rate_snapshot', 'amount_mmk'] as $column) {
            if (!Schema::connection('school')->hasColumn('other_incomes', $column)) return false;
        }
        foreach (['exchange_rate_snapshot', 'amount_mmk_snapshot'] as $column) {
            if (!Schema::connection('school')->hasColumn('student_fee_assignment_items', $column)) return false;
        }
        return true;
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
            ?: $this->reject('Targeted Round 4 migration or schema/history verification failed.');
    }

    private function fail(string $message): int { $this->error($message); return self::FAILURE; }
    private function reject(string $message): bool { $this->error($message); return false; }
}
