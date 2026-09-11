<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Http\Controllers\BankAccountController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinanceFundAccountLegacyRouteTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['school'];

    public function test_school_admin_with_legacy_expense_list_is_denied_from_fund_accounts(): void
    {
        // A null synthetic school id bypasses the unrelated subscription
        // feature check while retaining the tenant-local Spatie role shape
        // observed for SCH202615: School Admin + expense-list only.
        $user = User::create([
            'first_name' => 'Legacy',
            'last_name' => 'School Admin',
            'email' => uniqid('legacy-fund-account-', true) . '@test.local',
            'password' => bcrypt('local-only'),
            'school_id' => null,
            'status' => 1,
        ]);

        DB::table('roles')->updateOrInsert(
            ['name' => 'School Admin', 'school_id' => null],
            ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        $roleId = DB::table('roles')->where('name', 'School Admin')->whereNull('school_id')->value('id');
        DB::table('model_has_roles')->updateOrInsert(
            ['role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class],
            [],
        );

        Permission::findOrCreate('expense-list', 'web');
        Role::withoutGlobalScope('school')->findOrFail($roleId)->givePermissionTo('expense-list');
        $user = $user->fresh();

        $this->assertFalse($user->can('finance-fund-account-view'));
        $this->assertFalse(app(\App\Services\FinanceAuthorizationService::class)->can($user, 'finance-fund-account-view'));
        $this->assertFalse(app(\App\Services\FinanceAuthorizationService::class)->can($user, 'finance-fund-account-manage'));

        $this->assertTrue(Route::has('bank-accounts.index'));
        Auth::login($user);
        foreach ([
            fn () => app(BankAccountController::class)->index(),
            fn () => app(BankAccountController::class)->store(new \Illuminate\Http\Request()),
        ] as $call) {
            try {
                $call();
                $this->fail('School Admin must be denied before a Fund Account action.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }
}
