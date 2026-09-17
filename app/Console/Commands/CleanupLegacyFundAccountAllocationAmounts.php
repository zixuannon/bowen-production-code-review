<?php

namespace App\Console\Commands;

use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceFundAccountAllocationCleanupService;
use Illuminate\Console\Command;

/** Dry-run by default; execution is restricted to a reviewed immutable release. */
final class CleanupLegacyFundAccountAllocationAmounts extends Command
{
    protected $signature = 'finance:cleanup-fund-account-v2-1-allocations
        {--actor-id= : Existing Central Head Finance actor ID}
        {--reason= : Reviewed audit reason}
        {--execute : Clear legacy per-School allocation amounts after successful preflight}';

    protected $description = 'Preflight or clear obsolete per-School Fund Account allocation amounts';

    public function handle(CentralFinanceFundAccountAllocationCleanupService $cleanup): int
    {
        try {
            if (!$this->option('execute')) {
                $this->line(json_encode($cleanup->preflight(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT);
            $reason = trim((string) $this->option('reason'));
            if (!$actorId || $reason === '') {
                return $this->reject('--actor-id and --reason are required for execution.');
            }
            if (app()->environment('production') && !MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path())) {
                return $this->reject('Production execution is allowed only from an immutable release path.');
            }

            $actor = CentralFinanceUser::on('mysql')->findOrFail((int) $actorId);
            $this->line(json_encode($cleanup->execute($actor, $reason), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->reject('Fund Account V2.1 allocation cleanup failed closed: '.$exception->getMessage());
        }
    }

    private function reject(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
