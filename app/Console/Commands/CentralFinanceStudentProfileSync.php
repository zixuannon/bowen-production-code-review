<?php

namespace App\Console\Commands;

use App\Services\CentralFinanceGateASchoolScope;
use App\Services\CentralFinanceStudentProfileBackfillService;
use App\Services\CentralFinanceStudentProfileSyncService;
use Illuminate\Console\Command;

final class CentralFinanceStudentProfileSync extends Command
{
    protected $signature = 'central-finance:student-profile-sync {--school-code=* : Fixed Gate A School code only} {--backfill : Generate missing tenant UUIDs} {--execute : Perform the explicit sync/backfill write}';
    protected $description = 'Gate A trusted student UUID/profile sync. Defaults to read-only reconciliation.';

    public function handle(CentralFinanceGateASchoolScope $scope, CentralFinanceStudentProfileBackfillService $backfill, CentralFinanceStudentProfileSyncService $sync): int
    {
        try { $schools = $scope->resolve($this->option('school-code')); }
        catch (\Throwable $exception) { $this->error($exception->getMessage()); return self::FAILURE; }
        if ($this->option('backfill') && !$this->option('execute')) { $this->error('--backfill requires --execute.'); return self::FAILURE; }
        if ($this->option('execute')) {
            foreach ($backfill->synchronizeSchools($schools, (bool) $this->option('backfill')) as $id => $result) $this->line($id.': '.json_encode($result));
            return self::SUCCESS;
        }
        foreach ($schools as $school) {
            $report = $sync->reconcileSchool($school);
            $this->line(sprintf('%s: source=%d central=%d missing=%d stale=%d mismatched=%d', $school->code, $report['source_count'], $report['central_count'], count($report['missing_in_central']), count($report['stale_in_central']), count($report['mismatched'])));
        }
        return self::SUCCESS;
    }
}
