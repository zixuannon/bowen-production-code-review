<?php

namespace Tests\Feature;

use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

class PdfDependencyCompatibilityTest extends TestCase
{
    public function test_patched_dompdf_stack_renders_a_pdf(): void
    {
        $output = Pdf::loadHTML('<html><body><h1>eSchool PDF security smoke</h1></body></html>')->output();

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(500, strlen($output));
    }
}
