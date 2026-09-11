<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed, fail-closed release runner for the tenant-local record-lifecycle
 * schema. It deliberately accepts only approved School Codes, never a
 * database name, migration name, or arbitrary migration path.
 */
final class FinanceMigrateRecordLifecycle extends Command
{
    public const MIGRATIONS = [
        '2026_09_01_000001_create_school_record_lifecycle_audits_table',
        '2026_09_01_000002_add_deleted_at_to_fees_class_types',
    ];

    /** Demo is intentionally excluded from this lifecycle release. */
    public const PRODUCTION_TENANTS = [
        'MMBOWEN01' => 'eschool_saas_15_zixuan',
        'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys',
        'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_',
        'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];

    private const CANARY = 'MMBOWEN01';

    protected $signature = 'finance:migrate-record-lifecycle
        {--tenant=* : Exact trusted School Code(s); Demo, unknown Schools, and database names are refused}
        {--execute : Apply only the two fixed Record Lifecycle tenant migrations}';

    protected $description = 'Verify or safely apply the fixed Record Lifecycle tenant schema allowlist';

    public function handle(): int
    {
        $originalDatabase = config('database.connections.school.database');

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
                if (!$this->connect($trusted[$code]) || !$this->baseSchemaPresent()) {
                    return self::FAILURE;
                }
                $state = $this->state();
                $this->line("[{$code}] {$trusted[$code]}: {$state}");
                if ($state === 'unexpected') {
                    return $this->reject("[{$code}] migration/schema state is partial or inconsistent; no migration was run.");
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
                if (!$this->connect($trusted[$code]) || !$this->migrateAndVerify()) {
                    return self::FAILURE;
                }
                $this->info("[{$code}] Record Lifecycle schema verified.");
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
        return config('finance_release.record_lifecycle_tenants', self::PRODUCTION_TENANTS);
    }

    /** @param array<string, string> $trusted */
    private function validateRegistry(array $trusted): bool
    {
        if (app()->environment('production') && $trusted !== self::PRODUCTION_TENANTS) {
            return $this->reject('Production trusted tenant mapping differs from the fixed active-School allowlist.');
        }

        try {
            $rows = app(\App\Services\SchoolCodeService::class)->resolveTrustedRegistry($trusted);
        } catch (\Throwable) {
            return $this->reject('Trusted central School registry is unavailable.');
        }

        return $rows !== null ?: $this->reject('Trusted central School registry does not match the approved lifecycle allowlist.');
    }

    /** @param array<string, string> $trusted
     * @return list<string>|null */
    private function selected(array $trusted): ?array
    {
        $codes = $this->option('tenant') ?: array_keys($trusted);
        if ($codes === [] || count($codes) !== count(array_unique($codes)) || array_diff($codes, array_keys($trusted))) {
            $this->error('Tenant selection must contain unique active School Codes only; Demo, unknown Schools, and database names are refused.');
            return null;
        }

        return array_values($codes);
    }

    /** @param array<string, string> $trusted
     * @param list<string> $selected */
    private function validPhase(array $trusted, array $selected): bool
    {
        $remaining = array_values(array_diff(array_keys($trusted), [self::CANARY]));
        sort($remaining);
        $comparison = $selected;
        sort($comparison);
        if ($comparison === [self::CANARY]) {
            return true;
        }
        if ($comparison !== $remaining) {
            return $this->reject('--execute requires MMBOWEN01 alone for canary, then exactly the remaining six active Schools after canary verification.');
        }

        return $this->connect($trusted[self::CANARY]) && $this->baseSchemaPresent() && $this->state() === 'complete'
            ?: $this->reject('MMBOWEN01 canary is not schema/history verified; remaining Schools were not touched.');
    }

    /** @param array<string, string> $trusted */
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

            return $connection->getDatabaseName() === $database ?: $this->reject('Tenant connection did not resolve to the registry-selected database.');
        } catch (\Throwable) {
            return $this->reject('Tenant connection failed.');
        }
    }

    private function baseSchemaPresent(): bool
    {
        foreach (['migrations', 'students', 'users', 'fees_class_types'] as $table) {
            if (!Schema::connection('school')->hasTable($table)) {
                return $this->reject("Required tenant table missing: {$table}");
            }
        }

        return true;
    }

    private function state(): string
    {
        $recorded = DB::connection('school')->table('migrations')->whereIn('migration', self::MIGRATIONS)->pluck('migration')->all();
        $auditPresent = Schema::connection('school')->hasTable('school_record_lifecycle_audits');
        $deletedAtPresent = Schema::connection('school')->hasColumn('fees_class_types', 'deleted_at');

        if ($recorded === [] && !$auditPresent && !$deletedAtPresent) {
            return 'eligible';
        }

        return count($recorded) === count(self::MIGRATIONS) && $this->auditSchemaComplete() && $deletedAtPresent
            ? 'complete'
            : 'unexpected';
    }

    private function auditSchemaComplete(): bool
    {
        $schema = Schema::connection('school');
        return $schema->hasColumns('school_record_lifecycle_audits', [
            'id', 'uuid', 'subject_type', 'subject_id', 'subject_uuid', 'action', 'reason',
            'actor_user_id', 'actor_user_uuid', 'metadata', 'created_at',
        ]) && $this->hasUniqueIndex('school_record_lifecycle_audits', 'school_record_lifecycle_audits_uuid_unique');
    }

    private function hasUniqueIndex(string $table, string $name): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name && ($index['unique'] ?? false) === true) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function migrateAndVerify(): bool
    {
        foreach (self::MIGRATIONS as $migration) {
            $exit = Artisan::call('migrate', [
                '--database' => 'school',
                '--path' => database_path("migrations/schools/{$migration}.php"),
                '--realpath' => true,
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS) {
                return $this->reject("Targeted lifecycle migration failed: {$migration}");
            }
        }

        return $this->state() === 'complete' ?: $this->reject('Lifecycle migration schema/history verification failed.');
    }

    private function reject(string $message): bool
    {
        $this->error($message);
        return false;
    }
}
