<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path central migration gate for permanent Zixuan QA Run tables. */
final class MigrateCentralFinanceQaRuns extends Command
{
    public const MIGRATION = '2026_10_05_000001_create_central_finance_qa_runs';

    protected $signature = 'finance:qa-runs-migrate {--execute : Apply only the exact Central QA Run migration}';
    protected $description = 'Verify or apply the Central Finance Zixuan QA Run schema';

    public function handle(): int
    {
        if (app()->environment('production')) return $this->fail('Production is never a QA Run development or rehearsal target.');
        $url = strtolower((string) config('app.url'));
        if ($url !== '' && !str_contains($url, 'localhost') && !str_contains($url, '127.0.0.1')) {
            return $this->fail('QA Run migration is limited to a local application URL.');
        }
        $database = (string) config('database.connections.mysql.database');
        if ($database === '' || preg_match('/prod|production|staging/i', $database)) {
            return $this->fail('The configured Central database is not an eligible local QA target.');
        }
        $driver = (string) config('database.connections.mysql.driver');
        $host = strtolower((string) config('database.connections.mysql.host', ''));
        if ($driver === 'mysql' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $this->fail('The Central database host must be local for QA Run migration rehearsal.');
        }

        $state = $this->schemaState();
        $this->line("central={$state}; database={$database}");
        if ($state === 'unexpected') return $this->fail('QA Run schema/history is partial or inconsistent; no migration was run.');
        if (!$this->option('execute') || $state === 'complete') return self::SUCCESS;

        $exit = Artisan::call('migrate', [
            '--database' => 'mysql', '--path' => database_path('migrations/'.self::MIGRATION.'.php'),
            '--realpath' => true, '--force' => true,
        ]);
        $this->output->write(Artisan::output());
        return $exit === self::SUCCESS && $this->schemaState() === 'complete'
            ? self::SUCCESS
            : $this->fail('The exact Central QA Run migration did not verify after execution.');
    }

    private function schemaState(): string
    {
        $runs = Schema::connection('mysql')->hasTable('central_finance_qa_runs');
        $records = Schema::connection('mysql')->hasTable('central_finance_qa_run_records');
        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::MIGRATION)->exists();
        if (!$runs && !$records && !$recorded) return 'eligible';
        if ($runs && $records && $recorded
            && Schema::connection('mysql')->hasColumns('central_finance_qa_runs', ['run_uuid', 'school_id', 'run_number', 'status', 'created_by'])
            && Schema::connection('mysql')->hasColumns('central_finance_qa_run_records', ['qa_run_id', 'school_id', 'subject_scope', 'subject_type', 'subject_id', 'source_identity'])) return 'complete';
        return 'unexpected';
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
