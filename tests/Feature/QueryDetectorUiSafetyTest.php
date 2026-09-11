<?php

namespace Tests\Feature;

use BeyondCode\QueryDetector\Outputs\Alert;
use BeyondCode\QueryDetector\Outputs\Log;
use Tests\TestCase;

final class QueryDetectorUiSafetyTest extends TestCase
{
    public function test_query_detector_keeps_logging_without_browser_alerts(): void
    {
        $outputs = config('querydetector.output');

        $this->assertContains(Log::class, $outputs);
        $this->assertNotContains(Alert::class, $outputs);
    }

    public function test_production_debugbar_has_a_code_level_fail_safe(): void
    {
        $source = (string) file_get_contents(config_path('debugbar.php'));

        $this->assertStringContainsString("env('APP_ENV', 'production') === 'production' ? false", $source);
        $this->assertFalse((bool) config('debugbar.enabled'));
    }
}
