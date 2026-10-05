<?php

namespace Tests\Unit;

use App\Models\Fee;
use PHPUnit\Framework\TestCase;

class FeeOptionalDueDateModelTest extends TestCase
{
    public function test_blank_due_date_is_stored_as_null_and_formats_as_null(): void
    {
        $fee = new Fee();
        $fee->setAttribute('due_date', '');

        $this->assertNull($fee->getAttributes()['due_date']);
        $this->assertNull($fee->due_date);
        $this->assertNull($fee->format_due_date);
    }

    public function test_null_due_date_remains_null_and_does_not_format_as_epoch(): void
    {
        $fee = new Fee();
        $fee->setAttribute('due_date', null);

        $this->assertNull($fee->getAttributes()['due_date']);
        $this->assertNull($fee->due_date);
        $this->assertNull($fee->format_due_date);
    }

    public function test_existing_due_date_still_formats_in_the_established_model_format(): void
    {
        $fee = new Fee();
        $fee->setAttribute('due_date', '2026-01-15');

        $this->assertSame('2026-01-15', $fee->getAttributes()['due_date']);
        $this->assertSame('15-01-2026', $fee->due_date);
    }
}
