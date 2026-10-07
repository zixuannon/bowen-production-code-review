<?php
namespace Tests\Feature;
use App\Http\Controllers\CentralFinanceStudentCollectionController;
use App\Http\Controllers\CentralFinancePendingCollectionController;
use App\Http\Controllers\CentralFinanceWorkspaceController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceCollectionHandoverBatch; use App\Models\CentralFinanceDataClassification; use App\Models\CentralFinanceFundAccount; use App\Models\CentralFinancePayment; use App\Models\CentralFinancePendingCollection; use App\Models\CentralFinanceReceivable; use App\Models\CentralFinanceStudentProfile; use App\Models\CentralFinanceUser; use App\Models\School; use App\Services\CentralFinanceCollectionHandoverService; use App\Services\CentralFinanceDataIsolationService; use App\Services\CentralFinanceFundAccountAdministrationService; use App\Services\CentralFinanceFundAccountBalanceService; use App\Services\CentralFinanceHeadFinanceHandoverConfirmService; use App\Services\CentralFinancePaymentService; use App\Services\CentralFinancePendingCollectionConfirmationService; use App\Services\CentralFinancePendingCollectionService; use App\Services\CentralFinanceReceivableSyncService; use App\Services\CentralFinanceWorkspaceService; use Carbon\CarbonImmutable; use Illuminate\Auth\Access\AuthorizationException; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Config; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema; use Illuminate\Support\Facades\Session; use Illuminate\Support\Str; use InvalidArgumentException; use Symfony\Component\HttpKernel\Exception\HttpException; use Tests\TestCase;

class CentralFinanceReceivablePaymentTest extends TestCase {
 private string $central; private string $a; private string $b; private CentralFinanceUser $head; private CentralFinanceUser $accountant; private CentralFinanceFundAccount $hq; private CentralFinanceFundAccount $zix; private CentralFinanceStudentProfile $zixProfile; private CentralFinanceStudentProfile $timeProfile;
 protected function setUp(): void { parent::setUp(); @mkdir(storage_path('framework/views'),0777,true); $this->central=tempnam(sys_get_temp_dir(),'cf_recv_c_'); $this->a=tempnam(sys_get_temp_dir(),'cf_recv_a_'); $this->b=tempnam(sys_get_temp_dir(),'cf_recv_b_'); Config::set('database.connections.mysql',['driver'=>'sqlite','database'=>$this->central,'prefix'=>'','foreign_key_constraints'=>true]); Config::set('database.connections.school',['driver'=>'sqlite','database'=>$this->a,'prefix'=>'','foreign_key_constraints'=>true]); DB::purge('mysql'); DB::purge('school'); DB::setDefaultConnection('mysql');
  Schema::connection('mysql')->create('schools',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->string('code')->nullable(),$t->string('database_name')->nullable(),$t->softDeletes(),$t->timestamps()]); Schema::connection('mysql')->create('users',fn(Blueprint $t)=>[$t->id(),$t->string('first_name')->nullable(),$t->string('last_name')->nullable(),$t->unsignedBigInteger('school_id')->nullable(),$t->softDeletes(),$t->timestamps()]); Schema::connection('mysql')->create('roles',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->string('guard_name')->default('web'),$t->timestamps()]); Schema::connection('mysql')->create('model_has_roles',fn(Blueprint $t)=>[$t->unsignedBigInteger('role_id'),$t->string('model_type'),$t->unsignedBigInteger('model_id')]);
  (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up(); (require database_path('migrations/2026_08_20_000003_create_central_finance_student_sync_tables.php'))->up(); (require database_path('migrations/2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php'))->up(); (require database_path('migrations/2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php'))->up(); (require database_path('migrations/2026_08_26_000002_add_master_data_to_central_finance_fund_accounts.php'))->up(); (require database_path('migrations/2026_09_03_000001_create_central_finance_fund_account_school_allocations.php'))->up(); (require database_path('migrations/2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php'))->up(); (require database_path('migrations/2026_08_25_000002_create_central_finance_payment_refunds.php'))->up(); (require database_path('migrations/2026_08_21_000002_create_central_finance_operating_documents.php'))->up(); (require database_path('migrations/2026_08_27_000002_add_note_to_central_finance_payments.php'))->up(); (require database_path('migrations/2026_08_21_000005_create_central_finance_school_cutovers.php'))->up(); (require database_path('migrations/2026_08_25_000001_create_central_finance_receivable_sync_events.php'))->up(); (require database_path('migrations/2026_08_25_000003_create_central_finance_receivable_adjustments.php'))->up(); (require database_path('migrations/2026_08_25_000005_add_fresh_start_receivable_cutoff.php'))->up(); (require database_path('migrations/2026_08_24_000002_create_central_finance_school_staff_identities.php'))->up(); (require database_path('migrations/2026_09_04_000001_create_central_finance_pending_collections.php'))->up(); (require database_path('migrations/2026_09_08_000001_create_central_finance_collection_handover_batches.php'))->up(); (require database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php'))->up(); (require database_path('migrations/2026_09_28_000001_add_payment_correction_fields_and_reversals.php'))->up();
  DB::connection('mysql')->table('schools')->insert([['id'=>1,'name'=>'Zixuan QA','code'=>'ZIX','database_name'=>$this->a,'created_at'=>now(),'updated_at'=>now()],['id'=>2,'name'=>'Timecity QA','code'=>'TIM','database_name'=>$this->b,'created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('users')->insert([['id'=>100,'first_name'=>'Head','last_name'=>'Finance','school_id'=>null,'central_finance_principal_type'=>'central_user','created_at'=>now(),'updated_at'=>now()],['id'=>200,'first_name'=>'School','last_name'=>'Accountant','school_id'=>null,'central_finance_principal_type'=>'central_user','created_at'=>now(),'updated_at'=>now()],['id'=>300,'first_name'=>'Front','last_name'=>'Desk','school_id'=>1,'central_finance_principal_type'=>'school_staff_identity','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('central_finance_school_staff_identities')->insert(['identity_uuid'=>'00000000-0000-4000-8000-000000000300','school_id'=>1,'tenant_user_uuid'=>'00000000-0000-4000-8000-000000000301','central_user_id'=>300,'status'=>'active','created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('roles')->insert(['id'=>1,'name'=>'Head Finance','guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('model_has_roles')->insert(['role_id'=>1,'model_type'=>\App\Models\User::class,'model_id'=>100]); DB::connection('mysql')->table('finance_groups')->insert(['id'=>1,'name'=>'QA','status'=>'active','reporting_currency'=>'MMK','fiscal_year_start_month'=>1,'created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('finance_group_schools')->insert([['group_id'=>1,'school_id'=>1,'status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_id'=>1,'school_id'=>2,'status'=>'active','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('finance_group_users')->insert([['id'=>1,'group_id'=>1,'central_user_id'=>100,'status'=>'active','created_at'=>now(),'updated_at'=>now()],['id'=>2,'group_id'=>1,'central_user_id'=>300,'status'=>'active','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('finance_group_user_scopes')->insert([['group_user_id'=>1,'school_id'=>1,'scope_type'=>'SCHOOL','capability'=>'view_reports','scope_key'=>'school:1','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>1,'school_id'=>1,'scope_type'=>'SCHOOL','capability'=>'operate_finance','scope_key'=>'school:1','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>1,'school_id'=>2,'scope_type'=>'SCHOOL','capability'=>'view_reports','scope_key'=>'school:2','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>1,'school_id'=>2,'scope_type'=>'SCHOOL','capability'=>'operate_finance','scope_key'=>'school:2','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>2,'school_id'=>1,'scope_type'=>'SCHOOL','capability'=>'view_reports','scope_key'=>'school:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>300,'school_id'=>1,'can_view'=>1,'can_operate'=>0,'can_submit_collections'=>1,'created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('central_finance_school_cutovers')->insert([['school_id'=>1,'status'=>'central','receivable_sync_effective_at'=>'2026-08-01 00:00:00','cutover_at'=>now(),'created_at'=>now(),'updated_at'=>now()],['school_id'=>2,'status'=>'central','receivable_sync_effective_at'=>'2026-08-01 00:00:00','cutover_at'=>now(),'created_at'=>now(),'updated_at'=>now()]]); $this->head=CentralFinanceUser::on('mysql')->findOrFail(100); $this->accountant=CentralFinanceUser::on('mysql')->findOrFail(200);
  $this->tenant($this->a,'Zixuan Tuition',1000); $this->tenant($this->b,'Timecity Tuition',2000);
  $this->zixProfile=$this->profile(1,'11111111-1111-4111-8111-111111111111'); $this->timeProfile=$this->profile(2,'22222222-2222-4222-8222-222222222222'); $this->hq=$this->account('CF-HQ','HQ Bank','hq',null); $this->allocate($this->hq,1); $this->allocate($this->hq,2); $this->zix=$this->account('CF-ZIX','Zixuan Cash','school',1); foreach([$this->hq,$this->zix] as $account) $this->grant($this->head,$account); $this->grant($this->accountant,$this->zix); $this->schoolGrant($this->head,1); $this->schoolGrant($this->head,2); $this->schoolGrant($this->accountant,1);
  if ($this->name() !== 'test_unidentified_deposit_changes_physical_balance_once_then_matching_only_settles_the_receivable') $this->bankIdentityFixture();
 }
 protected function tearDown(): void { DB::purge('mysql');DB::purge('school');foreach([$this->central,$this->a,$this->b] as $f)@unlink($f);parent::tearDown(); }
 public function test_tenant_fee_assignments_create_idempotent_school_scoped_receivables_without_tenant_writes(): void { $before=$this->tenantHash(); $sync=app(CentralFinanceReceivableSyncService::class); $sync->syncProfile($this->zixProfile);$sync->syncProfile($this->timeProfile);$sync->syncProfile($this->zixProfile); $this->assertSame(2,CentralFinanceReceivable::on('mysql')->count()); $this->assertSame($before,$this->tenantHash()); $this->assertSame(1000.0,(float)CentralFinanceReceivable::on('mysql')->where('school_id',1)->firstOrFail()->amount_due); }
 public function test_legacy_tenant_fee_assignment_without_currency_column_defaults_to_mmk(): void { $previous=config('database.connections.school.database');try{Config::set('database.connections.school.database',$this->a);DB::purge('school');Schema::connection('school')->table('fees_class_types',fn(Blueprint $table)=>$table->dropColumn('fee_currency'));}finally{DB::purge('school');Config::set('database.connections.school.database',$previous);} $receivable=$this->receivable($this->zixProfile);$this->assertSame('MMK',$receivable->currency); }
 public function test_fresh_start_cutoff_excludes_pre_cutoff_assignments_without_cancelling_them_or_writing_tenant_finance(): void { DB::connection('mysql')->table('central_finance_school_cutovers')->where('school_id',1)->update(['receivable_sync_effective_at'=>'2026-08-25 00:00:00']); $previous=config('database.connections.school.database'); try { Config::set('database.connections.school.database',$this->a); DB::purge('school'); DB::connection('school')->table('fees_class_types')->where('id',1)->update(['created_at'=>'2026-08-20 00:00:00','updated_at'=>'2026-08-26 00:00:00']); DB::connection('school')->table('fees')->insert(['id'=>2,'name'=>'Post-cutoff tuition','due_date'=>'2026-09-02','created_at'=>'2026-08-26 00:00:00','updated_at'=>'2026-08-26 00:00:00']); DB::connection('school')->table('fees_class_types')->insert(['id'=>2,'fees_id'=>2,'class_id'=>1,'amount'=>333,'optional'=>0,'fee_currency'=>'MMK','created_at'=>'2026-08-26 00:00:00','updated_at'=>'2026-08-26 00:00:00']); } finally { DB::purge('school'); Config::set('database.connections.school.database',$previous); } $before=$this->tenantHash(); $sync=app(CentralFinanceReceivableSyncService::class); $sync->syncProfile($this->zixProfile); $rows=CentralFinanceReceivable::on('mysql')->where('school_id',1)->get(); $this->assertCount(1,$rows); $this->assertSame('2',(string)$rows->first()->source_id); $this->assertSame('2026-08-26 00:00:00',$rows->first()->source_created_at->format('Y-m-d H:i:s')); $report=$sync->reconcileSchool(School::on('mysql')->findOrFail(1)); $this->assertSame(1,$report['source_count']); $this->assertSame(1,$report['central_count']); $this->assertSame([],$report['missing_in_central']); $this->assertSame($before,$this->tenantHash()); }
 public function test_partial_and_full_collection_create_one_payment_receipt_and_ledger_per_idempotency_key(): void { $r=$this->receivable($this->zixProfile);$pay=app(CentralFinancePaymentService::class);$at=CarbonImmutable::parse('2026-08-21 09:00','Asia/Yangon'); $first=$pay->collect($this->head,$r->id,$this->zix,400,'Cash',$at,'ZIX-PAY-1','ZIX-REF-1');$retry=$pay->collect($this->head,$r->id,$this->zix,400,'Cash',$at,'ZIX-PAY-1','ZIX-REF-1'); $this->assertSame($first['payment']->id,$retry['payment']->id);$r->refresh();$this->assertSame(CentralFinanceReceivable::PARTIAL,$r->status);$this->assertSame(400.0,(float)$r->amount_paid);$pay->collect($this->head,$r->id,$this->zix,600,'Cash',$at,'ZIX-PAY-2','ZIX-REF-2');$r->refresh();$this->assertSame(CentralFinanceReceivable::PAID,$r->status);$this->assertSame(1000.0,app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zix));$this->assertSame(2,CentralFinancePayment::on('mysql')->count());$this->assertSame(2,DB::connection('mysql')->table('central_finance_receipts')->count());$this->assertSame(2,DB::connection('mysql')->table('central_finance_ledger_entries')->count()); }
 public function test_hq_account_can_receive_timecity_tuition_but_income_remains_timecity_attributed(): void { $r=$this->receivable($this->timeProfile);app(CentralFinancePaymentService::class)->collect($this->head,$r->id,$this->hq,2000,'Bank',$this->at(),'TIM-PAY-1','TIM-REF-1');$this->assertSame(2000.0,app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->hq));$total=app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(2);$this->assertSame(2000.0,$total['money_in']);$this->assertSame(2000.0,$total['operating_income']);$this->assertSame(0.0,$total['operating_expense']); }
 public function test_unscoped_accountant_and_duplicate_reference_are_rejected_without_extra_finance_records(): void { $r=$this->receivable($this->timeProfile);$pay=app(CentralFinancePaymentService::class);try{$pay->collect($this->accountant,$r->id,$this->hq,10,'Cash',$this->at(),'BAD-1','BAD-REF');$this->fail();}catch(AuthorizationException){$this->assertSame(0,CentralFinancePayment::on('mysql')->count());}$r=$this->receivable($this->zixProfile);$pay->collect($this->head,$r->id,$this->zix,10,'Cash',$this->at(),'Z-1','SAME-REF');try{$pay->collect($this->head,$r->id,$this->zix,10,'Cash',$this->at(),'Z-2','SAME-REF');$this->fail();}catch(InvalidArgumentException){$this->assertSame(1,CentralFinancePayment::on('mysql')->count());}}
 public function test_cross_school_or_inactive_fund_account_is_rejected_without_payment_or_ledger(): void { $r=$this->receivable($this->zixProfile);$time=$this->account('CF-TIM','Timecity Cash','school',2);$this->grant($this->head,$time);$paymentsBefore=CentralFinancePayment::on('mysql')->count();$ledgerBefore=DB::connection('mysql')->table('central_finance_ledger_entries')->count();try{app(CentralFinancePaymentService::class)->collect($this->head,$r->id,$time,10,'Cash',$this->at(),'Z-TIM','Z-TIM');$this->fail();}catch(InvalidArgumentException|AuthorizationException){$this->assertSame($paymentsBefore,CentralFinancePayment::on('mysql')->count());$this->assertSame($ledgerBefore,DB::connection('mysql')->table('central_finance_ledger_entries')->count());}$this->zix->update(['is_active'=>false]);try{app(CentralFinancePaymentService::class)->collect($this->head,$r->id,$this->zix,10,'Cash',$this->at(),'Z-INACTIVE','Z-INACTIVE');$this->fail();}catch(\Illuminate\Database\Eloquent\ModelNotFoundException|AuthorizationException){$this->assertSame($paymentsBefore,CentralFinancePayment::on('mysql')->count());$this->assertSame($ledgerBefore,DB::connection('mysql')->table('central_finance_ledger_entries')->count());}}
 public function test_head_finance_can_open_an_authorized_student_detail_direct_url_without_selected_school(): void { $this->actingAs($this->head);Session::forget(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY);$view=app(CentralFinanceStudentCollectionController::class)->show($this->timeProfile->id);$this->assertSame('central-finance.student-collection.show',$view->name());$this->assertSame(2,$view->getData()['school']->id);$this->assertFalse($view->getData()['canCollect']);$this->assertFalse($view->getData()['canSubmitPending']);$this->assertNull(Session::get(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY)); }
 public function test_head_finance_can_view_a_central_profile_when_the_historical_tenant_student_is_missing(): void { $this->actingAs($this->head);Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);$view=app(CentralFinanceStudentCollectionController::class)->show($this->zixProfile->id);$this->assertSame('central-finance.student-collection.show',$view->name());$this->assertSame($this->zixProfile->id,$view->getData()['profile']->id);$this->assertTrue($view->getData()['optionalItems']->isEmpty());$this->assertNull($view->getData()['optionalAttemptUuid']); }
 public function test_student_detail_direct_url_rejects_a_school_outside_the_existing_read_scope(): void { $this->actingAs($this->accountant);Session::forget(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY);$this->expectException(AuthorizationException::class);app(CentralFinanceStudentCollectionController::class)->show($this->timeProfile->id); }
 public function test_direct_collection_posting_is_retired_without_creating_a_payment(): void {
  $receivable=$this->receivable($this->zixProfile); $this->actingAs($this->head); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $controller=app(CentralFinanceStudentCollectionController::class);
  $redirect=$controller->review($this->zixProfile->id,$receivable->id);
  $this->assertSame(route('central-finance.pending-collections.index'),$redirect->getTargetUrl());
  $before=[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()];
  try { $controller->collect(new Request(),$this->zixProfile->id,$receivable->id); $this->fail('Direct posting must remain retired.'); }
  catch (HttpException $exception) { $this->assertSame(410,$exception->getStatusCode()); }
  $this->assertSame($before,[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()]);
 }
 public function test_head_finance_refund_is_partial_or_full_append_only_and_stably_idempotent(): void {
 $receivable=$this->receivable($this->zixProfile); $at=$this->at();
  $payment=app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,100,'Cash',$at,'REFUND-PAY-1','REFUND-PAY-1')['payment'];
  $service=app(\App\Services\CentralFinancePaymentRefundService::class);
  try { $service->refund($this->accountant,$payment->id,$this->zix,1,'Cash',$at,'No authority.',$at,'ui-payment-refund-denied','DENIED'); $this->fail('Non Head Finance must be denied.'); } catch (AuthorizationException) { $this->assertSame(0,DB::connection('mysql')->table('central_finance_payment_refunds')->count()); }
  $first=$service->refund($this->head,$payment->id,$this->zix,25,'Cash',CarbonImmutable::parse('2026-08-22','Asia/Yangon'),'Parent returned in person.',$at,'ui-payment-refund-refund-1','REFUND-1');
  $retry=$service->refund($this->head,$payment->id,$this->zix,25,'Cash',CarbonImmutable::parse('2026-08-22','Asia/Yangon'),'Parent returned in person.',$at,'ui-payment-refund-refund-1','REFUND-1');
  $this->assertSame($first->id,$retry->id); $this->assertSame(1,DB::connection('mysql')->table('central_finance_payment_refunds')->count());
  $receivable->refresh(); $this->assertSame(75.0,(float)$receivable->amount_paid); $this->assertSame(75.0,app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zix)); $this->assertSame(2,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
  $service->refund($this->head,$payment->id,$this->zix,75,'Cash',CarbonImmutable::parse('2026-08-23','Asia/Yangon'),'Full return.',$at,'ui-payment-refund-refund-2','REFUND-2');
  $receivable->refresh(); $this->assertSame(0.0,(float)$receivable->amount_paid); $this->assertSame(CentralFinanceReceivable::OPEN,$receivable->status); $this->assertSame(0.0,app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zix));
  try { $service->refund($this->head,$payment->id,$this->zix,0.01,'Cash',$at,'Over refund.',$at,'ui-payment-refund-refund-3','REFUND-3'); $this->fail('Over-refund must fail.'); } catch (InvalidArgumentException) { $this->assertSame(2,DB::connection('mysql')->table('central_finance_payment_refunds')->count()); }
 }
 public function test_payment_reversal_is_head_finance_only_full_append_only_and_mutually_exclusive_with_refunds(): void {
  $receivable=$this->receivable($this->zixProfile); $at=$this->at();
  $payment=app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,100,'Cash',$at,'REVERSAL-PAY-1','REVERSAL-PAY-1')['payment'];
  $service=app(\App\Services\CentralFinancePaymentReversalService::class);
  try { $service->reverse($this->accountant,$payment->id,$at,'No authority.',$at,'ui-payment-reversal-denied','DENIED'); $this->fail('Non Head Finance must be denied.'); } catch (AuthorizationException) { $this->assertSame(0,DB::connection('mysql')->table('central_finance_payment_reversals')->count()); }
  $first=$service->reverse($this->head,$payment->id,CarbonImmutable::parse('2026-08-22','Asia/Yangon'),'Duplicate POS entry.',$at,'ui-payment-reversal-1','REV-1');
  $retry=$service->reverse($this->head,$payment->id,CarbonImmutable::parse('2026-08-22','Asia/Yangon'),'Duplicate POS entry.',$at,'ui-payment-reversal-1','REV-1');
  $this->assertSame($first->id,$retry->id); $this->assertSame(100.0,(float)$first->amount); $this->assertSame(1,DB::connection('mysql')->table('central_finance_payment_reversals')->count());
  $receivable->refresh(); $this->assertSame(0.0,(float)$receivable->amount_paid); $this->assertSame(0.0,app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zix)); $this->assertSame(2,DB::connection('mysql')->table('central_finance_ledger_entries')->count()); $this->assertSame(1,CentralFinancePayment::on('mysql')->count()); $this->assertSame(1,DB::connection('mysql')->table('central_finance_receipts')->count());
  try { $service->reverse($this->head,$payment->id,$at,'Second reversal.',$at,'ui-payment-reversal-2','REV-2'); $this->fail('Second reversal must fail.'); } catch (InvalidArgumentException) { $this->assertTrue(true); }
  try { app(\App\Services\CentralFinancePaymentRefundService::class)->refund($this->head,$payment->id,$this->zix,1,'Cash',$at,'Cannot refund reversal.',$at,'ui-payment-refund-after-reversal','REFUND-AFTER-REV'); $this->fail('Refund after reversal must fail.'); } catch (InvalidArgumentException) { $this->assertSame(0,DB::connection('mysql')->table('central_finance_payment_refunds')->count()); }
 }
 public function test_prior_refund_blocks_full_payment_reversal(): void {
  $receivable=$this->receivable($this->zixProfile); $at=$this->at(); $payment=app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,100,'Cash',$at,'MUTUAL-PAY','MUTUAL-PAY')['payment'];
  app(\App\Services\CentralFinancePaymentRefundService::class)->refund($this->head,$payment->id,$this->zix,1,'Cash',$at,'Partial return.',$at,'ui-payment-refund-mutual','MUTUAL-REF');
  try { app(\App\Services\CentralFinancePaymentReversalService::class)->reverse($this->head,$payment->id,$at,'Cannot reverse refund.',$at,'ui-payment-reversal-mutual','MUTUAL-REV'); $this->fail('Prior refund blocks reversal.'); } catch (InvalidArgumentException) { $this->assertSame(0,DB::connection('mysql')->table('central_finance_payment_reversals')->count()); }
 }
 public function test_qa_payment_corrections_inherit_qa_classification_without_official_ledger_visibility(): void {
  $isolation=app(CentralFinanceDataIsolationService::class); Config::set('database.connections.school.database',$this->a); DB::purge('school');
  foreach ([['school',1],['student',1],['student_profile',$this->zixProfile->id]] as [$type,$id]) $isolation->classify($this->head,1,$type,$id,CentralFinanceDataClassification::QA_TEST,'QA correction fixture.');
  $receivable=$this->receivable($this->zixProfile); $isolation->classify($this->head,1,'receivable',$receivable->id,CentralFinanceDataClassification::QA_TEST,'QA correction fixture.');
  $isolation->classify($this->head,1,'fund_account',$this->zix->id,CentralFinanceDataClassification::QA_TEST,'QA correction fixture.');
  $payment=app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,100,'Cash',$this->at(),'QA-CORRECTION-PAY','QA-CORRECTION-PAY')['payment'];
  $refund=app(\App\Services\CentralFinancePaymentRefundService::class)->refund($this->head,$payment->id,$this->zix,25,'Cash',$this->at(),'QA partial refund.',$this->at(),'ui-payment-refund-qa-correction','QA-CORRECTION-REF');
  $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('payment',$payment->id));
  $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('payment_refund',$refund->id));
  $official=CentralFinanceLedgerEntry::on('mysql')->newQuery(); $isolation->apply($official,'ledger'); $this->assertSame(0,$official->count());
  $history=CentralFinanceLedgerEntry::on('mysql')->newQuery(); $isolation->apply($history,'ledger',true); $this->assertSame(2,$history->count());
 }
 public function test_authorized_qa_school_receipt_keeps_its_qa_fund_account_visible(): void {
  $isolation=app(CentralFinanceDataIsolationService::class); Config::set('database.connections.school.database',$this->a); DB::purge('school');
  foreach ([['school',1],['student',1],['student_profile',$this->zixProfile->id]] as [$type,$id]) $isolation->classify($this->head,1,$type,$id,CentralFinanceDataClassification::QA_TEST,'QA receipt visibility fixture.');
  $receivable=$this->receivable($this->zixProfile); $isolation->classify($this->head,1,'receivable',$receivable->id,CentralFinanceDataClassification::QA_TEST,'QA receipt visibility fixture.');
  $isolation->classify($this->head,1,'fund_account',$this->zix->id,CentralFinanceDataClassification::QA_TEST,'QA receipt visibility fixture.');
  $payment=app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,100,'Bank Transfer',$this->at(),'QA-RECEIPT-VISIBILITY','QA-RECEIPT-VISIBILITY');
  $this->actingAs($this->head); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $view=app(CentralFinanceWorkspaceController::class)->receipt($payment['payment']->id);
  $this->assertSame('central-finance.receipt',$view->name());
  $this->assertSame($payment['payment']->id,$view->getData()['document']->id);
  $this->assertSame($this->zix->id,$view->getData()['document']->fund_account_id);
 }
 public function test_front_desk_submission_is_non_financial_and_head_finance_confirmation_is_exactly_once(): void {
  $r=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $before=[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()];
  $pending=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$r->id,100,'Bank Transfer',$this->at(),'FD-1',$this->hq->id,'FD-REF');
  $retry=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$r->id,100,'Bank Transfer',$this->at(),'FD-1',$this->hq->id,'FD-REF');
  $this->assertSame($pending->id,$retry->id); $this->assertSame(CentralFinancePendingCollection::SUBMITTED,$pending->status);
  $this->assertSame($before,[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()]);
  try { app(CentralFinancePaymentService::class)->collect($front,$r->id,$this->zix,100,'Cash',$this->at(),'FORBIDDEN-FRONT'); $this->fail('Front Desk must never post a canonical Payment.'); } catch (AuthorizationException) { $this->assertSame(0, CentralFinancePayment::on('mysql')->count()); }
  $confirmed=app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$pending->id,$this->hq,$this->at(),'Verified bank transfer');
  $again=app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$pending->id,$this->hq,$this->at(),'Replay');
  $this->assertSame(CentralFinancePendingCollection::CONFIRMED,$confirmed->status); $this->assertSame($confirmed->id,$again->id);
  $this->assertSame(1,CentralFinancePayment::on('mysql')->count()); $this->assertSame(1,DB::connection('mysql')->table('central_finance_receipts')->count()); $this->assertSame(1,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
 $this->assertSame(300,(int)$confirmed->collected_by); $this->assertSame(100,(int)$confirmed->confirmed_by);
  $held=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$r->id,100,'Cash',$this->at(),'FD-2',null,'FD-HOLD');
  app(CentralFinancePendingCollectionService::class)->hold($this->head,$held->id,'Need cash count');
  $rejected=app(CentralFinancePendingCollectionService::class)->reject($this->head,$held->id,'Cash count did not reconcile');
 $this->assertSame(CentralFinancePendingCollection::REJECTED,$rejected->status); $this->assertSame(1,CentralFinancePayment::on('mysql')->count());
 }
 public function test_pending_collection_keeps_front_desk_collection_date_when_confirmed_in_a_later_period(): void {
  $receivable=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $collectedAt=CarbonImmutable::parse('2026-05-31 23:45:00','Asia/Yangon');
  $confirmedAt=CarbonImmutable::parse('2026-06-01 09:15:00','Asia/Yangon');
  $pending=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$receivable->id,100,'Bank Transfer',$collectedAt,'DATE-BOUNDARY-1',$this->hq->id,'DATE-BOUNDARY-1');
  $confirmed=app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$pending->id,$this->hq,$confirmedAt,'Verified after bank close.');
  $payment=CentralFinancePayment::on('mysql')->findOrFail($confirmed->confirmed_payment_id);
  $receipt=$payment->receipt()->firstOrFail();
  $ledger=CentralFinanceLedgerEntry::on('mysql')->where('source_type','central_payment')->sole();
  $this->assertSame('2026-05-31',$payment->paid_at->timezone('Asia/Yangon')->toDateString());
  $this->assertSame('2026-06-01',$confirmed->confirmed_at->timezone('Asia/Yangon')->toDateString());
  $this->assertSame('2026-06-01',$receipt->issued_at->toDateString());
  $this->assertSame('2026-05-31',$ledger->entry_date->toDateString());
 }
 public function test_pending_collection_keeps_december_business_date_when_confirmed_in_january(): void {
  $receivable=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $collectedAt=CarbonImmutable::parse('2026-12-31 23:45:00','Asia/Yangon');
  $confirmedAt=CarbonImmutable::parse('2027-01-01 09:15:00','Asia/Yangon');
  $pending=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$receivable->id,100,'Bank Transfer',$collectedAt,'DATE-BOUNDARY-2',$this->hq->id,'DATE-BOUNDARY-2');
  $confirmed=app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$pending->id,$this->hq,$confirmedAt,'Verified after year end.');
  $payment=CentralFinancePayment::on('mysql')->findOrFail($confirmed->confirmed_payment_id);
  $ledger=CentralFinanceLedgerEntry::on('mysql')->where('source_type','central_payment')->sole();
  $this->assertSame('2026-12-31',$payment->paid_at->timezone('Asia/Yangon')->toDateString());
  $this->assertSame('2027-01-01',$confirmed->confirmed_at->timezone('Asia/Yangon')->toDateString());
  $this->assertSame('2026-12-31',$ledger->entry_date->toDateString());
 }
 public function test_submitted_and_held_collections_reserve_the_receivable_without_posting_or_over_collection(): void {
  $r=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $pending=app(CentralFinancePendingCollectionService::class);
  $first=$pending->submit($front,$this->zixProfile->id,$r->id,400,'Bank Transfer',$this->at(),'RESERVE-1',$this->hq->id,'RESERVE-1');
  app(CentralFinancePendingCollectionService::class)->hold($this->head,$first->id,'Awaiting bank evidence');
  $second=$pending->submit($front,$this->zixProfile->id,$r->id,600,'Bank Transfer',$this->at(),'RESERVE-2',$this->hq->id,'RESERVE-2');
  $retry=$pending->submit($front,$this->zixProfile->id,$r->id,400,'Bank Transfer',$this->at(),'RESERVE-1',$this->hq->id,'RESERVE-1');
  $this->assertSame($first->id,$retry->id);
  try { $pending->submit($front,$this->zixProfile->id,$r->id,0.01,'Bank Transfer',$this->at(),'RESERVE-OVER',$this->hq->id,'RESERVE-OVER'); $this->fail('Submitted and held amounts must reserve the available receivable balance.'); }
  catch (InvalidArgumentException $exception) { $this->assertStringContainsString('available amount',$exception->getMessage()); }
  $r->refresh();
  $this->assertSame(CentralFinanceReceivable::OPEN,$r->status);
  $this->assertSame(0.0,(float)$r->amount_paid);
  $this->assertSame(1000.0,(float)CentralFinancePendingCollection::on('mysql')->whereIn('id',[$first->id,$second->id])->sum('amount'));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
 $this->assertSame(0,DB::connection('mysql')->table('central_finance_receipts')->count());
 $this->assertSame(0,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
 }
 public function test_student_collection_read_model_shows_pending_and_available_amounts_without_mislabeling_them_as_paid(): void {
  $r=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$r->id,400,'Bank Transfer',$this->at(),'READ-MODEL-RESERVE',$this->hq->id,'READ-MODEL-RESERVE');
  $this->actingAs($front);
  $view=app(CentralFinanceStudentCollectionController::class)->show($this->zixProfile->id);
  $receivable=$view->getData()['profile']->receivables->sole();
  $this->assertSame(0.0,(float)$receivable->amount_paid);
  $this->assertSame(400.0,(float)$receivable->pending_confirmation_amount);
  $this->assertSame(1000.0,$view->getData()['profile']->currency_totals['MMK']['outstanding']);
  $this->assertSame(600.0,$view->getData()['profile']->currency_totals['MMK']['available_to_collect']);
 }
 public function test_cash_pending_collection_cannot_be_directly_confirmed_before_a_cash_handover(): void {
  $r=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $pending=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$r->id,100,'Cash',$this->at(),'CASH-HO-1',null,'CASH-HO-1');
  try { app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$pending->id,$this->zix,$this->at(),'Attempt direct cash confirmation'); $this->fail('Cash must require a submitted handover.'); }
  catch (InvalidArgumentException) { $this->assertSame(0, CentralFinancePayment::on('mysql')->count()); }
  $this->assertSame(CentralFinancePendingCollection::SUBMITTED, $pending->fresh()->status);
 }
 public function test_handover_remove_readd_submit_confirm_and_replay_are_audited_and_exactly_once(): void {
  $r=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $pendingService=app(CentralFinancePendingCollectionService::class); $handover=app(CentralFinanceCollectionHandoverService::class); $confirm=app(CentralFinanceHeadFinanceHandoverConfirmService::class);
  $first=$pendingService->submit($front,$this->zixProfile->id,$r->id,10,'Cash',$this->at(),'HB-P-1',null,'HB-P-1');
  $second=$pendingService->submit($front,$this->zixProfile->id,$r->id,10,'Cash',$this->at(),'HB-P-2',null,'HB-P-2');
  $batch=$handover->create($front,'Cash','MMK','HB-20','HB-20-IDEMP','20');
  $handover->add($front,$batch,$first->id); $secondItem=$handover->add($front,$batch,$second->id);
  $handover->remove($front,$batch,$secondItem); $handover->add($front,$batch,$second->id);
  $submitted=$handover->submit($front,$batch);
  $this->assertSame(CentralFinanceCollectionHandoverBatch::SUBMITTED,$submitted->status);
  $this->assertSame(20.0,(float)$submitted->expected_amount);
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
  try { $confirm->confirm($this->head,$batch->id,$this->zix,'19','Shortage'); $this->fail('Shortage must be rejected.'); } catch (InvalidArgumentException) { $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); }
  $confirmed=$confirm->confirm($this->head,$batch->id,$this->zix,'20','Reconciled QA handover');
  $replay=$confirm->confirm($this->head,$batch->id,$this->zix,'20','Replay');
  $this->assertSame($confirmed->id,$replay->id);
  $this->assertSame(2,CentralFinancePayment::on('mysql')->count());
  $this->assertSame(2,DB::connection('mysql')->table('central_finance_receipts')->count());
  $this->assertSame(2,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
  $this->assertSame(20.0,app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zix));
  $this->assertSame(980.0,(float)$r->fresh()->amount_due-(float)$r->fresh()->amount_paid);
  $actions=DB::connection('mysql')->table('central_finance_document_audits')->where(['document_type'=>'collection_handover','document_id'=>$batch->id])->pluck('action')->all();
  foreach(['draft','item_attached','item_removed','item_reattached','submitted','confirmed'] as $action) $this->assertContains($action,$actions);
 }
 public function test_stale_item_aborts_the_whole_handover_without_partial_posting(): void {
  $r=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $pendingService=app(CentralFinancePendingCollectionService::class); $handover=app(CentralFinanceCollectionHandoverService::class);
  $valid=$pendingService->submit($front,$this->zixProfile->id,$r->id,10,'Cash',$this->at(),'STALE-V',null,'STALE-V');
  $stale=$pendingService->submit($front,$this->zixProfile->id,$r->id,10,'Cash',$this->at(),'STALE-S',null,'STALE-S');
  $batch=$handover->create($front,'Cash','MMK','HB-STALE','HB-STALE-IDEMP','20');
  $handover->add($front,$batch,$valid->id); $handover->add($front,$batch,$stale->id); $handover->submit($front,$batch);
  // Simulate confirmation through a separately submitted Cash handover. The
  // public HTTP path can only set this internal flag from the handover service.
  app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$stale->id,$this->zix,$this->at(),'Make second item stale', true);
  $before=CentralFinancePayment::on('mysql')->count();
  try { app(CentralFinanceHeadFinanceHandoverConfirmService::class)->confirm($this->head,$batch->id,$this->zix,'20','Should roll back'); $this->fail('A stale item must fail the whole batch.'); } catch (InvalidArgumentException) {}
  $this->assertSame($before,CentralFinancePayment::on('mysql')->count());
  $this->assertSame(CentralFinancePendingCollection::SUBMITTED,$valid->fresh()->status);
  $this->assertSame(CentralFinanceCollectionHandoverBatch::SUBMITTED,$batch->fresh()->status);
 }
 public function test_qa_school_pending_and_cash_handover_chain_inherit_qa_test_classification_without_official_ledger_visibility(): void {
  $isolation=app(CentralFinanceDataIsolationService::class); Config::set('database.connections.school.database',$this->a); DB::purge('school');
  $isolation->classify($this->head,1,'school',1,CentralFinanceDataClassification::QA_TEST,'Permanent QA School.');
  $isolation->classify($this->head,1,'student',1,CentralFinanceDataClassification::QA_TEST,'QA student fixture.');
  $isolation->classify($this->head,1,'student_profile',$this->zixProfile->id,CentralFinanceDataClassification::QA_TEST,'QA student profile.');
  $receivable=$this->receivable($this->zixProfile);
  $isolation->classify($this->head,1,'receivable',$receivable->id,CentralFinanceDataClassification::QA_TEST,'QA receivable.');
  $isolation->classify($this->head,1,'fund_account',$this->zix->id,CentralFinanceDataClassification::QA_TEST,'QA cash account.');
  $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $pending=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$receivable->id,100,'Cash',$this->at(),'QA-CASH-1',null,'QA-CASH-1');
  $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('pending_collection',$pending->id));
  $batch=app(CentralFinanceCollectionHandoverService::class)->create($front,'Cash','MMK','QA-CASH-HO','QA-CASH-HO-1','100');
  $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('collection_handover',$batch->id));
  app(CentralFinanceCollectionHandoverService::class)->add($front,$batch,$pending->id); app(CentralFinanceCollectionHandoverService::class)->submit($front,$batch);
  app(CentralFinanceHeadFinanceHandoverConfirmService::class)->confirm($this->head,$batch->id,$this->zix,'100','QA cash handover confirmed');
  $payment=CentralFinancePayment::on('mysql')->sole(); $receipt=DB::connection('mysql')->table('central_finance_receipts')->sole(); $ledger=DB::connection('mysql')->table('central_finance_ledger_entries')->sole();
  $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('payment',$payment->id));
  $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('receipt',$receipt->id));
  $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('ledger',$ledger->id));
  $official=CentralFinanceLedgerEntry::on('mysql')->newQuery(); $isolation->apply($official,'ledger'); $this->assertSame(0,$official->count());
  $history=CentralFinanceLedgerEntry::on('mysql')->newQuery(); $isolation->apply($history,'ledger',true); $this->assertSame(1,$history->count());
 }
 public function test_qa_school_rejects_an_allocated_official_fund_account(): void {
  $isolation=app(CentralFinanceDataIsolationService::class); Config::set('database.connections.school.database',$this->a); DB::purge('school');
  $isolation->classify($this->head,1,'school',1,CentralFinanceDataClassification::QA_TEST,'Permanent QA School.');
  $isolation->classify($this->head,1,'student',1,CentralFinanceDataClassification::QA_TEST,'QA student fixture.');
  $isolation->classify($this->head,1,'student_profile',$this->zixProfile->id,CentralFinanceDataClassification::QA_TEST,'QA student profile.');
  $receivable=$this->receivable($this->zixProfile); $isolation->classify($this->head,1,'receivable',$receivable->id,CentralFinanceDataClassification::QA_TEST,'QA receivable.');
  $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  try { app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$receivable->id,100,'Bank Transfer',$this->at(),'QA-OFFICIAL-ACCOUNT',$this->hq->id,'QA-OFFICIAL-ACCOUNT'); $this->fail('A QA School must not select an Official Fund Account.'); }
  catch (AuthorizationException) { $this->assertSame(0,CentralFinancePendingCollection::on('mysql')->count()); }
 }
 public function test_qa_front_desk_review_uses_its_trusted_school_classification_and_only_qa_bank_accounts(): void {
  $isolation=app(CentralFinanceDataIsolationService::class); Config::set('database.connections.school.database',$this->a); DB::purge('school');
  $isolation->classify($this->head,1,'school',1,CentralFinanceDataClassification::QA_TEST,'Permanent QA School.');
  $isolation->classify($this->head,1,'student',1,CentralFinanceDataClassification::QA_TEST,'QA student fixture.');
  $isolation->classify($this->head,1,'student_profile',$this->zixProfile->id,CentralFinanceDataClassification::QA_TEST,'QA student profile.');
  $receivable=$this->receivable($this->zixProfile); $isolation->classify($this->head,1,'receivable',$receivable->id,CentralFinanceDataClassification::QA_TEST,'QA receivable.');
  $qaBank=$this->account('ZIX-QA-BANK','Zixuan QA Bank','school',1); $isolation->classify($this->head,1,'fund_account',$qaBank->id,CentralFinanceDataClassification::QA_TEST,'QA bank account.');
  $front=CentralFinanceUser::on('mysql')->findOrFail(300); $this->actingAs($front); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $view=app(CentralFinancePendingCollectionController::class)->review($this->zixProfile->id,$receivable->id);
  $this->assertSame('central-finance.pending-collections.review',$view->name());
  $this->assertSame($this->zixProfile->id,$view->getData()['profile']->id);
  $this->assertSame([$qaBank->id],$view->getData()['accounts']->pluck('id')->all());
  $timeReceivable=$this->receivable($this->timeProfile);
  $this->expectException(ModelNotFoundException::class);
  app(CentralFinancePendingCollectionController::class)->review($this->timeProfile->id,$timeReceivable->id);
 }
 public function test_front_desk_collection_destination_scope_is_not_fund_account_administration_scope(): void {
  $isolation=app(CentralFinanceDataIsolationService::class);
  Config::set('database.connections.school.database',$this->a); DB::purge('school');
  $isolation->classify($this->head,1,'school',1,CentralFinanceDataClassification::QA_TEST,'Permanent QA School.');
  $isolation->classify($this->head,1,'student',1,CentralFinanceDataClassification::QA_TEST,'QA student fixture.');
  $isolation->classify($this->head,1,'student_profile',$this->zixProfile->id,CentralFinanceDataClassification::QA_TEST,'QA student profile.');
  $zixReceivable=$this->receivable($this->zixProfile);
  $isolation->classify($this->head,1,'receivable',$zixReceivable->id,CentralFinanceDataClassification::QA_TEST,'QA receivable.');

  $qaBank=$this->account('ZIX-QA-BANK','Zixuan QA Bank','school',1);
  $wrongCurrency=$this->account('ZIX-QA-USD','Zixuan QA USD Bank','school',1);
  $wrongCurrency->update(['currency'=>'USD']);
  $inactive=$this->account('ZIX-QA-INACTIVE','Zixuan inactive QA Bank','school',1);
  $inactive->update(['is_active'=>false,'status'=>CentralFinanceFundAccount::STATUS_INACTIVE]);
  $unallocated=$this->account('ZIX-QA-UNALLOCATED','Zixuan unallocated QA Bank','hq',null);
  foreach ([$qaBank,$wrongCurrency,$inactive,$unallocated] as $account) {
   $isolation->classify($this->head,1,'fund_account',$account->id,CentralFinanceDataClassification::QA_TEST,'QA account scope regression fixture.');
  }
  $timeBank=$this->account('TIM-OFFICIAL-BANK','Timecity Official Bank','school',2);

  $front=CentralFinanceUser::on('mysql')->findOrFail(300);
  $this->actingAs($front); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $view=app(CentralFinancePendingCollectionController::class)->review($this->zixProfile->id,$zixReceivable->id);
  $this->assertSame('central-finance.pending-collections.review',$view->name());
  $this->assertSame([$qaBank->id],$view->getData()['accounts']->pluck('id')->all());
  $this->assertSame([],app(CentralFinanceWorkspaceService::class)->accessibleAccountsForSchoolWorkflow($front,1)->pluck('id')->all());

  $school=School::on('mysql')->findOrFail(1);
  $administrator=app(CentralFinanceFundAccountAdministrationService::class);
  $scopeBefore=DB::connection('mysql')->table('central_finance_user_school_scopes')->where('user_id',200)->orderBy('school_id')->get()->map(fn ($row)=>(array)$row)->all();
  $openingBefore=(float)$qaBank->opening_balance;
  $allocationBefore=DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->where('fund_account_id',$qaBank->id)->orderBy('school_id')->get()->map(fn ($row)=>(array)$row)->all();
  try { $administrator->updateMasterData($front,$school,$qaBank,['account_name'=>'Forbidden','account_type'=>'bank','reason'=>'Forbidden']); $this->fail('Front Desk must not administer a Fund Account.'); } catch (AuthorizationException) { $this->assertTrue(true); }
  try { $administrator->adjustOpeningBalance($front,$school,$qaBank,1,'2026-09-26','Forbidden'); $this->fail('Front Desk must not adjust opening balance.'); } catch (AuthorizationException) { $this->assertTrue(true); }
  try { $administrator->syncSchoolAllocations($front,$school,$qaBank,[['school_id'=>1,'is_active'=>true]],'Forbidden'); $this->fail('Front Desk must not allocate Fund Accounts.'); } catch (AuthorizationException) { $this->assertTrue(true); }
  $this->assertSame($openingBefore,(float)$qaBank->fresh()->opening_balance);
  $this->assertSame($allocationBefore,DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->where('fund_account_id',$qaBank->id)->orderBy('school_id')->get()->map(fn ($row)=>(array)$row)->all());
  $this->assertSame($scopeBefore,DB::connection('mysql')->table('central_finance_user_school_scopes')->where('user_id',200)->orderBy('school_id')->get()->map(fn ($row)=>(array)$row)->all());
  $this->assertTrue(app(\App\Services\CentralFinanceFundAccountScopeService::class)->canOperate($this->head,$qaBank,1));

  DB::connection('mysql')->table('users')->insert(['id'=>301,'first_name'=>'Timecity','last_name'=>'Front Desk','school_id'=>2,'central_finance_principal_type'=>'school_staff_identity','created_at'=>now(),'updated_at'=>now()]);
  DB::connection('mysql')->table('central_finance_school_staff_identities')->insert(['identity_uuid'=>'00000000-0000-4000-8000-000000000302','school_id'=>2,'tenant_user_uuid'=>'00000000-0000-4000-8000-000000000303','central_user_id'=>301,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  DB::connection('mysql')->table('finance_group_users')->insert(['id'=>3,'group_id'=>1,'central_user_id'=>301,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>3,'school_id'=>2,'scope_type'=>'SCHOOL','capability'=>'view_reports','scope_key'=>'school:2','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>301,'school_id'=>2,'can_view'=>1,'can_operate'=>0,'can_submit_collections'=>1,'created_at'=>now(),'updated_at'=>now()]);
  $timeFront=CentralFinanceUser::on('mysql')->findOrFail(301);
  $timeReceivable=$this->receivable($this->timeProfile);
  $this->actingAs($timeFront); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,2);
  $timeView=app(CentralFinancePendingCollectionController::class)->review($this->timeProfile->id,$timeReceivable->id);
  $timeAccountIds=$timeView->getData()['accounts']->pluck('id')->all();
  $this->assertContains($timeBank->id,$timeAccountIds);
  $this->assertNotContains($qaBank->id,$timeAccountIds);
  $this->assertNotContains($wrongCurrency->id,$view->getData()['accounts']->pluck('id')->all());
  $this->assertNotContains($inactive->id,$view->getData()['accounts']->pluck('id')->all());
  $this->assertNotContains($unallocated->id,$view->getData()['accounts']->pluck('id')->all());
 }
 public function test_qa_front_desk_needs_no_toggle_but_cannot_request_one_and_head_finance_can_deliberately_include_qa_pending(): void {
  $isolation=app(CentralFinanceDataIsolationService::class); Config::set('database.connections.school.database',$this->a); DB::purge('school');
  $isolation->classify($this->head,1,'school',1,CentralFinanceDataClassification::QA_TEST,'Permanent QA School.');
  $isolation->classify($this->head,1,'student',1,CentralFinanceDataClassification::QA_TEST,'QA student fixture.');
  $isolation->classify($this->head,1,'student_profile',$this->zixProfile->id,CentralFinanceDataClassification::QA_TEST,'QA student profile.');
  $receivable=$this->receivable($this->zixProfile); $isolation->classify($this->head,1,'receivable',$receivable->id,CentralFinanceDataClassification::QA_TEST,'QA receivable.');
  $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $pending=app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$receivable->id,100,'Cash',$this->at(),'QA-PENDING-FILTER',null,'QA-PENDING-FILTER');
  $this->actingAs($front);
  $frontView=app(CentralFinancePendingCollectionController::class)->frontDeskIndex(Request::create('/central-finance/pending-collections','GET'));
  $this->assertFalse($frontView->getData()['canIncludeQaTest']);
  $this->assertSame([$pending->id],$frontView->getData()['pending']->pluck('id')->all());
  try { app(CentralFinancePendingCollectionController::class)->frontDeskIndex(Request::create('/central-finance/pending-collections?include_qa_test=1','GET')); $this->fail('Front Desk cannot opt into a QA/Test toggle.'); }
  catch (AuthorizationException) { $this->assertTrue(true); }
  $this->actingAs($this->head);
  $headView=app(CentralFinancePendingCollectionController::class)->headFinanceIndex(Request::create('/central-finance/pending-collections?include_qa_test=1','GET'));
  $this->assertTrue($headView->getData()['includeQaTest']);
  $this->assertSame([$pending->id],$headView->getData()['pending']->pluck('id')->all());
 }
 public function test_receivable_schema_is_additive_and_reversible_without_tenant_finance_changes(): void { $migration=require database_path('migrations/2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php');$migration->down();foreach(['central_finance_receipts','central_finance_payments','central_finance_receivables','central_finance_user_school_scopes'] as $table)$this->assertFalse(Schema::connection('mysql')->hasTable($table));$this->assertTrue(Schema::connection('school')->hasTable('fees'));$this->assertTrue(Schema::connection('school')->hasTable('fees_class_types'));}
 public function test_layer3_receivable_lifecycle_is_append_only_and_has_no_money_effect(): void {
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  $r=$this->receivable($this->zixProfile); $before=[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()];
  $promotion=\App\Models\CentralFinancePromotion::on('mysql')->create(['group_id'=>1,'name'=>'Early payment','code'=>'EARLY10','discount_type'=>'percentage','discount_value'=>'10.0000','valid_from'=>'2026-01-01','status'=>'active','fee_scope'=>'all_approved_fees','created_by'=>$this->head->id]);
  DB::connection('mysql')->table('central_finance_promotion_school_allocations')->insert(['promotion_id'=>$promotion->id,'school_id'=>1,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $application=app(\App\Services\CentralFinancePromotionService::class)->apply($this->head,$r->id,$promotion->id,$this->at(),'Approved early payment',$this->at(),'L3-PROMO-1'); $retry=app(\App\Services\CentralFinancePromotionService::class)->apply($this->head,$r->id,$promotion->id,$this->at(),'Retry',$this->at(),'L3-PROMO-1');
  $this->assertSame($application->id,$retry->id); $this->assertSame(900.0,(float)$r->fresh()->amount_due);
  app(\App\Services\CentralFinanceReceivableAdjustmentService::class)->correct($this->head,$r->id,'50.0000','Corrected charge',$this->at(),$this->at(),'L3-CORRECT-1'); app(\App\Services\CentralFinanceReceivableAdjustmentService::class)->waive($this->head,$r->id,'950.0000','Approved waiver',$this->at(),$this->at(),'L3-WAIVE-1');
  $this->assertSame(0.0,(float)$r->fresh()->amount_due); $this->assertSame(CentralFinanceReceivable::WAIVED,$r->fresh()->status); $this->assertSame($before,[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()]);
 $other=$this->receivable($this->timeProfile); app(\App\Services\CentralFinanceReceivableAdjustmentService::class)->void($this->head,$other->id,'Created in error',$this->at(),$this->at(),'L3-VOID-1'); $this->assertSame(CentralFinanceReceivable::VOIDED,$other->fresh()->status);
 }
 public function test_layer3_promotion_definition_is_group_scoped_audited_and_cannot_mix_qa_and_official_schools(): void {
  (require database_path('migrations/2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php'))->up(); (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $service=app(\App\Services\CentralFinancePromotionService::class); $promotion=$service->define($this->head,1,[1],['name'=>'New year','code'=>'NY10','description'=>'New year offer','discount_type'=>'percentage','discount_value'=>'10.0000','valid_from'=>'2026-01-01','valid_until'=>'2026-12-31','status'=>'active']);
  $this->assertSame('NY10',$promotion->code); $this->assertSame([1],$promotion->allocations->pluck('school_id')->all()); $this->assertSame(1,DB::connection('mysql')->table('central_finance_document_audits')->where(['document_type'=>'central_finance_promotion','document_id'=>$promotion->id])->count());
  $isolation=app(CentralFinanceDataIsolationService::class); $isolation->classify($this->head,1,'school',1,CentralFinanceDataClassification::QA_TEST,'QA School.');
  try { $service->define($this->head,1,[1,2],['name'=>'Mixed','code'=>'MIXED','description'=>null,'discount_type'=>'fixed','discount_value'=>'1.0000','valid_from'=>'2026-01-01','valid_until'=>null,'status'=>'active']); $this->fail('A definition must not mix QA and Official schools.'); } catch (InvalidArgumentException) { $this->assertSame(1,\App\Models\CentralFinancePromotion::on('mysql')->count()); }
 }
 public function test_duplicate_promotion_code_returns_a_safe_validation_error_without_a_second_definition_or_audit(): void {
  (require database_path('migrations/2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php'))->up(); (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $input=['name'=>'Existing promotion','code'=>'DUPLICATE-CODE','description'=>null,'discount_type'=>'fixed','discount_value'=>'100.0000','valid_from'=>'2026-01-01','valid_until'=>null,'status'=>'active'];
  $service=app(\App\Services\CentralFinancePromotionService::class); $first=$service->define($this->head,1,[1],$input);
  $before=[\App\Models\CentralFinancePromotion::on('mysql')->count(),DB::connection('mysql')->table('central_finance_document_audits')->count()];
  try { $service->define($this->head,1,[1],array_merge($input,['name'=>'Duplicate promotion','code'=>'duplicate-code'])); $this->fail('A duplicate Promotion code must be returned as a safe validation error.'); }
  catch (InvalidArgumentException $exception) { $this->assertSame(\App\Services\CentralFinancePromotionService::DUPLICATE_CODE_MESSAGE,$exception->getMessage()); }
  $this->assertSame($before,[\App\Models\CentralFinancePromotion::on('mysql')->count(),DB::connection('mysql')->table('central_finance_document_audits')->count()]);
  $this->assertSame($first->id,\App\Models\CentralFinancePromotion::on('mysql')->where('code','DUPLICATE-CODE')->value('id'));
 }
 public function test_promotion_form_maps_a_duplicate_code_to_the_code_field_instead_of_a_500(): void {
  (require database_path('migrations/2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php'))->up(); (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $input=['group_id'=>1,'name'=>'Existing promotion','code'=>'FORM-DUPLICATE','description'=>null,'discount_type'=>'fixed','discount_value'=>'100.0000','valid_from'=>'2026-01-01','valid_until'=>null,'status'=>'active','school_ids'=>[1]];
  app(\App\Services\CentralFinancePromotionService::class)->define($this->head,1,[1],$input);
  $this->actingAs($this->head); $request=Request::create('/central-finance/promotions','POST',$input,[],[],['HTTP_REFERER'=>'http://localhost/central-finance/promotions']);
  $response=app(CentralFinanceWorkspaceController::class)->storePromotion($request);
  $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class,$response);
  $this->assertSame(\App\Services\CentralFinancePromotionService::DUPLICATE_CODE_MESSAGE,session('errors')->first('code'));
  $this->assertSame(1,\App\Models\CentralFinancePromotion::on('mysql')->count());
 }
 public function test_student_specific_promotion_is_visible_only_to_its_exact_profile_and_remains_append_only(): void {
  (require database_path('migrations/2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php'))->up();
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  (require database_path('migrations/2026_09_29_000002_add_finance_collection_v2_documents.php'))->up();
  (require database_path('migrations/2026_10_02_000001_add_student_scope_to_central_finance_promotions.php'))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $service=app(\App\Services\CentralFinancePromotionService::class);
  $target=$service->defineStudentSpecificForFeeSetup($this->head,$this->zixProfile,1,['discount_type'=>'percentage','discount_value'=>'10.0000','reason'=>'Individual support approved for this Student.','effective_date'=>CarbonImmutable::parse('2026-01-01','Asia/Yangon')],hash('sha256','head-approved-zix-only-10'));
  $generic=$service->define($this->head,1,[1],['name'=>'School offer','code'=>'ZIX-ALL-5','description'=>null,'discount_type'=>'percentage','discount_value'=>'5.0000','valid_from'=>'2026-01-01','valid_until'=>'2026-12-31','status'=>'active']);
  $date=CarbonImmutable::parse('2026-08-21','Asia/Yangon');
  $front=CentralFinanceUser::on('mysql')->findOrFail(300);
  $otherZixProfile=$this->profile(1,'44444444-4444-4444-8444-444444444444',99);
  $expectedForTarget=[$generic->id,$target->id]; sort($expectedForTarget);
  $this->assertSame($expectedForTarget,$service->eligibleForFeeSetup($front,1,$this->zixProfile->id,1,$date)->pluck('id')->sort()->values()->all());
  $this->assertSame([$generic->id],$service->eligibleForFeeSetup($front,1,$otherZixProfile->id,1,$date)->pluck('id')->all());
  $zixReceivable=$this->receivable($this->zixProfile);
  $timeReceivable=$this->receivable($this->timeProfile);
  $before=[DB::connection('mysql')->table('central_finance_receivable_adjustments')->count(),DB::connection('mysql')->table('central_finance_promotion_applications')->count(),CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()];
  $application=$service->applyFromFeeSetup($front,$zixReceivable->id,$target->id,1,$date,$this->at(),'STUDENT-ONLY-ZIX','Approved individual support.');
  $this->assertSame($target->id,$application->promotion_id);
  try { $service->apply($this->head,$timeReceivable->id,$target->id,$date,'Must never cross Student scope.',$this->at(),'STUDENT-ONLY-TIME'); $this->fail('A student-specific Promotion must not apply to another Student.'); } catch (AuthorizationException) { $this->assertTrue(true); }
  $this->assertSame([$before[0] + 1,$before[1] + 1,$before[2],$before[3]],[DB::connection('mysql')->table('central_finance_receivable_adjustments')->count(),DB::connection('mysql')->table('central_finance_promotion_applications')->count(),CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()]);
  try { $service->define($this->head,1,[1],['student_profile_id'=>$this->zixProfile->id,'name'=>'Invalid direct scope','code'=>'INVALID-SCOPE','description'=>null,'discount_type'=>'fixed','discount_value'=>'1.0000','valid_from'=>'2026-01-01','valid_until'=>null,'status'=>'active']); $this->fail('Student-specific definitions must require the Front Desk request and Head Finance decision workflow.'); } catch (InvalidArgumentException) { $this->assertTrue(true); }
 }
 public function test_front_desk_student_specific_discount_is_fee_scoped_audited_and_exactly_once(): void {
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  (require database_path('migrations/2026_09_29_000002_add_finance_collection_v2_documents.php'))->up();
  (require database_path('migrations/2026_10_02_000001_add_student_scope_to_central_finance_promotions.php'))->up(); (require database_path('migrations/2026_10_02_000002_create_central_finance_student_discount_requests.php'))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id'=>300,'school_id'=>1])->update(['can_create_student_specific_discounts'=>1]);
  $front=CentralFinanceUser::on('mysql')->findOrFail(300); $service=app(\App\Services\CentralFinancePromotionService::class); $date=$this->at();
  $input=['discount_type'=>'percentage','discount_value'=>'10.0000','reason'=>'Sibling support approved for this Student.','effective_date'=>$date];
  $assignment=new \App\Models\StudentFeeAssignment(['uuid'=>'11111111-1111-4111-8111-111111111111']); $item=new \App\Models\StudentFeeAssignmentItem(['uuid'=>'22222222-2222-4222-8222-222222222222','fees_class_type_id'=>1,'amount_snapshot'=>'1000.0000','currency_snapshot'=>'MMK','student_discount_type'=>$input['discount_type'],'student_discount_value'=>$input['discount_value'],'student_discount_reason'=>$input['reason'],'student_discount_effective_date'=>$date]);
  $requests=app(\App\Services\CentralFinanceStudentDiscountRequestService::class); $request=$requests->submit($front,$this->zixProfile,$assignment,$item); $retry=$requests->submit($front,$this->zixProfile,$assignment,$item);
  $this->assertSame($request->id,$retry->id); $this->assertSame('pending',$request->status); $this->assertSame(0,\App\Models\CentralFinancePromotion::on('mysql')->count());
  // Approval is a Group control-plane action: it must not depend on a separate
  // school-operate grant after the request has been safely bound to that Group.
  DB::connection('mysql')->table('finance_group_user_scopes')->where(['group_user_id'=>1,'school_id'=>1,'capability'=>'operate_finance'])->delete();
  $discount=$requests->approve($this->head,$request); $promotion=\App\Models\CentralFinancePromotion::on('mysql')->findOrFail($discount->promotion_id); $this->assertSame('approved',$discount->status); $this->assertSame('student_specific',$promotion->scope); $this->assertSame($this->zixProfile->id,(int)$promotion->student_profile_id);
  $this->assertSame(1,DB::connection('mysql')->table('central_finance_promotion_fee_allocations')->where(['promotion_id'=>$promotion->id,'school_id'=>1,'fees_class_type_id'=>1,'status'=>'active'])->count());
  $this->assertSame(1,DB::connection('mysql')->table('central_finance_document_audits')->where(['document_id'=>$promotion->id,'action'=>'student_specific_discount_created'])->count());
  $other=$this->profile(1,'66666666-6666-4666-8666-666666666666',88);
  $this->assertSame([$promotion->id],$service->eligibleForFeeSetup($front,1,$this->zixProfile->id,1,$date)->pluck('id')->all());
  $this->assertFalse($service->eligibleForFeeSetup($front,1,$other->id,1,$date)->pluck('id')->contains($promotion->id));
  $receivable=$this->receivable($this->zixProfile); $before=[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()];
  $application=$service->applyFromFeeSetup($front,$receivable->id,$promotion->id,1,$date,$date,'front-desk-discount-item-1',$input['reason']);
  $again=$service->applyFromFeeSetup($front,$receivable->id,$promotion->id,1,$date,$date,'front-desk-discount-item-1',$input['reason']);
  $this->assertSame($application->id,$again->id); $this->assertSame('student_specific',$application->promotion_scope_snapshot); $this->assertSame($this->zixProfile->id,(int)$application->student_profile_id_snapshot); $this->assertSame(1,(int)$application->fees_class_type_id_snapshot); $this->assertSame('front_desk',$application->applied_by_role_snapshot); $this->assertSame($input['reason'],$application->reason);
  $this->assertSame('900.0000',(string)$receivable->fresh()->amount_due); $this->assertSame($before,[CentralFinancePayment::on('mysql')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count()]);
  try { $requests->submit($front,$this->timeProfile,$assignment,$item); $this->fail('A Front Desk user cannot submit a Discount for another School.'); } catch (AuthorizationException) { $this->assertTrue(true); }
 }
 public function test_layer3_corrections_waivers_and_voids_preserve_paid_floor_pending_guard_and_finance_history(): void {
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  $service=app(\App\Services\CentralFinanceReceivableAdjustmentService::class); $receivable=$this->receivable($this->zixProfile);
  $down=$service->correct($this->head,$receivable->id,'-200.0000','Correct duplicate charge.',$this->at(),$this->at(),'L3-FLOOR-DOWN');
  $up=$service->correct($this->head,$receivable->id,'200.0000','Restore valid charge.',$this->at(),$this->at(),'L3-FLOOR-UP');
  $this->assertSame('800.0000',(string)$down->amount_after); $this->assertSame('1000.0000',(string)$up->amount_after);
  app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,900,'Cash',$this->at(),'L3-FLOOR-PAY','L3-FLOOR-PAY');
  $before=[DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count(),app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zix)];
  try { $service->correct($this->head,$receivable->id,'-200.0000','Would reduce below paid.',$this->at(),$this->at(),'L3-FLOOR-BLOCK'); $this->fail('A correction may not reduce the net due below paid.'); } catch (InvalidArgumentException) { $this->assertTrue(true); }
  $waiver=$service->waive($this->head,$receivable->id,'100.0000','Waive remaining outstanding balance.',$this->at(),$this->at(),'L3-WAIVER-ONCE');
  $waiverRetry=$service->waive($this->head,$receivable->id,'100.0000','Network retry.',$this->at(),$this->at(),'L3-WAIVER-ONCE');
  $this->assertSame($waiver->id,$waiverRetry->id); $receivable->refresh(); $this->assertSame('900.0000',(string)$receivable->amount_due); $this->assertSame('900.0000',(string)$receivable->amount_paid);
  try { $service->void($this->head,$receivable->id,'Paid receivable.',$this->at(),$this->at(),'L3-VOID-PAID'); $this->fail('A paid receivable cannot be voided.'); } catch (InvalidArgumentException) { $this->assertTrue(true); }
  $this->assertSame($before,[DB::connection('mysql')->table('central_finance_receipts')->count(),DB::connection('mysql')->table('central_finance_ledger_entries')->count(),app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zix)]);
  $pendingProfile=$this->profile(1,'33333333-3333-4333-8333-333333333333',2); $pendingReceivable=$this->receivable($pendingProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  app(CentralFinancePendingCollectionService::class)->submit($front,$pendingProfile->id,$pendingReceivable->id,100,'Bank Transfer',$this->at(),'L3-VOID-PENDING',$this->hq->id,'L3-VOID-PENDING');
  try { $service->void($this->head,$pendingReceivable->id,'Pending collection exists.',$this->at(),$this->at(),'L3-VOID-PENDING'); $this->fail('A receivable with an active pending collection cannot be voided.'); } catch (InvalidArgumentException) { $this->assertTrue(true); }
 }
 public function test_layer3_promotion_enforces_status_date_scope_snapshot_and_qa_inheritance(): void {
  (require database_path('migrations/2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php'))->up();
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $isolation=app(CentralFinanceDataIsolationService::class); foreach ([['school',1],['student_profile',$this->zixProfile->id]] as [$type,$id]) $isolation->classify($this->head,1,$type,$id,CentralFinanceDataClassification::QA_TEST,'Layer 3 QA fixture.');
  $receivable=$this->receivable($this->zixProfile); $isolation->classify($this->head,1,'receivable',$receivable->id,CentralFinanceDataClassification::QA_TEST,'Layer 3 QA fixture.');
  $promotions=app(\App\Services\CentralFinancePromotionService::class); $promotion=$promotions->define($this->head,1,[1],['name'=>'QA fixed','code'=>'QAFIX','description'=>null,'discount_type'=>'fixed','discount_value'=>'250.0000','valid_from'=>'2026-01-01','valid_until'=>'2026-12-31','status'=>'active']);
  (require database_path('migrations/2026_09_29_000002_add_finance_collection_v2_documents.php'))->up();
  $applicationsBefore=DB::connection('mysql')->table('central_finance_promotion_applications')->count();
  $quote=$promotions->previewForFeeSetup(CentralFinanceUser::on('mysql')->findOrFail(300),1,1,$promotion->id,'1000.0000',CarbonImmutable::parse('2026-08-21','Asia/Yangon'));
  $this->assertSame('250.0000',$quote['discount']); $this->assertSame('750.0000',$quote['net']); $this->assertSame($applicationsBefore,DB::connection('mysql')->table('central_finance_promotion_applications')->count());
  $application=$promotions->apply($this->head,$receivable->id,$promotion->id,CarbonImmutable::parse('2026-08-21','Asia/Yangon'),'Approved QA fixed promotion.',$this->at(),'L3-QA-FIXED');
  $this->assertSame('750.0000',(string)$receivable->fresh()->amount_due); $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('promotion',$promotion->id)); $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('promotion_application',$application->id)); $this->assertSame(CentralFinanceDataClassification::QA_TEST,$isolation->classification('receivable_adjustment',$application->adjustment_id));
  $promotion->update(['name'=>'Changed after use','discount_value'=>'300.0000']); $application->refresh(); $this->assertSame('QA fixed',$application->promotion_name_snapshot); $this->assertSame('250.0000',(string)$application->discount_value_snapshot);
  $inactive=\App\Models\CentralFinancePromotion::on('mysql')->create(['group_id'=>1,'name'=>'Inactive','code'=>'OFF','discount_type'=>'fixed','discount_value'=>'1.0000','valid_from'=>'2026-01-01','status'=>'inactive','fee_scope'=>'all_approved_fees','created_by'=>$this->head->id]); DB::connection('mysql')->table('central_finance_promotion_school_allocations')->insert(['promotion_id'=>$inactive->id,'school_id'=>1,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $this->assertFalse($promotions->eligibleFor($this->head,$receivable,CarbonImmutable::parse('2026-08-21','Asia/Yangon'))->pluck('id')->contains($inactive->id));
 }
 public function test_layer3_migration_is_additive_reversible_and_reapplicable_on_disposable_schema(): void {
  $migration=require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'); $migration->up();
  foreach(['central_finance_promotions','central_finance_promotion_school_allocations','central_finance_promotion_applications'] as $table) $this->assertTrue(Schema::connection('mysql')->hasTable($table));
  $this->assertTrue(Schema::connection('mysql')->hasColumns('central_finance_receivable_adjustments',['effective_date','amount_before','amount_after']));
  $migration->down(); foreach(['central_finance_promotions','central_finance_promotion_school_allocations','central_finance_promotion_applications'] as $table) $this->assertFalse(Schema::connection('mysql')->hasTable($table));
  $migration->up(); $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_promotion_applications'));
 }
 public function test_collection_v2_migration_is_additive_and_backfills_one_immutable_line_per_legacy_document(): void {
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  $receivable=$this->receivable($this->zixProfile);
  $payment=app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,100,'Cash',$this->at(),'P0-MIG-PAY','P0-MIG-PAY')['payment'];
  $pending=app(CentralFinancePendingCollectionService::class)->submit(CentralFinanceUser::on('mysql')->findOrFail(300),$this->zixProfile->id,$receivable->id,100,'Bank Transfer',$this->at(),'P0-MIG-PENDING',$this->hq->id,'P0-MIG-PENDING');
  $migration=require database_path('migrations/2026_09_29_000002_add_finance_collection_v2_documents.php'); $migration->up();
  foreach(['central_finance_payment_allocations','central_finance_pending_collection_allocations','central_finance_unidentified_deposits','central_finance_unidentified_deposit_allocations','central_finance_promotion_fee_allocations'] as $table) $this->assertTrue(Schema::connection('mysql')->hasTable($table));
  $this->assertTrue(Schema::connection('mysql')->hasColumns('central_finance_receivables',['unit_price_snapshot','quantity_snapshot']));
  $this->assertSame(1,DB::connection('mysql')->table('central_finance_payment_allocations')->where('payment_id',$payment->id)->count());
  $this->assertSame(1,DB::connection('mysql')->table('central_finance_pending_collection_allocations')->where('pending_collection_id',$pending->id)->count());
  $before=DB::connection('mysql')->table('central_finance_payment_allocations')->where('payment_id',$payment->id)->first(); $migration->up(); $after=DB::connection('mysql')->table('central_finance_payment_allocations')->where('payment_id',$payment->id)->first();
  $this->assertSame($before->allocation_uuid,$after->allocation_uuid);
  $migration->down(); $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_payment_allocations')); $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_receivables','unit_price_snapshot'));
  $migration->up(); $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_payment_allocations'));
}
 public function test_collection_v2_creates_one_parent_payment_receipt_and_ledger_for_explicit_multi_receivable_allocations(): void {
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  (require database_path('migrations/2026_09_29_000002_add_finance_collection_v2_documents.php'))->up();
  Config::set('database.connections.school.database',$this->a); DB::purge('school');
  DB::connection('school')->table('fees')->insert(['id'=>2,'name'=>'Zixuan Activity','due_date'=>'2026-09-02','created_at'=>now(),'updated_at'=>now()]);
  DB::connection('school')->table('fees_class_types')->insert(['id'=>2,'fees_id'=>2,'class_id'=>1,'amount'=>500,'optional'=>0,'fee_currency'=>'MMK','created_at'=>now(),'updated_at'=>now()]);
  $rows=app(CentralFinanceReceivableSyncService::class)->syncProfile($this->zixProfile); $receivables=CentralFinanceReceivable::on('mysql')->where('student_profile_id',$this->zixProfile->id)->orderBy('id')->get();
  $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $pending=app(CentralFinancePendingCollectionService::class)->submitAllocations($front,$this->zixProfile->id,[['receivable_id'=>$receivables[0]->id,'amount'=>'100.0000'],['receivable_id'=>$receivables[1]->id,'amount'=>'200.0000']],'Bank Transfer',$this->at(),'P0-MULTI-PENDING',$this->hq->id,'P0-MULTI-REF');
  $this->assertNull($pending->receivable_id); $this->assertSame('300.0000',(string)$pending->amount); $this->assertSame(2,$pending->allocations()->count()); $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
  app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$pending->id,$this->hq,$this->at(),'Confirmed multi allocation.');
  $payment=CentralFinancePayment::on('mysql')->sole(); $this->assertNull($payment->receivable_id); $this->assertSame('300.0000',(string)$payment->amount); $this->assertSame(2,$payment->allocations()->count()); $this->assertSame(1,DB::connection('mysql')->table('central_finance_receipts')->count()); $this->assertSame(1,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
  $lines=$payment->allocations()->orderBy('id')->get(); $this->assertSame('100.0000',(string)$lines[0]->amount); $this->assertSame('200.0000',(string)$lines[1]->amount); $this->assertNotNull($lines[0]->description_snapshot); $this->assertSame(1,(int)$lines[0]->quantity_snapshot);
  $this->assertSame('100.0000',(string)$receivables[0]->fresh()->amount_paid); $this->assertSame('200.0000',(string)$receivables[1]->fresh()->amount_paid);
  $receipt=app(\App\Services\CentralFinanceReceiptViewModelFactory::class)->make($payment->fresh(['allocations.receivable.studentProfile','fundAccount','receipt','receivedBy','refunds','reversal']), School::on('mysql')->findOrFail(1));
  $this->assertSame(2,count($receipt->payment['lines'])); $this->assertSame('300.0000',number_format($receipt->payment['this_payment'],4,'.',''));
  try { app(\App\Services\CentralFinancePaymentRefundService::class)->refund($this->head,$payment->id,$this->hq,1,'Cash',$this->at(),'Must not infer a line.',$this->at(),'P0-MULTI-REFUND'); $this->fail('A multi-receivable parent must not enter the legacy refund path.'); }
  catch (InvalidArgumentException $exception) { $this->assertStringContainsString('multi-receivable',$exception->getMessage()); }
 }
 public function test_pending_reference_is_rejected_before_a_future_head_finance_confirmation_can_fail(): void {
  $receivable=$this->receivable($this->zixProfile); $front=CentralFinanceUser::on('mysql')->findOrFail(300); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$receivable->id,100,'Bank Transfer',$this->at(),'P0-REFERENCE-FIRST',$this->hq->id,'BANK-REF-P0');
  try { app(CentralFinancePendingCollectionService::class)->submit($front,$this->zixProfile->id,$receivable->id,100,'Bank Transfer',$this->at(),'P0-REFERENCE-SECOND',$this->hq->id,'BANK-REF-P0'); $this->fail('A reference already reserved by a pending collection must fail before confirmation.'); }
  catch (InvalidArgumentException $exception) { $this->assertStringContainsString('reserved',$exception->getMessage()); }
  $this->assertSame(1,CentralFinancePendingCollection::on('mysql')->count()); $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); $this->assertSame(0,CentralFinanceLedgerEntry::on('mysql')->count());
 }
 public function test_quantity_priced_optional_line_is_immutable_through_payment_allocation_and_receipt(): void {
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  (require database_path('migrations/2026_09_29_000002_add_finance_collection_v2_documents.php'))->up();
  $receivable=$this->receivable($this->zixProfile);
  // Compulsory tuition is projected with the fixed one-unit default.
  $this->assertSame(1,(int) $receivable->quantity_snapshot);
  $receivable->update(['description'=>'Uniform','source_amount_due'=>'100000.0000','amount_due'=>'100000.0000','unit_price_snapshot'=>'50000.0000','quantity_snapshot'=>2]);
  $payment=app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->zix,'100000.0000','Cash',$this->at(),'P0-UNIFORM-X2','P0-UNIFORM-X2')['payment'];
  $line=$payment->allocations()->sole();
  $this->assertSame('50000.0000',(string) $line->unit_price_snapshot); $this->assertSame(2,(int) $line->quantity_snapshot); $this->assertSame('100000.0000',(string) $line->gross_amount_snapshot); $this->assertSame('100000.0000',(string) $line->amount);
  // A later mutable receivable projection cannot change the allocation or
  // receipt because both use immutable payment-allocation snapshots.
  $receivable->update(['unit_price_snapshot'=>'75000.0000']); $line->refresh();
  $receipt=app(\App\Services\CentralFinanceReceiptViewModelFactory::class)->make($payment->fresh(['allocations.receivable.studentProfile','fundAccount','receipt','receivedBy','refunds','reversal']), School::on('mysql')->findOrFail(1));
  $this->assertSame('50000.0000',(string) $receipt->payment['lines'][0]['unit_price']); $this->assertSame(2,$receipt->payment['lines'][0]['quantity']); $this->assertSame('100000.0000',(string) $receipt->payment['lines'][0]['gross']);
  $html=view('central-finance.partials.receipt-document', ['receipt'=>$receipt, 'audits'=>collect()])->render();
  $this->assertStringContainsString('50,000.00 MMK',$html); $this->assertStringContainsString('100,000.00 MMK',$html);
 }
 public function test_unidentified_deposit_changes_physical_balance_once_then_matching_only_settles_the_receivable(): void {
  (require database_path('migrations/2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php'))->up();
  (require database_path('migrations/2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php'))->up();
  (require database_path('migrations/2026_09_29_000002_add_finance_collection_v2_documents.php'))->up();
  (require database_path('migrations/2026_10_07_000001_close_unidentified_deposit_p0.php'))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $receivable=$this->receivable($this->zixProfile); $service=app(\App\Services\CentralFinanceUnidentifiedDepositService::class);
  $deposit=$service->record($this->head,$this->hq,'250.0000',$this->at(),'P0-UNIDENTIFIED-1','BANK-UNIDENTIFIED-1','Unknown bank sender.');
  $retry=$service->record($this->head,$this->hq,'250.0000',$this->at(),'P0-UNIDENTIFIED-1','BANK-UNIDENTIFIED-1','Unknown bank sender.');
  $this->assertSame($deposit->id,$retry->id); $ledger=CentralFinanceLedgerEntry::on('mysql')->sole(); $this->assertNull($ledger->school_id); $this->assertSame('250.0000',(string)$ledger->money_in); $this->assertSame('0.0000',(string)$ledger->operating_income);
  $allocation=$service->match($this->head,$deposit->id,$receivable->id,'250.0000',$this->at(),'Matched by remittance advice.','P0-UNIDENTIFIED-MATCH-1');
  $this->assertSame('250.0000',(string)$allocation->amount); $this->assertSame(2,CentralFinanceLedgerEntry::on('mysql')->count()); $this->assertSame(1,CentralFinancePayment::on('mysql')->count()); $this->assertSame(CentralFinancePayment::on('mysql')->sole()->id,(int)$allocation->payment_id); $this->assertSame(1,DB::connection('mysql')->table('central_finance_payment_allocations')->count()); $this->assertSame(1,DB::connection('mysql')->table('central_finance_receipts')->count()); $this->assertSame(250.0,app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->hq)); $this->assertSame('250.0000',(string)$receivable->fresh()->amount_paid); $this->assertSame('applied',$deposit->fresh()->status);
 }
 private function receivable(CentralFinanceStudentProfile $p): CentralFinanceReceivable {
  if (app(CentralFinanceDataIsolationService::class)->isQaTestSchool((int) $p->school_id)) {
   $this->confirmedQaAssignmentFixture($p);
  }
  app(CentralFinanceReceivableSyncService::class)->syncProfile($p);
  return CentralFinanceReceivable::on('mysql')->where('student_profile_id',$p->id)->firstOrFail();
 }

 /**
  * These legacy payment fixtures have no assignment schema. QA templates now
  * require an explicit confirmed, classified Student snapshot before projection.
  * Add only that source fixture; keep the Central pre-V2 migration shape and
  * the Official legacy class-fee path used by the other tests unchanged.
  */
 private function confirmedQaAssignmentFixture(CentralFinanceStudentProfile $profile): void {
  $original = config('database.connections.school.database');
  $database = School::on('mysql')->findOrFail($profile->school_id)->database_name;
  Config::set('database.connections.school.database', $database);
  DB::purge('school');
  try {
   if (!Schema::connection('school')->hasTable('student_fee_assignments')) {
    $this->assertSame([], app(\App\Services\CentralFinanceTenantFeeAssignmentSource::class)->allForProfile($profile));
    (require database_path('migrations/schools/2026_08_27_000001_create_student_fee_assignment_tables.php'))->up();
    Schema::connection('school')->create('fees_types', function (Blueprint $table): void {
     $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name'); $table->timestamps();
    });
   }
   $db = DB::connection('school');
   if ($db->table('student_fee_assignments')->where('student_id', $profile->tenant_student_id)->exists()) return;
   $feeTypeId = $db->table('fees_types')->insertGetId([
    'school_id' => $profile->school_id, 'name' => 'QA payment source', 'created_at' => now(), 'updated_at' => now(),
   ]);
   $assignmentId = $db->table('student_fee_assignments')->insertGetId([
    'uuid' => (string) Str::uuid(), 'school_id' => $profile->school_id, 'student_id' => $profile->tenant_student_id,
    'academic_year_id' => 1, 'class_id' => $profile->class_id, 'status' => 'confirmed',
    'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
   ]);
   $subjects = [['student_fee_assignment', $assignmentId], ['fee_type', $feeTypeId]];
   foreach ($db->table('fees_class_types')->where('class_id', $profile->class_id)->where('optional', false)->get() as $template) {
    $fee = $db->table('fees')->where('id', $template->fees_id)->sole();
    $itemId = $db->table('student_fee_assignment_items')->insertGetId([
     'uuid' => (string) Str::uuid(), 'student_fee_assignment_id' => $assignmentId,
     'fee_id' => $fee->id, 'fees_class_type_id' => $template->id, 'fees_type_id' => $feeTypeId,
     'description_snapshot' => $fee->name, 'due_date_snapshot' => $fee->due_date,
     'amount_snapshot' => $template->amount, 'currency_snapshot' => $template->fee_currency ?: 'MMK',
     'optional_snapshot' => false, 'source_type' => 'fees_class_type', 'source_id' => (string) $template->id,
     'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $subjects[] = ['fee', (int) $fee->id];
    $subjects[] = ['fee_item', (int) $template->id];
    $subjects[] = ['student_fee_assignment_item', $itemId];
   }
   $isolation = app(CentralFinanceDataIsolationService::class);
   foreach ($subjects as [$type, $id]) {
    $isolation->classify($this->head, (int) $profile->school_id, $type, $id,
     CentralFinanceDataClassification::QA_TEST, 'Explicit synthetic confirmed QA payment source.');
   }
  } finally {
   DB::purge('school');
   Config::set('database.connections.school.database', $original);
  }
 }
 private function at():CarbonImmutable{return CarbonImmutable::parse('2026-08-21 10:00','Asia/Yangon');}
 /** Minimal additive identity fence preserves this suite's intentional pre-V2 migration fixtures. */
 private function bankIdentityFixture(): void {
  Schema::connection('mysql')->create('central_finance_bank_transaction_identities',function(Blueprint $t): void {
   $t->id(); $t->unsignedBigInteger('fund_account_id'); $t->string('currency',3); $t->string('identity_hash',64);
   $t->string('identity_namespace',24); $t->string('normalized_identity',100); $t->text('manual_reason')->nullable();
   $t->string('source_type',32); $t->string('source_id',64); $t->decimal('amount',20,4); $t->string('payload_hash',64)->nullable(); $t->timestamps();
   $t->unique(['fund_account_id','currency','identity_hash'],'cfbti_physical_identity_unique'); $t->unique(['source_type','source_id'],'cfbti_origin_unique');
   $t->foreign('fund_account_id','cfbti_account_fk')->references('id')->on('central_finance_fund_accounts')->restrictOnDelete();
  });
  Schema::connection('mysql')->table('central_finance_payments',function(Blueprint $t): void { $t->string('request_hash',64)->nullable(); $t->unsignedBigInteger('unidentified_deposit_id')->nullable(); });
 }
 private function profile(int $school,string $uuid,?int $tenantStudentId=null):CentralFinanceStudentProfile{return CentralFinanceStudentProfile::on('mysql')->create(['school_id'=>$school,'tenant_student_id'=>$tenantStudentId ?? $school,'source_uuid'=>$uuid,'class_id'=>1,'class_section_id'=>1,'student_name'=>'Student '.$school,'enrollment_status'=>'active','source_updated_at'=>now(),'last_synced_at'=>now()]);}
 private function account(string $code,string $name,string $type,?int $school):CentralFinanceFundAccount{$account=CentralFinanceFundAccount::on('mysql')->create(['account_uuid'=>(string)Str::uuid(),'group_id'=>1,'school_id'=>$school,'owner_type'=>$type,'account_code'=>$code,'account_name'=>$name,'account_type'=>str_contains(strtolower($name),'cash') ? 'cash' : 'bank','currency'=>'MMK','opening_balance'=>0,'is_active'=>true,'status'=>'active']);if($school!==null)$this->allocate($account,$school);return $account;}
 private function allocate(CentralFinanceFundAccount $account,int $school):void{DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->insert(['fund_account_id'=>$account->id,'school_id'=>$school,'opening_allocation_amount'=>0,'is_active'=>1,'status'=>'active','effective_from'=>now()->subDay()->toDateString(),'assignment_reason'=>'Central Fund Account V2 test allocation.','created_at'=>now(),'updated_at'=>now()]);}
 private function grant(CentralFinanceUser $u,CentralFinanceFundAccount $a):void{DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>$a->id,'user_id'=>$u->id,'can_view'=>1,'can_operate'=>1,'created_at'=>now(),'updated_at'=>now()]);}
 private function schoolGrant(CentralFinanceUser $u,int $s):void{DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>$u->id,'school_id'=>$s,'can_view'=>1,'can_operate'=>1,'created_at'=>now(),'updated_at'=>now()]);}
 private function tenant(string $db,string $name,float $amount):void{Config::set('database.connections.school.database',$db);DB::purge('school');Schema::connection('school')->create('students',fn(Blueprint $t)=>[$t->id(),$t->timestamps()]);Schema::connection('school')->create('fees',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->date('due_date')->nullable(),$t->timestamps()]);Schema::connection('school')->create('fees_class_types',fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('fees_id'),$t->unsignedBigInteger('class_id'),$t->decimal('amount',20,4),$t->boolean('optional')->default(false),$t->string('fee_currency',3)->nullable(),$t->timestamps()]);DB::connection('school')->table('students')->insert(['id'=>1,'created_at'=>now(),'updated_at'=>now()]);DB::connection('school')->table('fees')->insert(['id'=>1,'name'=>$name,'due_date'=>'2026-09-01','created_at'=>now(),'updated_at'=>now()]);DB::connection('school')->table('fees_class_types')->insert(['id'=>1,'fees_id'=>1,'class_id'=>1,'amount'=>$amount,'optional'=>0,'fee_currency'=>'MMK','created_at'=>now(),'updated_at'=>now()]);}
 private function tenantHash():string{$rows=[];foreach([$this->a,$this->b]as$db){Config::set('database.connections.school.database',$db);DB::purge('school');$rows[]=DB::connection('school')->table('fees')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();$rows[]=DB::connection('school')->table('fees_class_types')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();}return hash('sha256',serialize($rows));}
}
