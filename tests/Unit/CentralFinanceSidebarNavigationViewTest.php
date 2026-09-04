<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CentralFinanceSidebarNavigationViewTest extends TestCase
{
    public function test_central_finance_navigation_uses_role_gated_workspace_groups_and_existing_routes(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/sidebar.blade.php');

        foreach ([
            'central-finance-menu',
            '<details class="central-finance-sidebar-group">',
            "{{ __('财务管理') }}",
            "{{ __('支出管理') }}",
            "route('central-finance.dashboard')",
            "route('central-finance.student-collection.index')",
            "route('central-finance.receivables')",
            "route('central-finance.other-income.index')",
            "route('central-finance.expenses.index')",
            "route('central-finance.reimbursements.index')",
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
        $this->assertStringContainsString('CentralFinanceSchoolStaffIdentityService::SESSION_KEY', $view);
        $this->assertStringContainsString('resolveTrustedSession($centralStaffContext)', $view);
        $this->assertStringContainsString("contains('id', (int) (\$centralStaffContext['school_id'] ?? 0))", $view);
        $this->assertStringContainsString('$centralFinanceActor = Auth::user();', $view);
        $this->assertStringContainsString("{{ __('Student Collection') }}", $view);
        $this->assertStringContainsString("{{ __('Receivables') }}", $view);
        $this->assertStringContainsString('$centralIsSchoolStaffPrincipal', $view);
        $this->assertStringContainsString('$centralIsHeadFinance', $view);
        $this->assertStringContainsString('$centralCanMoveFunds', $view);
        $this->assertStringContainsString('$centralCanViewSchoolReports', $view);
        $this->assertStringContainsString('School pages retain the tenant Auth user.', $view);
        $this->assertStringContainsString('request()->routeIs(\'central-finance.student-collection.*\'', $view);
        $this->assertStringContainsString('@if($centralIsHeadFinance)', $view);
        $this->assertStringContainsString('@if($centralIsHeadFinance || $centralCanMoveFunds)', $view);
        $this->assertStringContainsString("route('central-finance.accounts.statements')", $view);

        $workspace = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/central-finance/workspace.blade.php');
        $this->assertStringContainsString('@if($school && $canAccessAllSchools)', $workspace);
    }
}
