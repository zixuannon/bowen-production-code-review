<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Central-only, exact-path extension; never opens or migrates a tenant database. */
final class MigrateFinanceClassificationActors extends Command
{
    public const MIGRATION = '2026_10_05_000001_add_tenant_actor_to_finance_data_classifications';

    private const TABLES = [
        'central_finance_data_classifications' => 'classified_by',
        'central_finance_data_classification_audits' => 'actor_id',
    ];

    protected $signature = 'finance:migrate-classification-actors
        {--execute : Apply only the exact Central classification actor extension}';
    protected $description = 'Read-only preflight or exact migration for tenant classification actor attribution';

    public function handle(): int
    {
        try {
            $state = $this->state();
            $this->line("central_classification_actors={$state}");
            if ($state === 'unexpected') {
                return $this->reject('Classification actor schema/history mismatch; zero migration was run.');
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
                return $this->reject('Classification actor migration did not verify; preserve state for a forward fix.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->reject('Classification actor preflight failed: '.get_class($exception));
        }
    }

    private function state(): string
    {
        $schema = Schema::connection('mysql');
        if (!$schema->hasTable('migrations')
            || DB::connection('mysql')->table('migrations')->where('migration', MigrateCentralFinanceDataIsolation::MIGRATION)->count() !== 1) {
            return 'unexpected';
        }
        // Reuse the prior runner's complete base column/index/FK verification,
        // with no --execute, so a damaged prerequisite can never trigger DDL.
        if ($this->callSilent('finance:migrate-data-isolation') !== self::SUCCESS) {
            return 'unexpected';
        }
        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::MIGRATION)->count();
        if ($recorded > 1) {
            return 'unexpected';
        }
        foreach (self::TABLES as $name => $legacyActor) {
            $columns = collect($schema->getColumns($name))->keyBy('name');
            $newColumns = ['actor_scope' => 'string', 'actor_school_id' => 'integer', 'actor_tenant_user_id' => 'integer'];
            if ($legacyActor === 'actor_id') {
                $newColumns['action'] = 'string';
            }
            if ((bool) ($columns[$legacyActor]['nullable'] ?? false) !== ($recorded === 1)) {
                return 'unexpected';
            }
            foreach ($newColumns as $column => $kind) {
                if ($recorded === 0) {
                    if ($columns->has($column)) {
                        return 'unexpected';
                    }
                    continue;
                }
                $definition = $columns->get($column);
                $types = $kind === 'string' ? ['varchar', 'text'] : ['bigint', 'integer'];
                if (!$definition || !($definition['nullable'] ?? false)
                    || !in_array($definition['type_name'] ?? '', $types, true)) {
                    return 'unexpected';
                }
                if (DB::connection('mysql')->getDriverName() === 'mysql') {
                    $expected = $kind === 'integer' ? 'bigint unsigned'
                        : ($column === 'action' ? 'varchar(64)' : 'varchar(20)');
                    // MariaDB may include the legacy integer display width.
                    $actual = preg_replace('/bigint\(\d+\)/', 'bigint', strtolower($definition['type'] ?? ''));
                    if ($actual !== $expected || ($definition['default'] ?? null) !== null) {
                        return 'unexpected';
                    }
                }
            }
        }

        return $recorded === 1 ? 'complete' : 'eligible';
    }

    private function reject(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
