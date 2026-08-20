<?php

namespace App\Console\Commands;

use App\Support\LocalQaTenantGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use App\Models\School;
use App\Models\FinanceGroup;
use App\Models\FinanceGroupUser;
use App\Services\FinanceGroupReportService;
use App\Services\FinanceGroupScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class LocalFinanceGroupQa extends Command
{
    public const TENANTS = [
        'GROUP_QA_SCHOOL_A' => 'eschool_local_group_qa_a',
        'GROUP_QA_SCHOOL_B' => 'eschool_local_group_qa_b',
        'GROUP_QA_UNRELATED' => 'eschool_local_group_qa_unrelated',
    ];

    private const SCHOOL_NAMES = [
        'GROUP_QA_SCHOOL_A' => 'Zixuan QA School',
        'GROUP_QA_SCHOOL_B' => 'Timecity QA School',
        'GROUP_QA_UNRELATED' => 'Unrelated QA School',
    ];
    protected $signature = 'local:finance-group-qa {action : reset or verify}';
    protected $description = 'Build or verify fixed local-only synthetic Finance Group QA tenants.';

    public function handle(): int
    {
        $previousDefault = DB::getDefaultConnection();
        $previousDatabase = Config::get('database.connections.school.database');

        try {
            LocalQaTenantGuard::assertEnvironment((string) app()->environment(), (string) config('app.url'), (string) config('database.connections.mysql.database'), self::TENANTS);
            return match ($this->argument('action')) { 'reset' => $this->reset(), 'verify' => $this->verify(), default => throw new \LogicException('Action must be reset or verify.') };
        } catch (\Throwable $e) { $this->error('GROUP_QA refused: '.$e->getMessage()); return self::FAILURE; }
        finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $previousDatabase);
            DB::setDefaultConnection($previousDefault);
        }
    }

    private function reset(): int
    {
        foreach (self::TENANTS as $code => $database) {
            LocalQaTenantGuard::assertTenant($code, $database, self::TENANTS);
            $this->line('Rebuilding '.$code);
            DB::connection('mysql')->statement('CREATE DATABASE IF NOT EXISTS `'.$database.'`');
            Config::set('database.connections.school.database', $database); DB::purge('school');
            \Artisan::call('migrate:fresh', ['--database'=>'school','--path'=>'database/migrations/schools','--force'=>true]);
            $this->seed($code, $database);
            $this->line($code.' rebuilt');
        }
        $this->line('Seeding central Group QA scope');
        $this->seedGroup();
        DB::purge('school');
        return $this->verify();
    }

    private function seedGroup(): void
    {
        $central = DB::connection('mysql'); $now = now();
        $this->seedLocalSidebarPermissionRecords($central, $now);
        foreach ([
            ['group_hq@group-qa.test', 'Group', 'HQ Accountant'],
            ['group_school_a@group-qa.test', 'Group', 'School A Accountant'],
            ['group_school_b@group-qa.test', 'Group', 'School B Accountant'],
        ] as [$email, $firstName, $lastName]) {
            $central->table('users')->updateOrInsert(['email' => $email], [
                'first_name' => $firstName, 'last_name' => $lastName, 'password' => bcrypt('local-only'),
                'school_id' => null, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $migration = require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php');
        $migration->up();
        $this->clearExistingGroupFixture($central);
        $scope=app(FinanceGroupScopeService::class); $group=FinanceGroup::query()->create(['code'=>'GROUP_QA', 'name'=>'Bowen QA Group','status'=>'active','reporting_currency'=>'MMK','fiscal_year_start_month'=>1]);
        $a=(int)$central->table('schools')->where('code','GROUP_QA_SCHOOL_A')->value('id');
        $b=(int)$central->table('schools')->where('code','GROUP_QA_SCHOOL_B')->value('id');
        $scope->syncSchools($group,[$a,$b]);

        $hq = $scope->addUser($group, (int) $central->table('users')->where('email','group_hq@group-qa.test')->value('id'));
        $scope->grantScope($hq,'view_reports','GROUP');
        $scope->grantScope($hq,'export_reports','GROUP');
        $scope->grantScope($hq,'operate_finance','GROUP');
        $scope->bindTenantIdentity($hq, $a, $this->tenantUserId('GROUP_QA_SCHOOL_A', 'group_hq@group-qa.test'));
        $scope->bindTenantIdentity($hq, $b, $this->tenantUserId('GROUP_QA_SCHOOL_B', 'group_hq@group-qa.test'));

        $schoolA = $scope->addUser($group, (int) $central->table('users')->where('email','group_school_a@group-qa.test')->value('id'));
        $scope->grantScope($schoolA,'view_reports','SCHOOL',$a);
        $scope->grantScope($schoolA,'export_reports','SCHOOL',$a);
        $scope->bindTenantIdentity($schoolA, $a, $this->tenantUserId('GROUP_QA_SCHOOL_A', 'accountant@GROUP_QA_SCHOOL_A.test'));

        $schoolB = $scope->addUser($group, (int) $central->table('users')->where('email','group_school_b@group-qa.test')->value('id'));
        $scope->grantScope($schoolB,'view_reports','SCHOOL',$b);
        $scope->grantScope($schoolB,'export_reports','SCHOOL',$b);
        $scope->bindTenantIdentity($schoolB, $b, $this->tenantUserId('GROUP_QA_SCHOOL_B', 'accountant@GROUP_QA_SCHOOL_B.test'));
    }

    private function seed(string $code, string $database): void
    {
        $now = now();
        $name = self::SCHOOL_NAMES[$code];
        $central = DB::connection('mysql');
        $central->table('schools')->updateOrInsert(['code'=>$code], ['name'=>$name,'database_name'=>$database,'status'=>1,'installed'=>1,'domain'=>strtolower($code).'.local','domain_type'=>'subdomain','type'=>'custom','updated_at'=>$now,'created_at'=>$now]);
        $schoolId = (int) $central->table('schools')->where('code',$code)->value('id');
        Config::set('database.connections.school.database',$database); DB::purge('school'); $db=DB::connection('school');
        $db->table('schools')->insert(['id'=>$schoolId,'name'=>$name,'status'=>1,'installed'=>1,'domain'=>strtolower($code).'.local','database_name'=>$database,'code'=>$code,'created_at'=>$now,'updated_at'=>$now]);
        foreach ([
            ['head@'.$code.'.test','Head','Finance'],
            ['accountant@'.$code.'.test','School','Accountant'],
            ['group_hq@group-qa.test','Group','HQ Accountant'],
        ] as [$email,$first,$last]) $db->table('users')->insert(['first_name'=>$first,'last_name'=>$last,'email'=>$email,'password'=>bcrypt('local-only'),'school_id'=>$schoolId,'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
        $head=(int)$db->table('users')->where('email','head@'.$code.'.test')->value('id'); $accountant=(int)$db->table('users')->where('email','accountant@'.$code.'.test')->value('id');
        $hq=(int)$db->table('users')->where('email','group_hq@group-qa.test')->value('id');
        foreach (['Head Finance','Cashier'] as $role) $db->table('roles')->insert(['name'=>$role,'guard_name'=>'web','school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
        $headRole=(int)$db->table('roles')->where('name','Head Finance')->value('id');
        foreach (['finance-payment-create','finance-expense-create','finance-dashboard-view','expense-list','expense-create','fees-paid'] as $permission) {
            $db->table('permissions')->insert(['name'=>$permission,'guard_name'=>'web','created_at'=>$now,'updated_at'=>$now]);
            $db->table('role_has_permissions')->insert(['permission_id'=>$db->table('permissions')->where('name',$permission)->value('id'),'role_id'=>$headRole]);
        }
        $db->table('model_has_roles')->insert(['role_id'=>$headRole,'model_type'=>'App\\Models\\User','model_id'=>$head]);
        $db->table('model_has_roles')->insert(['role_id'=>$headRole,'model_type'=>'App\\Models\\User','model_id'=>$hq]);
        $db->table('model_has_roles')->insert(['role_id'=>$db->table('roles')->where('name','Cashier')->value('id'),'model_type'=>'App\\Models\\User','model_id'=>$accountant]);
        foreach ([['CASH','Group QA '.$name.' Cash',1000],['BANK','Group QA '.$name.' Bank',0]] as [$num,$account,$opening]) $db->table('bank_accounts')->insert(['school_id'=>$schoolId,'account_number'=>$code.'_'.$num,'account_name'=>$account,'bank_name'=>'LOCAL ONLY','account_type'=>'cash','currency'=>'MMK','opening_balance'=>$opening,'opening_balance_date'=>'2026-01-01','is_active'=>1,'is_default'=>$num==='CASH','created_by'=>$head,'updated_by'=>$head,'created_at'=>$now,'updated_at'=>$now]);
        $cash=(int)$db->table('bank_accounts')->where('account_number',$code.'_CASH')->value('id'); $bank=(int)$db->table('bank_accounts')->where('account_number',$code.'_BANK')->value('id');
        $db->table('bank_account_user')->insert(['user_id'=>$accountant,'bank_account_id'=>$cash,'created_at'=>$now,'updated_at'=>$now]);
        if ($code !== 'GROUP_QA_UNRELATED') {
            $session=$db->table('session_years')->insertGetId(['name'=>'Group QA 2026','default'=>1,'start_date'=>'2026-01-01','end_date'=>'2026-12-31','school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $medium=$db->table('mediums')->insertGetId(['name'=>'Group QA Medium','school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]); $section=$db->table('sections')->insertGetId(['name'=>'Group QA Section','school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $class=$db->table('classes')->insertGetId(['name'=>'Group QA Class','medium_id'=>$medium,'school_id'=>$schoolId,'include_semesters'=>0,'created_at'=>$now,'updated_at'=>$now]); $classSection=$db->table('class_sections')->insertGetId(['class_id'=>$class,'section_id'=>$section,'medium_id'=>$medium,'school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $guardian=$db->table('users')->insertGetId(['first_name'=>'Group','last_name'=>'Guardian','email'=>'guardian@'.$code.'.test','password'=>bcrypt('local-only'),'school_id'=>$schoolId,'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
            $studentUser=$db->table('users')->insertGetId(['first_name'=>'Group','last_name'=>'Student','email'=>'student@'.$code.'.test','password'=>bcrypt('local-only'),'school_id'=>$schoolId,'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
            $student=$db->table('students')->insertGetId(['user_id'=>$studentUser,'guardian_id'=>$guardian,'class_id'=>$class,'class_section_id'=>$classSection,'session_year_id'=>$session,'join_session_year_id'=>$session,'admission_no'=>$code.'_STUDENT','admission_date'=>'2026-01-01','roll_number'=>1,'application_type'=>0,'application_status'=>1,'school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $fee=$db->table('fees')->insertGetId(['name'=>'Group QA Fee','currency'=>'MMK','due_date'=>'2026-08-01','due_charges'=>0,'due_charges_amount'=>0,'class_id'=>$class,'session_year_id'=>$session,'school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $type=$db->table('fees_types')->insertGetId(['name'=>'Group QA Tuition','description'=>'Synthetic','school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('fees_class_types')->insert(['fees_id'=>$fee,'fees_type_id'=>$type,'class_id'=>$class,'amount'=>200,'optional'=>0,'school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $operatingFee=$db->table('fees')->insertGetId(['name'=>'Group QA Operating Fee','currency'=>'MMK','due_date'=>'2026-08-20','due_charges'=>0,'due_charges_amount'=>0,'class_id'=>$class,'session_year_id'=>$session,'school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('fees_class_types')->insert(['fees_id'=>$operatingFee,'fees_type_id'=>$type,'class_id'=>$class,'amount'=>150,'optional'=>0,'school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $paid=$db->table('fees_paids')->insertGetId(['fees_id'=>$fee,'student_id'=>$student,'is_fully_paid'=>1,'amount'=>200,'date'=>'2026-08-01','school_id'=>$schoolId,'transaction_currency'=>'MMK','original_amount'=>200,'exchange_rate_snapshot'=>1,'amount_mmk'=>200,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('compulsory_fees')->insert(['student_id'=>$student,'type'=>'Full Payment','mode'=>'Cash','amount'=>200,'due_charges'=>0,'fees_paid_id'=>$paid,'status'=>'Success','date'=>'2026-08-01','school_id'=>$schoolId,'bank_account_id'=>$cash,'reference_no'=>$code.'_FEE','created_at'=>$now,'updated_at'=>$now]);
            $category=$db->table('expense_categories')->insertGetId(['name'=>'Group QA Category','description'=>'Synthetic','school_id'=>$schoolId,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('expenses')->insert(['category_id'=>$category,'ref_no'=>$code.'_EXPENSE','title'=>'Group QA Expense','description'=>'Synthetic','amount'=>30,'date'=>'2026-08-01','school_id'=>$schoolId,'session_year_id'=>$session,'bank_account_id'=>$cash,'created_by'=>$head,'updated_by'=>$head,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('other_incomes')->insert(['school_id'=>$schoolId,'bank_account_id'=>$cash,'date'=>'2026-08-01','payer'=>'Group QA','description'=>'Synthetic Other Income','amount'=>40,'payment_method'=>'Cash','reference_no'=>$code.'_OTHER','created_by'=>$head,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('bank_transfers')->insert(['school_id'=>$schoolId,'from_account_id'=>$cash,'to_account_id'=>$bank,'amount'=>100,'transfer_date'=>'2026-08-01','reference_no'=>$code.'_DIRECT_TRANSFER','status'=>'completed','created_by'=>$head,'created_at'=>$now,'updated_at'=>$now]);
            $handoverTransfer=$db->table('bank_transfers')->insertGetId(['school_id'=>$schoolId,'from_account_id'=>$cash,'to_account_id'=>$bank,'amount'=>50,'transfer_date'=>'2026-08-02','reference_no'=>$code.'_HANDOVER_TRANSFER','status'=>'completed','created_by'=>$head,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('fund_handovers')->insert(['school_id'=>$schoolId,'from_account_id'=>$cash,'to_account_id'=>$bank,'sender_id'=>$head,'receiver_id'=>$accountant,'amount'=>50,'handover_date'=>'2026-08-02','reference_no'=>$code.'_HANDOVER_CONFIRMED','status'=>'confirmed','bank_transfer_id'=>$handoverTransfer,'confirmed_at'=>$now,'confirmed_by'=>$accountant,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('fund_handovers')->insert(['school_id'=>$schoolId,'from_account_id'=>$cash,'to_account_id'=>$bank,'sender_id'=>$head,'receiver_id'=>$accountant,'amount'=>25,'handover_date'=>'2026-08-03','reference_no'=>$code.'_HANDOVER_PENDING','status'=>'pending','created_at'=>$now,'updated_at'=>$now]);
        }
    }

    /**
     * Reset must be repeatable even when a previous local browser test created
     * Group funding fixtures. This method touches only the fixed synthetic
     * GROUP_QA central records after the local-only guard has already passed.
     */
    private function clearExistingGroupFixture($central): void
    {
        $groupId = FinanceGroup::query()->where('code', 'GROUP_QA')->value('id');
        if (!$groupId) {
            return;
        }

        if (Schema::connection('mysql')->hasTable('finance_group_transfers')) {
            $central->table('finance_group_transfers')->where('group_id', $groupId)->delete();
        }
        if (Schema::connection('mysql')->hasTable('finance_group_hq_accounts')) {
            $hqAccountIds = $central->table('finance_group_hq_accounts')->where('group_id', $groupId)->pluck('id');
            if ($hqAccountIds->isNotEmpty() && Schema::connection('mysql')->hasTable('finance_group_hq_account_adjustments')) {
                $central->table('finance_group_hq_account_adjustments')->whereIn('hq_account_id', $hqAccountIds)->delete();
            }
            if ($hqAccountIds->isNotEmpty() && Schema::connection('mysql')->hasTable('finance_group_hq_account_users')) {
                $central->table('finance_group_hq_account_users')->whereIn('hq_account_id', $hqAccountIds)->delete();
            }
            $central->table('finance_group_hq_accounts')->whereIn('id', $hqAccountIds)->delete();
        }
        $central->table('finance_group_user_tenant_identities')
            ->whereIn('group_user_id', $central->table('finance_group_users')->where('group_id', $groupId)->pluck('id'))
            ->delete();
        $central->table('finance_group_user_scopes')
            ->whereIn('group_user_id', $central->table('finance_group_users')->where('group_id', $groupId)->pluck('id'))
            ->delete();
        $central->table('finance_group_users')->where('group_id', $groupId)->delete();
        $central->table('finance_group_schools')->where('group_id', $groupId)->delete();
        $central->table('finance_groups')->where('id', $groupId)->delete();
    }

    private function verify(): int
    {
        $before = $this->snapshot();
        foreach (self::TENANTS as $code => $database) {
            LocalQaTenantGuard::assertTenant($code, $database, self::TENANTS);
            Config::set('database.connections.school.database', $database); DB::purge('school');
            foreach (['schools','users','bank_accounts','bank_transfers','fund_handovers'] as $table) if (!DB::connection('school')->getSchemaBuilder()->hasTable($table)) throw new \LogicException($code.' missing '.$table);
            if ($db=DB::connection('school')) {
                if ($db->table('users')->count() < 2 || $db->table('bank_accounts')->count() < 2) throw new \LogicException($code.' fixture users/accounts missing');
                $expected=$code === 'GROUP_QA_UNRELATED' ? 0 : 2;
                if ($db->table('bank_transfers')->where('status','completed')->count() !== $expected) throw new \LogicException($code.' transfer fixture mismatch');
                $hasFinanceFixture=$code !== 'GROUP_QA_UNRELATED';
                if ($db->table('fund_handovers')->where('status','pending')->count() !== ($hasFinanceFixture ? 1 : 0)) throw new \LogicException($code.' pending handover fixture mismatch');
                if ($hasFinanceFixture && ($db->table('compulsory_fees')->count() !== 1 || $db->table('other_incomes')->count() !== 1 || $db->table('expenses')->count() !== 1 || $db->table('fund_handovers')->where('status','confirmed')->count() !== 1)) throw new \LogicException($code.' finance source fixture mismatch');
            }
        }
        $after = $this->snapshot(); if ($before !== $after) throw new \LogicException('Verify attempted a fixture financial write.');
        $central=DB::connection('mysql'); $group=FinanceGroup::query()->where('code','GROUP_QA')->first(); if (!$group || $group->schools()->where('status','active')->count() !== 2 || $group->schools()->whereHas('school',fn($q)=>$q->where('code','GROUP_QA_UNRELATED'))->exists()) throw new \LogicException('Group scope fixture mismatch.');
        $a = (int) $central->table('schools')->where('code', 'GROUP_QA_SCHOOL_A')->value('id');
        $b = (int) $central->table('schools')->where('code', 'GROUP_QA_SCHOOL_B')->value('id');
        $hq = FinanceGroupUser::query()->where('group_id', $group->id)->where('central_user_id', $central->table('users')->where('email', 'group_hq@group-qa.test')->value('id'))->firstOrFail();
        $register = app(FinanceGroupReportService::class)->register($hq);
        if ($register['incomplete']->isNotEmpty() || $register['schools']->count() !== 2
            || (float) $register['summary']['operating_income'] !== 480.0
            || (float) $register['summary']['operating_expense'] !== 60.0
            || (float) $register['summary']['internal_transfer_amount'] !== 300.0
            || $register['rows']->where('source_type', 'bank_transfer')->count() !== 4
            || $register['rows']->contains(fn (array $row) => str_contains((string) $row['reference_no'], 'HANDOVER_PENDING'))) {
            throw new \LogicException('Group Ledger V1 fixture semantics mismatch.');
        }
        foreach ([
            ['group_school_a@group-qa.test', 'GROUP_QA_SCHOOL_A'],
            ['group_school_b@group-qa.test', 'GROUP_QA_SCHOOL_B'],
        ] as [$email, $schoolCode]) {
            $groupUser = FinanceGroupUser::query()->where('group_id', $group->id)->where('central_user_id', $central->table('users')->where('email', $email)->value('id'))->firstOrFail();
            $schoolId = (int) $central->table('schools')->where('code', $schoolCode)->value('id');
            $scoped = app(FinanceGroupReportService::class)->register($groupUser);
            if ($scoped['schools']->count() !== 1 || (int) $scoped['schools']->sole()['school_id'] !== $schoolId) {
                throw new \LogicException('School-scoped Group reporter can see another tenant.');
            }
            try {
                app(FinanceGroupReportService::class)->register($groupUser, ['school_id' => $schoolCode === 'GROUP_QA_SCHOOL_A' ? $b : $a]);
                throw new \LogicException('Forged Group School scope was accepted.');
            } catch (AuthorizationException) {
                // The explicit School scope correctly rejects its peer tenant.
            }
            try {
                app(FinanceGroupReportService::class)->register($groupUser, [
                    'school_id' => $schoolId,
                    'bank_account_id' => $this->tenantAccountId($schoolCode, $schoolCode.'_BANK'),
                ]);
                throw new \LogicException('Cashier Group identity accessed an unassigned Fund Account.');
            } catch (ModelNotFoundException) {
                // The school Accountant identity remains restricted to its
                // explicit bank_account_user CASH assignment.
            }
        }
        DB::purge('school'); $this->info('GROUP_QA verified: fixed local tenants, scope fixture, and zero-write snapshot.'); return self::SUCCESS;
    }

    /** @return array<string,string> */
    private function snapshot(): array
    {
        $out=[];
        foreach (self::TENANTS as $code=>$database) {
            Config::set('database.connections.school.database',$database);
            DB::purge('school');
            $db=DB::connection('school');
            $parts=[];
            foreach (['bank_accounts','compulsory_fees','other_incomes','expenses','bank_transfers','fund_handovers','bank_account_user','finance_operating_audits'] as $table) {
                $rows = $db->table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
                $parts[] = $table.':'.count($rows).':'.hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            }
            $out[$code]=hash('sha256',implode('|',$parts));
        }
        return $out;
    }

    private function tenantUserId(string $code, string $email): int
    {
        $database = self::TENANTS[$code] ?? null;
        if (!$database) {
            throw new \LogicException('Unknown fixed Group QA tenant.');
        }
        LocalQaTenantGuard::assertTenant($code, $database, self::TENANTS);
        Config::set('database.connections.school.database', $database);
        DB::purge('school');

        $id = (int) DB::connection('school')->table('users')->where('email', $email)->value('id');
        if ($id <= 0) {
            throw new \LogicException('Required Group QA tenant identity is missing.');
        }

        return $id;
    }

    private function tenantAccountId(string $code, string $number): int
    {
        $database = self::TENANTS[$code] ?? null;
        if (!$database) {
            throw new \LogicException('Unknown fixed Group QA tenant.');
        }
        LocalQaTenantGuard::assertTenant($code, $database, self::TENANTS);
        Config::set('database.connections.school.database', $database);
        DB::purge('school');

        $id = (int) DB::connection('school')->table('bank_accounts')->where('account_number', $number)->value('id');
        if ($id <= 0) {
            throw new \LogicException('Required Group QA Fund Account is missing.');
        }

        return $id;
    }

    /**
     * The normal central installation provisioner owns these permission
     * records. A bare local developer database may not have been installed
     * through it, which makes Spatie throw while the shared layout evaluates
     * an otherwise-denied @can directive. Seed records only (never grants)
     * for the fixed synthetic central browser identity.
     */
    private function seedLocalSidebarPermissionRecords($central, $now): void
    {
        $sidebar = File::get(resource_path('views/layouts/sidebar.blade.php'));
        preg_match_all("/@can\\(['\"]([^'\"]+)/", $sidebar, $matches);
        foreach (array_unique($matches[1]) as $name) {
            $central->table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
