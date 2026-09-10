<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CentralFinanceOperatingSchoolContextEntryTest extends TestCase
{
    public function test_head_finance_login_enters_the_canonical_central_workspace(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Auth/LoginController.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("redirect()->route('central-finance.dashboard')", $source);
        $this->assertStringContainsString("\$user->school_id === null && \$user->hasRole('Head Finance')", $source);
        $this->assertLessThan(
            strpos($source, "redirect()->route('group-finance.index')"),
            strpos($source, "redirect()->route('central-finance.dashboard')")
        );
    }

    public function test_handover_without_context_guides_to_the_canonical_selector(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/CentralFinanceCollectionHandoverController.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("\$this->workspace->currentSchool(\$actor)", $source);
        $this->assertStringContainsString("route('central-finance.dashboard'", $source);
        $this->assertStringContainsString("'return_to' => route('central-finance.collection-handovers.index')", $source);
        $this->assertStringNotContainsString("request('school_id')", $source);
    }

    public function test_selector_preserves_a_safe_central_return_path(): void
    {
        $source = file_get_contents(base_path('resources/views/central-finance/workspace.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("request('return_to', url()->full())", $source);
        $this->assertStringContainsString("route('central-finance.school.enter')", $source);
    }

    public function test_context_routes_remain_server_side_guarded(): void
    {
        $route = app('router')->getRoutes()->getByName('central-finance.school.enter');
        $handover = app('router')->getRoutes()->getByName('central-finance.collection-handovers.index');

        $this->assertNotNull($route);
        $this->assertNotNull($handover);
        $this->assertContains('auth', $route->middleware());
        $this->assertContains('centralFinance', $handover->middleware());
    }
}
