<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class XiaobailongLifecycleQueueContractTest extends TestCase
{
    public function test_lifecycle_delivery_is_durable_retryable_and_after_commit(): void
    {
        $root = dirname(__DIR__, 2);
        $job = file_get_contents($root . '/app/Jobs/SendXiaobailongLifecycleEvent.php');
        $notifier = file_get_contents($root . '/app/Services/XiaobailongLifecycleNotifier.php');

        $this->assertStringContainsString('implements ShouldQueue', $job);
        $this->assertStringContainsString('public int $tries = 5', $job);
        $this->assertStringNotContainsString('public bool $afterCommit', $job);
        $this->assertStringContainsString('$this->afterCommit();', $job);
        $this->assertStringContainsString('return [10, 30, 120, 300]', $job);
        $this->assertStringContainsString("if (!\$response->successful())", $job);
        $this->assertStringContainsString('throw new RuntimeException', $job);
        $this->assertStringContainsString("'event_id' => \$this->payload['event_id']", $job);
        $this->assertStringContainsString("'event_type' => \$this->payload['event_type']", $job);

        $this->assertStringContainsString('SendXiaobailongLifecycleEvent::dispatch($payload)->afterCommit()', $notifier);
        $this->assertStringNotContainsString('app()->terminating', $notifier);
        $this->assertStringNotContainsString('Http::', $notifier);
    }

    public function test_event_id_is_created_before_queue_serialization_for_retry_idempotency(): void
    {
        $notifier = file_get_contents(dirname(__DIR__, 2) . '/app/Services/XiaobailongLifecycleNotifier.php');
        $this->assertGreaterThanOrEqual(2, substr_count($notifier, "'event_id' => (string) Str::uuid()"));
        $this->assertStringContainsString("'claims_version' => XiaobailongClaimsService::CLAIMS_VERSION", $notifier);
    }
}
