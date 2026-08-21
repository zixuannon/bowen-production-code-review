<?php

namespace App\Console\Commands;

use App\Services\CentralFinanceGateASchoolScope;
use App\Services\CentralFinanceLegacyCutoverService;
use Illuminate\Console\Command;

final class CentralFinanceLegacyCutover extends Command
{
    protected $signature = 'central-finance:legacy-cutover {--school-code=* : Trusted Gate A School code only}';
    protected $description = 'Read-only reconcile the trusted legacy Finance cutover inventory; never accepts a database name.';

    public function handle(CentralFinanceLegacyCutoverService $cutover, CentralFinanceGateASchoolScope $scope): int
    {
        $codes = $this->option('school-code');
        try { $schools = $scope->resolve($codes); }
        catch (\Throwable $exception) { $this->error($exception->getMessage()); return self::FAILURE; }
        foreach ($schools as $school) {
            $result = $cutover->reconcile($school);
            $this->line(sprintf('%s: expected=%d matched=%d missing=%d stale=%d', $school->code, $result['expected'], $result['matched'], count($result['missing']), count($result['stale'])));
        }
        return self::SUCCESS;
    }
}
