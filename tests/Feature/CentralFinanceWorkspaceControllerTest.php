<?php

namespace Tests\Feature;

use App\Http\Controllers\CentralFinanceWorkspaceController;
use App\Http\Controllers\CentralFinanceDataClassificationController;
use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use App\Models\User;
use App\Services\CentralFinanceWorkspaceService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceFundHandoverService;
use App\Services\CentralFinanceInternalTransferService;
use Carbon\CarbonImmutable;
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
        foreach(['2026_08_18_000001_create_finance_group_scope_tables.php','2026_08_20_000003_create_central_finance_student_sync_tables.php','2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php','2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php','2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php','2026_08_21_000002_create_central_finance_operating_documents.php','2026_08_21_000003_create_central_finance_internal_transfer_documents.php','2026_08_21_000005_create_central_finance_school_cutovers.php','2026_08_21_000006_create_central_finance_opening_balance_audits.php','2026_08_24_000001_create_central_finance_import_batches.php','2026_08_24_000002_create_central_finance_school_staff_identities.php','2026_08_26_000002_add_master_data_to_central_finance_fund_accounts.php','2026_09_01_000003_add_central_finance_transfer_reversal_links.php','2026_09_03_000001_create_central_finance_fund_account_school_allocations.php','2026_09_14_000003_create_central_finance_data_classifications.php','2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php'] as $migration) (require database_path('migrations/'.$migration))->up();
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
        DB::connection('mysql')->table('finance_group_user_scopes')->insert([
            'group_user_id' => $groupUsers[100], 'school_id' => null,
            'scope_type' => 'GROUP', 'capability' => 'manage_hq_accounts',
            'scope_key' => 'group', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

    public function test_head_finance_creates_group_account_without_school_context_then_manages_allocations(): void
    {
        $this->grantHeadFinanceRole();
        $this->actingAs($this->head);
        app(CentralFinanceWorkspaceService::class)->exitSchool();

        $response = app(CentralFinanceWorkspaceController::class)->createFundAccount(new Request([
            'group_id' => 1, 'account_code' => 'GROUP-BANK', 'account_name' => 'Bowen Group Bank',
            'currency' => 'MMK', 'opening_balance' => 0, 'opening_balance_date' => '2026-09-17',
            'opening_reason' => 'Approved group opening', 'account_type' => CentralFinanceFundAccount::TYPE_BANK,
        ]));
        $account = CentralFinanceFundAccount::on('mysql')->where('account_code', 'GROUP-BANK')->sole();

        $this->assertNull($account->school_id);
        $this->assertSame(CentralFinanceFundAccount::OWNER_HQ, $account->owner_type);
        $this->assertSame(route('central-finance.accounts.manage', $account->id), $response->getTargetUrl());
        $view = app(CentralFinanceWorkspaceController::class)->manageFundAccount(new Request(), $account->id);
        $this->assertSame($account->id, $view->getData()['accountReport']->id);
        $this->assertCount(0, $account->schoolAllocations);
        $this->assertStringContainsString('Bowen Group / Central Finance', $view->with('errors', new \Illuminate\Support\ViewErrorBag())->render());
    }

    public function test_transfer_and_handover_pages_hide_write_choices_until_a_central_school_is_selected(): void
    {
        $this->actingAs($this->head);
        $controller = app(CentralFinanceWorkspaceController::class);

        $allSchoolsTransfer = $controller->transfers(new Request());
        $allSchoolsHtml = $allSchoolsTransfer->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
        $this->assertStringContainsString('Account choices stay hidden until a School is selected.', $allSchoolsHtml);
        $this->assertStringNotContainsString('name="source_account_id"', $allSchoolsHtml);
        $this->assertStringNotContainsString('name="destination_account_id"', $allSchoolsHtml);

        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 1);
        $schoolTransferHtml = $controller->transfers(new Request())->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
        $this->assertStringContainsString('name="source_account_id"', $schoolTransferHtml);
        $this->assertStringContainsString('name="reason"', $schoolTransferHtml);
        $this->assertStringContainsString('data-lifecycle-confirm', $schoolTransferHtml);
        $this->assertStringContainsString('Review and confirm', $schoolTransferHtml);

        $handover = $controller->handovers(new Request());
        $this->assertSame([$this->zixuanAccountant->id], $handover->getData()['schoolUsers']->pluck('id')->all());
        $this->assertSame(
            [$this->zixuanAccountant->id],
            $handover->getData()['handoverDestinationUserIds']->get($this->zixuan->id)->sort()->values()->all(),
        );
        $handoverHtml = $handover->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
        $this->assertStringContainsString('name="receiver_user_id"', $handoverHtml);
        $this->assertStringContainsString('data-operating-user-ids="200"', $handoverHtml);
        $this->assertStringNotContainsString('value="'.$this->head->id.'">Head Finance', $handoverHtml);
    }

    public function test_all_schools_transfer_and_handover_history_is_consolidated_and_participant_actions_are_scoped(): void
    {
        $this->actingAs($this->head);
        $destination = $this->account('ZIX-RESERVE', 'Zixuan Reserve', 1);
        $this->grantAccount($this->head, $destination);
        $this->grantAccount($this->zixuanAccountant, $destination);
        $at = CarbonImmutable::parse('2026-09-16 10:00:00', 'Asia/Yangon');

        $transfer = app(CentralFinanceInternalTransferService::class)->transfer(
            $this->head, 1, $this->zixuan, $destination, 10, $at,
            'WORKSPACE-DIRECT-1', 'Move cash to reserve', 'WORKSPACE-DIRECT-REF'
        );
        $handover = app(CentralFinanceFundHandoverService::class)->request(
            $this->head, $this->zixuanAccountant, 1, $this->zixuan, $destination, 5, $at,
            'WORKSPACE-HANDOVER-1', 'WORKSPACE-HANDOVER-REF'
        );

        app(CentralFinanceWorkspaceService::class)->exitSchool();
        $controller = app(CentralFinanceWorkspaceController::class);
        $transferView = $controller->transfers(new Request());
        $this->assertSame([$transfer->id], $transferView->getData()['transfers']->pluck('id')->all());
        $transferHtml = $transferView->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
        $this->assertStringContainsString('WORKSPACE-DIRECT-REF', $transferHtml);
        $this->assertStringContainsString('Zixuan Cash', $transferHtml);
        $this->assertStringContainsString('Zixuan Reserve', $transferHtml);

        $handoverView = $controller->handovers(new Request());
        $this->assertSame([$handover->id], $handoverView->getData()['handovers']->pluck('id')->all());
        $handoverHtml = $handoverView->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
        $this->assertStringContainsString('WORKSPACE-HANDOVER-REF', $handoverHtml);
        $this->assertStringContainsString('Head Finance', $handoverHtml);
        $this->assertStringContainsString('Zixuan Accountant', $handoverHtml);
        $this->assertStringContainsString('No action', $handoverHtml);
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

    public function test_hq_funding_workspace_is_head_finance_only_even_for_an_operating_school_accountant(): void
    {
        $this->actingAs($this->zixuanAccountant);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->zixuanAccountant, 1);

        $this->expectException(AuthorizationException::class);
        app(CentralFinanceWorkspaceController::class)->funding();
    }

    public function test_hq_funding_mutations_cannot_bypass_the_head_finance_workspace_gate(): void
    {
        $this->actingAs($this->zixuanAccountant);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->zixuanAccountant, 1);
        $controller = app(CentralFinanceWorkspaceController::class);

        foreach ([
            fn () => $controller->storeFunding(new Request()),
            fn () => $controller->resolveFunding(new Request(), 999999, 'cancel'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A School Accountant must not bypass the HQ Funding authorization gate.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
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
        $this->assertStringContainsString('transfer_leg', $statement);
        $this->assertStringContainsString("->orderByDesc('occurred_at')->orderByDesc('id')->get()", (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/CentralFinanceWorkspaceController.php'));
    }

    public function test_fund_account_manage_page_reuses_head_finance_boundary_and_existing_account_services(): void
    {
        $headRole = DB::connection('mysql')->table('roles')->insertGetId([
            'name' => 'Head Finance', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('model_has_roles')->insert([
            'role_id' => $headRole, 'model_type' => User::class, 'model_id' => $this->head->id,
        ]);

        $this->actingAs($this->head);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 1);
        $view = app(CentralFinanceWorkspaceController::class)->manageFundAccount(new Request(), $this->zixuan->id);
        $this->assertSame('account-manage', $view->getData()['page']);
        $this->assertSame($this->zixuan->id, $view->getData()['accountReport']->id);
        $rendered = $view->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
        $this->assertStringContainsString('Account Opening Balance / Adjustment', $rendered);
        $this->assertStringNotContainsString('opening_allocation_amount', $rendered);

        $this->actingAs($this->zixuanAccountant);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->zixuanAccountant, 1);
        $this->expectException(AuthorizationException::class);
        app(CentralFinanceWorkspaceController::class)->manageFundAccount(new Request(), $this->zixuan->id);
    }

    public function test_group_report_keeps_school_and_currency_dimensions_separate(): void
    {
        $now = now();
        foreach ([
            ['school_id'=>1, 'fund_account_id'=>$this->zixuan->id, 'currency'=>'MMK', 'money_in'=>100, 'money_out'=>0, 'operating_income'=>100, 'operating_expense'=>0, 'source_id'=>'MMK-IN'],
            ['school_id'=>1, 'fund_account_id'=>$this->zixuan->id, 'currency'=>'USD', 'money_in'=>5, 'money_out'=>0, 'operating_income'=>5, 'operating_expense'=>0, 'source_id'=>'USD-IN'],
            ['school_id'=>2, 'fund_account_id'=>$this->timecity->id, 'currency'=>'MMK', 'money_in'=>0, 'money_out'=>20, 'operating_income'=>0, 'operating_expense'=>20, 'source_id'=>'MMK-OUT'],
            ['school_id'=>1, 'fund_account_id'=>$this->zixuan->id, 'currency'=>'MMK', 'money_in'=>50, 'money_out'=>0, 'operating_income'=>0, 'operating_expense'=>0, 'source_id'=>'NEUTRAL-COA-CASH-IN'],
        ] as $index => $entry) {
            CentralFinanceLedgerEntry::on('mysql')->create(array_merge($entry, [
                'entry_uuid'=>(string) Str::uuid(), 'entry_date'=>$now->toDateString(), 'occurred_at'=>$now,
                'source_type'=>'ux_report_test', 'source_line'=>(string) $index, 'reference_no'=>'REPORT-'.$index,
                'transaction_type'=>$entry['operating_income'] ? 'operating_income' : 'operating_expense',
                'memo'=>'Read-only report fixture', 'created_by'=>$this->head->id,
            ]));
        }

        $this->actingAs($this->head);
        $view = app(CentralFinanceWorkspaceController::class)->reports(new Request());
        $comparison = $view->getData()['reportSchoolComparison'];
        $this->assertCount(3, $comparison);
        $this->assertSame(['MMK', 'USD', 'MMK'], $comparison->pluck('currency')->all());
        $this->assertSame([1, 1, 2], $comparison->pluck('school_id')->all());
        $this->assertCount(2, $view->getData()['reportTrend']);
        $this->assertCount(3, $view->getData()['reportCategoryAnalysis']);
        $schoolMmk = $comparison->first(fn ($row) => $row['school_id'] === 1 && $row['currency'] === 'MMK');
        $this->assertSame(150.0, $schoolMmk['money_in']);
        $this->assertSame(100.0, $schoolMmk['income']);
        $analysisMmk = $view->getData()['reportCategoryAnalysis']->first(fn ($row) => $row['school_id'] === 1 && $row['currency'] === 'MMK');
        $this->assertSame(150.0, $analysisMmk['money_in']);
        $this->assertSame(150.0, $analysisMmk['net_movement']);
        $this->assertSame(100.0, $analysisMmk['income']);
    }

    public function test_import_error_detail_is_scoped_read_only_and_correction_keeps_original_batch(): void
    {
        $batch = \App\Models\CentralFinanceImportBatch::on('mysql')->create([
            'school_id'=>1, 'uploaded_by'=>$this->head->id, 'import_type'=>'payment', 'template_version'=>'2.2',
            'file_name'=>'invalid.xlsx', 'file_hash'=>hash('sha256', 'invalid'), 'status'=>'pending',
            'total_rows'=>1, 'valid_rows'=>0, 'error_rows'=>1,
            'preview_data'=>[['row_number'=>2, 'status'=>'error', 'errors'=>['Amount must be greater than zero.']]],
            'summary'=>['valid'=>0, 'errors'=>1],
        ]);
        $before = [
            'batches'=>DB::connection('mysql')->table('central_finance_import_batches')->count(),
            'payments'=>DB::connection('mysql')->table('central_finance_payments')->count(),
            'receipts'=>DB::connection('mysql')->table('central_finance_receipts')->count(),
            'ledger'=>DB::connection('mysql')->table('central_finance_ledger_entries')->count(),
        ];

        $this->actingAs($this->head);
        $controller = app(CentralFinanceWorkspaceController::class);
        $detail = $controller->importBatchDetail($batch->token);
        $download = $controller->downloadImportErrors($batch->token);
        $this->assertSame('validation_failed', $detail->getData()['state']['key']);
        $this->assertStringContainsString('new Batch', $detail->with('errors', new \Illuminate\Support\ViewErrorBag())->render());
        $this->assertStringContainsString('.csv', $download->headers->get('content-disposition'));
        $this->assertSame($before, [
            'batches'=>DB::connection('mysql')->table('central_finance_import_batches')->count(),
            'payments'=>DB::connection('mysql')->table('central_finance_payments')->count(),
            'receipts'=>DB::connection('mysql')->table('central_finance_receipts')->count(),
            'ledger'=>DB::connection('mysql')->table('central_finance_ledger_entries')->count(),
        ]);

        $this->actingAs($this->zixuanAccountant);
        $otherSchoolBatch = \App\Models\CentralFinanceImportBatch::on('mysql')->create([
            'school_id'=>2, 'uploaded_by'=>$this->head->id, 'import_type'=>'payment', 'template_version'=>'2.2',
            'file_name'=>'other.xlsx', 'file_hash'=>hash('sha256', 'other'), 'status'=>'pending',
            'total_rows'=>1, 'valid_rows'=>0, 'error_rows'=>1, 'preview_data'=>[], 'summary'=>['valid'=>0, 'errors'=>1],
        ]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $controller->importBatchDetail($otherSchoolBatch->token);
    }

    public function test_bootstrap_pagination_prevents_unbounded_tailwind_svg_arrows(): void
    {
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(range(1, 25), 50, 25, 1, ['path'=>'/central-finance/ledger']);
        $html = (string) $paginator->links();

        $this->assertStringContainsString('page-link', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_reporting_audit_and_import_workspaces_use_the_shared_read_only_presentation_contract(): void
    {
        $workspace = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/central-finance/workspace.blade.php');
        $auditSnapshot = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/central-finance/partials/audit-snapshot.blade.php');
        $styles = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/central-finance/partials/foundation-styles.blade.php');

        foreach (['reports', 'audits', 'imports', 'exports', 'ledger'] as $page) {
            $this->assertStringContainsString("'{$page}'", $workspace);
        }

        $this->assertStringContainsString('cf-workspace-toolbar', $workspace);
        $this->assertStringContainsString('cf-mobile-card-table', $workspace);
        $this->assertStringContainsString('cf-file-name', $workspace);
        $this->assertStringContainsString('cf-technical-detail', $auditSnapshot);
        $this->assertStringContainsString('cf-audit-diff', $auditSnapshot);
        $this->assertStringContainsString('cf-export-card', $workspace);
        $this->assertStringContainsString('cf-workspace-toolbar', $styles);
        $this->assertStringContainsString('cf-danger-panel', $styles);
        $this->assertStringContainsString('cf-history-notice', $styles);
        $this->assertStringNotContainsString('payments.store', substr($workspace, strpos($workspace, "@if(\$page === 'imports')"), strpos($workspace, "@if(\$page === 'ledger')") - strpos($workspace, "@if(\$page === 'imports')")));
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
        DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->insert([
            'fund_account_id' => $hq->id, 'school_id' => 1, 'opening_allocation_amount' => 0,
            'effective_from' => '2026-08-29', 'status' => 'active', 'is_active' => true,
            'assigned_by' => $this->head->id, 'assignment_reason' => 'Explicit HQ allocation fixture.',
            'created_at' => now(), 'updated_at' => now(),
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
        $this->assertSame(['HQ-MMK', 'ZIX-CASH'], $headZixuan->getData()['accounts']->pluck('account_code')->sort()->values()->all());
        $this->assertSame(31.0, $headZixuan->getData()['currencyTotals']['MMK']['money_in']);
        $this->assertSame(['HQ-MMK', 'ZIX-CASH'], $headZixuan->getData()['operationAccounts']->pluck('account_code')->sort()->values()->all());
        $directory = app(CentralFinanceWorkspaceController::class)->accounts(new Request());
        $this->assertSame(['HQ-MMK', 'ZIX-CASH'], $directory->getData()['accountDirectory']->pluck('account_code')->sort()->values()->all());
        $ledger = app(CentralFinanceWorkspaceController::class)->ledger(new Request());
        $this->assertSame([$this->zixuan->id, $hq->id], $ledger->getData()['ledger']->pluck('fund_account_id')->unique()->sort()->values()->all());
        $reports = app(CentralFinanceWorkspaceController::class)->reports(new Request());
        $this->assertSame($headZixuan->getData()['currencyTotals'], $reports->getData()['currencyTotals']);

        $this->actingAs($this->zixuanAccountant);
        $workspace->enterSchool($this->zixuanAccountant, 1);
        $mayZixuan = app(CentralFinanceWorkspaceController::class)->dashboard();
        $this->assertSame($headZixuan->getData()['currencyTotals'], $mayZixuan->getData()['currencyTotals']);
        $this->assertSame(['HQ-MMK', 'ZIX-CASH'], $mayZixuan->getData()['accounts']->pluck('account_code')->sort()->values()->all());
    }

    public function test_shared_fund_account_classification_uses_group_scope_without_inventing_an_owning_school(): void
    {
        $hq = CentralFinanceFundAccount::on('mysql')->create([
            'account_uuid' => (string) Str::uuid(), 'group_id' => 1,
            'account_code' => 'HQ-SHARED', 'account_name' => 'HQ Shared',
            'owner_type' => CentralFinanceFundAccount::OWNER_HQ,
            'school_id' => null, 'currency' => 'MMK', 'opening_balance' => 0,
            'is_active' => true,
        ]);
        $isolation = app(CentralFinanceDataIsolationService::class);

        $this->assertFalse($isolation->isProduction('fund_account', 999999));
        $this->assertTrue($isolation->isProduction('fund_account', $hq->id));
        $isolation->classify(
            $this->head,
            1,
            'fund_account',
            $hq->id,
            CentralFinanceDataClassification::QA_TEST,
            'Shared account retained only for QA history.',
        );

        $this->assertFalse($isolation->isProduction('fund_account', $hq->id));
        $this->assertDatabaseHas('central_finance_data_classifications', [
            'school_id' => 1,
            'subject_type' => 'fund_account',
            'subject_id' => $hq->id,
            'classification' => CentralFinanceDataClassification::QA_TEST,
        ], 'mysql');
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

    public function test_qa_school_is_excluded_from_default_dashboard_reports_and_accounts_but_authorized_history_remains_visible(): void
    {
        $this->grantHeadFinanceRole();
        foreach ([[$this->zixuan, 125, 'QA-LEDGER'], [$this->timecity, 40, 'PROD-LEDGER']] as [$account, $amount, $source]) {
            CentralFinanceLedgerEntry::on('mysql')->create([
                'entry_uuid'=>(string) Str::uuid(), 'school_id'=>$account->school_id,
                'fund_account_id'=>$account->id, 'entry_date'=>'2026-09-14', 'occurred_at'=>now(),
                'source_type'=>'data_isolation_test', 'source_id'=>$source, 'source_line'=>'1',
                'reference_no'=>$source, 'transaction_type'=>'operating_income', 'currency'=>'MMK',
                'money_in'=>$amount, 'money_out'=>0, 'operating_income'=>$amount,
                'operating_expense'=>0, 'memo'=>'immutable fixture', 'created_by'=>$this->head->id,
            ]);
        }
        $beforeLedger = CentralFinanceLedgerEntry::on('mysql')->count();
        $isolation = app(CentralFinanceDataIsolationService::class);
        $isolation->classify($this->head, 1, 'school', 1, CentralFinanceDataClassification::QA_TEST, 'Zixuan remains the approved QA School.');

        $this->actingAs($this->head);
        $default = app(CentralFinanceWorkspaceController::class)->reports(new Request());
        $this->assertSame([2], $default->getData()['schools']->pluck('id')->all());
        $this->assertSame(['TIM-CASH'], $default->getData()['accounts']->pluck('account_code')->all());
        $this->assertSame(40.0, $default->getData()['currencyTotals']['MMK']['money_in']);
        $this->assertFalse($default->getData()['includeQaTest']);

        $withHistory = app(CentralFinanceWorkspaceController::class)->reports(new Request(['include_qa_test'=>1]));
        $this->assertSame([2, 1], $withHistory->getData()['schools']->pluck('id')->all());
        $this->assertSame(165.0, $withHistory->getData()['currencyTotals']['MMK']['money_in']);
        $this->assertTrue($withHistory->getData()['includeQaTest']);
        $this->assertSame($beforeLedger, CentralFinanceLedgerEntry::on('mysql')->count());
        try {
            CentralFinanceLedgerEntry::on('mysql')->where('reference_no', 'QA-LEDGER')->firstOrFail()->delete();
            $this->fail('Canonical QA financial history must remain append-only.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $this->assertSame($beforeLedger, CentralFinanceLedgerEntry::on('mysql')->count());
        $this->assertDatabaseHas('central_finance_data_classification_audits', [
            'school_id'=>1, 'subject_type'=>'school', 'subject_id'=>1,
            'before_classification'=>null, 'after_classification'=>'qa_test', 'actor_id'=>$this->head->id,
        ], 'mysql');
    }

    public function test_direct_qa_master_and_student_rows_are_hidden_without_changing_production_rows(): void
    {
        $this->grantHeadFinanceRole();
        $now = now();
        $productionCategory = CentralFinanceCategory::on('mysql')->create(['school_id'=>2,'type'=>'income','name'=>'Production Tuition','is_active'=>true]);
        $qaCategory = CentralFinanceCategory::on('mysql')->create(['school_id'=>2,'type'=>'income','name'=>'Preview Tuition','is_active'=>true]);
        DB::connection('mysql')->table('central_finance_student_profiles')->insert([
            ['id'=>501,'school_id'=>2,'tenant_student_id'=>501,'source_uuid'=>(string)Str::uuid(),'student_name'=>'Production Student','admission_no'=>'000001','enrollment_status'=>'active','last_synced_at'=>$now,'created_at'=>$now,'updated_at'=>$now],
            ['id'=>502,'school_id'=>2,'tenant_student_id'=>502,'source_uuid'=>(string)Str::uuid(),'student_name'=>'QA Student','admission_no'=>'QA-1','enrollment_status'=>'active','last_synced_at'=>$now,'created_at'=>$now,'updated_at'=>$now],
        ]);
        $isolation = app(CentralFinanceDataIsolationService::class);
        $isolation->classify($this->head, 2, 'category', $qaCategory->id, CentralFinanceDataClassification::QA_TEST, 'Preview-only category.');
        CentralFinanceDataClassification::on('mysql')->create([
            'school_id'=>2, 'subject_scope'=>'tenant:2', 'subject_type'=>'student', 'subject_id'=>502,
            'classification'=>CentralFinanceDataClassification::ARCHIVED,
            'reason'=>'Archived QA tenant student.', 'classified_by'=>$this->head->id,
        ]);

        $this->actingAs($this->head);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 2);
        $default = app(CentralFinanceWorkspaceController::class)->receivables(new Request());
        $this->assertSame([$productionCategory->id], $default->getData()['categories']->pluck('id')->all());
        $this->assertSame([501], $default->getData()['profiles']->pluck('id')->all());

        $withHistory = app(CentralFinanceWorkspaceController::class)->receivables(new Request(['include_qa_test'=>1]));
        $this->assertEqualsCanonicalizing([$productionCategory->id, $qaCategory->id], $withHistory->getData()['categories']->pluck('id')->all());
        $this->assertEqualsCanonicalizing([501, 502], $withHistory->getData()['profiles']->pluck('id')->all());
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_student_profiles')->whereIn('id',[501,502])->count());
    }

    public function test_school_finance_identity_cannot_enable_the_qa_history_view(): void
    {
        $this->actingAs($this->zixuanAccountant);
        $this->expectException(AuthorizationException::class);
        app(CentralFinanceWorkspaceController::class)->dashboard(new Request(['include_qa_test'=>1]));
    }

    public function test_classification_is_head_finance_only_audited_and_does_not_change_financial_rows(): void
    {
        $this->grantHeadFinanceRole();
        $category = CentralFinanceCategory::on('mysql')->where(['school_id'=>1,'type'=>'expense'])->firstOrFail();
        $beforeLedger = CentralFinanceLedgerEntry::on('mysql')->count();

        $this->actingAs($this->head);
        $response = app(CentralFinanceDataClassificationController::class)->update(new Request([
            'school_id'=>1, 'subject_type'=>'category', 'subject_id'=>$category->id,
            'classification'=>'qa_test', 'reason'=>'UAT-only configuration',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertDatabaseHas('central_finance_data_classifications', [
            'school_id'=>1, 'subject_type'=>'category', 'subject_id'=>$category->id,
            'classification'=>'qa_test', 'classified_by'=>$this->head->id,
        ], 'mysql');
        $this->assertDatabaseHas('central_finance_data_classification_audits', [
            'subject_type'=>'category', 'subject_id'=>$category->id,
            'after_classification'=>'qa_test', 'actor_id'=>$this->head->id,
        ], 'mysql');
        $this->assertSame($beforeLedger, CentralFinanceLedgerEntry::on('mysql')->count());

        $this->actingAs($this->zixuanAccountant);
        $this->expectException(AuthorizationException::class);
        app(CentralFinanceDataClassificationController::class)->update(new Request([
            'school_id'=>1, 'subject_type'=>'category', 'subject_id'=>$category->id,
            'classification'=>'production', 'reason'=>'Unauthorized reclassification',
        ]));
    }

    public function test_qa_master_records_cannot_be_reused_by_direct_finance_write_urls(): void
    {
        $this->grantHeadFinanceRole();
        $category = CentralFinanceCategory::on('mysql')->where(['school_id'=>1,'type'=>'expense'])->firstOrFail();
        app(CentralFinanceDataIsolationService::class)->classify(
            $this->head, 1, 'category', (int) $category->id,
            CentralFinanceDataClassification::QA_TEST, 'QA-only category'
        );
        $beforeExpenses = DB::connection('mysql')->table('central_finance_expenses')->count();
        $beforeLedger = CentralFinanceLedgerEntry::on('mysql')->count();

        $this->actingAs($this->head);
        app(CentralFinanceWorkspaceService::class)->enterSchool($this->head, 1);
        try {
            app(CentralFinanceWorkspaceController::class)->expense(new Request([
                'category_id'=>$category->id, 'fund_account_id'=>$this->zixuan->id,
                'amount'=>25, 'payment_method'=>'Cash', 'reference_no'=>'QA-DIRECT-WRITE',
            ]));
            $this->fail('A classified QA category must not be reusable through a direct write URL.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($beforeExpenses, DB::connection('mysql')->table('central_finance_expenses')->count());
        $this->assertSame($beforeLedger, CentralFinanceLedgerEntry::on('mysql')->count());
    }

    private function account(string $code,string $name,int $school): CentralFinanceFundAccount
    {
        $account = CentralFinanceFundAccount::on('mysql')->create(['account_uuid'=>(string)Str::uuid(),'group_id'=>1,'account_code'=>$code,'account_name'=>$name,'owner_type'=>'school','school_id'=>$school,'currency'=>'MMK','opening_balance'=>100,'is_active'=>true]);
        DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->insert([
            'fund_account_id'=>$account->id,'school_id'=>$school,'opening_allocation_amount'=>100,
            'effective_from'=>'2026-08-20','status'=>'active','is_active'=>true,
            'assigned_by'=>null,'assignment_reason'=>'Explicit workspace fixture allocation.',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        return $account;
    }
    private function grantHeadFinanceRole(): void
    {
        $roleId = DB::connection('mysql')->table('roles')->insertGetId(['name'=>'Head Finance','guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('model_has_roles')->insert(['role_id'=>$roleId,'model_type'=>User::class,'model_id'=>$this->head->id]);
    }
    private function grantAccount(CentralFinanceUser $u,CentralFinanceFundAccount $a): void { DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>$a->id,'user_id'=>$u->id,'can_view'=>true,'can_operate'=>true,'created_at'=>now(),'updated_at'=>now()]); }
    private function grantSchool(CentralFinanceUser $u,int $school,bool $head): void { DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>$u->id,'school_id'=>$school,'can_view'=>true,'can_operate'=>true,'can_approve_reimbursements'=>$head,'can_confirm_funding'=>$head,'created_at'=>now(),'updated_at'=>now()]); }
}
