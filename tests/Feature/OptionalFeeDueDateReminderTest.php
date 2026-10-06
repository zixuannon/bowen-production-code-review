<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class OptionalFeeDueDateReminderTest extends TestCase
{
    public function test_null_overall_fee_date_does_not_generate_a_due_date_reminder(): void
    {
        $spyFile = tempnam(sys_get_temp_dir(), 'p1a-due-reminder-');
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/p1a-reminder-probe.php'),
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3318',
            'DB_DATABASE' => 'eschool_testing',
            'DB_SCHOOL_DATABASE' => 'school_testing',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            'P1A_REMINDER_SPY_FILE' => $spyFile,
        ], null, 30);

        try {
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            $this->assertStringContainsString('Notification Sent Successfully', $process->getOutput());
            $this->assertSame('', file_get_contents($spyFile), 'The reminder sender must not be called for a Fee without an overall due date.');
            $this->assertStringNotContainsString('1970', $process->getOutput(), 'A missing date must not format as an epoch date.');
        } finally {
            @unlink($spyFile);
        }
    }
}
