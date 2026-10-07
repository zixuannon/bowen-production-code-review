<?php
namespace Tests\Feature;
use App\Http\Controllers\CentralFinanceStudentCollectionController;
use App\Http\Controllers\CentralFinancePendingCollectionController;
use App\Http\Controllers\CentralFinanceWorkspaceController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceCollectionHandoverBatch; use App\Models\CentralFinanceDataClassification; use App\Models\CentralFinanceFundAccount; use App\Models\CentralFinancePayment; use App\Models\CentralFinancePendingCollection; use App\Models\CentralFinanceReceivable; use App\Models\CentralFinanceStudentProfile; use App\Models\CentralFinanceUser; use App\Models\School; use App\Services\CentralFinanceCollectionHandoverService; use App\Services\CentralFinanceDataIsolationService; use App\Services\CentralFinanceFundAccountAdministrationService; use App\Services\CentralFinanceFundAccountBalanceService; use App\Services\CentralFinanceHeadFinanceHandoverConfirmService; use App\Services\CentralFinancePaymentService; use App\Services\CentralFinancePendingCollectionConfirmationService; use App\Services\CentralFinancePendingCollectionService; use App\Services\CentralFinanceReceivableSyncService; use App\Services\CentralFinanceWorkspaceService; use Carbon\CarbonImmutable; use Illuminate\Auth\Access\AuthorizationException; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Config; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema; use Illuminate\Support\Facades\Session; use Illuminate\Support\Str; use App\Models\CentralFinanceUnidentifiedDeposit; use App\Services\CentralFinanceUnidentifiedDepositService; use App\Services\CentralFinancePaymentImportService; use App\Services\CentralFinanceReceiptViewModelFactory; use InvalidArgumentException; use Symfony\Component\HttpKernel\Exception\HttpException; use Tests\TestCase;

/** Disposable SQLite Central and tenant fixtures; never connects to the shared test DB. */
final class CentralFinanceUnidentifiedDepositQaRunTest extends TestCase {
 private string $central; private string $a; private string $b; private CentralFinanceUser $head; private CentralFinanceUser $accountant; private CentralFinanceFundAccount $hq; private CentralFinanceFundAccount $zix; private CentralFinanceStudentProfile $zixProfile; private CentralFinanceStudentProfile $timeProfile;
 protected function setUp(): void { parent::setUp(); @mkdir(storage_path('framework/views'),0777,true); $this->central=tempnam(sys_get_temp_dir(),'cf_recv_c_'); $this->a=tempnam(sys_get_temp_dir(),'cf_recv_a_'); $this->b=tempnam(sys_get_temp_dir(),'cf_recv_b_'); Config::set('database.connections.mysql',['driver'=>'sqlite','database'=>$this->central,'prefix'=>'','foreign_key_constraints'=>true]); Config::set('database.connections.school',['driver'=>'sqlite','database'=>$this->a,'prefix'=>'','foreign_key_constraints'=>true]); DB::purge('mysql'); DB::purge('school'); DB::setDefaultConnection('mysql');
  Schema::connection('mysql')->create('schools',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->string('code')->nullable(),$t->string('database_name')->nullable(),$t->softDeletes(),$t->timestamps()]); Schema::connection('mysql')->create('users',fn(Blueprint $t)=>[$t->id(),$t->string('first_name')->nullable(),$t->string('last_name')->nullable(),$t->unsignedBigInteger('school_id')->nullable(),$t->softDeletes(),$t->timestamps()]); Schema::connection('mysql')->create('roles',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->string('guard_name')->default('web'),$t->timestamps()]); Schema::connection('mysql')->create('model_has_roles',fn(Blueprint $t)=>[$t->unsignedBigInteger('role_id'),$t->string('model_type'),$t->unsignedBigInteger('model_id')]);
  (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up(); (require database_path('migrations/2026_08_20_000003_create_central_finance_student_sync_tables.php'))->up(); (require database_path('migrations/2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php'))->up(); (require database_path('migrations/2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php'))->up(); (require database_path('migrations/2026_08_26_000002_add_master_data_to_central_finance_fund_accounts.php'))->up(); (require database_path('migrations/2026_09_03_000001_create_central_finance_fund_account_school_allocations.php'))->up(); (require database_path('migrations/2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php'))->up(); (require database_path('migrations/2026_08_25_000002_create_central_finance_payment_refunds.php'))->up(); (require database_path('migrations/2026_08_21_000002_create_central_finance_operating_documents.php'))->up(); (require database_path('migrations/2026_08_27_000002_add_note_to_central_finance_payments.php'))->up(); (require database_path('migrations/2026_08_21_000005_create_central_finance_school_cutovers.php'))->up(); (require database_path('migrations/2026_08_25_000001_create_central_finance_receivable_sync_events.php'))->up(); (require database_path('migrations/2026_08_25_000003_create_central_finance_receivable_adjustments.php'))->up(); (require database_path('migrations/2026_08_25_000005_add_fresh_start_receivable_cutoff.php'))->up(); (require database_path('migrations/2026_08_24_000002_create_central_finance_school_staff_identities.php'))->up(); (require database_path('migrations/2026_09_04_000001_create_central_finance_pending_collections.php'))->up(); (require database_path('migrations/2026_09_08_000001_create_central_finance_collection_handover_batches.php'))->up(); (require database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php'))->up(); (require database_path('migrations/2026_09_28_000001_add_payment_correction_fields_and_reversals.php'))->up();
  DB::connection('mysql')->table('schools')->insert([['id'=>1,'name'=>'Zixuan QA','code'=>'ZIX','database_name'=>$this->a,'created_at'=>now(),'updated_at'=>now()],['id'=>2,'name'=>'Timecity QA','code'=>'TIM','database_name'=>$this->b,'created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('users')->insert([['id'=>100,'first_name'=>'Head','last_name'=>'Finance','school_id'=>null,'central_finance_principal_type'=>'central_user','created_at'=>now(),'updated_at'=>now()],['id'=>200,'first_name'=>'School','last_name'=>'Accountant','school_id'=>null,'central_finance_principal_type'=>'central_user','created_at'=>now(),'updated_at'=>now()],['id'=>300,'first_name'=>'Front','last_name'=>'Desk','school_id'=>1,'central_finance_principal_type'=>'school_staff_identity','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('central_finance_school_staff_identities')->insert(['identity_uuid'=>'00000000-0000-4000-8000-000000000300','school_id'=>1,'tenant_user_uuid'=>'00000000-0000-4000-8000-000000000301','central_user_id'=>300,'status'=>'active','created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('roles')->insert(['id'=>1,'name'=>'Head Finance','guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('model_has_roles')->insert(['role_id'=>1,'model_type'=>\App\Models\User::class,'model_id'=>100]); DB::connection('mysql')->table('finance_groups')->insert(['id'=>1,'name'=>'QA','status'=>'active','reporting_currency'=>'MMK','fiscal_year_start_month'=>1,'created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('finance_group_schools')->insert([['group_id'=>1,'school_id'=>1,'status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_id'=>1,'school_id'=>2,'status'=>'active','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('finance_group_users')->insert([['id'=>1,'group_id'=>1,'central_user_id'=>100,'status'=>'active','created_at'=>now(),'updated_at'=>now()],['id'=>2,'group_id'=>1,'central_user_id'=>300,'status'=>'active','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('finance_group_user_scopes')->insert([['group_user_id'=>1,'school_id'=>1,'scope_type'=>'SCHOOL','capability'=>'view_reports','scope_key'=>'school:1','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>1,'school_id'=>1,'scope_type'=>'SCHOOL','capability'=>'operate_finance','scope_key'=>'school:1','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>1,'school_id'=>2,'scope_type'=>'SCHOOL','capability'=>'view_reports','scope_key'=>'school:2','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>1,'school_id'=>2,'scope_type'=>'SCHOOL','capability'=>'operate_finance','scope_key'=>'school:2','status'=>'active','created_at'=>now(),'updated_at'=>now()],['group_user_id'=>2,'school_id'=>1,'scope_type'=>'SCHOOL','capability'=>'view_reports','scope_key'=>'school:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]]); DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>300,'school_id'=>1,'can_view'=>1,'can_operate'=>0,'can_submit_collections'=>1,'created_at'=>now(),'updated_at'=>now()]); DB::connection('mysql')->table('central_finance_school_cutovers')->insert([['school_id'=>1,'status'=>'central','receivable_sync_effective_at'=>'2026-08-01 00:00:00','cutover_at'=>now(),'created_at'=>now(),'updated_at'=>now()],['school_id'=>2,'status'=>'central','receivable_sync_effective_at'=>'2026-08-01 00:00:00','cutover_at'=>now(),'created_at'=>now(),'updated_at'=>now()]]); $this->head=CentralFinanceUser::on('mysql')->findOrFail(100); $this->accountant=CentralFinanceUser::on('mysql')->findOrFail(200);
  $this->tenant($this->a,'Zixuan Tuition',1000); $this->tenant($this->b,'Timecity Tuition',2000);
  $this->zixProfile=$this->profile(1,'11111111-1111-4111-8111-111111111111'); $this->timeProfile=$this->profile(2,'22222222-2222-4222-8222-222222222222'); $this->hq=$this->account('CF-HQ','HQ Bank','hq',null); $this->allocate($this->hq,1); $this->allocate($this->hq,2); $this->zix=$this->account('CF-ZIX','Zixuan Cash','school',1); foreach([$this->hq,$this->zix] as $account) $this->grant($this->head,$account); $this->grant($this->accountant,$this->zix); $this->schoolGrant($this->head,1); $this->schoolGrant($this->head,2); $this->schoolGrant($this->accountant,1);
  foreach ([
   '2026_09_03_000002_add_student_code_to_central_finance_student_profiles.php',
   '2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php',
   '2026_09_29_000001_add_central_finance_layer3_receivable_promotions.php',
   '2026_09_29_000002_add_finance_collection_v2_documents.php',
   '2026_08_24_000001_create_central_finance_import_batches.php',
   '2026_10_07_000001_close_unidentified_deposit_p0.php',
   '2026_10_05_000001_create_central_finance_qa_runs.php',
  ] as $migration) (require database_path('migrations/'.$migration))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $this->zixProfile->update(['admission_no'=>'GR-000001','student_code'=>'BOWEN-000001']);
  $this->travelTo(CarbonImmutable::parse('2026-08-10 14:30:00', 'Asia/Yangon'));
  DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->update(['effective_from'=>'2026-01-01']);
 }
 protected function tearDown(): void { $this->travelBack(); DB::purge('mysql');DB::purge('school');foreach([$this->central,$this->a,$this->b] as $f)@unlink($f);parent::tearDown(); }


 private function qaContext(?string $status='active',int $number=1): ?\App\Models\CentralFinanceQaRun
 {
  if (!Schema::connection('mysql')->hasColumn('schools','installed')) Schema::connection('mysql')->table('schools',fn(Blueprint $t)=>[$t->boolean('installed')->default(true),$t->string('status')->default('active')]);
  DB::connection('mysql')->table('schools')->where('id',1)->update(['code'=>'MMBOWEN01','installed'=>true,'status'=>'active']);
  Config::set('finance_release.p31_p32_tenants.MMBOWEN01',$this->a);
  foreach ([['school',1],['fund_account',$this->hq->id],['student_profile',$this->zixProfile->id]] as [$type,$id]) {
   if (!DB::connection('mysql')->table('central_finance_data_classifications')->where(['subject_scope'=>'central','subject_type'=>$type,'subject_id'=>$id])->exists()) $this->classify($type,$id);
  }
  DB::connection('mysql')->table('central_finance_data_classifications')->where(['subject_type'=>'fund_account','subject_id'=>$this->hq->id])->update(['school_id'=>1]);
  return $status===null ? null : \App\Models\CentralFinanceQaRun::on('mysql')->create(['school_id'=>1,'run_number'=>$number,'label'=>'Synthetic QA '.$number,'status'=>$status,'created_by'=>$this->head->id]);
 }
 private function qaTarget(\App\Models\CentralFinanceQaRun $run,string $amount='500000.0000'): CentralFinanceReceivable
 {
  $target=$this->target($amount,'Run tuition','run:'.$run->id);
  $this->classify('receivable',$target->id);
  \App\Models\CentralFinanceQaRunRecord::on('mysql')->create(['qa_run_id'=>$run->id,'school_id'=>1,'subject_scope'=>'central','subject_type'=>'receivable','subject_id'=>$target->id]);
  return $target;
 }
 public function test_active_qa_run_registers_unknown_cash_and_canonical_allocation_without_school_cash_attribution(): void
 {
  $run=$this->qaContext(); $target=$this->qaTarget($run); $deposit=$this->deposit();
  $runs=app(\App\Services\CentralFinanceQaRunService::class);
  $ledger=CentralFinanceLedgerEntry::on('mysql')->where('source_type','central_unidentified_deposit')->sole();
  $audit=DB::connection('mysql')->table('central_finance_document_audits')->where(['document_type'=>'unidentified_deposit','document_id'=>$deposit->id])->sole();
  $this->assertNull($ledger->school_id); $this->assertNull($audit->school_id);
  $this->assertNull(DB::connection('mysql')->table('central_finance_data_classifications')->where(['subject_type'=>'unidentified_deposit','subject_id'=>$deposit->id])->value('school_id'));
  foreach ([['unidentified_deposit',$deposit->id],['ledger',$ledger->id],['audit',$audit->id]] as [$type,$id]) $this->assertSame($run->id,$runs->runForRecord('central',$type,$id)->id);
  $before=$this->balance(); $allocation=$this->apply($deposit,$target,'500000.0000');
  $this->assertSame($before,$this->balance()); $this->assertSame(500000.0,$before);
  $payment=CentralFinancePayment::on('mysql')->findOrFail($allocation->payment_id);
  foreach ([['unidentified_deposit_allocation',$allocation->id],['payment',$payment->id],['payment_allocation',$payment->allocations()->sole()->id],['receipt',$payment->receipt->id]] as [$type,$id]) $this->assertSame($run->id,$runs->runForRecord('central',$type,$id)->id);
  $matchedAudit=DB::connection('mysql')->table('central_finance_document_audits')->where(['document_type'=>'unidentified_deposit_allocation','document_id'=>$allocation->id])->sole();
  $this->assertSame($run->id,$runs->runForRecord('central','audit',$matchedAudit->id)->id);
  $completed=$runs->complete($this->head,$run->id);
  $this->assertSame(1,$completed->completion_summary['record_counts']['unidentified_deposit']);
  $this->assertSame(1,$completed->completion_summary['record_counts']['unidentified_deposit_allocation']);
  $this->assertSame(2,$completed->completion_summary['record_counts']['ledger']);
  $runs->archive($this->head,$run->id,'Synthetic run complete.');
  $counts=$this->financeCounts();
  $this->assertSame($deposit->id,$this->deposit()->id);
  $this->assertSame($allocation->id,$this->apply($deposit,$target,'500000.0000')->id);
  $this->assertSame($counts,$this->financeCounts());
  $this->assertNull($ledger->fresh()->school_id);
 }
 #[\PHPUnit\Framework\Attributes\DataProvider('closedStatuses')]
 public function test_missing_or_nonactive_run_blocks_new_qa_cash_before_any_posting(?string $status): void
 {
  $this->qaContext($status); $before=$this->financeCounts();
  $this->denied(fn()=>$this->deposit());
  $this->assertSame($before,$this->financeCounts());
  $this->assertSame(0,DB::connection('mysql')->table('central_finance_bank_transaction_identities')->count());
 }
 public static function closedStatuses(): array { return [[null],['preparing'],['completed'],['archived']]; }
 public function test_archived_run_deposit_cannot_be_applied_to_a_new_run_receivable(): void
 {
  $run=$this->qaContext(); $deposit=$this->deposit();
  $runs=app(\App\Services\CentralFinanceQaRunService::class);
  $runs->complete($this->head,$run->id); $runs->archive($this->head,$run->id,'Synthetic run closed.');
  $next=$this->qaContext('active',2); $target=$this->qaTarget($next);
  $before=$this->financeCounts(); $this->denied(fn()=>$this->apply($deposit,$target,'500000'));
  $this->assertSame($before,$this->financeCounts());
  $this->assertSame('0.0000',(string)$target->fresh()->amount_paid);
  $this->assertSame($run->id,$runs->runForRecord('central','unidentified_deposit',$deposit->id)->id);
 }
 public function test_even_two_active_fixture_runs_cannot_combine_cash_and_receivable(): void
 {
  $run=$this->qaContext(); $deposit=$this->deposit();
  $next=$this->qaContext('active',2); $target=$this->qaTarget($next);
  $before=$this->financeCounts(); $this->denied(fn()=>$this->apply($deposit,$target,'500000'));
  $this->assertSame($before,$this->financeCounts());
 }
 public function test_ambiguous_qa_account_context_blocks_new_cash(): void
 {
  $this->qaContext();
  DB::connection('mysql')->table('central_finance_data_classifications')->where(['subject_type'=>'fund_account','subject_id'=>$this->hq->id])->update(['school_id'=>null]);
  $before=$this->financeCounts(); $this->denied(fn()=>$this->deposit());
  $this->assertSame($before,$this->financeCounts());
 }
 public function test_explicit_archived_qa_school_context_blocks_new_cash(): void
 {
  $this->qaContext();
  DB::connection('mysql')->table('central_finance_data_classifications')->where(['subject_type'=>'school','subject_id'=>1])->update(['classification'=>'archived']);
  $before=$this->financeCounts(); $this->denied(fn()=>$this->deposit());
  $this->assertSame($before,$this->financeCounts());
 }
 public function test_completed_run_deposit_cannot_allocate_even_to_its_original_run(): void
 {
  $run=$this->qaContext(); $target=$this->qaTarget($run); $deposit=$this->deposit();
  app(\App\Services\CentralFinanceQaRunService::class)->complete($this->head,$run->id);
  $before=$this->financeCounts(); $this->denied(fn()=>$this->apply($deposit,$target,'500000'));
  $this->assertSame($before,$this->financeCounts());
 }
 public function test_historical_unassigned_qa_deposit_cannot_join_an_active_run(): void
 {
  // Legacy synthetic QA context predates the trusted permanent School identity.
  $this->classify('fund_account',$this->hq->id); $deposit=$this->deposit();
  $run=$this->qaContext(); $target=$this->qaTarget($run);
  $before=$this->financeCounts(); $this->denied(fn()=>$this->apply($deposit,$target,'500000'));
  $this->assertSame($before,$this->financeCounts());
  $this->assertNull(app(\App\Services\CentralFinanceQaRunService::class)->runForRecord('central','unidentified_deposit',$deposit->id));
 }
 public function test_official_unknown_cash_has_no_qa_run_membership(): void
 {
  $deposit=$this->deposit(); $target=$this->target(); $this->apply($deposit,$target,'500000');
  $this->assertSame(0,DB::connection('mysql')->table('central_finance_qa_run_records')->count());
  $this->assertSame(500000.0,$this->balance());
 }

 private function deposit(): CentralFinanceUnidentifiedDeposit
 {
  return app(CentralFinanceUnidentifiedDepositService::class)->record($this->head,$this->hq,'500000.0000',$this->bankAt(),'DEPOSIT-500','BANK-500','Awaiting remittance advice.','Known sender');
 }
 private function target(string $amount='500000.0000',string $description='Tuition',string $source='1'): CentralFinanceReceivable
 {
  return CentralFinanceReceivable::on('mysql')->create(['receivable_uuid'=>(string)Str::uuid(),'school_id'=>1,'student_profile_id'=>$this->zixProfile->id,'source_type'=>'local_synthetic','source_id'=>$source,'description'=>$description,'currency'=>'MMK','amount_due'=>$amount,'source_amount_due'=>$amount,'amount_paid'=>'0.0000','status'=>'open','unit_price_snapshot'=>$amount,'quantity_snapshot'=>1]);
 }
 private function apply(CentralFinanceUnidentifiedDeposit $deposit,CentralFinanceReceivable $receivable,string $amount,string $key='APPLY-500')
 {
  return app(CentralFinanceUnidentifiedDepositService::class)->match($this->head,$deposit->id,$receivable->id,$amount,$this->allocatedAt(),'Matched by remittance advice.',$key);
 }
 private function bankAt(): CarbonImmutable { return CarbonImmutable::parse('2026-06-15 10:00:00','Asia/Yangon'); }
 private function allocatedAt(): CarbonImmutable { return CarbonImmutable::parse('2026-08-10 14:30:00','Asia/Yangon'); }
 private function balance(): float { return app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->hq); }
 private function financeCounts(): array
 {
  return collect(['central_finance_unidentified_deposits','central_finance_unidentified_deposit_allocations','central_finance_payments','central_finance_payment_allocations','central_finance_receipts','central_finance_ledger_entries','central_finance_document_audits'])->mapWithKeys(fn($table)=>[$table=>DB::connection('mysql')->table($table)->count()])->all();
 }
 private function denied(callable $operation): void
 {
  try { $operation(); $this->fail('Unsafe finance operation should have been denied.'); }
  catch (InvalidArgumentException|AuthorizationException|ModelNotFoundException $error) { $this->assertNotSame('',$error->getMessage()); }
 }
 private function classify(string $type,int $id): void
 {
  DB::connection('mysql')->table('central_finance_data_classifications')->insert(['classification_uuid'=>(string)Str::uuid(),'school_id'=>$type==='fund_account'?null:1,'subject_scope'=>'central','subject_type'=>$type,'subject_id'=>$id,'classification'=>'qa_test','reason'=>'Disposable QA fixture.','classified_by'=>$this->head->id,'created_at'=>now(),'updated_at'=>now()]);
 }
 private function pending(CentralFinanceReceivable $receivable,string $amount,string $reference): CentralFinancePendingCollection
 {
  $pending=CentralFinancePendingCollection::on('mysql')->create(['school_id'=>1,'student_profile_id'=>$this->zixProfile->id,'receivable_id'=>$receivable->id,'intended_fund_account_id'=>$this->hq->id,'idempotency_key'=>hash('sha256','fixture-'.$reference),'acknowledgement_no'=>'PCA-'.$reference,'status'=>'submitted','amount'=>$amount,'currency'=>'MMK','payment_method'=>'Bank Transfer','payment_reference'=>$reference,'collected_at'=>$this->bankAt(),'collected_by'=>300,'submitted_by'=>300,'submitted_at'=>now()]);
  DB::connection('mysql')->table('central_finance_pending_collection_allocations')->insert(['allocation_uuid'=>(string)Str::uuid(),'pending_collection_id'=>$pending->id,'receivable_id'=>$receivable->id,'school_id'=>1,'student_profile_id'=>$this->zixProfile->id,'description_snapshot'=>$receivable->description,'quantity_snapshot'=>1,'gross_amount_snapshot'=>$receivable->amount_due,'net_due_snapshot'=>$receivable->amount_due,'outstanding_before_snapshot'=>$receivable->amount_due,'amount'=>$amount,'currency'=>'MMK','created_at'=>now(),'updated_at'=>now()]);
  return $pending;
 }
 private function profile(int $school,string $uuid,?int $tenantStudentId=null):CentralFinanceStudentProfile{return CentralFinanceStudentProfile::on('mysql')->create(['school_id'=>$school,'tenant_student_id'=>$tenantStudentId ?? $school,'source_uuid'=>$uuid,'class_id'=>1,'class_section_id'=>1,'student_name'=>'Student '.$school,'enrollment_status'=>'active','source_updated_at'=>now(),'last_synced_at'=>now()]);}
 private function account(string $code,string $name,string $type,?int $school):CentralFinanceFundAccount{$account=CentralFinanceFundAccount::on('mysql')->create(['account_uuid'=>(string)Str::uuid(),'group_id'=>1,'school_id'=>$school,'owner_type'=>$type,'account_code'=>$code,'account_name'=>$name,'account_type'=>str_contains(strtolower($name),'cash') ? 'cash' : 'bank','currency'=>'MMK','opening_balance'=>0,'is_active'=>true,'status'=>'active']);if($school!==null)$this->allocate($account,$school);return $account;}
 private function allocate(CentralFinanceFundAccount $account,int $school):void{DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->insert(['fund_account_id'=>$account->id,'school_id'=>$school,'opening_allocation_amount'=>0,'is_active'=>1,'status'=>'active','effective_from'=>now()->subDay()->toDateString(),'assignment_reason'=>'Central Fund Account V2 test allocation.','created_at'=>now(),'updated_at'=>now()]);}
 private function grant(CentralFinanceUser $u,CentralFinanceFundAccount $a):void{DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>$a->id,'user_id'=>$u->id,'can_view'=>1,'can_operate'=>1,'created_at'=>now(),'updated_at'=>now()]);}
 private function schoolGrant(CentralFinanceUser $u,int $s):void{DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>$u->id,'school_id'=>$s,'can_view'=>1,'can_operate'=>1,'created_at'=>now(),'updated_at'=>now()]);}
 private function tenant(string $db,string $name,float $amount):void{Config::set('database.connections.school.database',$db);DB::purge('school');Schema::connection('school')->create('students',fn(Blueprint $t)=>[$t->id(),$t->timestamps()]);Schema::connection('school')->create('fees',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->date('due_date')->nullable(),$t->timestamps()]);Schema::connection('school')->create('fees_class_types',fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('fees_id'),$t->unsignedBigInteger('class_id'),$t->decimal('amount',20,4),$t->boolean('optional')->default(false),$t->string('fee_currency',3)->nullable(),$t->timestamps()]);DB::connection('school')->table('students')->insert(['id'=>1,'created_at'=>now(),'updated_at'=>now()]);DB::connection('school')->table('fees')->insert(['id'=>1,'name'=>$name,'due_date'=>'2026-09-01','created_at'=>now(),'updated_at'=>now()]);DB::connection('school')->table('fees_class_types')->insert(['id'=>1,'fees_id'=>1,'class_id'=>1,'amount'=>$amount,'optional'=>0,'fee_currency'=>'MMK','created_at'=>now(),'updated_at'=>now()]);}
 private function tenantHash():string{$rows=[];foreach([$this->a,$this->b]as$db){Config::set('database.connections.school.database',$db);DB::purge('school');$rows[]=DB::connection('school')->table('fees')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();$rows[]=DB::connection('school')->table('fees_class_types')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();}return hash('sha256',serialize($rows));}
}
