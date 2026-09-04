<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BankAccountListCashFlowViewTest extends TestCase
{
    public function test_list_uses_money_in_and_money_out_labels_with_directional_chinese_translations(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/bank-account/index.blade.php');
        $translations = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/resources/lang/zh-cn.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertStringContainsString('data-field="money_in_total"', $view);
        $this->assertStringContainsString("{{ __('Money In') }}", $view);
        $this->assertStringContainsString('data-field="money_out_total"', $view);
        $this->assertStringContainsString("{{ __('Money Out') }}", $view);
        $this->assertSame('资金流入', $translations['Money In']);
        $this->assertSame('资金流出', $translations['Money Out']);
    }
}
