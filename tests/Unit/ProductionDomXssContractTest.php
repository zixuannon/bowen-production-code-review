<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionDomXssContractTest extends TestCase
{
    public function test_school_gallery_caption_uses_text_content(): void
    {
        $source = file_get_contents(__DIR__.'/../../resources/views/layouts/school/footer_js.blade.php');

        $this->assertStringContainsString("captionText.textContent = caption || ''", $source);
        $this->assertStringNotContainsString('captionText.innerHTML = caption', $source);
    }

    public function test_bootstrap_table_plain_text_fields_do_not_use_html_sinks(): void
    {
        $source = file_get_contents(__DIR__.'/../../public/assets/js/custom/bootstrap-table/actionEvents.js');

        $this->assertStringContainsString("$('.edit-question').text(row.question || '')", $source);
        $this->assertStringContainsString("$('.plan-name').text(row.subscription.name || '')", $source);
        $this->assertStringNotContainsString("$('.edit-question').html(row.question)", $source);
        $this->assertStringNotContainsString("$('.plan-name').html(row.subscription.name)", $source);
    }
}
