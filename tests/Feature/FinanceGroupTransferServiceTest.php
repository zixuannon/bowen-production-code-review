<?php

namespace Tests\Feature;

use App\Http\Controllers\FinanceGroupTransferController;
use App\Models\BankAccount;
use App\Models\FinanceGroup;
use App\Models\FinanceGroupHqAccount;
use App\Models\FinanceGroupTransfer;
use App\Models\FinanceGroupUser;
use App\Models\FinanceGroupHqAccountAdjustment;
use App\Models\User;
use App\Services\FinanceGroupHqAccountBalanceService;
use App\Services\FinanceGroupScopeService;
use App\Services\FinanceGroupTransferService;
use App\Services\FundAccountBalanceService;
use App\Services\FinanceLedgerV1Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceGroupTransferServiceTest extends TestCase
{
    private array $mysqlConnection;
    private array $schoolConnection;
    private array $files = [];
    private string $central;
    private string $schoolA;
    private string $schoolB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->central = $this->temp(); $this->schoolA = $this->temp(); $this->schoolB = $this->temp();
        $sqlite = fn (string $path) => ['driver'=>'sqlite','database'=>$path,'prefix'=>'','foreign_key_constraints'=>true];
        Config::set('database.connections.mysql', $sqlite($this->central));
        Config::set('database.connections.school', $sqlite($this->schoolA));
        DB::purge('mysql'); DB::purge('school'); DB::setDefaultConnection('mysql');
        $this->centralSchema();
        (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up();
        (require database_path('migrations/2026_08_19_000001_create_finance_group_hq_accounts_and_transfers.php'))->up();
        $this->tenantSchema($this->schoolA, 1, 201, 202);
        $this->tenantSchema($this->schoolB, 2, 301, 302);
        Config::set('database.connections.school.database', $this->schoolA); DB::purge('school'); DB::setDefaultConnection('mysql');
    }

    protected function tearDown(): void
    {
        DB::purge('school'); DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysqlConnection); Config::set('database.connections.school', $this->schoolConnection); DB::setDefaultConnection('mysql');
        foreach ($this->files as $file) @unlink($file);
        parent::tearDown();
    }

    public function test_pending_request_has_no_balance_or_ledger_effect_and_confirmation_moves_once(): void
    {
        [$group, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $service = app(FinanceGroupTransferService::class);
        $request = $service->request($schoolAccountant, [
            'school_id'=>1, 'tenant_bank_account_id'=>11, 'direction'=>FinanceGroupTransfer::DIRECTION_HQ_TO_SCHOOL,
            'purpose'=>'HQ_FUNDING', 'amount'=>100, 'transfer_date'=>'2026-08-19', 'reference_no'=>'REQ-1',
        ]);
        $this->assertSame(FinanceGroupTransfer::STATUS_PENDING, $request->status);
        $this->assertSame(1000.0, $this->tenantBalance($this->schoolA, 11));
        $this->assertSame(1000.0, app(FinanceGroupHqAccountBalanceService::class)->currentBalance($hq));
        $this->assertSame(0, $this->tenantLedgerRows($this->schoolA, 201)->where('source_type', 'group_transfer')->count());

        $confirmed = $service->confirm($head, $request->id, ['hq_account_id'=>$hq->id]);
        $this->assertSame(FinanceGroupTransfer::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame((int) $hq->id, (int) $confirmed->hq_account_id);
        $this->assertSame(1100.0, $this->tenantBalance($this->schoolA, 11));
        $this->assertSame(900.0, app(FinanceGroupHqAccountBalanceService::class)->currentBalance($hq));
        $rows = $this->tenantLedgerRows($this->schoolA, 201)->where('source_type', 'group_transfer');
        $this->assertCount(1, $rows);
        $this->assertSame(0.0, (float) $rows->sole()['operating_income']);
        $this->assertSame(0.0, (float) $rows->sole()['operating_expense']);
        $this->assertSame(100.0, (float) $rows->sole()['internal_transfer_amount']);
    }

    public function test_confirmed_funding_is_not_projected_into_an_unregistered_tenant_with_the_same_school_id(): void
    {
        [, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $transfer = app(FinanceGroupTransferService::class)->request($schoolAccountant, [
            'school_id'=>1, 'tenant_bank_account_id'=>11, 'direction'=>'HQ_TO_SCHOOL',
            'purpose'=>'HQ_FUNDING', 'amount'=>100, 'transfer_date'=>'2026-08-19',
        ]);
        app(FinanceGroupTransferService::class)->confirm($head, $transfer->id, ['hq_account_id'=>$hq->id]);

        $unregisteredTenant = $this->temp();
        $this->tenantSchema($unregisteredTenant, 1, 401, 402);

        $this->assertSame(1000.0, $this->tenantBalance($unregisteredTenant, 11));
        $this->assertCount(0, $this->tenantLedgerRows($unregisteredTenant, 401)
            ->where('source_type', 'group_transfer'));
        $this->assertSame(1100.0, $this->tenantBalance($this->schoolA, 11));
    }

    public function test_school_accountant_cannot_forge_peer_school_or_unassigned_account_and_head_finance_confirms_only_once(): void
    {
        [, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $service = app(FinanceGroupTransferService::class);
        try {
            $service->request($schoolAccountant, ['school_id'=>2,'tenant_bank_account_id'=>11,'direction'=>'HQ_TO_SCHOOL','purpose'=>'HQ_FUNDING','amount'=>1,'transfer_date'=>'2026-08-19']);
            $this->fail('Peer School request accepted.');
        } catch (AuthorizationException) { $this->assertTrue(true); }
        try {
            $service->request($schoolAccountant, ['school_id'=>1,'tenant_bank_account_id'=>12,'direction'=>'HQ_TO_SCHOOL','purpose'=>'HQ_FUNDING','amount'=>1,'transfer_date'=>'2026-08-19']);
            $this->fail('Unassigned tenant Fund Account accepted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->assertTrue(true); }

        $request = $service->request($schoolAccountant, ['school_id'=>1,'tenant_bank_account_id'=>11,'direction'=>'HQ_TO_SCHOOL','purpose'=>'HQ_FUNDING','amount'=>1,'transfer_date'=>'2026-08-19']);
        $service->confirm($head, $request->id, ['hq_account_id'=>$hq->id]);
        $this->expectException(\DomainException::class);
        $service->confirm($head, $request->id, ['hq_account_id'=>$hq->id]);
    }

    public function test_school_to_hq_direction_increases_hq_and_decreases_only_selected_school_account(): void
    {
        [, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $transfer = app(FinanceGroupTransferService::class)->request($schoolAccountant, [
            'school_id'=>1,'tenant_bank_account_id'=>11,'direction'=>'SCHOOL_TO_HQ','purpose'=>'SCHOOL_REMITTANCE','amount'=>80,'transfer_date'=>'2026-08-19',
        ]);
        app(FinanceGroupTransferService::class)->confirm($head, $transfer->id, ['hq_account_id'=>$hq->id]);
        $this->assertSame(920.0, $this->tenantBalance($this->schoolA, 11));
        $this->assertSame(1080.0, app(FinanceGroupHqAccountBalanceService::class)->currentBalance($hq));
        $this->assertSame(1000.0, $this->tenantBalance($this->schoolB, 11));
    }

    public function test_confirmation_rechecks_current_tenant_account_scope_and_currency(): void
    {
        [, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $service = app(FinanceGroupTransferService::class);
        $transfer = $service->request($schoolAccountant, ['school_id'=>1,'tenant_bank_account_id'=>11,'direction'=>'HQ_TO_SCHOOL','purpose'=>'HQ_FUNDING','amount'=>1,'transfer_date'=>'2026-08-19']);
        $this->onTenant($this->schoolA, fn () => DB::connection('school')->table('bank_account_user')->where('user_id', 202)->delete());
        try {
            $service->confirm($head, $transfer->id, ['hq_account_id'=>$hq->id]);
            $this->fail('Confirmation used a revoked Account assignment.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->assertSame(FinanceGroupTransfer::STATUS_PENDING, FinanceGroupTransfer::find($transfer->id)->status); }
        $this->onTenant($this->schoolA, fn () => DB::connection('school')->table('bank_account_user')->insert(['user_id'=>202,'bank_account_id'=>11]));
        $this->onTenant($this->schoolA, fn () => DB::connection('school')->table('bank_accounts')->where('id',11)->update(['currency'=>'USD']));
        $this->expectException(ValidationException::class);
        $service->confirm($head, $transfer->id, ['hq_account_id'=>$hq->id]);
    }

    public function test_confirmation_requires_head_finance_even_when_a_scope_is_forged(): void
    {
        [, , $schoolAccountant, $hq] = $this->groupFixture();
        $scope = app(FinanceGroupScopeService::class);
        $scope->grantScope($schoolAccountant, 'confirm_group_transfers', 'GROUP');
        $transfer = app(FinanceGroupTransferService::class)->request($schoolAccountant, [
            'school_id'=>1, 'tenant_bank_account_id'=>11, 'direction'=>'HQ_TO_SCHOOL',
            'purpose'=>'HQ_FUNDING', 'amount'=>1, 'transfer_date'=>'2026-08-19',
        ]);

        $this->assertFalse($scope->canConfirmGroupTransfers($schoolAccountant));
        $this->expectException(AuthorizationException::class);
        app(FinanceGroupTransferService::class)->confirm($schoolAccountant, $transfer->id, ['hq_account_id'=>$hq->id]);
    }

    public function test_school_to_hq_confirmation_rechecks_source_balance_without_partial_movement(): void
    {
        [, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $service = app(FinanceGroupTransferService::class);
        $transfer = $service->request($schoolAccountant, [
            'school_id'=>1, 'tenant_bank_account_id'=>11, 'direction'=>'SCHOOL_TO_HQ',
            'purpose'=>'SCHOOL_REMITTANCE', 'amount'=>1000.01, 'transfer_date'=>'2026-08-19',
        ]);

        try {
            $service->confirm($head, $transfer->id, ['hq_account_id'=>$hq->id]);
            $this->fail('An insufficient School Fund Account was allowed to remit to HQ.');
        } catch (ValidationException) {
            $this->assertSame(FinanceGroupTransfer::STATUS_PENDING, $transfer->fresh()->status);
            $this->assertSame(1000.0, $this->tenantBalance($this->schoolA, 11));
            $this->assertSame(1000.0, app(FinanceGroupHqAccountBalanceService::class)->currentBalance($hq));
        }
    }

    public function test_school_scoped_funding_page_does_not_expose_peer_school_transfers(): void
    {
        [$group, $head, $schoolAccountant] = $this->groupFixture();
        $service = app(FinanceGroupTransferService::class);
        $own = $service->request($schoolAccountant, [
            'school_id'=>1, 'tenant_bank_account_id'=>11, 'direction'=>'HQ_TO_SCHOOL',
            'purpose'=>'HQ_FUNDING', 'amount'=>1, 'transfer_date'=>'2026-08-19',
        ]);
        $peer = $service->request($head, [
            'school_id'=>2, 'tenant_bank_account_id'=>11, 'direction'=>'HQ_TO_SCHOOL',
            'purpose'=>'HQ_FUNDING', 'amount'=>1, 'transfer_date'=>'2026-08-19',
        ]);

        $this->actingAs(User::on('mysql')->findOrFail($schoolAccountant->central_user_id));
        $visible = app(FinanceGroupTransferController::class)->funding($group)->getData()['transfers'];

        $this->assertSame([$own->id], $visible->pluck('id')->all());
        $this->assertFalse($visible->contains('id', $peer->id));
    }

    public function test_only_a_central_head_finance_with_group_control_scope_can_change_hq_balance_controls(): void
    {
        [, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $scope=app(FinanceGroupScopeService::class);
        $this->assertTrue($scope->canControlHqAccounts($head));
        $scope->grantScope($schoolAccountant,'manage_hq_accounts','HQ');
        $this->assertFalse($scope->canControlHqAccounts($schoolAccountant));
        $before=app(FinanceGroupHqAccountBalanceService::class)->currentBalance($hq);
        FinanceGroupHqAccountAdjustment::create(['hq_account_id'=>$hq->id,'amount'=>25,'balance_before'=>$before,'balance_after'=>$before+25,'adjustment_date'=>'2026-08-19','reason'=>'Counted cash difference','created_by_group_user_id'=>$head->id]);
        $this->assertSame(1025.0, app(FinanceGroupHqAccountBalanceService::class)->currentBalance($hq));
        $this->assertSame(1000.0, (float)$hq->fresh()->opening_balance, 'An adjustment never rewrites opening_balance.');
    }

    public function test_hq_accountant_needs_an_explicit_account_assignment_and_can_never_confirm(): void
    {
        [$group, $head, $schoolAccountant, $hq] = $this->groupFixture();
        $scope = app(FinanceGroupScopeService::class);
        $service = app(FinanceGroupTransferService::class);

        // A scoped but non-Head-Finance user cannot name an unassigned HQ
        // account or confirm a School request.
        $scope->grantScope($schoolAccountant, 'manage_hq_accounts', 'HQ');
        $scope->grantScope($schoolAccountant, 'confirm_group_transfers', 'GROUP');
        $this->assertFalse($scope->canConfirmGroupTransfers($schoolAccountant));
        $this->expectException(AuthorizationException::class);
        $service->request($schoolAccountant, [
            'school_id'=>1, 'tenant_bank_account_id'=>11, 'hq_account_id'=>$hq->id,
            'direction'=>'HQ_TO_SCHOOL', 'purpose'=>'HQ_FUNDING', 'amount'=>1, 'transfer_date'=>'2026-08-19',
        ]);
    }

    public function test_assigned_hq_accountant_can_open_only_assigned_hq_accounts_without_school_scope(): void
    {
        [$group, , $hqAccountant, $hq] = $this->groupFixture();
        DB::connection('mysql')->table('finance_group_user_scopes')
            ->where('group_user_id', $hqAccountant->id)
            ->where('capability', 'request_group_transfers')
            ->update(['status' => 'revoked']);
        $this->assertSame(0, DB::connection('mysql')->table('finance_group_user_scopes')
            ->where('group_user_id', $hqAccountant->id)
            ->where('capability', 'request_group_transfers')
            ->where('status', 'active')
            ->count());
        $hq->authorizedGroupUsers()->sync([$hqAccountant->id]);
        $this->actingAs(User::on('mysql')->findOrFail($hqAccountant->central_user_id));

        $view = app(\App\Http\Controllers\FinanceGroupTransferController::class)->funding($group);
        $this->assertSame([$hq->id], $view->getData()['hqAccounts']->pluck('id')->all());
        $this->assertTrue($view->getData()['schools']->isEmpty());
        $this->assertFalse($view->getData()['mayConfirm']);
        $this->assertFalse($view->getData()['mayControlHq']);
    }

    public function test_head_finance_confirmation_cannot_forge_an_unassigned_hq_account(): void
    {
        [$group, , $schoolAccountant, $hq] = $this->groupFixture();
        DB::connection('mysql')->table('users')->insert(['id'=>102, 'email'=>'limited-head@test']);
        DB::connection('mysql')->table('model_has_roles')->insert([
            'role_id'=>1, 'model_type'=>User::class, 'model_id'=>102,
        ]);
        $limitedHead = app(FinanceGroupScopeService::class)->addUser($group, 102);
        app(FinanceGroupScopeService::class)->grantScope($limitedHead, 'confirm_group_transfers', 'GROUP');
        $transfer = app(FinanceGroupTransferService::class)->request($schoolAccountant, [
            'school_id'=>1, 'tenant_bank_account_id'=>11, 'direction'=>'HQ_TO_SCHOOL',
            'purpose'=>'HQ_FUNDING', 'amount'=>1, 'transfer_date'=>'2026-08-19',
        ]);

        try {
            app(FinanceGroupTransferService::class)->confirm($limitedHead, $transfer->id, ['hq_account_id'=>$hq->id]);
            $this->fail('An unassigned HQ Fund Account was accepted at confirmation.');
        } catch (AuthorizationException) {
            $this->assertSame(FinanceGroupTransfer::STATUS_PENDING, $transfer->fresh()->status);
        }

        $hq->authorizedGroupUsers()->syncWithoutDetaching([$limitedHead->id]);
        $confirmed = app(FinanceGroupTransferService::class)->confirm($limitedHead, $transfer->id, ['hq_account_id'=>$hq->id]);
        $this->assertSame(FinanceGroupTransfer::STATUS_CONFIRMED, $confirmed->status);
    }

    public function test_group_funding_migration_is_central_only_and_rolls_back_without_tenant_changes(): void
    {
        $migration = require database_path('migrations/2026_08_19_000001_create_finance_group_hq_accounts_and_transfers.php');

        $this->assertTrue(Schema::connection('mysql')->hasTable('finance_group_hq_accounts'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('finance_group_transfers'));
        $this->assertFalse(Schema::connection('school')->hasTable('finance_group_transfers'));
        $migration->down();

        $this->assertFalse(Schema::connection('mysql')->hasTable('finance_group_hq_accounts'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('finance_group_transfers'));
        $this->assertTrue(Schema::connection('school')->hasTable('bank_accounts'));
    }

    /** @return array{0:FinanceGroup,1:FinanceGroupUser,2:FinanceGroupUser,3:FinanceGroupHqAccount} */
    private function groupFixture(): array
    {
        $scope = app(FinanceGroupScopeService::class);
        $group = $scope->createGroup(['name'=>'Bowen Group','code'=>'BOWEN','status'=>'active']); $scope->syncSchools($group,[1,2]);
        $head=$scope->addUser($group,100); $scope->grantScope($head,'request_group_transfers','GROUP'); $scope->grantScope($head,'confirm_group_transfers','GROUP'); $scope->grantScope($head,'manage_hq_accounts','GROUP'); $scope->bindTenantIdentity($head,1,201); $scope->bindTenantIdentity($head,2,301);
        $school=$scope->addUser($group,101); $scope->grantScope($school,'request_group_transfers','SCHOOL',1); $scope->bindTenantIdentity($school,1,202);
        $hq=FinanceGroupHqAccount::create(['group_id'=>$group->id,'account_name'=>'HQ Main Cash','currency'=>'MMK','opening_balance'=>1000,'is_active'=>true,'created_by'=>100]);
        return [$group,$head,$school,$hq];
    }

    private function centralSchema(): void
    {
        Schema::connection('mysql')->create('schools', function ($t): void { $t->id();$t->string('name');$t->string('code')->unique();$t->string('database_name')->unique();$t->timestamp('deleted_at')->nullable();$t->timestamps(); });
        Schema::connection('mysql')->create('users', function ($t): void { $t->id();$t->unsignedBigInteger('school_id')->nullable();$t->string('email')->nullable();$t->timestamp('deleted_at')->nullable();$t->timestamps(); });
        Schema::connection('mysql')->create('roles', function ($t): void { $t->id();$t->string('name');$t->string('guard_name');$t->unsignedBigInteger('school_id')->nullable();$t->timestamps(); });
        Schema::connection('mysql')->create('model_has_roles', function ($t): void { $t->unsignedBigInteger('role_id');$t->string('model_type');$t->unsignedBigInteger('model_id'); });
        DB::connection('mysql')->table('schools')->insert([['id'=>1,'name'=>'A','code'=>'A','database_name'=>$this->schoolA],['id'=>2,'name'=>'B','code'=>'B','database_name'=>$this->schoolB]]);
        DB::connection('mysql')->table('users')->insert([['id'=>100,'email'=>'head@test'],['id'=>101,'email'=>'school@test']]);
        DB::connection('mysql')->table('roles')->insert(['id'=>1,'name'=>'Head Finance','guard_name'=>'web']);
        DB::connection('mysql')->table('model_has_roles')->insert(['role_id'=>1,'model_type'=>User::class,'model_id'=>100]);
    }

    private function tenantSchema(string $database, int $schoolId, int $headId, int $cashierId): void
    {
        Config::set('database.connections.school.database',$database);DB::purge('school');
        Schema::connection('school')->create('users', function ($t): void { $t->id();$t->unsignedBigInteger('school_id');$t->string('first_name')->nullable();$t->string('last_name')->nullable();$t->timestamp('deleted_at')->nullable();$t->timestamps(); });
        Schema::connection('school')->create('roles', function ($t): void { $t->id();$t->string('name');$t->string('guard_name');$t->unsignedBigInteger('school_id')->nullable();$t->timestamps(); });
        Schema::connection('school')->create('model_has_roles', function ($t): void { $t->unsignedBigInteger('role_id');$t->string('model_type');$t->unsignedBigInteger('model_id'); });
        Schema::connection('school')->create('bank_accounts', function ($t): void { $t->id();$t->unsignedBigInteger('school_id');$t->string('account_name');$t->string('currency');$t->decimal('opening_balance',18,2)->default(0);$t->boolean('is_active')->default(true);$t->timestamp('deleted_at')->nullable();$t->timestamps(); });
        Schema::connection('school')->create('bank_account_user', function ($t): void { $t->unsignedBigInteger('user_id');$t->unsignedBigInteger('bank_account_id'); });
        foreach (['compulsory_fees','optional_fees','other_incomes','expenses','bank_transfers'] as $table) Schema::connection('school')->create($table, function ($t) use ($table): void { $t->id();$t->unsignedBigInteger('school_id');$t->unsignedBigInteger('bank_account_id')->nullable();$t->unsignedBigInteger('from_account_id')->nullable();$t->unsignedBigInteger('to_account_id')->nullable();$t->decimal('amount',18,2)->default(0);$t->string('status')->nullable();$t->date('date')->nullable();$t->date('transfer_date')->nullable();$t->timestamp('deleted_at')->nullable();$t->timestamps(); });
        $db=DB::connection('school');$db->table('users')->insert([['id'=>$headId,'school_id'=>$schoolId],['id'=>$cashierId,'school_id'=>$schoolId]]);
        $db->table('roles')->insert([['id'=>1,'name'=>'Head Finance','guard_name'=>'web','school_id'=>$schoolId],['id'=>2,'name'=>'Cashier','guard_name'=>'web','school_id'=>$schoolId]]);
        $db->table('model_has_roles')->insert([['role_id'=>1,'model_type'=>User::class,'model_id'=>$headId],['role_id'=>2,'model_type'=>User::class,'model_id'=>$cashierId]]);
        $db->table('bank_accounts')->insert([['id'=>11,'school_id'=>$schoolId,'account_name'=>'Cash','currency'=>'MMK','opening_balance'=>1000,'is_active'=>1],['id'=>12,'school_id'=>$schoolId,'account_name'=>'Private','currency'=>'MMK','opening_balance'=>0,'is_active'=>1]]);
        $db->table('bank_account_user')->insert(['user_id'=>$cashierId,'bank_account_id'=>11]);
    }

    private function tenantBalance(string $database,int $accountId): float { return $this->onTenant($database, function () use ($accountId): float { return app(FundAccountBalanceService::class)->currentBalance(BankAccount::on('school')->findOrFail($accountId)); }); }
    private function tenantLedgerRows(string $database,int $userId) { return $this->onTenant($database, fn () => app(FinanceLedgerV1Service::class)->register(User::on('school')->findOrFail($userId))['rows']); }
    private function onTenant(string $database, callable $callback) { $old=config('database.connections.school.database');$default=DB::getDefaultConnection();try { Config::set('database.connections.school.database',$database);DB::purge('school');DB::setDefaultConnection('school');return $callback(); } finally { DB::purge('school');Config::set('database.connections.school.database',$old);DB::setDefaultConnection($default); } }
    private function temp(): string { $path=tempnam(sys_get_temp_dir(),'eschool_group_transfer_');$this->files[]=$path;return $path; }
}
