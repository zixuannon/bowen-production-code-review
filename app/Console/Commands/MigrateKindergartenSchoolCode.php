<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, central-only runner for the registered Kindergarten identity transition. */
final class MigrateKindergartenSchoolCode extends Command
{
    public const CENTRAL_MIGRATION = '2026_09_18_000003_canonicalize_kindergarten_school_code';
    private const SCHOOL_ID = 20;
    private const LEGACY_CODE = 'SCH202620';
    private const CANONICAL_CODE = 'MMBOWEN04';
    private const DATABASE = 'eschool_saas_20_';

    protected $signature = 'centralization:migrate-kindergarten-school-code
        {--execute : Apply only the exact audited Kindergarten School Code migration}';
    protected $description = 'Preflight or apply the audited Kindergarten canonical School Code mapping';

    public function handle(): int
    {
        try {
            $state = $this->state();
            $this->line("kindergarten_school_code_mapping={$state}");
            if ($state === 'unexpected') return $this->fail('Kindergarten School Code registry, history, or migration state mismatch; zero write.');
            if (!$this->option('execute') || $state === 'complete') return self::SUCCESS;

            $exit = Artisan::call('migrate', [
                '--database' => 'mysql', '--path' => database_path('migrations/'.self::CENTRAL_MIGRATION.'.php'),
                '--realpath' => true, '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            return $exit === self::SUCCESS && $this->state() === 'complete'
                ? self::SUCCESS : $this->fail('Kindergarten canonical School Code migration did not verify.');
        } catch (\Throwable $exception) {
            return $this->fail('Kindergarten School Code preflight failed: '.get_class($exception));
        }
    }

    private function state(): string
    {
        if (!$this->baseSchemaComplete()) return 'unexpected';
        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::CENTRAL_MIGRATION)->exists();
        $legacy = DB::connection('mysql')->table('schools')->where(['id' => self::SCHOOL_ID, 'code' => self::LEGACY_CODE])
            ->whereNull('deleted_at')->get(['id', 'database_name']);
        $canonical = DB::connection('mysql')->table('schools')->where(['id' => self::SCHOOL_ID, 'code' => self::CANONICAL_CODE])
            ->whereNull('deleted_at')->get(['id', 'database_name']);
        $history = DB::connection('mysql')->table('school_code_history')->where([
            'school_id' => self::SCHOOL_ID, 'legacy_code' => self::LEGACY_CODE, 'canonical_code' => self::CANONICAL_CODE,
        ])->get();
        $sequence = (int) DB::connection('mysql')->table('school_code_sequences')->where('prefix', 'MMBOWEN')->value('next_number');
        $matchesLegacy = $legacy->count() === 1 && hash_equals(self::DATABASE, (string) $legacy->sole()->database_name);
        $matchesComplete = $canonical->count() === 1 && $history->count() === 1
            && hash_equals(self::DATABASE, (string) $canonical->sole()->database_name) && $sequence >= 5;
        if (!$recorded && $matchesLegacy && $history->isEmpty() && !DB::connection('mysql')->table('schools')->where('code', self::CANONICAL_CODE)->exists() && $sequence >= 4) return 'eligible';
        return $recorded && $legacy->isEmpty() && $matchesComplete ? 'complete' : 'unexpected';
    }

    private function baseSchemaComplete(): bool
    {
        if (!Schema::connection('mysql')->hasTable('school_code_history') || !Schema::connection('mysql')->hasTable('school_code_sequences')
            || !Schema::connection('mysql')->hasColumns('school_code_history', ['school_id', 'legacy_code', 'canonical_code', 'change_reason', 'changed_at'])
            || !Schema::connection('mysql')->hasColumns('school_code_sequences', ['prefix', 'next_number'])) return false;
        return DB::connection('mysql')->table('migrations')->where('migration', '2026_09_11_000001_finalize_school_code_identity')->exists()
            && DB::connection('mysql')->table('school_code_history')->where(['legacy_code' => 'SCH202615', 'canonical_code' => 'MMBOWEN01'])->exists();
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
