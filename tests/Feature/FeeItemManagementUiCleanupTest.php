<?php

namespace Tests\Feature;

use Tests\TestCase;

class FeeItemManagementUiCleanupTest extends TestCase
{
    public function test_create_and_edit_views_use_the_same_compact_fee_item_card_contract(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['index.blade.php', 'edit.blade.php'] as $view) {
            $contents = (string) file_get_contents($root.'/resources/views/Income/'.$view);

            $this->assertStringContainsString('fee-item-card', $contents);
            $this->assertStringContainsString('fee-quantity-control', $contents);
            $this->assertStringContainsString("__('Allow Multiple Quantity')", $contents);
            $this->assertStringContainsString("__('Students may select more than one unit during Fee Setup.')", $contents);
            $this->assertStringContainsString('fee-mmk-detail', $contents);
            $this->assertStringContainsString('fee-exchange-detail', $contents);
        }
    }

    public function test_currency_presentation_is_conditional_without_changing_quantity_field_names(): void
    {
        $root = dirname(__DIR__, 2);
        $create = (string) file_get_contents($root.'/resources/views/Income/index.blade.php');
        $styles = (string) file_get_contents($root.'/resources/views/Income/partials/fee-item-card-styles.blade.php');

        $this->assertStringContainsString('syncCurrencyPresentation', $create);
        $this->assertStringContainsString('.fee-item-card.is-mmk .fee-exchange-detail', $styles);
        $this->assertStringContainsString('compulsory_fees_type[][quantity_enabled]', $create);
        $this->assertStringContainsString('optional_fees_type[][quantity_enabled]', $create);
        $this->assertStringContainsString("__('Add Fee Item')", $create);
    }
}
