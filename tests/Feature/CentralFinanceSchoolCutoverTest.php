<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\CentralFinanceUser;
use App\Models\User;
use App\Services\CentralFinanceSchoolCutoverService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class CentralFinanceSchoolCutoverTest extends TestCase
{
    private string $database;
    private CentralFinanceUser $headFinance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_cutover_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('schools', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('code'), $t->string('database_name')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::connection('mysql')->create('users', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::connection('mysql')->create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name'), $t->unsignedBigInteger('school_id')->nullable(), $t->timestamps()]);
        Schema::connection('mysql')->create('model_has_roles', fn (Blueprint $t) => [$t->unsignedBigInteger('role_id'), $t->string('model_type'), $t->unsignedBigInteger('model_id')]);
        foreach (['2026_08_18_000001_create_finance_group_scope_tables.php','2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php','2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php','2026_08_21_000002_create_central_finance_operating_documents.php','2026_08_21_000003_create_central_finance_internal_transfer_documents.php','2026_08_21_000005_create_central_finance_school_cutovers.php','2026_08_21_000006_create_central_finance_opening_balance_audits.php','2026_08_24_000002_create_central_finance_school_staff_identities.php','2026_08_25_000004_add_readiness_approval_audit_to_central_finance_school_cutovers.php','2026_08_25_000005_add_fresh_start_receivable_cutoff.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Zixuan', 'code' => 'SCH202615', 'database_name' => 'local_zixuan', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Timecity', 'code' => 'SCH202619', 'database_name' => 'local_timecity', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('users')->insert([['id'=>100,'school_id'=>null,'central_finance_principal_type'=>'central_user','first_name'=>'Head','last_name'=>'Finance','created_at'=>now(),'updated_at'=>now()], ['id'=>101,'school_id'=>1,'central_finance_principal_type'=>'school_staff_identity','first_name'=>'Zixuan','last_name'=>'Accountant','created_at'=>now(),'updated_at'=>now()]]);
        DB::connection('mysql')->table('roles')->insert(['id'=>1,'name'=>'Head Finance','guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('model_has_roles')->insert(['role_id'=>1,'model_type'=>User::class,'model_id'=>100]);
        $this->headFinance = CentralFinanceUser::on('mysql')->findOrFail(100);
        DB::connection('mysql')->table('finance_groups')->insert(['id'=>1,'name'=>'QA Group','code'=>'QA','status'=>'active','reporting_currency'=>'MMK','fiscal_year_start_month'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('finance_group_schools')->insert(['group_id'=>1,'school_id'=>1,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('finance_group_users')->insert(['id'=>1,'group_id'=>1,'central_user_id'=>100,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('finance_group_user_scopes')->insert([
            ['group_user_id'=>1,'scope_type'=>'GROUP','scope_key'=>'group','capability'=>'view_reports','status'=>'active','created_at'=>now(),'updated_at'=>now()],
            ['group_user_id'=>1,'scope_type'=>'GROUP','scope_key'=>'group','capability'=>'operate_finance','status'=>'active','created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>100,'school_id'=>1,'can_view'=>true,'can_operate'=>true,'can_approve_reimbursements'=>false,'can_confirm_funding'=>false,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>101,'school_id'=>1,'can_view'=>true,'can_operate'=>true,'can_approve_reimbursements'=>false,'can_confirm_funding'=>false,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('central_finance_fund_accounts')->insert(['id'=>1,'account_uuid'=>'11111111-1111-4111-8111-111111111111','group_id'=>1,'school_id'=>1,'owner_type'=>'school','account_code'=>'ZIX-CASH','account_name'=>'Zixuan Cash','currency'=>'MMK','opening_balance'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('central_finance_fund_account_opening_balance_audits')->insert(['fund_account_id'=>1,'change_type'=>'initial','new_opening_balance'=>0,'effective_date'=>'2026-08-21','reason'=>'Signed zero opening','created_by'=>100,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>1,'user_id'=>100,'can_view'=>true,'can_operate'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>1,'user_id'=>101,'can_view'=>true,'can_operate'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('central_finance_school_staff_identities')->insert(['identity_uuid'=>(string) Str::uuid(),'school_id'=>1,'tenant_user_uuid'=>(string) Str::uuid(),'central_user_id'=>101,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        DB::connection('mysql')->table('central_finance_school_cutovers')->insert([
            ['school_id'=>1,'status'=>'legacy','receivable_sync_effective_at'=>'2026-08-21 00:00:00','receivable_sync_effective_by'=>100,'receivable_sync_effective_reason'=>'Approved Fresh Start QA boundary','created_at'=>now(),'updated_at'=>now()],
            ['school_id'=>2,'status'=>'legacy','receivable_sync_effective_at'=>'2026-08-21 00:00:00','receivable_sync_effective_by'=>100,'receivable_sync_effective_reason'=>'Approved Fresh Start QA boundary','created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql'); @unlink($this->database); parent::tearDown();
    }

    public function test_zixuan_can_cut_over_while_timecity_remains_legacy_and_tenant_writes_are_isolated(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $zixuan = School::on('mysql')->findOrFail(1);

        $cutovers->transition($this->headFinance, $zixuan, 'ready');
        $this->assertFalse($cutovers->allowsCentralWrites(1));
        $cutovers->assertTenantFinanceWritesAllowed($this->tenantActor(1));
        $this->assertSame('legacy', $cutovers->statusForSchool(2));
        $cutovers->transition($this->headFinance, $zixuan, 'central');

        $this->assertTrue($cutovers->allowsCentralWrites(1));
        $this->assertFalse($cutovers->allowsCentralWrites(2));
        $this->expectException(AuthorizationException::class);
        $cutovers->assertTenantFinanceWritesAllowed($this->tenantActor(1));
    }

    public function test_timecity_legacy_tenant_writes_remain_allowed_after_zixuan_cutover(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $school = School::on('mysql')->findOrFail(1);
        $cutovers->transition($this->headFinance, $school, 'ready');
        $cutovers->transition($this->headFinance, $school, 'central');
        $cutovers->assertTenantFinanceWritesAllowed($this->tenantActor(2));
        $this->assertSame('legacy', $cutovers->statusForSchool(2));
    }

    public function test_central_to_legacy_rollback_is_allowed_only_before_real_central_financial_activity(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $school = School::on('mysql')->findOrFail(1);
        $cutovers->transition($this->headFinance, $school, 'ready');
        $cutovers->transition($this->headFinance, $school, 'central');
        $this->assertSame('legacy', $cutovers->transition($this->headFinance, $school, 'legacy')->status);

        $cutovers->transition($this->headFinance, $school, 'ready');
        $cutovers->transition($this->headFinance, $school, 'central');
        DB::connection('mysql')->table('central_finance_ledger_entries')->insert(['entry_uuid'=>(string) Str::uuid(),'school_id'=>1,'fund_account_id'=>1,'entry_date'=>'2026-08-21','occurred_at'=>now(),'source_type'=>'test','source_id'=>'real-1','source_line'=>'main','transaction_type'=>'operating_income','currency'=>'MMK','money_in'=>1,'money_out'=>0,'operating_income'=>1,'operating_expense'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $this->expectException(LogicException::class);
        $cutovers->transition($this->headFinance, $school, 'legacy');
    }

    public function test_cutover_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_08_21_000005_create_central_finance_school_cutovers.php');
        $migration->down();
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_school_cutovers'));
        $migration->up();
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_school_cutovers'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('schools'));
    }

    public function test_fresh_start_cutoff_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_08_25_000005_add_fresh_start_receivable_cutoff.php');
        $migration->down();
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_school_cutovers', 'receivable_sync_effective_at'));
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_receivables', 'source_created_at'));
        $migration->up();
        $this->assertTrue(Schema::connection('mysql')->hasColumn('central_finance_school_cutovers', 'receivable_sync_effective_at'));
        $this->assertTrue(Schema::connection('mysql')->hasColumn('central_finance_receivables', 'source_created_at'));
    }

    public function test_missing_fresh_start_receivable_cutoff_blocks_ready(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $school = School::on('mysql')->findOrFail(1);
        DB::connection('mysql')->table('central_finance_school_cutovers')->where('school_id', 1)->update([
            'receivable_sync_effective_at' => null,
            'receivable_sync_effective_by' => null,
            'receivable_sync_effective_reason' => null,
        ]);

        $this->expectException(LogicException::class);
        $cutovers->transition($this->headFinance, $school, 'ready');
    }

    public function test_explicit_fresh_start_receivable_cutoff_is_audited_and_freezes_after_ready(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $school = School::on('mysql')->findOrFail(1);
        $row = $cutovers->setReceivableSyncEffectiveAt($this->headFinance, $school, \Carbon\CarbonImmutable::parse('2026-08-21 00:00:00'), 'Approved Fresh Start boundary');
        $this->assertSame(100, (int) $row->receivable_sync_effective_by);
        $this->assertSame('ready', $cutovers->transition($this->headFinance, $school, 'ready')->status);
        $this->expectException(LogicException::class);
        $cutovers->setReceivableSyncEffectiveAt($this->headFinance, $school, \Carbon\CarbonImmutable::parse('2026-08-22 00:00:00'), 'Unsafe late change');
    }

    public function test_fresh_start_cutoff_uses_yangon_business_time_even_when_the_application_timezone_differs(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);

        $effectiveAt = CentralFinanceSchoolCutoverService::parseReceivableSyncEffectiveAt('2026-09-01T00:00');

        $this->assertSame('Asia/Yangon', $effectiveAt->getTimezone()->getName());
        $this->assertSame('2026-08-31 17:30:00', $effectiveAt->utc()->format('Y-m-d H:i:s'));
    }

    private function tenantActor(int $schoolId): User
    {
        $actor = new User();
        $actor->id = 900 + $schoolId;
        $actor->school_id = $schoolId;
        return $actor;
    }
}
