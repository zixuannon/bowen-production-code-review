<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinanceAuthorizationService;
use Mockery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class FinanceAuthorizationServiceTest extends TestCase
{
    public function test_only_strict_legacy_finance_permission_equivalents_are_accepted(): void
    {
        $service = app(FinanceAuthorizationService::class);

        $paidFeesUser = Mockery::mock(User::class);
        $paidFeesUser->shouldReceive('hasRole')->with('School Admin')->once()->andReturnFalse();
        $paidFeesUser->shouldReceive('can')->with('finance-payment-view')->once()->andReturnFalse();
        $paidFeesUser->shouldReceive('canany')->with(['fees-paid'])->once()->andReturnTrue();
        $this->assertTrue($service->can($paidFeesUser, 'finance-payment-view'));

        $expenseUser = Mockery::mock(User::class);
        $expenseUser->shouldReceive('hasRole')->with('School Admin')->once()->andReturnFalse();
        $expenseUser->shouldReceive('can')->with('finance-expense-create')->once()->andReturnFalse();
        $expenseUser->shouldReceive('canany')->with(['expense-create'])->once()->andReturnTrue();
        $this->assertTrue($service->can($expenseUser, 'finance-expense-create'));

        $legacyFinanceUser = Mockery::mock(User::class);
        $legacyFinanceUser->shouldReceive('hasRole')->with('School Admin')->once()->andReturnFalse();
        $legacyFinanceUser->shouldReceive('can')->with('finance-fund-account-view')->once()->andReturnFalse();
        $legacyFinanceUser->shouldReceive('canany')->with(['expense-list'])->once()->andReturnTrue();
        $this->assertTrue($service->can($legacyFinanceUser, 'finance-fund-account-view'));

        $schoolAdmin = Mockery::mock(User::class);
        $schoolAdmin->shouldReceive('hasRole')->with('School Admin')->once()->andReturnTrue();
        $schoolAdmin->shouldReceive('hasRole')->with('Super Admin')->once()->andReturnFalse();
        $this->assertFalse($service->can($schoolAdmin, 'finance-fund-account-view'));
    }

    public function test_legacy_permissions_never_expand_to_fund_account_transfer_or_handover_control(): void
    {
        $service = app(FinanceAuthorizationService::class);

        foreach (['finance-fund-account-manage', 'finance-transfer-create', 'finance-handover-create'] as $permission) {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('hasRole')->with('School Admin')->once()->andReturnFalse();
            $user->shouldReceive('can')->with($permission)->once()->andReturnFalse();
            $user->shouldReceive('canany')->with([])->once()->andReturnFalse();
            $this->assertFalse($service->can($user, $permission));
        }
    }

    public function test_assert_rejects_a_user_without_the_requested_finance_permission(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasRole')->with('School Admin')->once()->andReturnFalse();
        $user->shouldReceive('can')->with('finance-transfer-create')->once()->andReturnFalse();
        $user->shouldReceive('canany')->with([])->once()->andReturnFalse();

        $this->expectException(AccessDeniedHttpException::class);
        app(FinanceAuthorizationService::class)->assert($user, 'finance-transfer-create');
    }

    public function test_sidebar_uses_the_same_central_fund_account_read_decision_as_the_controller(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));

        $this->assertStringContainsString(
            "FinanceAuthorizationService::class)->can(Auth::user(), 'finance-fund-account-view')",
            $sidebar,
        );
        $this->assertStringNotContainsString("@can('finance-fund-account-view')", $sidebar);
    }
}
