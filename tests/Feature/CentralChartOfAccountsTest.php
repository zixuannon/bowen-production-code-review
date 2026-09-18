<?php

namespace Tests\Feature;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceUser;
use App\Models\User;
use App\Services\CentralChartOfAccountsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CentralChartOfAccountsTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = tempnam(sys_get_temp_dir(), 'coa_');
        Config::set('database.connections.mysql', ['driver'=>'sqlite','database'=>$this->db,'prefix'=>'','foreign_key_constraints'=>true]);
        DB::purge('mysql'); DB::setDefaultConnection('mysql');
        Schema::create('schools', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('code'); $t->softDeletes(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('school_id')->nullable(); $t->string('first_name'); $t->string('last_name')->nullable(); $t->softDeletes(); $t->timestamps(); });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps(); });
        Schema::create('model_has_roles', function (Blueprint $t) { $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('model_id'); $t->string('model_type'); });
        Schema::create('central_finance_user_school_scopes', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('school_id'); $t->boolean('can_view'); $t->boolean('can_operate'); });
        (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up();
        (require database_path('migrations/2026_08_21_000002_create_central_finance_operating_documents.php'))->up();
        (require database_path('migrations/2026_09_02_000002_add_category_codes_for_group_finance_import.php'))->up();
        (require database_path('migrations/2026_09_18_000001_add_central_chart_of_accounts.php'))->up();
        DB::table('finance_groups')->insert(['id'=>1,'name'=>'Bowen','code'=>'BOWEN','status'=>'active']);
        DB::table('users')->insert([['id'=>100,'school_id'=>null,'first_name'=>'Head'],['id'=>101,'school_id'=>1,'first_name'=>'Principal']]);
        DB::table('roles')->insert(['id'=>1,'name'=>'Head Finance','guard_name'=>'web']);
        DB::table('model_has_roles')->insert(['role_id'=>1,'model_id'=>100,'model_type'=>User::class]);
        DB::table('finance_group_users')->insert(['id'=>1,'group_id'=>1,'central_user_id'=>100,'status'=>'active']);
        foreach (['view_reports','operate_finance','manage_hq_accounts'] as $capability) DB::table('finance_group_user_scopes')->insert(['group_user_id'=>1,'scope_type'=>'GROUP','scope_key'=>'group','capability'=>$capability,'status'=>'active']);
        foreach ([1,2,3] as $id) {
            DB::table('schools')->insert(['id'=>$id,'name'=>'School '.$id,'code'=>'MMBOWEN0'.$id]);
            DB::table('finance_group_schools')->insert(['group_id'=>1,'school_id'=>$id,'status'=>'active']);
            DB::table('central_finance_user_school_scopes')->insert(['user_id'=>100,'school_id'=>$id,'can_view'=>1,'can_operate'=>1]);
        }
    }

    protected function tearDown(): void { DB::purge('mysql'); @unlink($this->db); parent::tearDown(); }

    private function input(array $override = []): array { return array_replace(['group_id'=>1,'type'=>'income','category_code'=>'4001','name'=>'Tuition','is_active'=>true,'school_ids'=>[1,2],'reason'=>'QA explicit group definition'], $override); }
    private function save(array $input = [], ?int $id = null): CentralFinanceCategory { return app(CentralChartOfAccountsService::class)->save(CentralFinanceUser::findOrFail(100),$this->input($input),$id); }

    public function test_one_definition_multiple_schools_and_audited_idempotent_allocations(): void
    {
        $account=$this->save();
        $this->assertNull($account->school_id); $this->assertSame('4001',$account->category_code);
        $this->assertTrue($account->availableToSchool(1)); $this->assertTrue($account->availableToSchool(2)); $this->assertFalse($account->availableToSchool(3));
        $this->save([], $account->id); $this->assertSame(1,CentralFinanceCategory::count());
        $this->assertSame(2,DB::table('central_finance_category_school_allocations')->count());
        $this->assertSame(2,DB::table('central_finance_category_audits')->count());
        $this->assertSame(0,DB::table('central_finance_expenses')->count()); $this->assertSame(0,DB::table('central_finance_other_incomes')->count());
    }

    public function test_five_types_and_leading_zero_and_cash_direction(): void
    {
        foreach (CentralFinanceCategory::TYPES as $i=>$type) {
            $account=$this->save(['category_code'=>'010'.$i,'type'=>$type,'name'=>$type]);
            $this->assertSame('010'.$i,$account->fresh()->category_code);
            $this->assertSame($type!=='expense',$account->allowsCashDirection('income'));
            $this->assertSame($type!=='income',$account->allowsCashDirection('expense'));
        }
        $this->assertSame(4,CentralFinanceCategory::availableForSchool(1)->forCashDirection('income')->count());
        $this->assertSame(4,CentralFinanceCategory::availableForSchool(1)->forCashDirection('expense')->count());
    }

    public function test_code_unique_across_types_in_group(): void
    {
        $this->save(); $this->expectException(ValidationException::class); $this->save(['type'=>'expense']);
    }

    public function test_revoked_allocation_group_membership_and_inactive_definition_fail_closed(): void
    {
        $account=$this->save();
        DB::table('finance_group_schools')->where('school_id',1)->update(['status'=>'revoked']);
        $this->assertFalse($account->availableToSchool(1)); $this->assertTrue($account->availableToSchool(2));
        DB::table('finance_group_schools')->where('school_id',1)->update(['status'=>'active']);
        $this->save(['school_ids'=>[2],'is_active'=>false],$account->id);
        $this->assertFalse($account->fresh()->availableToSchool(2));
    }

    public function test_principal_cannot_create_or_change_accounts(): void
    {
        $this->expectException(AuthorizationException::class);
        app(CentralChartOfAccountsService::class)->save(CentralFinanceUser::findOrFail(101),$this->input());
    }

    public function test_cross_group_school_allocation_rejected_without_writes(): void
    {
        DB::table('finance_group_schools')->where('school_id',2)->update(['status'=>'revoked']);
        try { $this->save(); $this->fail('Expected rejection'); } catch (ValidationException|AuthorizationException $e) {}
        $this->assertSame(0,CentralFinanceCategory::count()); $this->assertSame(0,DB::table('central_finance_category_audits')->count());
    }

    public function test_account_code_is_editable_and_audited_but_type_cannot_rewrite_history(): void
    {
        $account=$this->save(['category_code'=>'0101']);
        $id = $account->id;
        $allocations = DB::table('central_finance_category_school_allocations')->orderBy('school_id')->pluck('school_id')->all();
        $this->save(['category_code'=>'0102','reason'=>'Correct the published code'], $account->id);
        $account->refresh();
        $this->assertSame($id, $account->id);
        $this->assertSame('0102', $account->category_code);
        $this->assertSame($allocations, DB::table('central_finance_category_school_allocations')->orderBy('school_id')->pluck('school_id')->all());
        $audit = DB::table('central_finance_category_audits')->where('action','account_code_updated')->sole();
        $this->assertSame(100, (int) $audit->actor_id);
        $this->assertSame('Correct the published code', $audit->reason);
        $this->assertSame('0101', json_decode($audit->before, true, 512, JSON_THROW_ON_ERROR)['category_code']);
        $this->assertSame('0102', json_decode($audit->after, true, 512, JSON_THROW_ON_ERROR)['category_code']);
        $this->assertSame(0, DB::table('central_finance_expenses')->count());
        $this->assertSame(0, DB::table('central_finance_other_incomes')->count());
        try { $this->save(['category_code'=>'0102','name'=>'Duplicate','reason'=>'Must reject duplicate'], null); $this->fail('Expected duplicate Account Code rejection'); } catch (ValidationException $e) {}
        try { $this->save(['type'=>'liability'],$account->id); $this->fail('Expected rejection'); } catch (ValidationException $e) {}
        $this->assertSame('income',$account->fresh()->type); $this->assertSame(2,DB::table('central_finance_category_audits')->count());
    }

    public function test_legacy_school_definition_remains_scoped_without_rewriting_id_or_code(): void
    {
        $legacy=CentralFinanceCategory::create(['school_id'=>3,'type'=>'income','name'=>'Legacy tuition','category_code'=>'LEGACY-03','is_active'=>true]);
        $this->assertTrue($legacy->availableToSchool(3)); $this->assertFalse($legacy->availableToSchool(1));
        $this->assertSame('LEGACY-03',$legacy->fresh()->category_code); $this->assertNull($legacy->group_id);
    }

    public function test_partial_or_preexisting_schema_never_silently_succeeds(): void
    {
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_09_18_000001_add_central_chart_of_accounts.php'))->up();
    }

    public function test_missing_unique_constraint_fails_before_any_definition_or_audit_write(): void
    {
        Schema::table('central_finance_category_school_allocations',fn (Blueprint $table)=>$table->dropUnique('coa_allocation_unique'));
        try { $this->save(); $this->fail('Expected incomplete schema rejection'); } catch (\RuntimeException $e) { $this->assertStringContainsString('unique constraint',$e->getMessage()); }
        $this->assertSame(0,CentralFinanceCategory::count()); $this->assertSame(0,DB::table('central_finance_category_audits')->count());
    }

    public function test_partial_school_scope_cannot_revoke_unseen_allocation_or_edit_shared_definition(): void
    {
        $account=$this->save();
        $beforeCategory=(array)DB::table('central_finance_categories')->where('id',$account->id)->first();
        $beforeAllocations=DB::table('central_finance_category_school_allocations')->orderBy('id')->get()->toJson();
        DB::table('central_finance_user_school_scopes')->where(['user_id'=>100,'school_id'=>2])->update(['can_operate'=>false]);
        try { $this->save(['school_ids'=>[1],'name'=>'Unauthorized partial edit'],$account->id); $this->fail('Expected omitted School authorization rejection'); } catch (AuthorizationException $e) {}
        $this->assertSame($beforeCategory,(array)DB::table('central_finance_categories')->where('id',$account->id)->first());
        $this->assertSame($beforeAllocations,DB::table('central_finance_category_school_allocations')->orderBy('id')->get()->toJson());
        $this->assertSame(1,DB::table('central_finance_category_audits')->count());
    }

    public function test_create_and_edit_fields_render_complete_blade_and_preserve_text_code(): void
    {
        $data=['formKey'=>'qa','configurableGroups'=>\App\Models\FinanceGroup::all(),'schools'=>\App\Models\School::all()];
        $new=view('central-finance.chart-of-accounts-fields',array_merge($data,['coa'=>null]))->render();
        $this->assertStringContainsString('name="category_code"',$new); $this->assertStringNotContainsString('@endforeach',$new);
        $account=$this->save(['category_code'=>'0101']);
        $edit=view('central-finance.chart-of-accounts-fields',array_merge($data,['coa'=>$account]))->render();
        $this->assertStringContainsString('value="0101"',$edit); $this->assertStringNotContainsString('readonly',$edit);
        $this->assertStringNotContainsString('Account Code and Type are locked',$edit);
    }

    public function test_reimbursement_requires_matching_group_operate_scope_before_any_write(): void
    {
        (require database_path('migrations/2026_08_21_000005_create_central_finance_school_cutovers.php'))->up();
        (require database_path('migrations/2026_08_26_000001_complete_central_finance_reimbursement_workflow.php'))->up();
        DB::table('central_finance_school_cutovers')->insert(['school_id'=>1,'status'=>'central']);
        $category=$this->save(['type'=>'expense']);
        DB::table('finance_group_user_scopes')->where('capability','operate_finance')->update(['status'=>'revoked']);
        $service=app(\App\Services\CentralFinanceReimbursementService::class);
        try { $service->submit(CentralFinanceUser::findOrFail(100),1,$category->id,10,'MMK','COA-REQ-1','QA group scope'); $this->fail('Expected group scope rejection'); } catch (AuthorizationException $e) { $this->assertStringContainsString('Chart Account Finance Group',$e->getMessage()); }
        $this->assertSame(0,DB::table('central_finance_reimbursement_requests')->count());
        $this->assertSame(0,DB::table('central_finance_document_audits')->count());
        DB::table('finance_group_user_scopes')->where('capability','operate_finance')->update(['status'=>'active']);
        $request=$service->submit(CentralFinanceUser::findOrFail(100),1,$category->id,10,'MMK','COA-REQ-1','QA group scope');
        $this->assertSame('pending',$request->status);
        $this->assertSame(1,DB::table('central_finance_reimbursement_requests')->count());
        $this->assertSame(0,DB::table('central_finance_expenses')->count());
    }

    public function test_actor_dropdown_group_scope_does_not_leak_another_groups_accounts_in_same_school(): void
    {
        $own=$this->save();
        DB::table('finance_groups')->insert(['id'=>2,'name'=>'Another Group','code'=>'OTHER','status'=>'active']);
        DB::table('finance_group_schools')->insert(['group_id'=>2,'school_id'=>1,'status'=>'active']);
        $other=CentralFinanceCategory::create(['group_id'=>2,'school_id'=>null,'type'=>'income','category_code'=>'4001','name'=>'Other private tuition','is_active'=>true]);
        DB::table('central_finance_category_school_allocations')->insert(['category_id'=>$other->id,'school_id'=>1,'is_active'=>true]);
        $legacy=CentralFinanceCategory::create(['school_id'=>1,'type'=>'income','category_code'=>'LEGACY-01','name'=>'Legacy own school','is_active'=>true]);
        $this->assertTrue($other->availableToSchool(1));
        $this->assertEqualsCanonicalizing([$own->id,$legacy->id],CentralFinanceCategory::forActor(CentralFinanceUser::findOrFail(100),[1],true)->pluck('id')->all());
        DB::table('finance_group_users')->insert(['id'=>2,'group_id'=>1,'central_user_id'=>101,'status'=>'active']);
        DB::table('finance_group_user_scopes')->insert(['group_user_id'=>2,'scope_type'=>'SCHOOL','scope_key'=>'school:1','school_id'=>1,'capability'=>'view_reports','status'=>'active']);
        DB::table('central_finance_user_school_scopes')->insert(['user_id'=>101,'school_id'=>1,'can_view'=>true,'can_operate'=>false]);
        $principal=CentralFinanceUser::findOrFail(101);
        $this->assertEqualsCanonicalizing([$own->id,$legacy->id],CentralFinanceCategory::forActor($principal,[1])->pluck('id')->all());
        $this->assertSame(0,CentralFinanceCategory::forActor($principal,[1],true)->count());
        $this->assertSame(0,CentralFinanceCategory::forActor($principal,[2])->count());
    }
}
