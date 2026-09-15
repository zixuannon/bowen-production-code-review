<?php

namespace App\Console\Commands;

use App\Support\ProductionRestoreGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** This command never creates a database or imports SQL. */
final class IncidentRestorePreflight extends Command
{
    protected $signature = 'incident:restore-preflight
        {sql : Absolute path to a reviewed plain SQL or .gz dump}
        {--target= : Already-created disposable database name}';

    protected $description = 'Read-only fail-closed gate before a separately approved disposable restore';

    public function handle(ProductionRestoreGuard $guard): int
    {
        try {
            $sql = (string) $this->argument('sql');
            $target = (string) $this->option('target');
            if (!str_starts_with($sql, '/') || realpath($sql) !== $sql) {
                throw new \RuntimeException('Restore SQL path must be an absolute, resolved path.');
            }

            $central = (string) config('database.connections.mysql.database');
            $tenants = DB::connection('mysql')->table('schools')->whereNotNull('database_name')
                ->pluck('database_name')->map(static fn ($name) => (string) $name)->all();
            $existing = DB::connection('mysql')->table('information_schema.schemata')
                ->pluck('schema_name')->map(static fn ($name) => (string) $name)->all();
            $protected = array_values(array_unique(array_merge([$central], $tenants)));

            $guard->assertTarget($target, $central, $tenants, $existing);
            $guard->assertEmptyTarget((int) DB::connection('mysql')->table('information_schema.tables')
                ->where('table_schema', $target)->count());
            $guard->assertSqlFile($sql, $protected);
            $this->info("Read-only restore preflight PASS: target={$target}; SQL contains no database switching/direct protected-DB reference.");
            $this->warn('No import was executed. A disposable import and any forward-fix require separate human approval.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Restore preflight BLOCKED: '.$exception->getMessage().'; zero schema/data write.');
            return self::FAILURE;
        }
    }
}
