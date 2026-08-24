<?php

namespace App\Console\Commands;

use App\Services\CentralFinanceGateASchoolScope;
use App\Services\CentralFinanceReceivableSyncService;
use Illuminate\Console\Command;

/** Production-safe trusted-registry runner; it accepts school codes, never database names. */
final class CentralFinanceReceivableSync extends Command
{
    protected $signature = 'central-finance:receivable-sync {--school-code=* : Fixed active School code only} {--execute : Perform Central receivable projection writes}';
    protected $description = 'Reconcile or safely sync tenant compulsory fee assignments into Central Finance receivables.';

    public function handle(CentralFinanceGateASchoolScope $scope, CentralFinanceReceivableSyncService $sync): int
    {
        try {
            $schools = $scope->resolve($this->option('school-code'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $failed = false;
        foreach ($schools as $school) {
            try {
                if (!$this->option('execute')) {
                    $report = $sync->reconcileSchool($school);
                    $this->line(sprintf('%s: source=%d central=%d missing=%d stale=%d mismatched=%d cancelled=%d blocked_paid=%d', $school->code, $report['source_count'], $report['central_count'], count($report['missing_in_central']), count($report['stale_in_central']), count($report['mismatched']), count($report['cancelled_source']), count($report['blocked_paid'])));
                    continue;
                }
                $result = $sync->syncSchool($school);
                $this->line($school->code.': '.json_encode($result, JSON_THROW_ON_ERROR));
                $failed = $failed || $result['failed'] > 0;
            } catch (\Throwable $exception) {
                $failed = true;
                $this->error($school->code.': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
