<?php

namespace Tests\Feature;

use App\Http\Controllers\CentralFinanceWorkspaceController;
use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use App\Models\User;
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
        Schema::connection('mysql')->create('staffs', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->timestamps()]);
        // The header composer may safely inspect Spatie roles even for a
        // role-less Central School Staff principal. Keep this isolated SQLite
        // fixture structurally compatible with the normal central schema.
        Schema::connection('mysql')->create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name')->default('web'), $t->timestamps()]);
        Schema::connection('mysql')->create('model_has_roles', fn (Blueprint $t) => [$t->unsignedBigInteger('role_id'), $t->string('model_type'), $t->unsignedBigInteger('model_id')]);
        Schema::connection('mysql')->create('permissions', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name')->default('web'), $t->timestamps()]);
        Schema::connection('mysql')->create('role_has_permissions', fn (Blueprint $t) => [$t->unsignedBigInteger('permission_id'), $t->unsignedBigInteger('role_id')]);
        Schema::connection('mysql')->create('model_has_permissions', fn (Blueprint $t) => [$t->unsignedBigInteger('permission_id'), $t->string('model_type'), $t->unsignedBigInteger('model_id')]);
        DB::connection('mysql')->table('permissions')->insert([
            'name' => 'database-backup', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::connection('mysql')->create('system_settings', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->text('data')->nullable(), $t->string('type')->default('text')]);
        Schema::connection('mysql')->create('languages', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('code')->nullable(), $t->string('file')->nullable(), $t->boolean('status')->default(true), $t->boolean('is_rtl')->default(false), $t->timestamps()]);
        foreach(['2026_08_18_000001_create_finance_group_scope_tables.php','2026_08_20_000003_create_central_finance_student_sync_tables.php','2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php','2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php','2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php','2026_08_21_000002_create_central_finance_operating_documents.php','2026_08_21_000003_create_central_finance_internal_transfer_documents.php','2026_08_21_000005_create_central_finance_school_cutovers.php','2026_08_24_000002_create_central_finance_school_staff_identities.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        DB::connection('mysql')->table('schools')->insert([['id'=>1,'name'=>'Zixuan','code'=>'ZIX','database_name'=>'not-a-tenant-connection'],['id'=>2,'name'=>'Timecity','code'=>'TIM','database_name'=>'not-a-tenant-connection']]);
        DB::connection('mysql')->table('system_settings')->insert(['name' => 'date_format', 'data' => 'd-m-Y', 'type' => 'text']);
        DB::connection('mysql')->table('users')->insert([['id'=>100,'first_name'=>'Head','last_name'=>'Finance','email'=>'head@example.test','school_id'=>null],['id'=>200,'first_name'=>'Zixuan','last_name'=>'Accountant','email'=>'zix@example.test','school_id'=>null],['id'=>300,'first_name'=>'Super','last_name'=>'Admin','email'=>'super@example.test','school_id'=>null],['id'=>400,'first_name'=>'School','last_name'=>'Staff','email'=>'staff@example.test','school_id'=>1]]);
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
        foreach(['central-finance.dashboard','central-finance.receivables','central-finance.student-ledger','central-finance.payments.index','central-finance.operations','central-finance.accounts','central-finance.accounts.report','central-finance.staff','central-finance.categories','central-finance.audits','central-finance.transfers','central-finance.handovers','central-finance.funding','central-finance.ledger','central-finance.reports'] as $name) $this->assertNotNull(app('router')->getRoutes()->getByName($name));
        $route = app('router')->getRoutes()->getByName('central-finance.dashboard');
        $this->assertContains('centralFinance', $route->middleware());
        $this->assertNotContains('SwitchDatabase', $route->middleware());
        $view=(string)file_get_contents(dirname(__DIR__, 2).'/resources/views/central-finance/workspace.blade.php');
        $this->assertStringContainsString('Central Finance',$view); $this->assertStringContainsString('All Schools',$view); $this->assertStringContainsString('<x-central-finance.page-header',$view); $this->assertStringContainsString('@php($operation',$view); $this->assertStringContainsString('$showWriteForm',$view); $this->assertStringContainsString('<fieldset @disabled($writeDisabled)>',$view); $this->assertStringNotContainsString('Operating Context',$view); $this->assertStringNotContainsString('tenant identity',$view);
        $this->assertStringContainsString('All Schools is read-only for payment history and totals',$view);
        $this->assertStringContainsString('cf-mobile-card-table',$view);
        $this->assertStringContainsString("route('central-finance.student-collection.show'",$view);
        $this->assertStringContainsString("route('central-finance.student-collection.index')",$view);
        foreach (['expense.store', 'bank-transfers.store', 'fund-handovers.store', 'finance-transactions.receive', 'fees.compulsory.store'] as $name) {
            $this->assertContains('tenantFinanceWritable', app('router')->getRoutes()->getByName($name)->middleware());
        }
    }

    public function test_student_collection_views_use_the_single_guided_collection_flow_and_currency_safe_cards(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views/central-finance/student-collection/';
        $index = (string) file_get_contents($root.'index.blade.php');
        $show = (string) file_get_contents($root.'show.blade.php');
        $review = (string) file_get_contents($root.'review.blade.php');

        foreach ([$index, $show, $review] as $view) {
            $this->assertStringContainsString('central-finance.partials.foundation-styles', $view);
            $this->assertStringContainsString('<x-central-finance.page-header', $view);
        }
        $this->assertStringContainsString('currency_totals', $index);
        $this->assertStringContainsString('currency_totals', $show);
        $this->assertStringContainsString("route('central-finance.student-collection.review'", $show);
        $this->assertStringContainsString("route('central-finance.student-collection.collect'", $review);
        $this->assertStringNotContainsString("route('central-finance.payments.store')", $index);
    }

    public function test_fund_account_directory_links_to_the_selected_account_statement_workspace(): void
    {
        $workspace = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/central-finance/workspace.blade.php');
        $statement = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/central-finance/partials/account-statements.blade.php');

        $this->assertStringContainsString('cf-account-directory', $workspace);
        $this->assertStringContainsString("route('central-finance.accounts.statements', ['fund_account_id' => \$a->id])", $workspace);
        $this->assertStringContainsString('cf-account-statement-workspace', $statement);
        $this->assertStringContainsString('name="fund_account_id"', $statement);
        $this->assertStringContainsString('name="category_id"', $statement);
        $this->assertStringContainsString('name="operator_id"', $statement);
        $this->assertStringContainsString('cf-mobile-card-table', $statement);
        $this->assertStringContainsString('funding_leg', $statement);
    }

    public function test_sidebar_keeps_school_accountants_in_their_daily_workspace_and_head_finance_in_the_full_workspace(): void
    {
        // Render the sidebar under its real Central Finance request path so the
        // shared view composer does not try to open tenant-only settings.
        request()->server->set('REQUEST_URI', '/central-finance');

        $headRole = DB::connection('mysql')->table('roles')->insertGetId([
            'name' => 'Head Finance', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('model_has_roles')->insert([
            'role_id' => $headRole, 'model_type' => User::class, 'model_id' => $this->head->id,
        ]);

        $this->actingAs($this->head);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 1);
        $headSidebar = view('layouts.sidebar')->render();
        $this->assertStringContainsString(route('central-finance.other-income.index'), $headSidebar);
        $this->assertStringContainsString(route('central-finance.audits'), $headSidebar);
        $this->assertStringContainsString(route('central-finance.funding'), $headSidebar);

        $uuid = (string) Str::uuid();
        DB::connection('mysql')->table('users')->where('id', $this->zixuanAccountant->id)->update([
            'school_id' => 1,
            'central_finance_principal_type' => 'school_staff_identity',
        ]);
        DB::connection('mysql')->table('central_finance_school_staff_identities')->insert([
            'identity_uuid' => (string) Str::uuid(), 'school_id' => 1, 'tenant_user_uuid' => $uuid,
            'central_user_id' => $this->zixuanAccountant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->zixuanAccountant->refresh();
        $this->actingAs($this->zixuanAccountant);
        Session::forget(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY);
        $schoolSidebar = view('layouts.sidebar')->render();
        $this->assertStringContainsString(route('central-finance.student-collection.index'), $schoolSidebar);
        $this->assertStringContainsString(route('central-finance.accounts.statements'), $schoolSidebar);
        $this->assertStringContainsString(route('central-finance.reports'), $schoolSidebar);
        $this->assertStringNotContainsString(route('central-finance.other-income.index'), $schoolSidebar);
        $this->assertStringNotContainsString(route('central-finance.audits'), $schoolSidebar);
        $this->assertStringNotContainsString(route('central-finance.funding'), $schoolSidebar);

        $superRole = DB::connection('mysql')->table('roles')->insertGetId([
            'name' => 'Super Admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('model_has_roles')->insert([
            'role_id' => $superRole, 'model_type' => User::class, 'model_id' => 300,
        ]);

        $this->actingAs(CentralFinanceUser::on('mysql')->findOrFail(400));
        $this->assertStringNotContainsString(route('central-finance.dashboard'), view('layouts.sidebar')->render());

        $this->actingAs(CentralFinanceUser::on('mysql')->findOrFail(300));
        $this->assertStringNotContainsString(route('central-finance.dashboard'), view('layouts.sidebar')->render());
    }

    public function test_central_finance_layout_does_not_query_tenant_session_years_for_a_school_scoped_principal(): void
    {
        $tenantUuid = (string) Str::uuid();
        DB::connection('mysql')->table('users')->where('id', $this->zixuanAccountant->id)->update([
            'school_id' => 1,
            'central_finance_principal_type' => 'school_staff_identity',
        ]);
        DB::connection('mysql')->table('central_finance_school_staff_identities')->insert([
            'identity_uuid' => (string) Str::uuid(), 'school_id' => 1,
            'tenant_user_uuid' => $tenantUuid, 'central_user_id' => $this->zixuanAccountant->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->zixuanAccountant->refresh();

        // Invoke the real header composer while the default connection is mysql.
        // The Central connection middleware itself is covered separately; this
        // characterization catches accidental tenant-only session-year queries.
        $this->actingAs($this->zixuanAccountant);
        app()->instance('request', Request::create('/central-finance'));
        $withCalls = [];
        $view = \Mockery::mock(\Illuminate\View\View::class);
        $view->shouldReceive('name')->andReturn('layouts.header');
        $view->shouldReceive('with')->zeroOrMoreTimes()->andReturnUsing(function (...$arguments) use (&$withCalls, $view) {
            $withCalls[] = $arguments;
            return $view;
        });
        $factory = app('view');
        $factory->callComposer($view);

        $keys = array_map(fn (array $arguments) => $arguments[0] ?? null, $withCalls);
        $this->assertNotContains('sessionYear', $keys);
        $this->assertNotContains('semester', $keys);
    }

    public function test_school_read_models_are_paginated_and_include_the_school_ledger_beyond_operation_account_scope(): void
    {
        $hidden = $this->account('ZIX-PRIVATE', 'Zixuan Private', 1);
        CentralFinanceLedgerEntry::on('mysql')->create(['entry_uuid'=>(string) Str::uuid(), 'school_id'=>1, 'fund_account_id'=>$this->zixuan->id, 'entry_date'=>'2026-08-23', 'occurred_at'=>now(), 'source_type'=>'central_payment', 'source_id'=>'visible', 'source_line'=>1, 'transaction_type'=>'operating_income', 'currency'=>'MMK', 'money_in'=>10, 'money_out'=>0, 'operating_income'=>10, 'operating_expense'=>0, 'created_by'=>$this->head->id]);
        CentralFinanceLedgerEntry::on('mysql')->create(['entry_uuid'=>(string) Str::uuid(), 'school_id'=>1, 'fund_account_id'=>$hidden->id, 'entry_date'=>'2026-08-23', 'occurred_at'=>now(), 'source_type'=>'central_payment', 'source_id'=>'hidden', 'source_line'=>1, 'transaction_type'=>'operating_income', 'currency'=>'MMK', 'money_in'=>20, 'money_out'=>0, 'operating_income'=>20, 'operating_expense'=>0, 'created_by'=>$this->head->id]);
        $this->actingAs($this->zixuanAccountant);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->zixuanAccountant, 1);

        $view = app(CentralFinanceWorkspaceController::class)->ledger(new Request());
        // Fund Account scope governs money movement. School-scoped read models
        // intentionally include the whole School ledger for reconciliation.
        $this->assertSame([$this->zixuan->id, $hidden->id], $view->getData()['ledger']->pluck('fund_account_id')->sort()->values()->all());
        $this->assertSame(30.0, $view->getData()['currencyTotals']['MMK']['money_in']);
        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $view->getData()['ledger']);
    }

    public function test_student_payment_history_and_category_read_models_stay_in_the_central_connection(): void
    {
        $this->actingAs($this->head);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 1);
        foreach (['student-ledger', 'payments', 'categories'] as $page) {
            $view = app(CentralFinanceWorkspaceController::class)->{$page === 'student-ledger' ? 'studentLedger' : ($page === 'payments' ? 'paymentHistory' : $page)}(new Request());
            $this->assertSame('central-finance.workspace', $view->name());
            $this->assertSame(1, $view->getData()['school']->id);
        }
        $this->assertFalse(Schema::connection('mysql')->hasTable('fees_paid'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('expenses'));
    }

    public function test_selected_school_read_models_exclude_hq_but_keep_hq_available_in_all_schools_and_operation_scope(): void
    {
        $hq = CentralFinanceFundAccount::on('mysql')->create([
            'account_uuid' => (string) Str::uuid(), 'group_id' => 1,
            'account_code' => 'HQ-MMK', 'account_name' => 'HQ MMK',
            'owner_type' => CentralFinanceFundAccount::OWNER_HQ,
            'school_id' => null, 'currency' => 'MMK', 'opening_balance' => 0,
            'is_active' => true,
        ]);
        $this->grantAccount($this->head, $hq);
        foreach ([[$this->zixuan, 20], [$hq, 11]] as [$account, $amount]) {
            CentralFinanceLedgerEntry::on('mysql')->create([
                'entry_uuid' => (string) Str::uuid(), 'school_id' => 1,
                'fund_account_id' => $account->id, 'entry_date' => '2026-08-29',
                'occurred_at' => now(), 'source_type' => 'central_payment',
                'source_id' => 'scope-'.$account->id, 'source_line' => 1,
                'transaction_type' => 'operating_income', 'currency' => 'MMK',
                'money_in' => $amount, 'money_out' => 0, 'operating_income' => $amount,
                'operating_expense' => 0, 'created_by' => $this->head->id,
            ]);
        }

        $workspace = app(CentralFinanceWorkspaceService::class);
        $this->actingAs($this->head);
        $allSchools = app(CentralFinanceWorkspaceController::class)->dashboard();
        $this->assertSame(['HQ-MMK', 'TIM-CASH', 'ZIX-CASH'], $allSchools->getData()['accounts']->pluck('account_code')->sort()->values()->all());
        $this->assertSame(31.0, $allSchools->getData()['currencyTotals']['MMK']['money_in']);

        $workspace->enterSchool($this->head, 1);
        $headZixuan = app(CentralFinanceWorkspaceController::class)->dashboard();
        $this->assertSame(['ZIX-CASH'], $headZixuan->getData()['accounts']->pluck('account_code')->all());
        $this->assertSame(20.0, $headZixuan->getData()['currencyTotals']['MMK']['money_in']);
        $this->assertSame(['HQ-MMK', 'ZIX-CASH'], $headZixuan->getData()['operationAccounts']->pluck('account_code')->sort()->values()->all());
        $directory = app(CentralFinanceWorkspaceController::class)->accounts(new Request());
        $this->assertSame(['ZIX-CASH'], $directory->getData()['accountDirectory']->pluck('account_code')->all());
        $ledger = app(CentralFinanceWorkspaceController::class)->ledger(new Request());
        $this->assertSame([$this->zixuan->id], $ledger->getData()['ledger']->pluck('fund_account_id')->unique()->values()->all());
        $reports = app(CentralFinanceWorkspaceController::class)->reports(new Request());
        $this->assertSame($headZixuan->getData()['currencyTotals'], $reports->getData()['currencyTotals']);

        $this->actingAs($this->zixuanAccountant);
        $workspace->enterSchool($this->zixuanAccountant, 1);
        $mayZixuan = app(CentralFinanceWorkspaceController::class)->dashboard();
        $this->assertSame($headZixuan->getData()['currencyTotals'], $mayZixuan->getData()['currencyTotals']);
        $this->assertSame(['ZIX-CASH'], $mayZixuan->getData()['accounts']->pluck('account_code')->all());
    }

    public function test_student_fee_selector_uses_only_current_school_profiles_and_open_receivables(): void
    {
        $now = now();
        DB::connection('mysql')->table('central_finance_student_profiles')->insert([
            ['id'=>301, 'school_id'=>1, 'tenant_student_id'=>301, 'source_uuid'=>(string) Str::uuid(), 'student_name'=>'Zixuan Student', 'class_name'=>'Primary A', 'section_name'=>'Red', 'admission_no'=>'ZIX-301', 'enrollment_status'=>'active', 'last_synced_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now],
            ['id'=>302, 'school_id'=>2, 'tenant_student_id'=>302, 'source_uuid'=>(string) Str::uuid(), 'student_name'=>'Timecity Student', 'class_name'=>'Primary B', 'section_name'=>null, 'admission_no'=>'TIM-302', 'enrollment_status'=>'active', 'last_synced_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now],
        ]);
        DB::connection('mysql')->table('central_finance_receivables')->insert([
            ['receivable_uuid'=>(string) Str::uuid(), 'school_id'=>1, 'student_profile_id'=>301, 'source_type'=>'tenant_fee', 'source_id'=>'open', 'description'=>'Open tuition', 'currency'=>'MMK', 'amount_due'=>100, 'amount_paid'=>0, 'status'=>'open', 'created_at'=>$now, 'updated_at'=>$now],
            ['receivable_uuid'=>(string) Str::uuid(), 'school_id'=>1, 'student_profile_id'=>301, 'source_type'=>'tenant_fee', 'source_id'=>'paid', 'description'=>'Paid tuition', 'currency'=>'MMK', 'amount_due'=>100, 'amount_paid'=>100, 'status'=>'paid', 'created_at'=>$now, 'updated_at'=>$now],
            ['receivable_uuid'=>(string) Str::uuid(), 'school_id'=>2, 'student_profile_id'=>302, 'source_type'=>'tenant_fee', 'source_id'=>'other', 'description'=>'Other school tuition', 'currency'=>'MMK', 'amount_due'=>100, 'amount_paid'=>0, 'status'=>'open', 'created_at'=>$now, 'updated_at'=>$now],
        ]);
        $this->actingAs($this->head);
        $controller = app(CentralFinanceWorkspaceController::class);
        $allSchools = $controller->receivables(new Request());
        $this->assertCount(0, $allSchools->getData()['paymentProfiles']);
        $this->assertCount(0, $allSchools->getData()['paymentReceivables']);

        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 1);
        $school = $controller->receivables(new Request());
        $this->assertSame([301], $school->getData()['paymentProfiles']->pluck('id')->all());
        $this->assertSame(['Open tuition'], $school->getData()['paymentReceivables']->pluck('description')->all());
        $this->assertSame(['Primary A'], $school->getData()['paymentClasses']->all());
        $profileTotals = $school->getData()['paymentProfiles']->first()->currency_totals;
        $this->assertSame(200.0, (float) $profileTotals['MMK']['due']);
        $this->assertSame(100.0, (float) $profileTotals['MMK']['paid']);

        $filtered = $controller->receivables(new Request(['payment_class' => 'Primary A', 'payment_student' => 'ZIX-301']));
        $this->assertSame([301], $filtered->getData()['paymentProfiles']->pluck('id')->all());
        $this->assertSame('Red', $filtered->getData()['paymentProfiles']->first()->section_name);

        $none = $controller->receivables(new Request(['payment_class' => 'Primary B']));
        $this->assertCount(0, $none->getData()['paymentProfiles']);
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

    public function test_super_admin_without_an_explicit_group_finance_grant_is_not_a_central_finance_actor(): void
    {
        $this->expectException(AuthorizationException::class);
        app(CentralFinanceWorkspaceService::class)->actor(CentralFinanceUser::on('mysql')->findOrFail(300));
    }

    public function test_legacy_school_keeps_student_filters_and_profiles_read_only(): void
    {
        $now = now();
        DB::connection('mysql')->table('central_finance_student_profiles')->insert([
            'id'=>303, 'school_id'=>2, 'tenant_student_id'=>303, 'source_uuid'=>(string) Str::uuid(), 'student_name'=>'Timecity Legacy Student', 'class_name'=>'Primary B', 'admission_no'=>'TIM-303', 'enrollment_status'=>'active', 'last_synced_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now,
        ]);
        DB::connection('mysql')->table('central_finance_receivables')->insert([
            'receivable_uuid'=>(string) Str::uuid(), 'school_id'=>2, 'student_profile_id'=>303, 'source_type'=>'tenant_fee', 'source_id'=>'legacy-open', 'description'=>'Legacy open tuition', 'currency'=>'MMK', 'amount_due'=>100, 'amount_paid'=>0, 'status'=>'open', 'created_at'=>$now, 'updated_at'=>$now,
        ]);
        $this->actingAs($this->head);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 2);
        $view = app(CentralFinanceWorkspaceController::class)->receivables(new Request(['payment_class' => 'Primary B']));

        $this->assertFalse($view->getData()['canOperate']);
        $this->assertSame([303], $view->getData()['paymentProfiles']->pluck('id')->all());
        $this->assertSame(['Legacy open tuition'], $view->getData()['paymentReceivables']->pluck('description')->all());
    }

    private function account(string $code,string $name,int $school): CentralFinanceFundAccount { return CentralFinanceFundAccount::on('mysql')->create(['account_uuid'=>(string)Str::uuid(),'group_id'=>1,'account_code'=>$code,'account_name'=>$name,'owner_type'=>'school','school_id'=>$school,'currency'=>'MMK','opening_balance'=>100,'is_active'=>true]); }
    private function grantAccount(CentralFinanceUser $u,CentralFinanceFundAccount $a): void { DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>$a->id,'user_id'=>$u->id,'can_view'=>true,'can_operate'=>true,'created_at'=>now(),'updated_at'=>now()]); }
    private function grantSchool(CentralFinanceUser $u,int $school,bool $head): void { DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>$u->id,'school_id'=>$school,'can_view'=>true,'can_operate'=>true,'can_approve_reimbursements'=>$head,'can_confirm_funding'=>$head,'created_at'=>now(),'updated_at'=>now()]); }
}
