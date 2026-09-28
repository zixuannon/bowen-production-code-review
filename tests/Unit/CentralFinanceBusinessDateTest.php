<?php

namespace Tests\Unit;

use App\Support\CentralFinanceBusinessDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class CentralFinanceBusinessDateTest extends TestCase
{
    public function test_it_accepts_only_real_non_future_yangon_calendar_dates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 12:00:00', 'Asia/Yangon'));
        try {
            $this->assertSame('2026-05-31', CentralFinanceBusinessDate::parse('2026-05-31')->toDateString());
            foreach (['2026-02-30', '05/31/2026', '2026-09-29', '2026-05-31 10:00'] as $invalid) {
                try {
                    CentralFinanceBusinessDate::parse($invalid);
                    $this->fail($invalid.' must be rejected.');
                } catch (InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
