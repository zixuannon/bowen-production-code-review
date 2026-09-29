<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path runner; tenant targets come only from the live trusted School registry. */
final class MigrateFinanceCollectionV2 extends Command
{
    public const CENTRAL_MIGRATIONS = [
        '2026_09_29_000001_add_central_finance_layer3_receivable_promotions',
        '2026_09_29_000002_add_finance_collection_v2_documents',
    ];
    public const TENANT_MIGRATION = '2026_09_29_000002_add_student_fee_quantity_snapshots';

    protected $signature = 'finance:migrate-collection-v2
        {--execute : Apply only the exact reviewed Collection V2 Central and trusted-tenant migrations}';

    protected $description = 'Preflight or apply the Finance Collection V2 schema using the canonical School registry';

    public function handle(): int
    {
        $original = config('database.connections.school.database');
        try {
            $tenants = $this->trustedTenants();
            if ($tenants === []) return $this->fail('No active canonical tenant is available for Collection V2 migration.');
            $central = $this->centralState();
            $this->line("central={$central}");
            if ($central === 'unexpected') return $this->fail('Central Collection V2 migration/schema state is unsafe; no migration was run.');
            foreach ($tenants as $code => $database) {
                if (!$this->connect($database)) return self::FAILURE;
                $state = $this->tenantState();
                $this->line("[{$code}] {$state}");
                if ($state === 'unexpected') return $this->fail("[{$code}] tenant Collection V2 state is unsafe; no migration was run.");
            }
            if (!$this->option('execute')) return self::SUCCESS;
            if (app()->environment('production') && !MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path())) {
                return $this->fail('Production execution is allowed only from an immutable release path.');
            }
            if ($central === 'eligible' && !$this->migrate('mysql', database_path('migrations'), self::CENTRAL_MIGRATIONS)) return self::FAILURE;
            if ($this->centralState() !== 'complete') return $this->fail('Central Collection V2 migration did not verify.');
            foreach ($tenants as $code => $database) {
                if (!$this->connect($database)) return self::FAILURE;
                if ($this->tenantState() === 'eligible' && !$this->migrate('school', database_path('migrations/schools'), [self::TENANT_MIGRATION])) return self::FAILURE;
                if ($this->tenantState() !== 'complete') return $this->fail("[{$code}] tenant Collection V2 migration did not verify.");
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->fail('Collection V2 migration preflight failed: '.get_class($exception));
        } finally {
            Config::set('database.connections.school.database', $original);
            DB::purge('school');
        }
    }

    /** @return array<string,string> */
    private function trustedTenants(): array
    {
        $query = School::on('mysql')->whereNotNull('code')->whereNotNull('database_name');
        if (Schema::connection('mysql')->hasColumn('schools', 'status')) $query->whereIn('status', [1, '1', 'active', 'ACTIVE']);
        $result = [];
        foreach ($query->orderBy('id')->get(['id', 'code', 'database_name']) as $school) {
            $code = strtoupper(trim((string) $school->getRawOriginal('code')));
            $database = (string) $school->getRawOriginal('database_name');
            if (!preg_match('/^[A-Z][A-Z0-9_-]{2,80}$/', $code) || !preg_match('/^[A-Za-z0-9_]+$/', $database) || isset($result[$code])) {
                throw new \RuntimeException('Canonical School registry contains an unsafe or duplicate tenant identity.');
            }
            $result[$code] = $database;
        }
        return $result;
    }

    private function centralState(): string
    {
        $schema = Schema::connection('mysql'); $db = DB::connection('mysql');
        foreach (['migrations','central_finance_receivables','central_finance_payments','central_finance_pending_collections','central_finance_ledger_entries','central_finance_document_audits'] as $table) if (!$schema->hasTable($table)) return 'unexpected';
        $recorded = $db->table('migrations')->whereIn('migration', self::CENTRAL_MIGRATIONS)->pluck('migration')->all();
        $complete = count($recorded) === count(self::CENTRAL_MIGRATIONS)
            && $schema->hasColumns('central_finance_receivables', ['unit_price_snapshot','quantity_snapshot'])
            && $schema->hasColumns('central_finance_payments', ['receivable_id'])
            && $schema->hasTable('central_finance_payment_allocations')
            && $schema->hasTable('central_finance_pending_collection_allocations')
            && $schema->hasTable('central_finance_unidentified_deposits')
            && $schema->hasTable('central_finance_unidentified_deposit_allocations')
            && $schema->hasTable('central_finance_promotion_fee_allocations');
        if ($complete) return 'complete';
        return $recorded === [] ? 'eligible' : 'unexpected';
    }

    private function tenantState(): string
    {
        $schema = Schema::connection('school');
        if (!$schema->hasTable('migrations') || !$schema->hasTable('fees_class_types') || !$schema->hasTable('student_fee_assignment_items')) return 'unexpected';
        $recorded = DB::connection('school')->table('migrations')->where('migration', self::TENANT_MIGRATION)->count();
        $complete = $recorded === 1 && $schema->hasColumn('fees_class_types', 'quantity_enabled') && $schema->hasColumns('student_fee_assignment_items', ['unit_price_snapshot','quantity_snapshot']);
        if ($complete) return 'complete';
        return $recorded === 0 && !$schema->hasColumn('fees_class_types', 'quantity_enabled') ? 'eligible' : 'unexpected';
    }

    private function connect(string $database): bool
    {
        Config::set('database.connections.school.database', $database); DB::purge('school');
        try { return DB::connection('school')->getDatabaseName() === $database; }
        catch (\Throwable) { $this->error('Trusted tenant connection failed.'); return false; }
    }

    /** @param list<string> $migrations */
    private function migrate(string $connection, string $directory, array $migrations): bool
    {
        foreach ($migrations as $migration) {
            $exit = Artisan::call('migrate', ['--database' => $connection, '--path' => $directory.'/'.$migration.'.php', '--realpath' => true, '--force' => true]);
            $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS) { $this->error("Targeted migration failed: {$migration}"); return false; }
        }
        return true;
    }

    private function fail(string $message): int { $this->error($message); return self::FAILURE; }
}
