<?php

namespace App\Console\Commands;

use App\Services\UnidentifiedDepositP0Migration;
use App\Services\UnidentifiedDepositP0ReleaseGate;
use App\Services\UnidentifiedDepositP0WriteGate;
use Illuminate\Console\Command;
use RuntimeException;

final class UnidentifiedDepositP0WriteWindow extends Command
{
    protected $signature = 'finance:unidentified-deposit-p0-write-gate {action : status, close or open}';
    protected $description = 'Inspect or operate the exact P0 Central migration write window';

    public function handle(UnidentifiedDepositP0WriteGate $gate): int
    {
        try {
            app(UnidentifiedDepositP0Migration::class)->assertTarget();
            $state = match ($this->argument('action')) {
                'status' => $gate->inspect(),
                'close' => $gate->enable(),
                'open' => $gate->disable(),
                default => throw new RuntimeException('Only status, close and open are supported.'),
            };
            $this->line(json_encode(['closed' => $state['closed'], 'present' => count($state['present']),
                'expected' => $state['expected_count'], 'conflicts' => $state['conflicts'],
                'queue' => app(UnidentifiedDepositP0ReleaseGate::class)->assertEmptyQueue()], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
