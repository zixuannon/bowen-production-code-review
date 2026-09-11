<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Production-safe, targeted tenant schema runner for Student Fee Assignment. */
final class MigrateStudentFeeAssignmentSchema extends Command
{
    public const MIGRATIONS = [
        '2026_08_27_000001_create_student_fee_assignment_tables',
        '2026_08_27_000003_add_type_to_student_fee_assignments',
        '2026_08_27_000004_create_student_fee_assignment_source_locks',
    ];

    /** Demo is intentionally absent. Keep this exact release allowlist. */
    public const PRODUCTION_TENANTS = [
        'MMBOWEN01' => 'eschool_saas_15_zixuan', 'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys', 'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_', 'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];
    private const CANARY = 'MMBOWEN01';

    protected $signature = 'finance:migrate-student-fee-assignments
        {--tenant=* : Exact trusted School Code(s); database names are refused}
        {--execute : Apply only the fixed Student Fee Assignment migration allowlist}';

    protected $description = 'Verify or safely apply Student Fee Assignment schema for the fixed active-school allowlist';

    public function handle(): int
    {
        $original = config('database.connections.school.database');
        try {
            $trusted = $this->trustedTenants();
            if (!$this->validateRegistry($trusted) || !($selected = $this->selected($trusted))) return self::FAILURE;
            if ($this->option('execute') && (!$this->environmentTrusted($trusted) || !$this->validPhase($trusted, $selected))) return self::FAILURE;
            $states = [];
            foreach ($selected as $code) {
                if (!$this->connect($trusted[$code]) || !$this->baseSchema()) return self::FAILURE;
                $state = $this->state(); $this->line("[{$code}] {$trusted[$code]}: {$state}");
                if ($state === 'unexpected') return $this->fail("[{$code}] unexpected migration/schema state; no migration was run.");
                $states[$code] = $state;
            }
            if (!$this->option('execute')) return self::SUCCESS;
            foreach ($selected as $code) {
                if ($states[$code] === 'complete') continue;
                if (!$this->connect($trusted[$code]) || !$this->migrateAndVerify()) return self::FAILURE;
                $this->info("[{$code}] Student Fee Assignment schema verified.");
            }
            return self::SUCCESS;
        } finally { Config::set('database.connections.school.database', $original); DB::purge('school'); }
    }

    /** @return array<string,string> */
    public function trustedTenants(): array { return config('finance_release.student_fee_assignment_tenants', self::PRODUCTION_TENANTS); }

    /** @param array<string,string> $trusted */
    private function validateRegistry(array $trusted): bool
    {
        if (app()->environment('production') && $trusted !== self::PRODUCTION_TENANTS) return $this->reject('Production trusted tenant mapping differs from the fixed active-school allowlist.');
        try { $rows = app(\App\Services\SchoolCodeService::class)->resolveTrustedRegistry($trusted); }
        catch (\Throwable) { return $this->reject('Trusted central School registry is unavailable.'); }
        return $rows !== null ? true : $this->reject('Trusted central School registry does not match the approved tenant mapping.');
    }

    /** @param array<string,string> $trusted @return list<string>|null */
    private function selected(array $trusted): ?array
    {
        $codes = $this->option('tenant') ?: array_keys($trusted);
        if ($codes === [] || count($codes) !== count(array_unique($codes)) || array_diff($codes, array_keys($trusted))) { $this->error('Tenant selection must contain unique trusted active School Codes only; Demo and raw database names are refused.'); return null; }
        return array_values($codes);
    }

    /** @param array<string,string> $trusted @param list<string> $selected */
    private function validPhase(array $trusted, array $selected): bool
    {
        $canary = config('finance_release.student_fee_assignment_canary', self::CANARY); $remaining = array_values(array_diff(array_keys($trusted), [$canary]));
        sort($remaining); $comparison = $selected; sort($comparison);
        if ($comparison === [$canary]) return true;
        if ($comparison !== $remaining) return $this->reject("--execute requires {$canary} alone for the canary, then exactly the remaining active School Codes after canary verification.");
        return $this->connect($trusted[$canary]) && $this->baseSchema() && $this->state() === 'complete'
            ? true : $this->reject("Canary {$canary} is not schema/history verified; remaining tenants were not touched.");
    }

    /** @param array<string,string> $trusted */
    private function environmentTrusted(array $trusted): bool
    {
        if (app()->environment('production')) return MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path()) && $trusted === self::PRODUCTION_TENANTS;
        return app()->environment(['local','testing']);
    }

    private function connect(string $database): bool
    {
        try { Config::set('database.connections.school.database', $database); DB::purge('school'); $connection = DB::connection('school'); $connection->getPdo(); return $connection->getDatabaseName() === $database ? true : $this->reject('Tenant connection did not resolve to the registry-selected database.'); }
        catch (\Throwable) { return $this->reject('Tenant connection failed.'); }
    }
    private function baseSchema(): bool
    {
        foreach (['migrations','students','fees_class_types'] as $table) if (!Schema::connection('school')->hasTable($table)) return $this->reject("Required base table missing: {$table}");
        return true;
    }
    private function state(): string
    {
        $recorded = DB::connection('school')->table('migrations')->whereIn('migration', self::MIGRATIONS)->pluck('migration')->all();
        $assignmentTable = Schema::connection('school')->hasTable('student_fee_assignments');
        $itemsTable = Schema::connection('school')->hasTable('student_fee_assignment_items');
        $locksTable = Schema::connection('school')->hasTable('student_fee_assignment_source_locks');
        $tables = $assignmentTable && $itemsTable && $locksTable;
        $type = Schema::connection('school')->hasColumn('student_fee_assignments', 'assignment_type');
        if ($recorded === [] && !$assignmentTable && !$itemsTable && !$locksTable && !$type) return 'eligible';
        return count($recorded) === count(self::MIGRATIONS) && $tables && $type && $this->uniqueIndexesPresent()
            ? 'complete' : 'unexpected';
    }

    private function uniqueIndexesPresent(): bool
    {
        return $this->hasUniqueIndex('student_fee_assignments', 'student_fee_assignments_uuid_unique')
            && $this->hasUniqueIndex('student_fee_assignment_items', 'student_fee_assignment_item_source_unique')
            && $this->hasUniqueIndex('student_fee_assignment_source_locks', 'student_fee_assignment_source_lock_unique');
    }

    private function hasUniqueIndex(string $table, string $name): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name && ($index['unique'] ?? false) === true) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }
    private function migrateAndVerify(): bool
    {
        foreach (self::MIGRATIONS as $migration) {
            $exit = Artisan::call('migrate', ['--database'=>'school','--path'=>database_path('migrations/schools/'.$migration.'.php'),'--realpath'=>true,'--force'=>true]); $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS) return $this->reject("Targeted migration failed: {$migration}");
        }
        return $this->state() === 'complete' ? true : $this->reject('Targeted migration schema/history verification failed.');
    }
    private function fail(string $message): int { $this->error($message); return self::FAILURE; }
    private function reject(string $message): bool { $this->error($message); return false; }
}
