<?php

namespace Tests\Feature;

use App\Http\Controllers\CentralFinanceWorkspaceController;
use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralFinanceWorkspaceControllerTest extends TestCase
{
    private string $database;
    private CentralFinanceUser $head;
    private CentralFinanceUser $zixuanAccountant;
    private CentralFinanceFundAccount $zixuan;
    private CentralFinanceFundAccount $timecity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_workspace_');
        Config::set('database.connections.mysql', ['driver'=>'sqlite','database'=>$this->database,'prefix'=>'','foreign_key_constraints'=>true]);
        DB::purge('mysql'); DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('schools', fn (Blueprint $t) => [$t->id(),$t->string('name'),$t->string('code')->nullable(),$t->string('database_name')->nullable(),$t->softDeletes(),$t->timestamps()]);
        Schema::connection('mysql')->create('users', fn (Blueprint $t) => [$t->id(),$t->string('first_name')->nullable(),$t->string('last_name')->nullable(),$t->string('email')->nullable(),$t->unsignedBigInteger('school_id')->nullable(),$t->softDeletes(),$t->timestamps()]);
        foreach(['2026_08_18_000001_create_finance_group_scope_tables.php','2026_08_20_000003_create_central_finance_student_sync_tables.php','2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php','2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php','2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php','2026_08_21_000002_create_central_finance_operating_documents.php','2026_08_21_000003_create_central_finance_internal_transfer_documents.php','2026_08_21_000005_create_central_finance_school_cutovers.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        DB::connection('mysql')->table('schools')->insert([['id'=>1,'name'=>'Zixuan','code'=>'ZIX','database_name'=>'not-a-tenant-connection'],['id'=>2,'name'=>'Timecity','code'=>'TIM','database_name'=>'not-a-tenant-connection']]);
        DB::connection('mysql')->table('users')->insert([['id'=>100,'first_name'=>'Head','last_name'=>'Finance','email'=>'head@example.test','school_id'=>null],['id'=>200,'first_name'=>'Zixuan','last_name'=>'Accountant','email'=>'zix@example.test','school_id'=>null]]);
        DB::connection('mysql')->table('finance_groups')->insert(['id'=>1,'code'=>'CENTRAL-QA','name'=>'Central QA','status'=>'active','reporting_currency'=>'MMK','fiscal_year_start_month'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('finance_group_schools')->insert([['group_id'=>1,'school_id'=>1,'status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_id'=>1,'school_id'=>2,'status'=>'active','created_at'=>now(),'updated_at'=>now()]]);
        DB::connection('mysql')->table('finance_group_users')->insert([['group_id'=>1,'central_user_id'=>100,'status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_id'=>1,'central_user_id'=>200,'status'=>'active','created_at'=>now(),'updated_at'=>now()]]);
        $groupUsers = DB::connection('mysql')->table('finance_group_users')->pluck('id', 'central_user_id');
        foreach ([100, 200] as $userId) {
            foreach (['view_reports', 'operate_finance'] as $capability) {
                DB::connection('mysql')->table('finance_group_user_scopes')->insert([
                    'group_user_id' => $groupUsers[$userId], 'school_id' => null,
                    'scope_type' => 'GROUP', 'capability' => $capability,
                    'scope_key' => 'group', 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $this->head=CentralFinanceUser::on('mysql')->findOrFail(100); $this->zixuanAccountant=CentralFinanceUser::on('mysql')->findOrFail(200);
        $this->zixuan=$this->account('ZIX-CASH','Zixuan Cash',1); $this->timecity=$this->account('TIM-CASH','Timecity Cash',2);
        $this->grantSchool($this->head,1,true);$this->grantSchool($this->head,2,true);$this->grantSchool($this->zixuanAccountant,1,false);
        DB::connection('mysql')->table('central_finance_school_cutovers')->insert([['school_id'=>1,'status'=>'central','cutover_at'=>now(),'created_at'=>now(),'updated_at'=>now()],['school_id'=>2,'status'=>'legacy','cutover_at'=>null,'created_at'=>now(),'updated_at'=>now()]]);
        $this->grantAccount($this->head,$this->zixuan);$this->grantAccount($this->head,$this->timecity);$this->grantAccount($this->zixuanAccountant,$this->zixuan);
        CentralFinanceCategory::on('mysql')->create(['school_id'=>1,'type'=>'expense','name'=>'Supplies','is_active'=>true]);
        CentralFinanceCategory::on('mysql')->create(['school_id'=>1,'type'=>'income','name'=>'Activity','is_active'=>true]);
        Session::start(); Session::forget(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY);
    }
    protected function tearDown(): void { DB::purge('mysql'); @unlink($this->database); parent::tearDown(); }

    public function test_all_schools_is_central_read_model_and_school_switcher_is_scope_limited(): void
    {
        $this->actingAs($this->head);
        $workspace=app(CentralFinanceWorkspaceService::class);
        $this->assertSame([2,1],$workspace->accessibleSchools($this->head)->pluck('id')->all());
        $view=app(CentralFinanceWorkspaceController::class)->dashboard();
        $this->assertSame('central-finance.workspace',$view->name()); $this->assertNull($view->getData()['school']);
        $this->expectException(AuthorizationException::class); $workspace->enterSchool($this->zixuanAccountant,2);
    }

    public function test_school_accountant_can_only_enter_own_school_and_central_expense_never_writes_tenant_data(): void
    {
        $this->actingAs($this->zixuanAccountant); $workspace=app(CentralFinanceWorkspaceService::class); $workspace->enterSchool($this->zixuanAccountant,1);
        $this->assertSame(1,$workspace->currentSchool($this->zixuanAccountant)?->id);
        $category=CentralFinanceCategory::on('mysql')->where(['school_id'=>1,'type'=>'expense'])->value('id');
        $response=app(CentralFinanceWorkspaceController::class)->expense(new Request(['category_id'=>$category,'fund_account_id'=>$this->zixuan->id,'amount'=>25,'payment_method'=>'Cash','reference_no'=>'WS-EXP-1']));
        $this->assertSame(302,$response->getStatusCode());
        $this->assertSame(1,DB::connection('mysql')->table('central_finance_expenses')->count());
        $this->assertSame(1,CentralFinanceLedgerEntry::on('mysql')->where('school_id',1)->count());
        $this->assertFalse(Schema::connection('mysql')->hasTable('expenses'));
        $this->assertSame(['ZIX-CASH'],$workspace->accessibleAccounts($this->zixuanAccountant,1)->pluck('account_code')->all());
    }

    public function test_workspace_routes_and_ui_have_no_tenant_operating_context_dependency(): void
    {
        foreach(['central-finance.dashboard','central-finance.receivables','central-finance.operations','central-finance.accounts','central-finance.transfers','central-finance.handovers','central-finance.funding','central-finance.ledger','central-finance.reports'] as $name) $this->assertNotNull(app('router')->getRoutes()->getByName($name));
        $route = app('router')->getRoutes()->getByName('central-finance.dashboard');
        $this->assertContains('centralFinance', $route->middleware());
        $this->assertNotContains('SwitchDatabase', $route->middleware());
        $view=(string)file_get_contents(resource_path('views/central-finance/workspace.blade.php'));
        $this->assertStringContainsString('Central Finance',$view); $this->assertStringContainsString('All Schools',$view); $this->assertStringContainsString('当前操作校区',$view); $this->assertStringContainsString("['operation' => 'expense']",$view); $this->assertStringContainsString("['operation' => 'income']",$view); $this->assertStringNotContainsString('Operating Context',$view); $this->assertStringNotContainsString('tenant identity',$view);
        foreach (['expense.store', 'bank-transfers.store', 'fund-handovers.store', 'finance-transactions.receive', 'fees.compulsory.store'] as $name) {
            $this->assertContains('tenantFinanceWritable', app('router')->getRoutes()->getByName($name)->middleware());
        }
    }

    public function test_scope_without_active_group_membership_is_not_finance_authority(): void
    {
        DB::connection('mysql')->table('finance_group_users')->where(['group_id'=>1,'central_user_id'=>$this->zixuanAccountant->id])->update(['status'=>'revoked']);
        $workspace=app(CentralFinanceWorkspaceService::class);
        $this->assertSame([], $workspace->accessibleSchools($this->zixuanAccountant)->pluck('id')->all());
        $this->expectException(AuthorizationException::class);
        $workspace->enterSchool($this->zixuanAccountant, 1);
    }

    public function test_legacy_school_is_central_read_only_even_for_a_scoped_head_finance_actor(): void
    {
        $this->actingAs($this->head);
        $workspace = app(CentralFinanceWorkspaceService::class);
        $workspace->enterSchool($this->head, 2);

        $view = app(CentralFinanceWorkspaceController::class)->dashboard();
        $this->assertSame('legacy', $view->getData()['cutoverStatus']);
        $this->assertFalse($view->getData()['canOperate']);
        $this->expectException(AuthorizationException::class);
        app(CentralFinanceWorkspaceController::class)->expense(new Request([
            'category_id' => 999, 'fund_account_id' => $this->timecity->id,
            'amount' => 25, 'payment_method' => 'Cash', 'reference_no' => 'TIM-BLOCKED',
        ]));
    }

    private function account(string $code,string $name,int $school): CentralFinanceFundAccount { return CentralFinanceFundAccount::on('mysql')->create(['account_uuid'=>(string)Str::uuid(),'group_id'=>1,'account_code'=>$code,'account_name'=>$name,'owner_type'=>'school','school_id'=>$school,'currency'=>'MMK','opening_balance'=>100,'is_active'=>true]); }
    private function grantAccount(CentralFinanceUser $u,CentralFinanceFundAccount $a): void { DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>$a->id,'user_id'=>$u->id,'can_view'=>true,'can_operate'=>true,'created_at'=>now(),'updated_at'=>now()]); }
    private function grantSchool(CentralFinanceUser $u,int $school,bool $head): void { DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>$u->id,'school_id'=>$school,'can_view'=>true,'can_operate'=>true,'can_approve_reimbursements'=>$head,'can_confirm_funding'=>$head,'created_at'=>now(),'updated_at'=>now()]); }
}
