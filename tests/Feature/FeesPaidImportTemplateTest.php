<?php

namespace Tests\Feature;

use App\Exports\FeesPaidSampleExport;
use App\Support\FeesPaidImportTemplate;
use Tests\TestCase;

class FeesPaidImportTemplateTest extends TestCase
{
    public function test_paid_fee_template_uses_business_fields_and_never_exposes_database_ids(): void
    {
        $headings = (new FeesPaidSampleExport())->headings();

        $this->assertSame(FeesPaidImportTemplate::HEADINGS, $headings);
        $this->assertContains('Date', $headings);
        $this->assertContains('Student Admission No', $headings);
        $this->assertContains('Fee Structure Name', $headings);
        $this->assertContains('Receipt / Reference No', $headings);
        $this->assertContains('Payment Method', $headings);
        $this->assertContains('Fund Account Name', $headings);
        $this->assertFalse(collect($headings)->contains(fn (string $heading) => str_contains(strtolower($heading), 'database id')));
    }
}
