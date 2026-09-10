<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BootstrapTableDescriptionXssContractTest extends TestCase
{
    public function test_description_modal_does_not_build_user_controlled_html_strings(): void
    {
        $source = file_get_contents(__DIR__.'/../../public/assets/js/custom/bootstrap-table/actionEvents.js');
        $start = strpos($source, 'window.tableDescriptionEvents');
        $end = strpos($source, 'window.paryollSettingsEvents', $start);
        $handler = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString('.html(description + listStudents)', $handler);
        $this->assertStringNotContainsString("'<a href=\"' +", $handler);
        $this->assertStringContainsString(".text(row.description || '')", $handler);
        $this->assertStringContainsString("/^\\d+$/.test(diaryId)", $handler);
        $this->assertStringContainsString(".attr('href', removePath)", $handler);
    }
}
