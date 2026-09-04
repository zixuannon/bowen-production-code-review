<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SchoolFinanceFacadeContractTest extends TestCase
{
    public function test_school_staff_facade_is_presentation_only_and_reuses_central_scope_services(): void
    {
        $root = dirname(__DIR__, 2);
        $workspace = (string) file_get_contents($root.'/app/Services/CentralFinanceWorkspaceService.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceWorkspaceController.php');

        $this->assertStringContainsString('function usesSchoolFinanceFacade', $workspace);
        $this->assertStringContainsString('return $this->isSchoolStaffPrincipal($actor);', $workspace);
        $this->assertStringContainsString('requireOperatingSchool($actor)', $controller);
        $this->assertStringContainsString('readableAccounts($actor,$school?->id)', $controller);
        $this->assertStringContainsString('schoolBalance($account, $schoolId)', $controller);
    }

    public function test_school_finance_navigation_hides_group_surfaces_without_changing_routes(): void
    {
        $sidebar = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/sidebar.blade.php');

        $this->assertStringContainsString('$centralIsSchoolStaffPrincipal ? __(\'School Finance\')', $sidebar);
        $this->assertStringContainsString('$centralIsHeadFinance ? __(\'校区 / 集团报表\') : __(\'School Reports\')', $sidebar);
        $this->assertStringContainsString('@if($centralIsHeadFinance)', $sidebar);
        $this->assertStringContainsString("route('central-finance.group-import.index')", $sidebar);
        $this->assertStringContainsString("route('central-finance.funding')", $sidebar);
    }

    public function test_school_collection_pages_receive_the_same_school_facade_context(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceStudentCollectionController.php');
        $header = (string) file_get_contents($root.'/resources/views/components/central-finance/page-header.blade.php');

        $this->assertStringContainsString('usesSchoolFinanceFacade($actor)', $controller);
        $this->assertStringContainsString("'schoolFinanceFacade'", $controller);
        $this->assertStringContainsString("__('School Finance')", $header);
        $this->assertStringContainsString("__('Current School')", $header);
    }

    public function test_page_header_brands_the_school_facade_without_changing_head_finance_branding(): void
    {
        $schoolFacade = view('components.central-finance.page-header', [
            'title' => 'Student Collection', 'schoolFinanceFacade' => true,
            'slot' => new \Illuminate\Support\HtmlString(''),
        ])->render();
        $headFinance = view('components.central-finance.page-header', [
            'title' => 'Finance Overview', 'schoolFinanceFacade' => false,
            'slot' => new \Illuminate\Support\HtmlString(''),
        ])->render();

        $this->assertStringContainsString('School Finance', $schoolFacade);
        $this->assertStringContainsString('Central Finance', $headFinance);
    }
}
