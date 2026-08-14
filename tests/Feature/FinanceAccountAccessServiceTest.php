<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Fee;
use App\Models\User;
use App\Http\Controllers\BankAccountAssignmentController;
use App\Services\FinanceAccountAccessService;
use App\Services\FeesPaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use App\Models\Role;
use Spatie\Permission\Models\Permission;

class FinanceAccountAccessServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['school'];

    public function test_roles_pivot_and_account_scope_preserve_tenant_and_school_admin_access(): void
    {
        $this->ensurePivotTable();

        $cashierA = $this->createUser('cashier-a', 1);
        $cashierB = $this->createUser('cashier-b', 1);
        $headFinance = $this->createUser('head-finance', 1);
        $schoolAdmin = $this->createUser('school-admin', 1);

        $this->assignRole($cashierA, 'Cashier', 1);
        $this->assignRole($cashierB, 'Cashier', 1);
        $this->assignRole($headFinance, 'Head Finance', 1);
        $this->assignRole($schoolAdmin, 'School Admin', 1);

        $cashA = $this->createAccount('Cash A', 1);
        $cashB = $this->createAccount('Cash B', 1);
        $otherSchool = $this->createAccount('Other school', 2);

        $cashierA->authorized_bank_accounts()->sync([$cashA->id, $otherSchool->id]);
        $cashierB->authorized_bank_accounts()->sync([$cashB->id]);

        $access = app(FinanceAccountAccessService::class);

        $this->assertSame([$cashA->id], $access->accessibleAccounts($cashierA)->pluck('id')->sort()->values()->all());
        $this->assertSame([$cashB->id], $access->accessibleAccounts($cashierB)->pluck('id')->sort()->values()->all());
        $this->assertFalse($access->canAccessAccount($cashierA, $cashB));
        $this->assertFalse($access->canAccessAccount($cashierA, $otherSchool));
        $this->expectException(ModelNotFoundException::class);
        $access->authorize($cashierA, $cashB->id);
    }

    public function test_head_finance_and_school_admin_have_all_current_school_accounts_but_cashier_cannot_manage_or_change_opening_balance(): void
    {
        $this->ensurePivotTable();

        $cashier = $this->createUser('restricted-cashier', 1);
        $headFinance = $this->createUser('all-access-head', 1);
        $schoolAdmin = $this->createUser('all-access-admin', 1);
        $this->assignRole($cashier, 'Cashier', 1);
        $this->assignRole($headFinance, 'Head Finance', 1);
        $this->assignRole($schoolAdmin, 'School Admin', 1);

        $first = $this->createAccount('First current school', 1);
        $second = $this->createAccount('Second current school', 1);
        $otherSchool = $this->createAccount('Foreign school', 2);
        $cashier->authorized_bank_accounts()->sync([$first->id]);

        $access = app(FinanceAccountAccessService::class);

        $headIds = $access->accessibleAccounts($headFinance)->pluck('id')->all();
        $adminIds = $access->accessibleAccounts($schoolAdmin)->pluck('id')->all();
        $this->assertContains($first->id, $headIds);
        $this->assertContains($second->id, $headIds);
        $this->assertNotContains($otherSchool->id, $headIds);
        $this->assertContains($first->id, $adminIds);
        $this->assertContains($second->id, $adminIds);
        $this->assertNotContains($otherSchool->id, $adminIds);
        $this->assertTrue($access->canManageAccountAssignments($headFinance));
        $this->assertTrue($access->canManageAccounts($schoolAdmin));
        $this->assertTrue($access->canModifyOpeningBalance($headFinance));
        $this->assertFalse($access->canManageAccountAssignments($cashier));
        $this->assertFalse($access->canManageAccounts($cashier));
        $this->assertFalse($access->canModifyOpeningBalance($cashier));
    }

    public function test_cashier_without_pivot_assignments_cannot_access_any_fund_account(): void
    {
        $this->ensurePivotTable();

        $cashier = $this->createUser('unassigned-cashier', 1);
        $this->assignRole($cashier, 'Cashier', 1);
        $account = $this->createAccount('Unassigned cash account', 1);

        $access = app(FinanceAccountAccessService::class);

        $this->assertSame([], $access->accessibleAccounts($cashier)->pluck('id')->all());
        $this->assertFalse($access->canAccessAccount($cashier, $account));
        $this->expectException(ModelNotFoundException::class);
        $access->authorize($cashier, $account->id);
    }

    public function test_head_finance_can_assign_only_current_school_cashiers_and_cashier_cannot_manage_assignments(): void
    {
        $this->ensurePivotTable();

        $headFinance = $this->createUser('assignment-head', 1);
        $cashier = $this->createUser('assignment-cashier', 1);
        $foreignUser = $this->createUser('assignment-foreign', 2);
        $account = $this->createAccount('Assignment target', 1);
        $this->assignRole($headFinance, 'Head Finance', 1);
        $this->assignRole($cashier, 'Cashier', 1);

        Auth::login($headFinance);
        $response = app(BankAccountAssignmentController::class)->update(
            new Request(['user_ids' => [$cashier->id]]),
            $account,
            app(FinanceAccountAccessService::class),
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$cashier->id], $account->authorized_users()->pluck('users.id')->all());

        try {
            app(BankAccountAssignmentController::class)->update(
                new Request(['user_ids' => [$foreignUser->id]]),
                $account,
                app(FinanceAccountAccessService::class),
            );
            $this->fail('A Head Finance user must not assign a user from another school.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        Auth::login($cashier);
        $this->expectException(HttpException::class);
        app(BankAccountAssignmentController::class)->update(
            new Request(['user_ids' => [$cashier->id]]),
            $account,
            app(FinanceAccountAccessService::class),
        );
    }

    public function test_cashier_payment_service_rejects_an_unassigned_fund_account_before_any_financial_write(): void
    {
        $this->ensurePivotTable();

        $cashier = $this->createUser('payment-cashier', 1);
        $this->assignRole($cashier, 'Cashier', 1);
        $allowed = $this->createAccount('Payment allowed', 1);
        $forbidden = $this->createAccount('Payment forbidden', 1);
        $cashier->authorized_bank_accounts()->sync([$allowed->id]);
        Auth::login($cashier);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not authorized');
        app(FeesPaymentService::class)->processPayment([
            'bank_account_id' => $forbidden->id,
        ], new Fee());
    }

    private function ensurePivotTable(): void
    {
        if (!Schema::hasTable('bank_account_user')) {
            Schema::create('bank_account_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bank_account_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['bank_account_id', 'user_id']);
            });
        }
    }

    private function createUser(string $label, int $schoolId): User
    {
        return User::create([
            'first_name' => 'P2',
            'last_name' => $label,
            'email' => uniqid($label . '-', true) . '@test.local',
            'password' => bcrypt('local-only'),
            'school_id' => $schoolId,
            'status' => 1,
        ]);
    }

    private function assignRole(User $user, string $name, int $schoolId): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => $name, 'school_id' => $schoolId],
            ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'updated_at' => now(), 'created_at' => now()]
        );
        $roleId = DB::table('roles')->where('name', $name)->where('school_id', $schoolId)->value('id');
        DB::table('model_has_roles')->updateOrInsert(
            ['role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class], []
        );
        if (in_array($name, ['School Admin', 'Head Finance'], true)) {
            Permission::findOrCreate('finance-fund-account-manage', 'web');
            Role::withoutGlobalScope('school')->find($roleId)->givePermissionTo('finance-fund-account-manage');
        }
    }

    private function createAccount(string $name, int $schoolId): BankAccount
    {
        return BankAccount::create([
            'school_id' => $schoolId,
            'account_name' => $name,
            'account_type' => 'cash',
            'currency' => 'MMK',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }
}
