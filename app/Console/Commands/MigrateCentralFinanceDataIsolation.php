<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, central-only runner for canonical QA/Test classification metadata. */
final class MigrateCentralFinanceDataIsolation extends Command
{
    public const MIGRATION = '2026_09_14_000003_create_central_finance_data_classifications';

    protected $signature = 'finance:migrate-data-isolation
        {--execute : Apply only the exact additive Central Finance data-isolation migration}';
    protected $description = 'Preflight or apply the Central Finance QA/Test data-isolation metadata schema';

    public function handle(): int
    {
        try {
            $state = $this->state();
            $this->line("central_finance_data_isolation={$state}");
            if ($state === 'unexpected') {
                return $this->reject('Central Finance data-isolation migration/schema mismatch; zero migration was run.');
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
                return $this->reject('Central Finance data-isolation migration did not verify.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->reject('Central Finance data-isolation preflight failed: '.get_class($exception));
        }
    }

    private function state(): string
    {
        foreach (['migrations', 'schools', 'users', 'central_finance_fund_accounts', 'central_finance_ledger_entries'] as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                return 'unexpected';
            }
        }
        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::MIGRATION)->exists();
        $classifications = Schema::connection('mysql')->hasTable('central_finance_data_classifications');
        $audits = Schema::connection('mysql')->hasTable('central_finance_data_classification_audits');
        if (!$recorded && !$classifications && !$audits) {
            return 'eligible';
        }
        if (!$recorded || !$this->schemaComplete()) {
            return 'unexpected';
        }

        return 'complete';
    }

    private function schemaComplete(): bool
    {
        $schema = Schema::connection('mysql');
        return $schema->hasColumns('central_finance_data_classifications', [
            'id', 'classification_uuid', 'school_id', 'subject_type', 'subject_id',
            'classification', 'reason', 'classified_by', 'created_at', 'updated_at',
        ]) && $schema->hasColumns('central_finance_data_classification_audits', [
            'id', 'audit_uuid', 'classification_id', 'school_id', 'subject_type', 'subject_id',
            'before_classification', 'after_classification', 'reason', 'actor_id', 'created_at',
        ]) && $this->hasUniqueIndex('central_finance_data_classifications', 'cf_data_classification_uuid_unique')
            && $this->hasUniqueIndex('central_finance_data_classifications', 'cf_data_classification_subject_unique')
            && $this->hasUniqueIndex('central_finance_data_classification_audits', 'cf_data_classification_audit_uuid_unique')
            && $this->hasForeignKey('central_finance_data_classifications', ['school_id'], 'schools')
            && $this->hasForeignKey('central_finance_data_classifications', ['classified_by'], 'users')
            && $this->hasForeignKey('central_finance_data_classification_audits', ['classification_id'], 'central_finance_data_classifications')
            && $this->hasForeignKey('central_finance_data_classification_audits', ['school_id'], 'schools')
            && $this->hasForeignKey('central_finance_data_classification_audits', ['actor_id'], 'users');
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

    /** @param list<string> $columns */
    private function hasForeignKey(string $table, array $columns, string $foreignTable): bool
    {
        try {
            foreach (Schema::connection('mysql')->getForeignKeys($table) as $foreignKey) {
                if (array_values($foreignKey['columns'] ?? []) === $columns
                    && ($foreignKey['foreign_table'] ?? null) === $foreignTable
                    && array_values($foreignKey['foreign_columns'] ?? []) === ['id']) {
                    return true;
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
