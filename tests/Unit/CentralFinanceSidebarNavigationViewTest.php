<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CentralFinanceSidebarNavigationViewTest extends TestCase
{
    public function test_central_finance_navigation_uses_two_collapsible_groups_and_existing_routes(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/sidebar.blade.php');

        foreach ([
            'central-finance-menu',
            'central-finance-management-menu',
            'central-finance-expense-menu',
            "route('central-finance.dashboard')",
            "route('central-finance.receivables')",
            "route('central-finance.operations', ['operation' => 'income'])",
            "route('central-finance.operations', ['operation' => 'expense'])",
            "route('central-finance.operations', ['operation' => 'reimbursement'])",
            "route('central-finance.accounts')",
            "route('central-finance.transfers')",
            "route('central-finance.handovers')",
            "route('central-finance.funding')",
            "route('central-finance.reports')",
            "route('central-finance.ledger')",
        ] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }

        $this->assertStringContainsString("hasRole('Super Admin')", $view);
        $this->assertStringNotContainsString("route('group-finance.index')", $view);
    }
}
