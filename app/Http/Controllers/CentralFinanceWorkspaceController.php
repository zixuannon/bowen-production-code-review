<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundHandover;
use App\Models\CentralFinanceHqFundingRequest;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinanceImportBatch;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceReimbursementRequest;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinanceFundAccountAdministrationService;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use App\Services\CentralFinanceFundHandoverService;
use App\Services\CentralFinanceHqFundingService;
use App\Services\CentralFinanceInternalTransferService;
use App\Services\CentralFinanceOperatingDocumentService;
use App\Services\CentralFinancePaymentService;
use App\Services\CentralFinancePaymentRefundService;
use App\Services\CentralFinancePaymentImportService;
use App\Services\CentralFinanceExpenseImportService;
use App\Services\CentralFinanceReimbursementService;
use App\Services\CentralFinanceSchoolCutoverService;
use App\Services\CentralFinanceCutoverReadinessService;
use App\Services\CentralFinanceWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CentralPaymentImportTemplateExport;
use App\Exports\CentralExpenseImportTemplateExport;
use App\Exports\CentralFinanceReadExport;

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
        private readonly CentralFinancePaymentRefundService $refunds,
        private readonly CentralFinancePaymentImportService $paymentImports,
        private readonly CentralFinanceExpenseImportService $expenseImports,
        private readonly CentralFinanceOperatingDocumentService $documents,
        private readonly CentralFinanceReimbursementService $reimbursements,
        private readonly CentralFinanceInternalTransferService $transfers,
        private readonly CentralFinanceFundHandoverService $handovers,
        private readonly CentralFinanceHqFundingService $funding,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceCutoverReadinessService $cutoverReadiness,
        private readonly CentralFinanceFundAccountAdministrationService $accountAdministration,
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
    ) {}

    public function dashboard(?Request $request = null): View
    {
        return $this->render('dashboard', $request ?? request());
    }

    public function receivables(?Request $request = null): View
    {
        return $this->render('receivables', $request ?? request());
    }

    public function operating(?Request $request = null): View
    {
        return $this->render('operating', $request ?? request());
    }

    public function accounts(?Request $request = null): View
    {
        return $this->render('accounts', $request ?? request());
    }

    public function transfers(?Request $request = null): View
    {
        return $this->render('transfers', $request ?? request());
    }

    public function handovers(?Request $request = null): View
    {
        return $this->render('handovers', $request ?? request());
    }

    public function funding(?Request $request = null): View
    {
        return $this->render('funding', $request ?? request());
    }

    public function ledger(?Request $request = null): View
    {
        return $this->render('ledger', $request ?? request());
    }

    public function reports(?Request $request = null): View
    {
        return $this->render('reports', $request ?? request());
    }

    public function studentLedger(Request $request): View { return $this->render('student-ledger', $request); }
    public function paymentHistory(Request $request): View { return $this->render('payments', $request); }
    public function staff(Request $request): View { return $this->render('staff', $request); }
    public function categories(Request $request): View { return $this->render('categories', $request); }
    public function audits(Request $request): View
    {
        [$actor, $school] = $this->currentOperatingContext();
        $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $school);

        return $this->render('audits', $request);
    }
    public function fundAccountReport(Request $request, int $fundAccount): View { return $this->render('account-report', $request, $fundAccount); }

    /** Standard Ledger export uses precisely the Central read-model filters. */
    public function exportLedger(Request $request, string $format)
    {
        $actor = $this->actor(); $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor); $accounts = $this->workspace->accessibleAccounts($actor, $school?->id);
        $filters = $this->validatedReadFilters($request, $school, $accounts);
        $entries = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)->with('fundAccount')->orderBy('occurred_at')->get();
        $schoolNames = $schools->pluck('name', 'id'); $operators = CentralFinanceUser::on('mysql')->whereIn('id', $entries->pluck('created_by')->filter()->unique())->get()->mapWithKeys(fn ($user) => [$user->id => $user->full_name]);
        $categories = $this->ledgerCategoryNames($entries);
        $rows = $entries->map(fn ($entry) => [
            $entry->entry_date?->format('Y-m-d'), $schoolNames[$entry->school_id] ?? '', $entry->fundAccount?->account_code, $entry->fundAccount?->account_name,
            $entry->source_type, $entry->reference_no, $categories[$entry->source_type.':'.$entry->source_id] ?? '', $operators[$entry->created_by] ?? '', $entry->currency,
            (float) $entry->money_in, (float) $entry->money_out, (float) $entry->operating_income, (float) $entry->operating_expense,
        ])->all();
        return $this->downloadReadExport(new CentralFinanceReadExport('Standard Ledger', ['Date','School','Fund Account Code','Fund Account','Source','Reference','Category','Operator','Currency','Money In','Money Out','Operating Income','Operating Expense'], $rows), 'central_standard_ledger', $format);
    }

    /** Payment/receipt export intentionally contains no guardian/contact data. */
    public function exportPayments(Request $request, string $format)
    {
        $actor = $this->actor(); $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor); $accounts = $this->workspace->accessibleAccounts($actor, $school?->id);
        $filters = $this->validatedReadFilters($request, $school, $accounts);
        $payments = $this->scopedPaymentQuery($school, $schools, $accounts, $filters)->with(['receipt','receivable.studentProfile','fundAccount'])->orderBy('paid_at')->get();
        $schoolNames = $schools->pluck('name', 'id');
        $rows = $payments->map(fn ($payment) => [
            $payment->paid_at?->format('Y-m-d H:i'), $schoolNames[$payment->school_id] ?? '', $payment->receivable?->studentProfile?->student_name,
            $payment->receivable?->studentProfile?->admission_no, $payment->fundAccount?->account_code, $payment->fundAccount?->account_name,
            $payment->payment_reference, $payment->receipt?->receipt_no, $payment->payment_method, $payment->currency, (float) $payment->amount,
        ])->all();
        return $this->downloadReadExport(new CentralFinanceReadExport('Payment Receipts', ['Paid At','School','Student','Student Code','Fund Account Code','Fund Account','Payment Reference','Receipt No','Method','Currency','Amount'], $rows), 'central_payment_receipts', $format);
    }

    public function exportFundAccountReport(Request $request, int $fundAccount, string $format)
    {
        $actor = $this->actor(); $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor); $accounts = $this->workspace->accessibleAccounts($actor, $school?->id);
        abort_unless($accounts->contains('id', $fundAccount), 404);
        $filters = $this->validatedReadFilters($request, $school, $accounts); $filters['fund_account_id'] = $fundAccount;
        $account = $accounts->firstWhere('id', $fundAccount);
        $entries = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)->with('fundAccount')->orderBy('occurred_at')->get();
        $schoolNames = $schools->pluck('name', 'id');
        $moneyIn = (float) $entries->sum('money_in'); $moneyOut = (float) $entries->sum('money_out'); $closing = (float) $account->opening_balance + $moneyIn - $moneyOut;
        $rows = [[
            'Summary', '', $schoolNames[$account->school_id] ?? 'HQ', $account->account_code, $account->account_name, '', '', $account->currency,
            (float) $account->opening_balance, $moneyIn, $moneyOut, $closing,
        ]];
        foreach ($entries as $entry) $rows[] = [
            'Transaction', $entry->entry_date?->format('Y-m-d'), $schoolNames[$entry->school_id] ?? '', $account->account_code, $account->account_name,
            $entry->source_type, $entry->reference_no, $entry->currency, null, (float) $entry->money_in, (float) $entry->money_out, null,
        ];
        return $this->downloadReadExport(new CentralFinanceReadExport('Fund Account Report', ['Record','Date','School','Account Code','Fund Account','Source','Reference','Currency','Opening Balance','Money In','Money Out','Closing Balance'], $rows), 'central_fund_account_report_'.$account->account_code, $format);
    }

    public function createCategory(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $school);
        $data = $request->validate(['type' => ['required', Rule::in([CentralFinanceCategory::INCOME, CentralFinanceCategory::EXPENSE])], 'name' => ['required', 'string', 'max:120']]);
        CentralFinanceCategory::on('mysql')->firstOrCreate(['school_id' => $school->id, 'type' => $data['type'], 'name' => trim($data['name'])], ['is_active' => true]);
        return back()->with('success', __('Central Finance category saved.'));
    }

    public function toggleCategory(int $category): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $school);
        $item = CentralFinanceCategory::on('mysql')->where('school_id', $school->id)->findOrFail($category);
        $item->is_active = !$item->is_active;
        $item->save();
        return back()->with('success', __('Central Finance category status updated.'));
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

    public function createFundAccount(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate([
            'account_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'account_name' => ['required', 'string', 'max:191'], 'currency' => ['required', 'string', 'size:3', 'alpha'],
            'owner_type' => ['required', Rule::in([CentralFinanceFundAccount::OWNER_SCHOOL, CentralFinanceFundAccount::OWNER_HQ])],
            'opening_balance' => ['required', 'numeric', 'min:0'], 'opening_balance_date' => ['required', 'date'],
            'opening_reason' => ['required', 'string', 'max:2000'], 'authorized_user_ids' => ['nullable', 'array'],
            'authorized_user_ids.*' => ['integer', 'distinct'],
        ]);
        if ($data['owner_type'] === CentralFinanceFundAccount::OWNER_HQ) {
            $this->accountAdministration->createHqAccount($actor, $school, $data, $data['authorized_user_ids'] ?? []);
        } else {
            $this->accountAdministration->createSchoolAccount($actor, $school, $data, $data['authorized_user_ids'] ?? []);
        }
        return back()->with('success', __('Central Fund Account created with an audited opening balance.'));
    }

    public function syncFundAccountAssignments(Request $request, int $fundAccount): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['authorized_user_ids' => ['nullable', 'array'], 'authorized_user_ids.*' => ['integer', 'distinct']]);
        $this->accountAdministration->syncSchoolAssignments($actor, $school, CentralFinanceFundAccount::on('mysql')->findOrFail($fundAccount), $data['authorized_user_ids'] ?? []);
        return back()->with('success', __('Central Fund Account assignments saved.'));
    }

    public function adjustFundAccountOpeningBalance(Request $request, int $fundAccount): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['amount' => ['required', 'numeric', 'not_in:0'], 'effective_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->accountAdministration->adjustOpeningBalance($actor, $school, CentralFinanceFundAccount::on('mysql')->findOrFail($fundAccount), (float) $data['amount'], $data['effective_date'], $data['reason']);
        return back()->with('success', __('Central Fund Account opening balance adjustment audited.'));
    }

    public function changeCutoverState(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $status = $request->validate(['status' => ['required', Rule::in(['legacy', 'ready', 'central'])]])['status'];
        $this->cutovers->transition($actor, $school, $status);
        return back()->with('success', __('Central Finance cutover state updated.'));
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

    public function refundPayment(Request $request, int $payment): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['fund_account_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:2000'], 'refund_reference' => ['nullable', 'string', 'max:100']]);
        $document = CentralFinancePayment::on('mysql')->where('school_id', $school->id)->findOrFail($payment);
        $this->refunds->refund($actor, $document->id, CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']), (float) $data['amount'], $data['reason'], CarbonImmutable::now(), $this->workspace->idempotencyReference('ui-payment-refund'), $data['refund_reference'] ?? null);
        return back()->with('success', __('Central payment refund recorded.'));
    }

    public function paymentImportTemplate()
    {
        return Excel::download(new CentralPaymentImportTemplateExport(), 'central_payment_import_template_v1.xlsx');
    }

    public function previewPaymentImport(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['payment_import' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv']]);
        $batch = $this->paymentImports->previewUploaded($actor, $school->id, $data['payment_import']);

        return redirect()->route('central-finance.payments.index', ['import_batch' => $batch->token])
            ->with('success', __('Central payment import preview created. Confirm only after reviewing every row.'));
    }

    public function confirmPaymentImport(string $batch): RedirectResponse
    {
        [$actor] = $this->currentOperatingContext();
        $this->paymentImports->confirm($actor, $batch);

        return redirect()->route('central-finance.payments.index')->with('success', __('Central payment import confirmed.'));
    }

    public function expenseImportTemplate()
    {
        return Excel::download(new CentralExpenseImportTemplateExport(), 'central_expense_import_template_v1.xlsx');
    }

    public function previewExpenseImport(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['expense_import' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv']]);
        $batch = $this->expenseImports->previewUploaded($actor, $school->id, $data['expense_import']);

        return redirect()->route('central-finance.operations', ['operation' => 'expense', 'import_batch' => $batch->token])
            ->with('success', __('Central Expense import preview created. Confirm only after reviewing every row.'));
    }

    public function confirmExpenseImport(string $batch): RedirectResponse
    {
        [$actor] = $this->currentOperatingContext();
        $this->expenseImports->confirm($actor, $batch);

        return redirect()->route('central-finance.operations', ['operation' => 'expense'])->with('success', __('Central Expense import confirmed.'));
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

    private function render(string $page, Request $request, ?int $requestedAccountId = null): View
    {
        $actor=$this->actor(); $school=$this->workspace->currentSchool($actor); $schools=$this->workspace->accessibleSchools($actor); $accounts=$this->workspace->accessibleAccounts($actor,$school?->id);
        $schoolId=$school?->id;
        $filters=$this->validatedReadFilters($request, $school, $accounts);
        if ($requestedAccountId !== null) {
            abort_unless($accounts->contains('id', $requestedAccountId), 404);
            $filters['fund_account_id'] = $requestedAccountId;
        }
        $filteredLedger=$this->scopedLedgerQuery($school, $schools, $accounts, $filters);
        $ledger=(clone $filteredLedger)->latest('occurred_at')->paginate(25)->withQueryString();
        // Account balances and cumulative Money In/Out must use the complete
        // authorized account history, never the current Ledger page.
        if ($page === 'accounts') {
            $ledger = (clone $filteredLedger)->latest('occurred_at')->get();
        }
        $totals=['money_in'=>(float)(clone $filteredLedger)->sum('money_in'),'money_out'=>(float)(clone $filteredLedger)->sum('money_out'),'operating_income'=>(float)(clone $filteredLedger)->sum('operating_income'),'operating_expense'=>(float)(clone $filteredLedger)->sum('operating_expense')]; $totals['operating_net']=$totals['operating_income']-$totals['operating_expense'];
        $canOperate=false; if($school){try{$this->workspace->requireOperatingSchool($actor);$canOperate=$this->cutovers->allowsCentralWrites($school->id);}catch(AuthorizationException){$canOperate=false;}}
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
        $canConfigureAccounts=false; if($school){try{app(\App\Services\CentralFinanceConfigurationAuthorizationService::class)->assertHeadFinanceCanConfigureSchool($actor,$school);$canConfigureAccounts=true;}catch(AuthorizationException){$canConfigureAccounts=false;}}
        $receivables=$schoolId ? CentralFinanceReceivable::on('mysql')->with('studentProfile')->where('school_id',$schoolId)->latest()->paginate(25, ['*'], 'receivables_page')->withQueryString() : collect();
        $profiles=$schoolId ? CentralFinanceStudentProfile::on('mysql')->with(['receivables' => fn ($query) => $query->latest()])->where('school_id',$schoolId)->when($filters['student'] ?? null, fn ($query, $student) => $query->where(fn ($nested) => $nested->where('student_name','like',"%{$student}%")->orWhere('admission_no','like',"%{$student}%")))->latest()->paginate(25, ['*'], 'students_page')->withQueryString() : collect();
        $payments=$this->scopedPaymentQuery($school, $schools, $accounts, $filters)->with(['receipt','receivable.studentProfile','fundAccount'])->latest('paid_at')->paginate(25, ['*'], 'payments_page')->withQueryString();
        $paymentProfileQuery = $schoolId ? CentralFinanceStudentProfile::on('mysql')->where('school_id', $schoolId) : null;
        if ($paymentProfileQuery) {
            $paymentProfileQuery
                ->when($filters['payment_class'] ?? null, fn ($query, $class) => $query->where('class_name', $class))
                ->when($filters['payment_student'] ?? null, fn ($query, $student) => $query->where(fn ($nested) => $nested
                    ->where('student_name', 'like', "%{$student}%")
                    ->orWhere('admission_no', 'like', "%{$student}%")))
                ->withSum('receivables as total_due', 'amount_due')
                ->withSum('receivables as total_paid', 'amount_paid');
        }
        $paymentProfiles = $paymentProfileQuery ? $paymentProfileQuery->orderBy('student_name')->get(['id','student_name','admission_no','class_name','section_name']) : collect();
        $paymentClasses = $schoolId ? CentralFinanceStudentProfile::on('mysql')->where('school_id', $schoolId)->whereNotNull('class_name')->where('class_name', '!=', '')->distinct()->orderBy('class_name')->pluck('class_name') : collect();
        $paymentReceivables=$schoolId ? CentralFinanceReceivable::on('mysql')->where('school_id',$schoolId)->whereIn('status',['open','partial'])->orderBy('student_profile_id')->orderBy('due_date')->get(['id','student_profile_id','description','amount_due','amount_paid','currency']) : collect();
        $categories=$schoolId ? CentralFinanceCategory::on('mysql')->where('school_id',$schoolId)->orderBy('type')->orderBy('name')->get() : collect();
        $operators=CentralFinanceUser::on('mysql')->whereIn('id', (clone $filteredLedger)->distinct()->pluck('created_by')->filter())->orderBy('first_name')->get(['id','first_name','last_name','email']);
        $staff=$schoolId && $canConfigureAccounts ? CentralFinanceUser::on('mysql')->whereIn('id', \Illuminate\Support\Facades\DB::connection('mysql')->table('central_finance_user_school_scopes')->where('school_id',$schoolId)->where('can_view',true)->pluck('user_id'))->with(['authorizedFundAccounts' => fn ($query) => $query->where(function ($account) use ($schoolId) { $account->where('school_id',$schoolId)->orWhere('owner_type',CentralFinanceFundAccount::OWNER_HQ); })])->orderBy('first_name')->paginate(25, ['*'], 'staff_page')->withQueryString() : collect();
        $accountReport=null;
        if ($requestedAccountId !== null) {
            $accountReport=$accounts->firstWhere('id',$requestedAccountId);
            abort_unless($accountReport !== null, 404);
        }
        $audits=$schoolId && $canConfigureAccounts ? CentralFinanceDocumentAudit::on('mysql')->where('school_id',$schoolId)->when($filters['source'] ?? null, fn ($query, $source) => $query->where('document_type',$source))->latest()->paginate(25, ['*'], 'audits_page')->withQueryString() : collect();
        $paymentImportBatch = null; $expenseImportBatch = null;
        if ($page === 'payments' && $schoolId && $request->filled('import_batch')) {
            $paymentImportBatch = CentralFinanceImportBatch::on('mysql')->where([
                'token' => $request->string('import_batch')->toString(), 'school_id' => $schoolId,
                'uploaded_by' => $actor->id, 'import_type' => 'payment',
            ])->firstOrFail();
        }
        if ($page === 'operating' && $request->string('operation')->toString() === 'expense' && $schoolId && $request->filled('import_batch')) {
            $expenseImportBatch = CentralFinanceImportBatch::on('mysql')->where([
                'token' => $request->string('import_batch')->toString(), 'school_id' => $schoolId,
                'uploaded_by' => $actor->id, 'import_type' => 'expense',
            ])->firstOrFail();
        }
        $cutoverChecklist = $school && $canConfigureAccounts ? $this->cutoverReadiness->checklist($school) : collect();
        $data=['page'=>$page,'actor'=>$actor,'school'=>$school,'schools'=>$schools,'accounts'=>$accounts,'canOperate'=>$canOperate,'canConfigureAccounts'=>$canConfigureAccounts,'cutoverStatus'=>$schoolId?$this->cutovers->statusForSchool($schoolId):null,'cutoverChecklist'=>$cutoverChecklist,'schoolUsers'=>$schoolUsers,'operators'=>$operators,'ledger'=>$ledger,'totals'=>$totals,'filters'=>$filters,'receivables'=>$receivables,'profiles'=>$profiles,'payments'=>$payments,'paymentProfiles'=>$paymentProfiles,'paymentClasses'=>$paymentClasses,'paymentReceivables'=>$paymentReceivables,'categories'=>$categories,'staff'=>$staff,'audits'=>$audits,'accountReport'=>$accountReport,'paymentImportBatch'=>$paymentImportBatch,'expenseImportBatch'=>$expenseImportBatch,'expenseCategories'=>$schoolId?CentralFinanceCategory::on('mysql')->where(['school_id'=>$schoolId,'type'=>'expense','is_active'=>true])->get():collect(),'incomeCategories'=>$schoolId?CentralFinanceCategory::on('mysql')->where(['school_id'=>$schoolId,'type'=>'income','is_active'=>true])->get():collect(),'expenses'=>$schoolId?CentralFinanceExpense::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'otherIncomes'=>$schoolId?CentralFinanceOtherIncome::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'reimbursements'=>$schoolId?CentralFinanceReimbursementRequest::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'handovers'=>$schoolId?CentralFinanceFundHandover::on('mysql')->where('school_id',$schoolId)->latest()->get():collect(),'fundingRequests'=>$schoolId?CentralFinanceHqFundingRequest::on('mysql')->where('school_id',$schoolId)->latest()->get():collect()];
        return view('central-finance.workspace',$data);
    }

    /** @return array<string, mixed> */
    private function validatedReadFilters(Request $request, ?\App\Models\School $school, \Illuminate\Support\Collection $accounts): array
    {
        $data=$request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'school_id'=>['nullable','integer'],'fund_account_id'=>['nullable','integer'],'source'=>['nullable','string','max:80'],'category_id'=>['nullable','integer'],'operator_id'=>['nullable','integer'],'student'=>['nullable','string','max:191'],'reference'=>['nullable','string','max:100'],'payment_class'=>['nullable','string','max:191'],'payment_student'=>['nullable','string','max:191']]);
        if ($school && isset($data['school_id']) && (int) $data['school_id'] !== $school->id) abort(404);
        if (isset($data['fund_account_id']) && !$accounts->contains('id',(int)$data['fund_account_id'])) abort(404);
        return $data;
    }

    private function applyLedgerFilters($query, array $filters)
    {
        if (!empty($filters['from'])) $query->whereDate('entry_date','>=',$filters['from']);
        if (!empty($filters['to'])) $query->whereDate('entry_date','<=',$filters['to']);
        if (!empty($filters['school_id'])) $query->where('school_id',(int)$filters['school_id']);
        if (!empty($filters['fund_account_id'])) $query->where('fund_account_id',(int)$filters['fund_account_id']);
        if (!empty($filters['source'])) $query->where('source_type',$filters['source']);
        if (!empty($filters['operator_id'])) $query->where('created_by',(int)$filters['operator_id']);
        if (!empty($filters['category_id'])) {
            $category=(int)$filters['category_id'];
            $expenseIds=CentralFinanceExpense::on('mysql')->where('category_id',$category)->pluck('expense_uuid');
            $incomeIds=CentralFinanceOtherIncome::on('mysql')->where('category_id',$category)->pluck('income_uuid');
            $query->where(function ($nested) use ($expenseIds,$incomeIds): void {
                $nested->where(fn ($expenses) => $expenses->whereIn('source_type',['central_expense','central_expense_void'])->whereIn('source_id',$expenseIds))
                    ->orWhere(fn ($income) => $income->whereIn('source_type',['central_other_income','central_other_income_void'])->whereIn('source_id',$incomeIds));
            });
        }
        return $query;
    }

    /** Central-only Ledger scope shared by the rendered page and export. */
    private function scopedLedgerQuery(?\App\Models\School $school, \Illuminate\Support\Collection $schools, \Illuminate\Support\Collection $accounts, array $filters)
    {
        $query = CentralFinanceLedgerEntry::on('mysql');
        if ($school) $query->where('school_id', $school->id);
        elseif ($schools->isNotEmpty()) $query->whereIn('school_id', $schools->pluck('id'));
        else $query->whereRaw('1 = 0');
        // A School scope is never a substitute for an explicit Fund Account scope.
        $query->whereIn('fund_account_id', $accounts->pluck('id'));
        return $this->applyLedgerFilters($query, $filters);
    }

    /** Central-only Payment/Receipt scope shared by the rendered page and export. */
    private function scopedPaymentQuery(?\App\Models\School $school, \Illuminate\Support\Collection $schools, \Illuminate\Support\Collection $accounts, array $filters)
    {
        $query = CentralFinancePayment::on('mysql');
        if ($school) $query->where('school_id', $school->id);
        elseif ($schools->isNotEmpty()) $query->whereIn('school_id', $schools->pluck('id'));
        else $query->whereRaw('1 = 0');
        $query->whereIn('fund_account_id', $accounts->pluck('id'));
        if (!empty($filters['school_id'])) $query->where('school_id', (int) $filters['school_id']);
        if (!empty($filters['fund_account_id'])) $query->where('fund_account_id', (int) $filters['fund_account_id']);
        if (!empty($filters['from'])) $query->whereDate('paid_at', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('paid_at', '<=', $filters['to']);
        if (!empty($filters['student'])) $query->whereHas('receivable.studentProfile', fn ($profile) => $profile->where(fn ($nested) => $nested->where('student_name','like',"%{$filters['student']}%")->orWhere('admission_no','like',"%{$filters['student']}%")));
        if (!empty($filters['reference'])) $query->where('payment_reference','like',"%{$filters['reference']}%");
        return $query;
    }

    /** @return array<string,string> */
    private function ledgerCategoryNames(\Illuminate\Support\Collection $entries): array
    {
        $expenseIds = $entries->whereIn('source_type', ['central_expense','central_expense_void'])->pluck('source_id')->unique()->filter();
        $incomeIds = $entries->whereIn('source_type', ['central_other_income','central_other_income_void'])->pluck('source_id')->unique()->filter();
        $names = [];
        CentralFinanceExpense::on('mysql')->withTrashed()->with('category')->whereIn('expense_uuid', $expenseIds)->get()->each(fn ($item) => $names['central_expense:'.$item->expense_uuid] = $names['central_expense_void:'.$item->expense_uuid] = $item->category?->name ?? '');
        CentralFinanceOtherIncome::on('mysql')->withTrashed()->with('category')->whereIn('income_uuid', $incomeIds)->get()->each(fn ($item) => $names['central_other_income:'.$item->income_uuid] = $names['central_other_income_void:'.$item->income_uuid] = $item->category?->name ?? '');
        return $names;
    }

    private function downloadReadExport(CentralFinanceReadExport $export, string $basename, string $format)
    {
        abort_unless(in_array($format, ['xlsx','csv'], true), 404);
        return Excel::download($export, $basename.'_'.now()->format('Ymd_His').'.'.$format, $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX);
    }
}
