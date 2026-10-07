<?php

namespace App\Console\Commands;

use App\Services\ProductionMigrationGuard;
use App\Services\UnidentifiedDepositP0Migration;
use App\Services\UnidentifiedDepositP0ReleaseGate;
use App\Services\UnidentifiedDepositP0WriteGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class MigrateUnidentifiedDepositP0 extends Command
{
    protected $signature = 'finance:unidentified-deposit-p0-migrate {--preflight : Read-only exact Central preflight (the default)} {--execute : Apply only the pinned Central P0 migration}';
    protected $description = 'Verify or apply the exact Central Unidentified Deposit P0 migration';

    public function handle(UnidentifiedDepositP0Migration $migration): int
    {
        try {
            if ($this->option('preflight') && $this->option('execute')) throw new RuntimeException('Choose preflight or execute, not both.');
            $work = fn (): int => $this->runMigration($migration);
            return $this->option('execute')
                ? app(UnidentifiedDepositP0WriteGate::class)->withLock(fn () => app(UnidentifiedDepositP0WriteGate::class)->withClosedFence($work))
                : $work();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function runMigration(UnidentifiedDepositP0Migration $migration): int
    {
        $result = $migration->inspect();
        $this->line('central='.$result['state'].'; historical_identities='.count($result['identities']).'; conflicts='.json_encode($result['conflicts'], JSON_THROW_ON_ERROR));
        if (!in_array($result['state'], ['eligible', 'complete'], true)) throw new RuntimeException('P0 schema/history/data is not ready or partial; no migration was run. A reviewed forward fix is required for partial state.');
        if (!$this->option('execute')) return self::SUCCESS;

        // The production gate requires deployment-owned attestation and the
        // database writer fence; no env flag or --force bypass exists.
        app(UnidentifiedDepositP0ReleaseGate::class)->assertReadyForMigration($result['state'] === 'complete');
        if ($result['state'] === 'complete') {
            $migration->assertMigrationPreserved();
            $this->info('Complete: verified no-op; no migration or history write.');
            return self::SUCCESS;
        }
        app(ProductionMigrationGuard::class)->assertAllowed('migrate', 'finance:unidentified-deposit-p0-migrate', [$migration->migrationPath()], true, app()->environment('production'), 'mysql');
        $before = $migration->financialSnapshot();
        $history = $migration->historySnapshot();
        $again = $migration->inspect();
        if ($again['state'] !== 'eligible' || $before !== $migration->financialSnapshot()) throw new RuntimeException('Central data/schema changed before execution.');
        app(UnidentifiedDepositP0ReleaseGate::class)->assertReadyForMigration();
        $exit = Artisan::call('migrate', ['--database' => 'mysql', '--path' => $migration->migrationPath(), '--realpath' => true, '--force' => true]);
        $this->output->write(Artisan::output());
        if ($before !== $migration->financialSnapshot() || $history !== $migration->historySnapshot()) {
            throw new RuntimeException('Financial/history preservation verification failed. Keep writers blocked; use a reviewed forward fix.');
        }
        if ($exit !== self::SUCCESS || $migration->inspect()['state'] !== 'complete') throw new RuntimeException('P0 did not verify complete. Keep writers blocked; do not retry partial DDL.');
        $migration->recordMigrationSuccess($before, $history);
        $this->info('Complete: exact migration/history and unchanged financial rows verified.');
        return self::SUCCESS;
    }
}
