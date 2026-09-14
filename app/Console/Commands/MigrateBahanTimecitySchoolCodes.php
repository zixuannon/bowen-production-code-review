<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, central-only runner for the Bahan/Timecity canonical identity transition. */
final class MigrateBahanTimecitySchoolCodes extends Command
{
    public const CENTRAL_MIGRATION = '2026_09_14_000002_canonicalize_bahan_timecity_school_codes';
    public const TARGETS = [
        'MMBOWEN02' => ['legacy' => 'SCH202616', 'database' => 'eschool_saas_17_bahan'],
        'MMBOWEN03' => ['legacy' => 'SCH202619', 'database' => 'eschool_saas_19_timecitys'],
    ];

    protected $signature = 'centralization:migrate-school-codes
        {--execute : Apply only the exact audited Bahan/Timecity School Code migration}';
    protected $description = 'Preflight or apply the audited Bahan and Timecity canonical School Code mapping';

    public function handle(): int
    {
        try {
            $state = $this->state();
            $this->line("bahan_timecity_school_code_mapping={$state}");
            if ($state === 'unexpected') {
                return $this->fail('Bahan/Timecity School Code registry, history, or migration state mismatch; zero write.');
            }
            if (!$this->option('execute') || $state === 'complete') {
                return self::SUCCESS;
            }

            $path = database_path('migrations/'.self::CENTRAL_MIGRATION.'.php');
            $exit = Artisan::call('migrate', [
                '--database' => 'mysql', '--path' => $path, '--realpath' => true, '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS || $this->state() !== 'complete') {
                return $this->fail('Bahan/Timecity canonical School Code migration did not verify.');
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->fail('Bahan/Timecity School Code preflight failed: '.get_class($exception));
        }
    }

    private function state(): string
    {
        if (!$this->baseSchemaComplete()) {
            return 'unexpected';
        }
        $recorded = DB::connection('mysql')->table('migrations')
            ->where('migration', self::CENTRAL_MIGRATION)->exists();
        $eligible = true;
        $complete = true;

        foreach (self::TARGETS as $canonical => $target) {
            $legacySchool = DB::connection('mysql')->table('schools')
                ->where('code', $target['legacy'])->whereNull('deleted_at')->get(['id', 'database_name']);
            $canonicalSchool = DB::connection('mysql')->table('schools')
                ->where('code', $canonical)->get(['id', 'database_name']);
            $history = DB::connection('mysql')->table('school_code_history')
                ->where(['legacy_code' => $target['legacy'], 'canonical_code' => $canonical])->get(['school_id']);

            $eligible = $eligible && $legacySchool->count() === 1 && $canonicalSchool->isEmpty()
                && $history->isEmpty()
                && hash_equals($target['database'], (string) $legacySchool->sole()->database_name);
            $complete = $complete && $legacySchool->isEmpty() && $canonicalSchool->count() === 1
                && $history->count() === 1
                && hash_equals($target['database'], (string) $canonicalSchool->sole()->database_name)
                && (int) $history->sole()->school_id === (int) $canonicalSchool->sole()->id;
        }

        $sequence = (int) DB::connection('mysql')->table('school_code_sequences')
            ->where('prefix', 'MMBOWEN')->value('next_number');
        if (!$recorded && $eligible && $sequence >= 2) return 'eligible';
        if ($recorded && $complete && $sequence >= 4) return 'complete';
        return 'unexpected';
    }

    private function baseSchemaComplete(): bool
    {
        if (!Schema::connection('mysql')->hasTable('school_code_history')
            || !Schema::connection('mysql')->hasTable('school_code_sequences')
            || !Schema::connection('mysql')->hasColumns('school_code_history', ['school_id', 'legacy_code', 'canonical_code', 'change_reason', 'changed_at'])
            || !Schema::connection('mysql')->hasColumns('school_code_sequences', ['prefix', 'next_number'])
            || !$this->hasUniqueColumn('schools', 'code')
            || !$this->hasUniqueIndex('school_code_history', 'school_code_history_legacy_unique')
            || !$this->hasUniqueIndex('school_code_history', 'school_code_history_canonical_unique')
            || !$this->hasForeignKey('school_code_history', 'school_code_history_school_id_foreign')
            || !DB::connection('mysql')->table('migrations')->where(
                'migration', '2026_09_11_000001_finalize_school_code_identity'
            )->exists()) {
            return false;
        }
        return DB::connection('mysql')->table('school_code_history')->where([
            'legacy_code' => 'SCH202615', 'canonical_code' => 'MMBOWEN01',
        ])->exists();
    }

    private function hasUniqueIndex(string $table, string $name): bool
    {
        try {
            foreach (Schema::connection('mysql')->getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name && ($index['unique'] ?? false) === true) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function hasUniqueColumn(string $table, string $column): bool
    {
        try {
            foreach (Schema::connection('mysql')->getIndexes($table) as $index) {
                if (($index['unique'] ?? false) === true
                    && array_values($index['columns'] ?? []) === [$column]) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        try {
            foreach (Schema::connection('mysql')->getForeignKeys($table) as $foreignKey) {
                if (($foreignKey['name'] ?? null) === $name
                    || (array_values($foreignKey['columns'] ?? []) === ['school_id']
                        && ($foreignKey['foreign_table'] ?? null) === 'schools'
                        && array_values($foreignKey['foreign_columns'] ?? []) === ['id'])) {
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
