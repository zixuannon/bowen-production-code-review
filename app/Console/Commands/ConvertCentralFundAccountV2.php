<?php

namespace App\Console\Commands;

use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceFundAccountV2ConversionService;
use Illuminate\Console\Command;

/** Dry-run by default; conversion requires explicit reviewed execution. */
final class ConvertCentralFundAccountV2 extends Command
{
    protected $signature = 'finance:convert-fund-account-v2
        {--actor-id= : Existing Central Head Finance actor ID}
        {--reason= : Reviewed audit reason}
        {--execute : Convert only B-0001 and M-0001 after successful preflight}';
    protected $description = 'Preflight or convert the reviewed Bowen Fund Accounts to Group ownership';

    public function handle(CentralFinanceFundAccountV2ConversionService $conversion): int
    {
        try {
            if (!$this->option('execute')) {
                $this->line(json_encode($conversion->preflight(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT);
            if (!$actorId || trim((string) $this->option('reason')) === '') {
                return $this->reject('--actor-id and --reason are required for execution.');
            }
            if (app()->environment('production') && !MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath(base_path())) {
                return $this->reject('Production execution is allowed only from an immutable release path.');
            }
            $actor = CentralFinanceUser::on('mysql')->findOrFail((int) $actorId);
            $this->line(json_encode($conversion->execute($actor, (string) $this->option('reason')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->reject('Fund Account V2 conversion failed closed: '.$exception->getMessage());
        }
    }

    private function reject(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
