<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundHandover;
use App\Models\CentralFinanceHqFundingRequest;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceReimbursementRequest;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinanceFundHandoverService;
use App\Services\CentralFinanceHqFundingService;
use App\Services\CentralFinanceInternalTransferService;
use App\Services\CentralFinanceOperatingDocumentService;
use App\Services\CentralFinancePaymentService;
use App\Services\CentralFinanceReimbursementService;
use App\Services\CentralFinanceWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Central Finance's first real UI. It never establishes tenant context:
 * central `school_id` is an organizational/reporting dimension only.
 */
final class CentralFinanceWorkspaceController extends Controller
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceFundAccountBalanceService $balances,
        private readonly CentralFinancePaymentService $payments,
        private readonly CentralFinanceOperatingDocumentService $documents,
        private readonly CentralFinanceReimbursementService $reimbursements,
        private readonly CentralFinanceInternalTransferService $transfers,
        private readonly CentralFinanceFundHandoverService $handovers,
        private readonly CentralFinanceHqFundingService $funding,
    ) {}

    public function dashboard(): View
    {
        return $this->render('dashboard');
    }

    public function receivables(): View
    {
        return $this->render('receivables');
    }

    public function operating(): View
    {
        return $this->render('operating');
    }

    public function accounts(): View
    {
        return $this->render('accounts');
    }

    public function transfers(): View
    {
        return $this->render('transfers');
    }

    public function handovers(): View
    {
        return $this->render('handovers');
    }

    public function funding(): View
    {
        return $this->render('funding');
    }

    public function ledger(): View
    {
        return $this->render('ledger');
    }

    public function reports(): View
    {
        return $this->render('reports');
    }

    public function enterSchool(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $schoolId = (int) $request->validate(['school_id' => ['required', 'integer', 'min:1']])['school_id'];
        $this->workspace->enterSchool($actor, $schoolId);
        return redirect()->route('central-finance.dashboard');
    }

    public function exitSchool(): RedirectResponse
    {
        $this->workspace->exitSchool();
        return redirect()->route('central-finance.dashboard');
    }

    public function collect(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate([
            'receivable_id' => ['required', 'integer'], 'fund_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', 'string', 'max:40'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $receivable = CentralFinanceReceivable::on('mysql')->where('school_id', $school->id)->findOrFail($data['receivable_id']);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']);
        $this->payments->collect($actor, $receivable->id, $account, (float) $data['amount'], $data['payment_method'], CarbonImmutable::now(), $this->workspace->idempotencyReference('ui-payment'), $data['payment_reference'] ?? null);
        return back()->with('success', __('Central payment collected.'));
    }

    public function expense(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['category_id'=>['required','integer'],'fund_account_id'=>['required','integer'],'amount'=>['required','numeric','gt:0'],'payment_method'=>['required','string','max:40'],'reference_no'=>['nullable','string','max:100'],'description'=>['nullable','string','max:2000']]);
        $this->documents->createExpense($actor, $school->id, (int) $data['category_id'], CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']), (float) $data['amount'], $data['payment_method'], CarbonImmutable::now(), $this->workspace->idempotencyReference('ui-expense'), $data['reference_no'] ?? null, $data['description'] ?? null);
        return back()->with('success', __('Central expense recorded.'));
    }

    public function otherIncome(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['category_id'=>['required','integer'],'fund_account_id'=>['required','integer'],'amount'=>['required','numeric','gt:0'],'payment_method'=>['required','string','max:40'],'reference_no'=>['nullable','string','max:100'],'payer'=>['nullable','string','max:191'],'description'=>['nullable','string','max:2000']]);
        $this->documents->createOtherIncome($actor, $school->id, (int) $data['category_id'], CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']), (float) $data['amount'], $data['payment_method'], CarbonImmutable::now(), $this->workspace->idempotencyReference('ui-income'), $data['reference_no'] ?? null, $data['payer'] ?? null, $data['description'] ?? null);
        return back()->with('success', __('Central other income recorded.'));
    }

    public function reimbursement(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['category_id'=>['required','integer'],'amount'=>['required','numeric','gt:0'],'currency'=>['required','string','size:3'],'reference_no'=>['nullable','string','max:100'],'description'=>['nullable','string','max:2000']]);
        $this->reimbursements->submit($actor, $school->id, (int) $data['category_id'], (float) $data['amount'], $data['currency'], $this->workspace->idempotencyReference('ui-reimbursement'), $data['reference_no'] ?? null, $data['description'] ?? null);
        return back()->with('success', __('Central reimbursement submitted.'));
    }

    public function approveReimbursement(Request $request, int $reimbursement): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data=$request->validate(['fund_account_id'=>['required','integer'],'payment_method'=>['required','string','max:40'],'reason'=>['required','string','max:255']]);
        $document=CentralFinanceReimbursementRequest::on('mysql')->where('school_id',$school->id)->findOrFail($reimbursement);
        $this->reimbursements->approve($actor,$document->id,CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']),$data['payment_method'],CarbonImmutable::now(),$data['reason']);
        return back()->with('success', __('Central reimbursement approved.'));
    }

    public function transfer(Request $request): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $data=$request->validate(['source_account_id'=>['required','integer'],'destination_account_id'=>['required','integer','different:source_account_id'],'amount'=>['required','numeric','gt:0'],'reference_no'=>['nullable','string','max:100']]);
        $this->transfers->transfer($actor,$school->id,CentralFinanceFundAccount::on('mysql')->findOrFail($data['source_account_id']),CentralFinanceFundAccount::on('mysql')->findOrFail($data['destination_account_id']),(float)$data['amount'],CarbonImmutable::now(),$this->workspace->idempotencyReference('ui-transfer'),$data['reference_no'] ?? null);
        return back()->with('success', __('Central bank transfer confirmed.'));
    }

    public function handover(Request $request): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $data=$request->validate(['receiver_user_id'=>['required','integer'],'source_account_id'=>['required','integer'],'destination_account_id'=>['required','integer','different:source_account_id'],'amount'=>['required','numeric','gt:0'],'reference_no'=>['nullable','string','max:100']]);
        $receiver=CentralFinanceUser::on('mysql')->findOrFail($data['receiver_user_id']);
        $this->handovers->request($actor,$receiver,$school->id,CentralFinanceFundAccount::on('mysql')->findOrFail($data['source_account_id']),CentralFinanceFundAccount::on('mysql')->findOrFail($data['destination_account_id']),(float)$data['amount'],CarbonImmutable::now(),$this->workspace->idempotencyReference('ui-handover'),$data['reference_no'] ?? null);
        return back()->with('success', __('Central Fund Handover is pending.'));
    }

    public function resolveHandover(Request $request, int $handover, string $action): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $document=CentralFinanceFundHandover::on('mysql')->where('school_id',$school->id)->findOrFail($handover);
        if ($action === 'confirm') $this->handovers->confirm($actor,$document->id,CarbonImmutable::now());
        elseif ($action === 'reject') $this->handovers->reject($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        else $this->handovers->cancel($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        return back()->with('success', __('Central Fund Handover updated.'));
    }

    public function storeFunding(Request $request): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $data=$request->validate(['source_account_id'=>['required','integer'],'destination_account_id'=>['required','integer','different:source_account_id'],'amount'=>['required','numeric','gt:0'],'reference_no'=>['nullable','string','max:100']]);
        $this->funding->request($actor,$school->id,CentralFinanceFundAccount::on('mysql')->findOrFail($data['source_account_id']),CentralFinanceFundAccount::on('mysql')->findOrFail($data['destination_account_id']),(float)$data['amount'],CarbonImmutable::now(),$this->workspace->idempotencyReference('ui-funding'),$data['reference_no'] ?? null);
        return back()->with('success', __('Central HQ Funding is pending.'));
    }

    public function resolveFunding(Request $request, int $funding, string $action): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $document=CentralFinanceHqFundingRequest::on('mysql')->where('school_id',$school->id)->findOrFail($funding);
        if ($action === 'confirm') $this->funding->confirm($actor,$document->id,CarbonImmutable::now());
        elseif ($action === 'reject') $this->funding->reject($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        else $this->funding->cancel($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        return back()->with('success', __('Central HQ Funding updated.'));
    }

    /** @return array{0: CentralFinanceUser, 1: \App\Models\School} */
    private function currentOperatingContext(): array { $actor=$this->actor(); return [$actor,$this->workspace->requireOperatingSchool($actor)]; }
    private function actor(): CentralFinanceUser { $user=Auth::user(); abort_unless($user,403); return $this->workspace->actor($user); }

    private function render(string $page): View
    {
        $actor=$this->actor(); $school=$this->workspace->currentSchool($actor); $schools=$this->workspace->accessibleSchools($actor); $accounts=$this->workspace->accessibleAccounts($actor,$school?->id);
        $schoolId=$school?->id; $base=CentralFinanceLedgerEntry::on('mysql'); if($schoolId) $base->where('school_id',$schoolId); elseif($schools->isNotEmpty()) $base->whereIn('school_id',$schools->pluck('id'));
        $ledger=(clone $base)->latest('occurred_at')->limit(100)->get();
        $totals=['money_in'=>(float)(clone $base)->sum('money_in'),'money_out'=>(float)(clone $base)->sum('money_out'),'operating_income'=>(float)(clone $base)->sum('operating_income'),'operating_expense'=>(float)(clone $base)->sum('operating_expense')]; $totals['operating_net']=$totals['operating_income']-$totals['operating_expense'];
        $canOperate=false; if($school){try{$this->workspace->requireOperatingSchool($actor);$canOperate=true;}catch(AuthorizationException){$canOperate=false;}}
        $schoolUsers = $schoolId ? CentralFinanceUser::on('mysql')->whereIn('id',
            \Illuminate\Support\Facades\DB::connection('mysql')->table('central_finance_user_school_scopes as scopes')
                ->join('finance_group_users as group_users', 'group_users.central_user_id', '=', 'scopes.user_id')
                ->join('finance_groups as groups', 'groups.id', '=', 'group_users.group_id')
                ->join('finance_group_schools as group_schools', 'group_schools.group_id', '=', 'groups.id')
                ->where('scopes.school_id', $schoolId)
                ->where('scopes.can_view', true)
                ->where('scopes.can_operate', true)
                ->where('group_users.status', 'active')
                ->where('groups.status', 'active')
                ->where('group_schools.school_id', $schoolId)
                ->where('group_schools.status', 'active')
                ->pluck('scopes.user_id')->unique()
        )->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']) : collect();
        $data=['page'=>$page,'actor'=>$actor,'school'=>$school,'schools'=>$schools,'accounts'=>$accounts,'canOperate'=>$canOperate,'schoolUsers'=>$schoolUsers,'ledger'=>$ledger,'totals'=>$totals,'receivables'=>$schoolId?CentralFinanceReceivable::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'profiles'=>$schoolId?CentralFinanceStudentProfile::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'expenseCategories'=>$schoolId?CentralFinanceCategory::on('mysql')->where(['school_id'=>$schoolId,'type'=>'expense','is_active'=>true])->get():collect(),'incomeCategories'=>$schoolId?CentralFinanceCategory::on('mysql')->where(['school_id'=>$schoolId,'type'=>'income','is_active'=>true])->get():collect(),'expenses'=>$schoolId?CentralFinanceExpense::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'otherIncomes'=>$schoolId?CentralFinanceOtherIncome::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'reimbursements'=>$schoolId?CentralFinanceReimbursementRequest::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'handovers'=>$schoolId?CentralFinanceFundHandover::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'fundingRequests'=>$schoolId?CentralFinanceHqFundingRequest::on('mysql')->where('school_id',$schoolId)->latest()->get():collect()];
        return view('central-finance.workspace',$data);
    }
}
