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
class CentralFinanceUnidentifiedDepositP0Test extends TestCase {
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
  ] as $migration) (require database_path('migrations/'.$migration))->up();
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>1,'school_id'=>null,'scope_type'=>'GROUP','capability'=>'manage_hq_accounts','scope_key'=>'group:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $this->zixProfile->update(['admission_no'=>'GR-000001','student_code'=>'BOWEN-000001']);
  $this->travelTo(CarbonImmutable::parse('2026-08-10 14:30:00', 'Asia/Yangon'));
  DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->update(['effective_from'=>'2026-01-01']);
 }
 protected function tearDown(): void { $this->travelBack(); DB::purge('mysql');DB::purge('school');foreach([$this->central,$this->a,$this->b] as $f)@unlink($f);parent::tearDown(); }

 public function test_initial_bank_fact_has_one_cash_movement_and_no_school_revenue(): void
 {
  $before=$this->balance();
  $deposit=$this->deposit();
  $retry=$this->deposit();
  $this->assertSame($deposit->id,$retry->id);
  $this->assertSame(500000.0,$this->balance()-$before);
  $this->assertSame('2026-06-15',$deposit->received_date->toDateString());
  $this->assertSame('2026-08-10',$deposit->recorded_at->toDateString());
  $this->assertSame('2026-08-10',$deposit->created_at->toDateString());
  $this->assertSame('BANK-500',$deposit->bank_reference);
  $this->assertSame('Known sender',$deposit->known_payer);
  $this->assertSame('Awaiting remittance advice.',$deposit->description);
  $this->assertSame($this->head->id,(int)$deposit->recorded_by);
  $ledger=CentralFinanceLedgerEntry::on('mysql')->sole();
  $this->assertNull($ledger->school_id);
  $this->assertSame('2026-06-15',$ledger->entry_date->toDateString());
  $this->assertSame('500000.0000',(string)$ledger->money_in);
  $this->assertSame('0.0000',(string)$ledger->operating_income);
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
  $this->assertSame(0.0,app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(1)['operating_income']);
 }

 public function test_full_allocation_uses_canonical_documents_without_a_second_physical_receipt(): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  $bankFact=CentralFinanceLedgerEntry::on('mysql')->sole()->getAttributes();
  $before=$this->balance();
  $allocation=$this->apply($deposit,$receivable,'500000.0000');
  $payment=CentralFinancePayment::on('mysql')->sole();
  $this->assertSame($payment->id,(int)$allocation->payment_id);
  $this->assertSame($deposit->id,(int)$payment->unidentified_deposit_id);
  $this->assertSame($receivable->id,(int)$payment->receivable_id);
  $this->assertSame(1,(int)$payment->school_id);
  $this->assertSame('500000.0000',(string)$payment->amount);
  // Multiple legitimate allocations link the one original reference through
  // the deposit; they do not claim independent physical bank references.
  $this->assertNull($payment->payment_reference);
  $this->assertSame('2026-06-15',$payment->paid_at->toDateString());
  $this->assertSame('2026-08-10',$allocation->matched_at->toDateString());
  $this->assertSame('2026-08-10',$payment->receipt->issued_at->toDateString());
  $line=$payment->allocations()->sole();
  $this->assertSame($receivable->id,(int)$line->receivable_id);
  $this->assertSame($this->zixProfile->id,(int)$line->student_profile_id);
  $this->assertSame('500000.0000',(string)$line->amount);
  $this->assertSame('2026-08-10',$line->allocated_at->toDateString());
  $this->assertSame('500000.0000',(string)$receivable->fresh()->amount_paid);
  $this->assertSame(CentralFinanceReceivable::PAID,$receivable->fresh()->status);
  $this->assertSame('applied',$deposit->fresh()->status);
  $this->assertSame(0.0,$this->balance()-$before);
  $this->assertSame(500000.0,$this->balance());
  $this->assertSame($bankFact,CentralFinanceLedgerEntry::on('mysql')->findOrFail($bankFact['id'])->getAttributes());
  $attribution=CentralFinanceLedgerEntry::on('mysql')->where('school_id',1)->sole();
  $this->assertSame('0.0000',(string)$attribution->money_in);
  $this->assertSame('0.0000',(string)$attribution->money_out);
  $this->assertSame('500000.0000',(string)$attribution->operating_income);
  $totals=app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(1);
  $this->assertSame(0.0,$totals['money_in']); $this->assertSame(500000.0,$totals['operating_income']);
  $receipt=app(CentralFinanceReceiptViewModelFactory::class)->make($payment,School::on('mysql')->findOrFail(1));
  $this->assertSame('Student 1',$receipt->student['name']);
  $this->assertSame('GR-000001',$receipt->student['admission_no']);
  $this->assertSame('BOWEN-000001',$receipt->student['student_code']);
  $this->assertSame('Zixuan QA',$receipt->school['name']);
  $this->assertSame('BANK-500',$receipt->payment['unidentified_deposit']['reference']);
  $this->assertSame('2026-06-15',$receipt->payment['unidentified_deposit']['received_date']->toDateString());
  $this->assertSame('2026-08-10',$receipt->payment['unidentified_deposit']['allocated_at']->toDateString());
  $this->assertSame($deposit->deposit_uuid,$receipt->payment['unidentified_deposit']['deposit_uuid']);
  $this->assertSame('500000.0000',$receipt->payment['this_payment']);
  $this->assertSame('Tuition',$receipt->payment['lines'][0]['description']);
 }

 public function test_partial_then_same_receivable_allocation_and_exact_full_retry_converge(): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  $first=$this->apply($deposit,$receivable,'400000.0000','PART-1');
  $this->assertSame('partially_applied',$deposit->fresh()->status);
  $this->assertSame('400000.0000',(string)$receivable->fresh()->amount_paid);
  $this->assertSame(100000.0,(float)$deposit->amount-(float)$deposit->allocations()->sum('amount'));
  $second=$this->apply($deposit,$receivable,'100000.0000','PART-2');
  $counts=$this->financeCounts();
  $this->assertSame($second->id,$this->apply($deposit,$receivable,'100000.0000','PART-2')->id);
  $this->assertSame($first->id,$this->apply($deposit,$receivable,'400000.0000','PART-1')->id);
  $this->assertSame($counts,$this->financeCounts());
  $this->assertSame(2,$deposit->allocations()->count());
  $this->assertSame(2,CentralFinancePayment::on('mysql')->count());
  $this->assertSame('applied',$deposit->fresh()->status);
  $this->assertSame('paid',$receivable->fresh()->status);
  $this->assertSame(500000.0,$this->balance());
 }

 public function test_partial_deposit_can_settle_two_different_receivables(): void
 {
  $tuition=$this->target('400000.0000'); $uniform=$this->target('100000.0000','Uniform','2'); $deposit=$this->deposit();
  $this->apply($deposit,$tuition,'400000.0000','TUITION');
  $this->apply($deposit,$uniform,'100000.0000','UNIFORM');
  $this->assertSame('paid',$tuition->fresh()->status); $this->assertSame('paid',$uniform->fresh()->status);
  $this->assertSame('applied',$deposit->fresh()->status);
  $this->assertSame(500000.0,$this->balance());
 }

 /** @dataProvider conflictingAllocationContent */
 public function test_same_allocation_idempotency_key_rejects_changed_request(string $change): void
 {
  $receivable=$this->target('1000000.0000'); $other=$this->target('1000000.0000','Uniform','2'); $deposit=$this->deposit();
  $this->apply($deposit,$receivable,'100000.0000','ONE-KEY');
  $before=$this->financeCounts();
  $this->denied(fn()=>app(CentralFinanceUnidentifiedDepositService::class)->match(
   $this->head,$deposit->id,$change==='target'?$other->id:$receivable->id,
   $change==='amount'?'200000.0000':'100000.0000',$this->allocatedAt(),
   $change==='reason'?'Different advice.':'Matched by remittance advice.','ONE-KEY'));
  $this->assertSame($before,$this->financeCounts()); $this->assertSame(500000.0,$this->balance());
 }
 public static function conflictingAllocationContent(): array { return [['amount'],['target'],['reason']]; }

 public function test_record_retry_with_different_amount_is_denied(): void
 {
  $this->deposit(); $before=$this->financeCounts();
  $this->denied(fn()=>app(CentralFinanceUnidentifiedDepositService::class)->record($this->head,$this->hq,'499999.0000',$this->bankAt(),'DEPOSIT-500','BANK-500','Awaiting remittance advice.','Known sender'));
  $this->assertSame($before,$this->financeCounts()); $this->assertSame(500000.0,$this->balance());
 }

 public function test_deposit_reference_cannot_be_posted_as_normal_payment_even_with_different_case_or_amount(): void
 {
  $receivable=$this->target(); $this->deposit();
  $this->denied(fn()=>app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->hq,'100000.0000','Bank Transfer',$this->bankAt(),'NORMAL-PAY',' bank-500 '));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
  $this->assertSame('0.0000',(string)$receivable->fresh()->amount_paid);
  $this->assertSame(500000.0,$this->balance());
 }

 public function test_normal_payment_reference_cannot_be_recorded_as_unidentified_deposit(): void
 {
  $receivable=$this->target();
  app(CentralFinancePaymentService::class)->collect($this->head,$receivable->id,$this->hq,'500000.0000','Bank Transfer',$this->bankAt(),'NORMAL-FIRST','BANK-500');
  $this->denied(fn()=>$this->deposit());
  $this->assertSame(0,CentralFinanceUnidentifiedDeposit::on('mysql')->count()); $this->assertSame(500000.0,$this->balance());
 }

 public function test_deposit_reference_cannot_be_reposted_as_other_income(): void
 {
  $this->deposit();
  $category=\App\Models\CentralFinanceCategory::on('mysql')->create(['school_id'=>1,'type'=>'income','name'=>'Synthetic income','is_active'=>true]);
  $this->denied(fn()=>app(\App\Services\CentralFinanceOperatingDocumentService::class)->createOtherIncome($this->head,1,$category->id,$this->hq,500000,'Bank Transfer',$this->bankAt(),'OTHER-INCOME','BANK-500'));
  $this->assertSame(0,DB::connection('mysql')->table('central_finance_other_incomes')->count()); $this->assertSame(500000.0,$this->balance());
 }

 public function test_other_income_reference_cannot_be_reposted_as_unidentified_deposit(): void
 {
  $category=\App\Models\CentralFinanceCategory::on('mysql')->create(['school_id'=>1,'type'=>'income','name'=>'Synthetic income','is_active'=>true]);
  app(\App\Services\CentralFinanceOperatingDocumentService::class)->createOtherIncome($this->head,1,$category->id,$this->hq,500000,'Bank Transfer',$this->bankAt(),'OTHER-INCOME','BANK-500');
  $this->denied(fn()=>$this->deposit());
  $this->assertSame(0,CentralFinanceUnidentifiedDeposit::on('mysql')->count()); $this->assertSame(500000.0,$this->balance());
 }

 public function test_group_ledger_source_opens_the_original_school_null_deposit_and_its_audit(): void
 {
  $deposit=$this->deposit(); $ledger=CentralFinanceLedgerEntry::on('mysql')->sole();
  $this->actingAs($this->head); Session::forget(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY);
  $data=app(CentralFinanceWorkspaceController::class)->ledgerSource($ledger->id)->getData();
  $this->assertSame($deposit->id,$data['source']['model']->id);
  $this->assertSame('Unidentified Deposit',$data['source']['label']);
  $this->assertCount(1,$data['audits']);
  $this->assertSame('recorded',$data['audits']->first()->action);
  $this->assertSame($this->head->id,(int)$data['audits']->first()->actor_id);
  $this->assertSame(500000.0,$this->balance());
 }

 public function test_import_confirmation_rejects_a_reference_recorded_after_preview(): void
 {
  $receivable=$this->target(); Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY,1);
  $service=app(CentralFinancePaymentImportService::class);
  $batch=$service->previewRows($this->head,1,'local-synthetic.csv',hash('sha256','local-synthetic'),[[
   'Payment Date'=>'2026-06-15','Student UUID'=>$this->zixProfile->source_uuid,'Receivable Reference'=>$receivable->receivable_uuid,
   'Fund Account Code'=>$this->hq->account_code,'Currency'=>'MMK','Amount'=>'500000','Payment Method'=>'Bank Transfer','Payment Reference'=>'BANK-500',
  ]]);
  $this->assertSame(0,(int)$batch->error_rows); $this->deposit();
  $this->denied(fn()=>$service->confirm($this->head,$batch->token));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
  $this->assertSame('0.0000',(string)$receivable->fresh()->amount_paid); $this->assertSame(500000.0,$this->balance());
 }

 public function test_pending_confirmation_cannot_duplicate_an_existing_deposit_bank_receipt(): void
 {
  $receivable=$this->target(); $this->deposit();
  // Historical submitted declaration predates shared physical identity checks.
  $pending=$this->pending($receivable,'500000.0000','BANK-500');
  $this->denied(fn()=>app(CentralFinancePendingCollectionConfirmationService::class)->confirm($this->head,$pending->id,$this->hq,$this->allocatedAt(),'Bank reviewed.'));
  $this->assertSame('submitted',$pending->fresh()->status);
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); $this->assertSame(500000.0,$this->balance());
 }

 /** @dataProvider reservedStatuses */
 public function test_pending_reservations_reduce_actual_allocatable_outstanding(string $status): void
 {
  $receivable=$this->target(); $deposit=$this->deposit(); $pending=$this->pending($receivable,'400000.0000','OTHER-BANK');
  $pending->update(['status'=>$status]);
  $this->denied(fn()=>$this->apply($deposit,$receivable,'100001.0000'));
  $this->assertSame(0,$deposit->allocations()->count());
  $this->apply($deposit,$receivable,'100000.0000');
  $this->assertSame('100000.0000',(string)$receivable->fresh()->amount_paid);
  $this->assertSame(500000.0,$this->balance());
 }
 public static function reservedStatuses(): array { return [['submitted'],['held']]; }

 public function test_missing_reference_requires_explicit_alternative_identity_and_reason(): void
 {
  $service=app(CentralFinanceUnidentifiedDepositService::class);
  $this->denied(fn()=>$service->record($this->head,$this->hq,'500000.0000',$this->bankAt(),'EMPTY-REF'));
  $this->denied(fn()=>$service->record($this->head,$this->hq,'500000.0000',$this->bankAt(),'EMPTY-REASON',null,null,null,'STATEMENT-15-LINE-8'));
  $this->assertSame(0.0,$this->balance());
  $deposit=$service->record($this->head,$this->hq,'500000.0000',$this->bankAt(),'MANUAL-1',null,'Statement row checked.','Known sender','STATEMENT-15-LINE-8','Verified bank statement line 8.');
  $this->assertSame('STATEMENT-15-LINE-8',$deposit->manual_identity);
  $this->assertSame('Verified bank statement line 8.',$deposit->manual_reason);
  $this->denied(fn()=>$service->record($this->head,$this->hq,'500000.0000',$this->bankAt(),'MANUAL-2',null,'Statement row checked.','Known sender','STATEMENT-15-LINE-8','Verified bank statement line 8.'));
  $this->assertSame(500000.0,$this->balance());
 }

 /** @dataProvider allocationGuards */
 public function test_allocation_rejects_invalid_scope_currency_account_and_cutover(string $guard): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  if($guard==='currency') $receivable->update(['currency'=>'USD']);
  if($guard==='unallocated') DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->where('fund_account_id',$this->hq->id)->where('school_id',1)->delete();
  if($guard==='inactive') $this->hq->update(['is_active'=>false,'status'=>'inactive']);
  if($guard==='deleted') $this->hq->delete();
  if($guard==='school_scope') DB::connection('mysql')->table('central_finance_user_school_scopes')->where('user_id',$this->head->id)->where('school_id',1)->update(['can_operate'=>false]);
  if($guard==='group') DB::connection('mysql')->table('finance_group_schools')->where('school_id',1)->update(['status'=>'inactive']);
  if($guard==='cutover') DB::connection('mysql')->table('central_finance_school_cutovers')->where('school_id',1)->update(['status'=>'legacy']);
  $this->denied(fn()=>$this->apply($deposit,$receivable,'500000.0000'));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); $this->assertSame(0,$deposit->allocations()->count());
  $this->assertSame('0.0000',(string)$receivable->fresh()->amount_paid); $this->assertSame(500000.0,$this->balance());
 }
 public static function allocationGuards(): array { return array_map(fn($g)=>[$g],['currency','unallocated','inactive','deleted','school_scope','group','cutover']); }

 public function test_approval_for_two_groups_cannot_spend_one_groups_deposit_for_the_other(): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  DB::connection('mysql')->table('finance_groups')->insert(['id'=>2,'name'=>'Other synthetic group','status'=>'active','reporting_currency'=>'MMK','fiscal_year_start_month'=>1,'created_at'=>now(),'updated_at'=>now()]);
  DB::connection('mysql')->table('finance_group_schools')->where('school_id',1)->update(['group_id'=>2]);
  DB::connection('mysql')->table('finance_group_users')->insert(['id'=>3,'group_id'=>2,'central_user_id'=>$this->head->id,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id'=>3,'school_id'=>1,'scope_type'=>'SCHOOL','capability'=>'operate_finance','scope_key'=>'school:1','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $this->denied(fn()=>$this->apply($deposit,$receivable,'500000.0000'));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); $this->assertSame(500000.0,$this->balance());
 }

 /** @dataProvider excessiveAmounts */
 public function test_deposit_and_receivable_limits_are_enforced_without_partial_documents(string $due,string $amount): void
 {
  $receivable=$this->target($due); $deposit=$this->deposit(); $before=$this->financeCounts();
  $this->denied(fn()=>$this->apply($deposit,$receivable,$amount));
  $this->assertSame($before,$this->financeCounts()); $this->assertSame('0.0000',(string)$receivable->fresh()->amount_paid); $this->assertSame(500000.0,$this->balance());
 }
 public static function excessiveAmounts(): array { return [['1000000.0000','500001.0000'],['400000.0000','400001.0000']]; }

 public function test_qa_allocation_keeps_all_canonical_documents_and_attribution_in_qa(): void
 {
  $receivable=$this->target();
  foreach([['school',1],['student_profile',$this->zixProfile->id],['receivable',$receivable->id],['fund_account',$this->hq->id]] as [$type,$id]) $this->classify($type,$id);
  $deposit=$this->deposit(); $allocation=$this->apply($deposit,$receivable,'500000.0000');
  $payment=CentralFinancePayment::on('mysql')->sole();
  $isolation=app(CentralFinanceDataIsolationService::class);
  foreach([['unidentified_deposit',$deposit->id],['unidentified_deposit_allocation',$allocation->id],['payment',$payment->id],['payment_allocation',$payment->allocations()->sole()->id],['receipt',$payment->receipt->id]] as [$type,$id]) {
   $this->assertSame('qa_test',$isolation->classification($type,$id),$type.' must inherit QA.');
  }
  foreach(CentralFinanceLedgerEntry::on('mysql')->get() as $entry) $this->assertSame('qa_test',$isolation->classification('ledger',$entry->id));
  $this->assertSame(500000.0,$this->balance());
 }

 public function test_qa_deposit_cannot_allocate_to_official_receivable(): void
 {
  $receivable=$this->target(); $this->classify('fund_account',$this->hq->id);
  $deposit=$this->deposit();
  $this->assertSame('qa_test',app(CentralFinanceDataIsolationService::class)->classification('unidentified_deposit',$deposit->id));
  $this->denied(fn()=>$this->apply($deposit,$receivable,'500000.0000'));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); $this->assertSame(500000.0,$this->balance());
 }

 public function test_official_deposit_cannot_allocate_to_qa_receivable(): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  $this->classify('school',1); $this->classify('student_profile',$this->zixProfile->id); $this->classify('receivable',$receivable->id);
  $this->denied(fn()=>$this->apply($deposit,$receivable,'500000.0000'));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); $this->assertSame(500000.0,$this->balance());
 }

 public function test_deposit_classification_is_rechecked_if_account_classification_changes(): void
 {
  $receivable=$this->target(); $this->classify('fund_account',$this->hq->id); $deposit=$this->deposit();
  DB::connection('mysql')->table('central_finance_data_classifications')->where('subject_type','fund_account')->where('subject_id',$this->hq->id)->update(['classification'=>'production']);
  $this->denied(fn()=>$this->apply($deposit,$receivable,'500000.0000'));
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
 }

 /** @dataProvider excludedRoles */
 public function test_only_head_finance_can_create_and_allocate_group_deposits(string $role): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  DB::connection('mysql')->table('roles')->where('id',1)->update(['name'=>$role]);
  $this->denied(fn()=>app(CentralFinanceUnidentifiedDepositService::class)->record($this->head,$this->hq,'1.0000',$this->bankAt(),'UNAUTHORIZED','UNAUTHORIZED'));
  $this->denied(fn()=>$this->apply($deposit,$receivable,'500000.0000'));
  $this->assertSame(1,CentralFinanceUnidentifiedDeposit::on('mysql')->count()); $this->assertSame(0,CentralFinancePayment::on('mysql')->count());
 }
 public static function excludedRoles(): array { return array_map(fn($r)=>[$r],['Front Desk','School Admin','Principal','School Accountant','Super Admin']); }

 /** @dataProvider studentSearches */
 public function test_selector_searches_student_code_gr_and_name_and_subtracts_reservations(string $term): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  $this->pending($receivable,'400000.0000','OTHER-BANK');
  $other=$this->target('100000.0000','Other School','2'); $other->update(['school_id'=>2,'student_profile_id'=>$this->timeProfile->id]);
  $this->actingAs($this->head);
  $data=app(\App\Http\Controllers\CentralFinanceUnidentifiedDepositController::class)->receivables(new Request(['school_id'=>1,'student'=>$term]),$deposit)->getData(true);
  $this->assertCount(1,$data['receivables']); $row=$data['receivables'][0];
  $this->assertSame($receivable->id,$row['id']); $this->assertSame('BOWEN-000001',$row['student_code']); $this->assertSame('GR-000001',$row['gr']);
  $this->assertSame('100000.0000',$row['available']); $this->assertSame('400000.0000',$row['reserved']);
  $this->assertSame(0,CentralFinancePayment::on('mysql')->count()); $this->assertSame(500000.0,$this->balance());
 }
 public static function studentSearches(): array { return [['BOWEN-000001'],['GR-000001'],['Student 1']]; }

 public function test_selector_returns_no_fully_reserved_receivables_and_cannot_bypass_cutover(): void
 {
  $receivable=$this->target(); $deposit=$this->deposit(); $this->pending($receivable,'500000.0000','OTHER-BANK'); $this->actingAs($this->head);
  $controller=app(\App\Http\Controllers\CentralFinanceUnidentifiedDepositController::class);
  $request=new Request(['school_id'=>1,'student'=>'Student 1']);
  $this->assertSame([],$controller->receivables($request,$deposit)->getData(true)['receivables']);
  DB::connection('mysql')->table('central_finance_school_cutovers')->where('school_id',1)->update(['status'=>'legacy']);
  $this->expectException(AuthorizationException::class); $controller->receivables($request,$deposit);
 }

 public function test_deposit_settlement_cannot_use_the_ordinary_cash_refund_or_reversal_path(): void
 {
  $receivable=$this->target(); $deposit=$this->deposit();
  $allocation=$this->apply($deposit,$receivable,'500000.0000');
  $before=$this->financeCounts(); $balance=$this->balance();
  $this->denied(fn()=>app(\App\Services\CentralFinancePaymentRefundService::class)->refund($this->head,$allocation->payment_id,$this->hq,100000,'Bank Transfer',$this->allocatedAt(),'Requires allocation-aware correction.',$this->allocatedAt(),'DEPOSIT-REFUND'));
  $this->denied(fn()=>app(\App\Services\CentralFinancePaymentReversalService::class)->reverse($this->head,$allocation->payment_id,$this->allocatedAt(),'Requires allocation-aware correction.',$this->allocatedAt(),'DEPOSIT-REVERSAL'));
  $this->assertSame($before,$this->financeCounts());
  $this->assertSame($balance,$this->balance());
  $this->assertSame('500000.0000',(string)$receivable->fresh()->amount_paid);
  $this->assertSame(0,DB::connection('mysql')->table('central_finance_payment_refunds')->count());
  $this->assertSame(0,DB::connection('mysql')->table('central_finance_payment_reversals')->count());
 }

 public function test_group_bank_without_any_school_allocation_is_visible_only_to_its_group_head(): void
 {
  $unassigned=$this->account('GROUP-UNASSIGNED','Unassigned Group Bank','hq',null);
  $this->assertFalse($unassigned->schoolAllocations()->exists());
  $deposit=app(CentralFinanceUnidentifiedDepositService::class)->record($this->head,$unassigned,'500000.0000',$this->bankAt(),'NO-SCHOOL-DEPOSIT','NO-SCHOOL-BANK');
  $entry=CentralFinanceLedgerEntry::on('mysql')->sole();
  DB::connection('mysql')->table('finance_groups')->insert(['id'=>2,'name'=>'Unauthorized Group','status'=>'active','reporting_currency'=>'MMK','fiscal_year_start_month'=>1,'created_at'=>now(),'updated_at'=>now()]);
  $foreign=$this->account('FOREIGN-GROUP','Foreign Group Bank','hq',null); $foreign->update(['group_id'=>2]);
  $this->denied(fn()=>app(CentralFinanceUnidentifiedDepositService::class)->record($this->head,$foreign,'1.0000',$this->bankAt(),'FOREIGN-DEPOSIT','FOREIGN-BANK'));
  // Historical source fixture in another Group must not enter any read scope.
  $foreignDeposit=CentralFinanceUnidentifiedDeposit::on('mysql')->create(array_merge($deposit->only($deposit->getFillable()),[
   'deposit_uuid'=>(string)Str::uuid(),'group_id'=>2,'fund_account_id'=>$foreign->id,'idempotency_key'=>hash('sha256','foreign-deposit'),'bank_reference'=>'FOREIGN-BANK',
  ]));
  $foreignEntry=CentralFinanceLedgerEntry::on('mysql')->create(array_merge($entry->only($entry->getFillable()),[
   'entry_uuid'=>(string)Str::uuid(),'fund_account_id'=>$foreign->id,'source_id'=>$foreignDeposit->deposit_uuid,'reference_no'=>'FOREIGN-BANK',
  ]));
  $this->actingAs($this->head); Session::forget(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY);
  $index=app(\App\Http\Controllers\CentralFinanceUnidentifiedDepositController::class)->index(new Request())->getData();
  $this->assertTrue($index['accounts']->contains('id',$unassigned->id));
  $this->assertFalse($index['accounts']->contains('id',$foreign->id));
  $this->assertSame([$deposit->id],$index['deposits']->pluck('id')->all());
  $controller=app(CentralFinanceWorkspaceController::class);
  $this->assertSame($entry->id,$controller->ledgerSource($entry->id)->getData()['entry']->id);
  $report=$controller->reports(new Request())->getData();
  $this->assertSame([$entry->id],$report['ledger']->pluck('id')->all());
  $this->assertSame(500000.0,$report['currencyTotals']['MMK']['money_in']);
  $this->assertSame(0.0,$report['currencyTotals']['MMK']['operating_income']);
  $this->assertSame(500000.0,$controller->fundAccountStatement(new Request(),$unassigned->id)->getData()['statementTotals']['money_in']);
  $this->denied(fn()=>$controller->ledgerSource($foreignEntry->id));
  app(CentralFinanceWorkspaceService::class)->enterSchool($this->head,1);
  $this->denied(fn()=>$controller->ledgerSource($entry->id));
  app(CentralFinanceWorkspaceService::class)->exitSchool(); $this->actingAs($this->accountant);
  $this->denied(fn()=>$controller->ledger(new Request()));
  $this->denied(fn()=>app(\App\Http\Controllers\CentralFinanceUnidentifiedDepositController::class)->index(new Request()));
 }

 public function test_long_deposit_notes_are_preserved_with_a_bounded_ledger_memo(): void
 {
  $notes=str_repeat('核实付款资料',200);
  $deposit=app(CentralFinanceUnidentifiedDepositService::class)->record($this->head,$this->hq,'500000.0000',$this->bankAt(),'LONG-NOTES','BANK-LONG-NOTES',$notes);
  $this->assertSame($notes,$deposit->fresh()->description);
  $memo=CentralFinanceLedgerEntry::on('mysql')->sole()->memo;
  $this->assertSame(500,mb_strlen($memo));
  $this->assertSame(mb_substr($notes,0,500),$memo);
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
