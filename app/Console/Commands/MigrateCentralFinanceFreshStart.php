<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Exact Central-only additive migration runner for the pre-go-live release. */
final class MigrateCentralFinanceFreshStart extends Command
{
    private const MIGRATIONS = [
        '2026_09_18_000002_create_central_finance_pre_go_live_reset_manifests' => 'central_finance_pre_go_live_reset_manifests',
        '2026_09_18_000003_create_central_finance_content_translations' => 'central_finance_content_translations',
    ];

    private const REQUIRED_COLUMNS = [
        'central_finance_pre_go_live_reset_manifests' => ['id','reset_uuid','actor_id','approval_reference','reason','pre_reset_counts','deleted_counts','protected_hash_before','protected_hash_after','executed_at'],
        'central_finance_content_translations' => ['id','subject_type','subject_id','school_id','locale','translated_value','source_hash','status','updated_by','reason'],
    ];

    protected $signature = 'finance:migrate-fresh-start {--execute : Apply only the reviewed Fresh Start additive Central migrations}';
    protected $description = 'Read-only preflight or exact-path Fresh Start Central schema migration';

    public function handle(): int
    {
        try {
            $states = $this->states();
            foreach ($states as $migration => $state) $this->line($migration.'='.$state);
            if (!$this->option('execute')) return self::SUCCESS;
            if (app()->environment('production') && !MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path())) {
                throw new RuntimeException('Production execution requires an immutable release path.');
            }
            foreach ($states as $migration => $state) {
                if ($state === 'complete') continue;
                $exit = Artisan::call('migrate', ['--database'=>'mysql', '--path'=>database_path('migrations/'.$migration.'.php'), '--realpath'=>true, '--force'=>true]);
                $this->output->write(Artisan::output());
                if ($exit !== self::SUCCESS || $this->states()[$migration] !== 'complete') throw new RuntimeException('Exact migration failed verification: '.$migration);
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Fresh Start migration failed closed: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    /** @return array<string,string> */
    private function states(): array
    {
        $schema = Schema::connection('mysql'); $db = DB::connection('mysql'); $states = [];
        foreach (self::MIGRATIONS as $migration => $table) {
            $path = database_path('migrations/'.$migration.'.php');
            if (!is_file($path) || is_link($path)) throw new RuntimeException('Missing exact migration path: '.$migration);
            $count = $db->table('migrations')->where('migration', $migration)->count();
            if ($count > 1) throw new RuntimeException('Duplicate migration registry: '.$migration);
            $exists = $schema->hasTable($table);
            if ($count === 0 && !$exists) { $states[$migration] = 'eligible'; continue; }
            if ($count !== 1 || !$exists || !$schema->hasColumns($table, self::REQUIRED_COLUMNS[$table]) || !$this->requiredIndexesExist($table)) {
                throw new RuntimeException('Registry/schema mismatch: '.$migration);
            }
            $states[$migration] = 'complete';
        }
        return $states;
    }

    private function requiredIndexesExist(string $table): bool
    {
        $indexes = collect(Schema::connection('mysql')->getIndexes($table));
        if ($table === 'central_finance_pre_go_live_reset_manifests') {
            return $indexes->contains(fn (array $index): bool => (bool) $index['unique'] && $index['columns'] === ['reset_uuid']);
        }
        return $indexes->contains(fn (array $index): bool => (bool) $index['unique'] && $index['columns'] === ['subject_type','subject_id','school_id','locale'])
            && $indexes->contains(fn (array $index): bool => $index['columns'] === ['subject_type','school_id','status']);
    }
}
