<?php

namespace Tests\Feature;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundHandover;
use App\Models\CentralFinanceHqFundingRequest;
use App\Models\CentralFinanceInternalTransfer;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinanceFundHandoverService;
use App\Services\CentralFinanceHqFundingService;
use App\Services\CentralFinanceInternalTransferService;
use App\Services\CentralFinanceLedgerPresentationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CentralFinanceInternalTransferDocumentsTest extends TestCase
{
    private string $central;
    private CentralFinanceUser $head;
    private CentralFinanceUser $zixuanAccountant;
    private CentralFinanceUser $timecityAccountant;
    private CentralFinanceFundAccount $hq;
    private CentralFinanceFundAccount $zixuanA;
    private CentralFinanceFundAccount $zixuanB;
    private CentralFinanceFundAccount $timecityA;
    private CentralFinanceFundAccount $timecityB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->central = tempnam(sys_get_temp_dir(), 'cf_transfer_docs_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->central, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql'); DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('schools', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->softDeletes(), $t->timestamps()]);
        Schema::connection('mysql')->create('users', fn (Blueprint $t) => [$t->id(), $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->softDeletes(), $t->timestamps()]);
        foreach ([
            '2026_08_20_000003_create_central_finance_student_sync_tables.php',
            '2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php',
            '2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php',
            '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php',
            '2026_08_21_000002_create_central_finance_operating_documents.php',
            '2026_08_21_000003_create_central_finance_internal_transfer_documents.php',
            '2026_08_21_000005_create_central_finance_school_cutovers.php',
        ] as $migration) (require database_path('migrations/'.$migration))->up();
        DB::connection('mysql')->table('schools')->insert([
            ['id'=>1,'name'=>'Zixuan QA','created_at'=>now(),'updated_at'=>now()], ['id'=>2,'name'=>'Timecity QA','created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id'=>100,'first_name'=>'Head','last_name'=>'Finance','created_at'=>now(),'updated_at'=>now()], ['id'=>200,'first_name'=>'Zixuan','last_name'=>'Accountant','created_at'=>now(),'updated_at'=>now()], ['id'=>300,'first_name'=>'Timecity','last_name'=>'Accountant','created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::connection('mysql')->table('central_finance_school_cutovers')->insert([
            ['school_id'=>1,'status'=>'central','cutover_at'=>now(),'created_at'=>now(),'updated_at'=>now()], ['school_id'=>2,'status'=>'central','cutover_at'=>now(),'created_at'=>now(),'updated_at'=>now()],
        ]);
        $this->head=CentralFinanceUser::on('mysql')->findOrFail(100); $this->zixuanAccountant=CentralFinanceUser::on('mysql')->findOrFail(200); $this->timecityAccountant=CentralFinanceUser::on('mysql')->findOrFail(300);
        $this->hq=$this->account('HQ-MAIN','HQ Main','hq',null,1000); $this->zixuanA=$this->account('ZIX-A','Zixuan A','school',1,1000); $this->zixuanB=$this->account('ZIX-B','Zixuan B','school',1,0); $this->timecityA=$this->account('TIM-A','Timecity A','school',2,1000); $this->timecityB=$this->account('TIM-B','Timecity B','school',2,0);
        foreach([$this->hq,$this->zixuanA,$this->zixuanB,$this->timecityA,$this->timecityB] as $account) $this->grantAccount($this->head,$account);
        foreach([$this->zixuanA,$this->zixuanB] as $account) $this->grantAccount($this->zixuanAccountant,$account);
        foreach([$this->timecityA,$this->timecityB] as $account) $this->grantAccount($this->timecityAccountant,$account);
        $this->grantSchool($this->head,1,true); $this->grantSchool($this->head,2,true); $this->grantSchool($this->zixuanAccountant,1,false); $this->grantSchool($this->timecityAccountant,2,false);
    }
    protected function tearDown(): void { DB::purge('mysql'); @unlink($this->central); parent::tearDown(); }

    public function test_direct_bank_transfer_is_directional_once_and_has_no_operating_effect(): void
    {
        $service=app(CentralFinanceInternalTransferService::class);
        $first=$service->transfer($this->head,1,$this->zixuanA,$this->zixuanB,250,$this->at(),'ZIX-DIRECT-1','ZIX-DIRECT-REF');
        $retry=$service->transfer($this->head,1,$this->zixuanA,$this->zixuanB,250,$this->at(),'ZIX-DIRECT-1','ZIX-DIRECT-REF');
        $this->assertSame($first->id,$retry->id); $this->assertSame(750.0,$this->balance($this->zixuanA)); $this->assertSame(250.0,$this->balance($this->zixuanB));
        $this->assertSame(1,CentralFinanceInternalTransfer::on('mysql')->count()); $this->assertSame(2,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $totals=app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(1); $this->assertSame(250.0,$totals['money_in']); $this->assertSame(250.0,$totals['money_out']); $this->assertSame(0.0,$totals['operating_income']); $this->assertSame(0.0,$totals['operating_expense']); $this->assertSame(0.0,$totals['operating_net']);

        $legs = CentralFinanceLedgerEntry::on('mysql')->where('source_id', $first->transfer_uuid)->get()->keyBy('source_line');
        app(CentralFinanceLedgerPresentationService::class)->decorate($legs);
        $this->assertSame('outgoing', $legs['source']->transfer_leg);
        $this->assertSame('incoming', $legs['destination']->transfer_leg);
        $this->assertSame('Zixuan A · ZIX-A', $legs['source']->transfer_source_account_label);
        $this->assertSame('Zixuan B · ZIX-B', $legs['source']->transfer_destination_account_label);
        $this->assertNull($legs['source']->funding_leg);
    }

    public function test_handover_is_pending_neutral_then_receiver_confirms_one_split_custody_transfer(): void
    {
        $service=app(CentralFinanceFundHandoverService::class);
        $handover=$service->request($this->head,$this->zixuanAccountant,1,$this->zixuanA,$this->zixuanB,300,$this->at(),'ZIX-HAND-1','ZIX-HAND-REF');
        $this->assertSame(CentralFinanceFundHandover::PENDING,$handover->status); $this->assertSame(1000.0,$this->balance($this->zixuanA)); $this->assertSame(0.0,$this->balance($this->zixuanB)); $this->assertSame(0,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $confirmed=$service->confirm($this->zixuanAccountant,$handover->id,$this->at()->addMinute()); $this->assertSame(CentralFinanceFundHandover::CONFIRMED,$confirmed->status); $this->assertNotNull($confirmed->internal_transfer_id); $this->assertSame(700.0,$this->balance($this->zixuanA)); $this->assertSame(300.0,$this->balance($this->zixuanB));
        $this->assertSame(1,CentralFinanceInternalTransfer::on('mysql')->where('source_type','fund_handover')->count()); $this->assertSame(2,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $this->assertSame(0.0, app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(1)['operating_net']);
        $this->expectException(InvalidArgumentException::class); $service->confirm($this->zixuanAccountant,$handover->id,$this->at()->addMinutes(2));
    }

    public function test_handover_reject_and_cancel_are_pending_only_and_ledger_neutral(): void
    {
        $service=app(CentralFinanceFundHandoverService::class);
        $rejected=$service->request($this->head,$this->zixuanAccountant,1,$this->zixuanA,$this->zixuanB,10,$this->at(),'ZIX-HAND-REJECT'); $service->reject($this->zixuanAccountant,$rejected->id,'Custody count mismatch',$this->at());
        $cancelled=$service->request($this->head,$this->zixuanAccountant,1,$this->zixuanA,$this->zixuanB,10,$this->at(),'ZIX-HAND-CANCEL'); $service->cancel($this->head,$cancelled->id,'Request withdrawn',$this->at());
        $this->assertSame(CentralFinanceFundHandover::REJECTED,$rejected->fresh()->status); $this->assertSame(CentralFinanceFundHandover::CANCELLED,$cancelled->fresh()->status); $this->assertSame(0,CentralFinanceInternalTransfer::on('mysql')->count()); $this->assertSame(0,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_hq_to_school_and_school_to_hq_funding_are_pending_neutral_and_head_confirmed(): void
    {
        $service=app(CentralFinanceHqFundingService::class);
        $hqToSchool=$service->request($this->head,1,$this->hq,$this->zixuanA,400,$this->at(),'HQ-ZIX-1','HQ-ZIX-REF');
        $this->assertSame(CentralFinanceHqFundingRequest::PENDING,$hqToSchool->status); $this->assertSame(1000.0,$this->balance($this->hq)); $this->assertSame(1000.0,$this->balance($this->zixuanA)); $this->assertSame(0,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $service->confirm($this->head,$hqToSchool->id,$this->at()->addMinute()); $this->assertSame(600.0,$this->balance($this->hq)); $this->assertSame(1400.0,$this->balance($this->zixuanA));
        $schoolToHq=$service->request($this->zixuanAccountant,1,$this->zixuanA,$this->hq,200,$this->at(),'ZIX-HQ-1','ZIX-HQ-REF');
        try { $service->confirm($this->zixuanAccountant,$schoolToHq->id,$this->at()->addMinutes(2)); $this->fail('School Accountant cannot confirm HQ funding.'); } catch (AuthorizationException) { $this->assertSame(CentralFinanceHqFundingRequest::PENDING,$schoolToHq->fresh()->status); }
        $service->confirm($this->head,$schoolToHq->id,$this->at()->addMinutes(3)); $this->assertSame(800.0,$this->balance($this->hq)); $this->assertSame(1200.0,$this->balance($this->zixuanA));
        $totals=app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(1); $this->assertSame(600.0,$totals['money_in']); $this->assertSame(600.0,$totals['money_out']); $this->assertSame(0.0,$totals['operating_net']);
        $this->expectException(InvalidArgumentException::class); $service->confirm($this->head,$schoolToHq->id,$this->at()->addMinutes(4));
    }

    public function test_hq_funding_reject_and_cancel_are_pending_only_and_ledger_neutral(): void
    {
        $service=app(CentralFinanceHqFundingService::class);
        $rejected=$service->request($this->head,1,$this->hq,$this->zixuanA,50,$this->at(),'HQ-ZIX-REJECT'); $service->reject($this->head,$rejected->id,'Budget deferred',$this->at());
        $cancelled=$service->request($this->zixuanAccountant,1,$this->zixuanA,$this->hq,50,$this->at(),'ZIX-HQ-CANCEL'); $service->cancel($this->zixuanAccountant,$cancelled->id,'Request withdrawn',$this->at());
        $this->assertSame(CentralFinanceHqFundingRequest::REJECTED,$rejected->fresh()->status); $this->assertSame(CentralFinanceHqFundingRequest::CANCELLED,$cancelled->fresh()->status); $this->assertSame(0,CentralFinanceInternalTransfer::on('mysql')->count()); $this->assertSame(0,DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_cross_school_unauthorized_inactive_and_insufficient_transfers_fail_without_writes(): void
    {
        $direct=app(CentralFinanceInternalTransferService::class);
        foreach ([
            fn()=> $direct->transfer($this->head,1,$this->zixuanA,$this->timecityA,1,$this->at(),'CROSS-1'),
            fn()=> $direct->transfer($this->head,1,$this->zixuanA,$this->zixuanA,1,$this->at(),'SAME-1'),
            fn()=> $direct->transfer($this->zixuanAccountant,1,$this->zixuanA,$this->zixuanB,1001,$this->at(),'LOW-1'),
            fn()=> $direct->transfer($this->zixuanAccountant,2,$this->timecityA,$this->timecityB,1,$this->at(),'FORGED-1'),
        ] as $attempt) { try { $attempt(); $this->fail('Forged or unsafe transfer accepted.'); } catch (InvalidArgumentException|AuthorizationException|RuntimeException) { $this->assertSame(0,CentralFinanceInternalTransfer::on('mysql')->count()); } }
        $this->timecityA->update(['is_active'=>false]);
        try { $direct->transfer($this->head,2,$this->timecityA,$this->timecityB,1,$this->at(),'INACTIVE-1'); $this->fail('Inactive source accepted.'); } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->assertSame(0,DB::connection('mysql')->table('central_finance_ledger_entries')->count()); }
    }

    public function test_transfer_document_schema_is_additive_and_reversible(): void
    {
        $migration=require database_path('migrations/2026_08_21_000003_create_central_finance_internal_transfer_documents.php'); $migration->down();
        foreach(['central_finance_hq_funding_requests','central_finance_fund_handovers','central_finance_internal_transfers'] as $table) $this->assertFalse(Schema::connection('mysql')->hasTable($table));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_ledger_entries')); $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_expenses'));
    }

    private function account(string $code,string $name,string $owner,?int $school,float $opening): CentralFinanceFundAccount { return CentralFinanceFundAccount::on('mysql')->create(['account_uuid'=>(string)Str::uuid(),'group_id'=>1,'account_code'=>$code,'account_name'=>$name,'owner_type'=>$owner,'school_id'=>$school,'currency'=>'MMK','opening_balance'=>$opening,'is_active'=>true]); }
    private function grantAccount(CentralFinanceUser $user,CentralFinanceFundAccount $account): void { DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id'=>$account->id,'user_id'=>$user->id,'can_view'=>true,'can_operate'=>true,'created_at'=>now(),'updated_at'=>now()]); }
    private function grantSchool(CentralFinanceUser $user,int $school,bool $funding): void { DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id'=>$user->id,'school_id'=>$school,'can_view'=>true,'can_operate'=>true,'can_approve_reimbursements'=>$funding,'can_confirm_funding'=>$funding,'created_at'=>now(),'updated_at'=>now()]); }
    private function balance(CentralFinanceFundAccount $account): float { return app(CentralFinanceFundAccountBalanceService::class)->currentBalance($account); }
    private function at(): CarbonImmutable { return CarbonImmutable::parse('2026-08-21 15:00:00','Asia/Yangon'); }
}
