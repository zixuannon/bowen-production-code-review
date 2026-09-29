<?php

namespace Tests\Unit;

use App\Services\TenantFrontDeskFeeSetupPermissionContract;
use PHPUnit\Framework\TestCase;

final class FrontDeskSchoolFinanceWorkspaceContractTest extends TestCase
{
    public function test_front_desk_fee_setup_contract_excludes_legacy_settlement_and_finance_administration(): void
    {
        self::assertSame([
            'fees-list', 'fees-create', 'fees-edit',
            'fees-type-list', 'fees-type-create', 'fees-type-edit',
            'fees-class-list', 'fees-class-create', 'fees-class-edit',
        ], TenantFrontDeskFeeSetupPermissionContract::names());

        foreach ([
            'fees-paid', 'fees-config', 'finance-payment-create',
            'finance-fund-account-manage', 'finance-transfer-create',
            'finance-handover-confirm', 'finance-staff-manage',
        ] as $forbidden) {
            self::assertNotContains($forbidden, TenantFrontDeskFeeSetupPermissionContract::names());
        }
    }

    public function test_central_cutover_keeps_legacy_settlement_posts_behind_the_trusted_write_guard(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        foreach ([
            "Route::post('pay/compulsory'",
            "Route::post('pay/optional'",
            "Route::post('/paid/store'",
            "Route::post('/optional-paid/store'",
            "Route::post('finance/transactions/receive'",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        self::assertGreaterThanOrEqual(5, substr_count($routes, "->middleware('tenantFinanceWritable')"));

        $guard = (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/EnsureTenantFinanceWritesAllowed.php');
        self::assertStringContainsString('assertTenantFinanceWritesAllowed', $guard);
        self::assertStringContainsString('!$request->isMethodSafe()', $guard);
    }
}
