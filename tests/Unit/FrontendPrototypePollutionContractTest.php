<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FrontendPrototypePollutionContractTest extends TestCase
{
    public function test_axios_is_on_the_patched_release_line(): void
    {
        $lock = json_decode(file_get_contents(__DIR__.'/../../package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $version = $lock['packages']['node_modules/axios']['version'] ?? null;

        $this->assertNotNull($version);
        $this->assertTrue(version_compare($version, '1.20.0', '>='), 'Axios must include the prototype-pollution fixes.');
    }

    public function test_subject_loader_does_not_merge_an_untrusted_prototype(): void
    {
        $source = file_get_contents(__DIR__.'/../../public/assets/js/custom/function.js');
        $start = strpos($source, 'function loadSubjectsByClass');
        $handler = substr($source, $start);

        $this->assertStringNotContainsString('Object.assign({}, defaults, options)', $handler);
        $this->assertStringContainsString('Object.getPrototypeOf(options) === Object.prototype', $handler);
        $this->assertStringContainsString('Object.prototype.hasOwnProperty.call(safeOptions, key)', $handler);
    }

    public function test_fees_chart_skips_dashboards_without_its_target_element(): void
    {
        $source = file_get_contents(__DIR__.'/../../public/assets/js/custom/function.js');
        $start = strpos($source, 'function fees_details');
        $handler = substr($source, $start, strpos($source, 'function class_attendance', $start) - $start);

        $this->assertStringContainsString('const chartElement = document.querySelector("#fees_details_chart")', $handler);
        $this->assertStringContainsString('if (!chartElement)', $handler);
        $this->assertStringContainsString('new ApexCharts(chartElement, options)', $handler);
    }
}
