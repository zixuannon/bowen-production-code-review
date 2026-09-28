<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, central-only runner for Phase 2A append-only payment corrections. */
final class MigrateCentralFinancePaymentCorrections extends Command
{
    public const MIGRATION = '2026_09_28_000001_add_payment_correction_fields_and_reversals';

    private const PRODUCTION_DATABASE = 'sql_43_160_241_126';

    protected $signature = 'finance:migrate-payment-corrections
        {--execute : Apply only the exact additive payment-correction migration}';

    protected $description = 'Preflight or apply the Phase 2A Central Finance payment-correction schema';

    public function handle(): int
    {
        try {
            $state = $this->state();
            $this->line("central_finance_payment_corrections={$state}");
            if ($state === 'unexpected') {
                return $this->reject('Payment-correction migration/schema mismatch; zero migration was run.');
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
                return $this->reject('Payment-correction migration did not verify.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->reject('Payment-correction migration preflight failed: '.get_class($exception));
        }
    }

    private function state(): string
    {
        $db = DB::connection('mysql');
        if (app()->environment('production') && ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== self::PRODUCTION_DATABASE)) {
            return 'unexpected';
        }

        $schema = Schema::connection('mysql');
        foreach ([
            'migrations' => ['id', 'migration', 'batch'],
            'schools' => ['id'],
            'users' => ['id'],
            'central_finance_payments' => ['id', 'school_id', 'amount', 'currency'],
            'central_finance_receipts' => ['id'],
            'central_finance_fund_accounts' => ['id'],
            'central_finance_ledger_entries' => ['id'],
            'central_finance_payment_refunds' => ['id', 'payment_id', 'amount', 'reason'],
        ] as $table => $columns) {
            if (!$schema->hasTable($table) || !$schema->hasColumns($table, $columns)) {
                return 'unexpected';
            }
        }

        $path = database_path('migrations/'.self::MIGRATION.'.php');
        if (!is_file($path) || is_link($path)) {
            return 'unexpected';
        }

        $migrationCount = $db->table('migrations')->where('migration', self::MIGRATION)->count();
        if ($migrationCount > 1) {
            return 'unexpected';
        }

        $refundColumnsPresent = $schema->hasColumn('central_finance_payment_refunds', 'refund_method')
            || $schema->hasColumn('central_finance_payment_refunds', 'effective_date');
        $reversalTablePresent = $schema->hasTable('central_finance_payment_reversals');
        if ($migrationCount === 0 && !$refundColumnsPresent && !$reversalTablePresent) {
            return 'eligible';
        }

        if ($migrationCount !== 1 || !$refundColumnsPresent || !$reversalTablePresent || !$this->schemaComplete()) {
            return 'unexpected';
        }

        return 'complete';
    }

    private function schemaComplete(): bool
    {
        $schema = Schema::connection('mysql');
        if (!$schema->hasColumns('central_finance_payment_refunds', ['refund_method', 'effective_date'])
            || !$schema->hasColumns('central_finance_payment_reversals', [
                'id', 'reversal_uuid', 'school_id', 'payment_id', 'original_receipt_id', 'fund_account_id',
                'idempotency_key', 'reversal_reference', 'amount', 'currency', 'reason', 'effective_date',
                'reversed_at', 'reversed_by', 'created_at', 'updated_at',
            ])) {
            return false;
        }

        return $this->hasUniqueIndex('central_finance_payment_reversals', 'central_finance_payment_reversals_reversal_uuid_unique')
            && $this->hasUniqueIndex('central_finance_payment_reversals', 'central_finance_payment_reversals_payment_id_unique')
            && $this->hasUniqueIndex('central_finance_payment_reversals', 'central_finance_payment_reversals_idempotency_key_unique')
            && $this->hasUniqueIndex('central_finance_payment_reversals', 'cfprv_school_reference_unique');
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

    private function reject(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
