<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExpenseValidationContractTest extends TestCase
{
    public function test_create_omits_edit_reason_while_update_requires_it(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ExpenseController.php'));
        $store = $this->method($source, 'store', 'show');
        $update = $this->method($source, 'update', 'destroy');

        $this->assertStringNotContainsString("'edit_reason' => 'required|string|max:255'", $store);
        $this->assertStringContainsString("'edit_reason' => 'required|string|max:255'", $update);
        $this->assertStringContainsString("'delete_reason' => 'required|string|max:255'", $source);
    }

    public function test_create_form_does_not_fabricate_an_edit_reason(): void
    {
        $view = file_get_contents(resource_path('views/expense/index.blade.php'));
        $create = strstr($view, '<form class="pt-3" id="create-form"', true);
        $edit = strstr($view, '<form class="pt-3 edit-form"');

        $this->assertStringNotContainsString('name="edit_reason"', $create);
        $this->assertStringContainsString('name="edit_reason"', $edit);
    }

    private function method(string $source, string $name, string $nextMethod): string
    {
        $start = strpos($source, "public function {$name}");
        $end = strpos($source, "public function {$nextMethod}", $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}
