<?php

namespace Tests\Unit;

use App\Models\CentralFinanceImportBatch;
use App\Services\CentralFinanceImportBatchPresentationService;
use Tests\TestCase;

final class CentralFinanceUxBatchContractTest extends TestCase
{
    public function test_import_batch_states_are_explicit_and_corrections_create_a_new_batch_flow(): void
    {
        $presenter = app(CentralFinanceImportBatchPresentationService::class);

        $invalid = new CentralFinanceImportBatch(['status' => 'pending', 'error_rows' => 1, 'import_type' => 'payment']);
        $valid = new CentralFinanceImportBatch(['status' => 'pending', 'error_rows' => 0, 'import_type' => 'expense']);
        $completed = new CentralFinanceImportBatch(['status' => 'completed', 'error_rows' => 0, 'import_type' => 'payment']);
        $cancelled = new CentralFinanceImportBatch(['status' => 'discarded', 'error_rows' => 0, 'import_type' => 'payment']);

        $this->assertSame('validation_failed', $presenter->state($invalid)['key']);
        $this->assertSame('pending_confirmation', $presenter->state($valid)['key']);
        $this->assertSame('completed', $presenter->state($completed)['key']);
        $this->assertSame('cancelled', $presenter->state($cancelled)['key']);
        $this->assertStringContainsString('#central-payment-import', $presenter->correctedUploadUrl($invalid));
        $this->assertStringContainsString('operation=expense', $presenter->correctedUploadUrl($valid));
    }

    public function test_fund_account_management_reporting_audit_and_pagination_contracts_are_present(): void
    {
        $workspace = $this->read('resources/views/central-finance/workspace.blade.php');
        $manage = $this->read('resources/views/central-finance/partials/fund-account-manage.blade.php');
        $report = $this->read('resources/views/central-finance/partials/report-analysis.blade.php');
        $audit = $this->read('resources/views/central-finance/partials/audit-snapshot.blade.php');
        $routes = $this->read('routes/web.php');
        $provider = $this->read('app/Providers/AppServiceProvider.php');

        $this->assertStringNotContainsString('central-finance-cutover-readiness', $workspace);
        $this->assertStringNotContainsString("__('More actions')", $workspace);
        $this->assertStringContainsString("route('central-finance.accounts.manage'", $workspace);
        foreach (['Basic details', 'Authorized users', 'School allocations', 'Account Opening Balance / Adjustment', 'Audit History'] as $section) {
            $this->assertStringContainsString($section, $manage);
        }
        $this->assertStringNotContainsString('opening_allocation_amount', $manage);
        foreach (['Report filters', 'School comparison', 'Income / expense trend', 'Category analysis', 'Open Ledger'] as $section) {
            $this->assertStringContainsString($section, $report);
        }
        $this->assertStringContainsString('Field', $audit);
        $this->assertStringContainsString('Raw JSON technical detail', $audit);
        $this->assertStringContainsString('central-finance.accounts.manage', $routes);
        $this->assertStringContainsString('central-finance.imports.errors', $routes);
        $this->assertStringContainsString('Paginator::useBootstrap()', $provider);
    }

    public function test_sidebar_uses_one_route_derived_leaf_and_unstyled_ancestors(): void
    {
        $sidebar = $this->read('resources/views/layouts/sidebar.blade.php');
        $script = $this->read('public/assets/js/custom/custom.js');
        $legacyScript = $this->read('public/assets/js/misc.js');
        $css = $this->read('public/assets/css/custom.css');

        $this->assertStringContainsString("? 'is-ancestor' : ''", $sidebar);
        $this->assertStringContainsString('var activeLink = exactLinks[0] || serverActiveLinks[0] || pathLinks[0] || null;', $script);
        $this->assertStringContainsString("item.classList.remove('active', 'is-current')", $script);
        $this->assertStringContainsString("data-route-active-bound", $script);
        $this->assertStringNotContainsString("parents('.nav-item').last().addClass('active')", $legacyScript);
        $this->assertStringContainsString("attr('aria-current', 'page')", $legacyScript);
        $this->assertStringContainsString('.sidebar .nav .nav-link.is-ancestor', $css);
        $this->assertStringNotContainsString('.sidebar .nav .nav-link.is-parent-active {', $css);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
