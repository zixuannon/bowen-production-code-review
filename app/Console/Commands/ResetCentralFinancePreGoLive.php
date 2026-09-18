<?php

namespace App\Console\Commands;

use App\Models\CentralFinanceUser;
use App\Services\CentralFinancePreGoLiveResetService;
use Illuminate\Console\Command;

/** Production remains dry-run unless all reviewed execution arguments are supplied. */
final class ResetCentralFinancePreGoLive extends Command
{
    protected $signature = 'finance:reset-pre-go-live
        {--actor-id= : Existing Central Head Finance actor ID}
        {--approval-reference= : Approved change reference}
        {--reason= : Audited business reason}
        {--execute : Delete only the reviewed Central Finance test-data allowlist}';

    protected $description = 'Preflight or execute the audited Central Finance pre-go-live fresh-start reset';

    public function handle(CentralFinancePreGoLiveResetService $reset): int
    {
        try {
            if (!$this->option('execute')) {
                $this->line(json_encode($reset->preflight(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }

            $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT);
            $reference = trim((string) $this->option('approval-reference'));
            $reason = trim((string) $this->option('reason'));
            if (!$actorId || $reference === '' || $reason === '') {
                throw new \RuntimeException('--actor-id, --approval-reference and --reason are required for execution.');
            }
            $actor = CentralFinanceUser::on('mysql')->findOrFail((int) $actorId);
            $this->line(json_encode($reset->execute($actor, $reference, $reason), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Pre-go-live reset failed closed: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
