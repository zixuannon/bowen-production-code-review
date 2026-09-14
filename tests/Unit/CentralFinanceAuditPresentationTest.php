<?php

namespace Tests\Unit;

use App\Services\CentralFinanceLedgerPresentationService;
use Tests\TestCase;

final class CentralFinanceAuditPresentationTest extends TestCase
{
    public function test_it_builds_human_readable_field_level_rows_without_removing_raw_values(): void
    {
        $rows = (new CentralFinanceLedgerPresentationService())->auditDiff(
            ['account_name' => 'Old', 'is_active' => true, 'meta' => ['a' => 1]],
            ['account_name' => 'New', 'is_active' => false, 'status' => 'inactive'],
        );

        $this->assertSame(['Account Name', 'Is Active', 'Meta', 'Status'], array_column($rows, 'field'));
        $this->assertSame('Old', $rows[0]['before']);
        $this->assertSame('New', $rows[0]['after']);
        $this->assertSame('{"a":1}', $rows[2]['before']);
        $this->assertSame('—', $rows[3]['before']);
    }
}
