<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundHandover;
use App\Models\CentralFinanceHqFundingRequest;
use App\Models\CentralFinanceInternalTransfer;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinanceReceivableAdjustment;
use App\Models\CentralFinanceImportBatch;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceReimbursementRequest;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceSchoolCutover;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Models\FinanceGroupSchool;
use App\Models\CentralFinancePromotion;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinanceFundAccountSchoolAvailabilityService;
use App\Services\CentralFinanceFundAccountScopeService;
use App\Services\CentralFinanceLedgerPresentationService;
use App\Services\CentralFinanceFundAccountAdministrationService;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use App\Services\CentralFinanceFundHandoverService;
use App\Services\CentralFinanceHqFundingService;
use App\Services\CentralFinanceInternalTransferService;
use App\Services\CentralFinanceOperatingDocumentService;
use App\Services\CentralFinancePaymentService;
use App\Services\CentralFinancePaymentRefundService;
use App\Services\CentralFinancePaymentReversalService;
use App\Services\CentralFinanceReceivableAdjustmentService;
use App\Services\CentralFinancePromotionService;
use App\Services\CentralFinancePaymentImportService;
use App\Services\CentralFinanceExpenseImportService;
use App\Services\CentralFinanceImportBatchService;
use App\Services\CentralFinanceImportBatchPresentationService;
use App\Services\CentralFinanceReimbursementService;
use App\Services\CentralFinanceSchoolCutoverService;
use App\Services\CentralFinanceCutoverReadinessService;
use App\Services\CentralFinanceWorkspaceService;
use App\Services\CentralFinanceCurrencySummaryService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceReceiptViewModelFactory;
use App\ViewModels\CentralFinanceReceiptViewModel;
use App\Support\CentralFinanceCurrency;
use App\Support\CentralFinanceBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $accountAvailability,
        private readonly CentralFinanceFundAccountScopeService $accountScopes,
        private readonly CentralFinancePaymentService $payments,
        private readonly CentralFinancePaymentRefundService $refunds,
        private readonly CentralFinancePaymentReversalService $reversals,
        private readonly CentralFinanceReceivableAdjustmentService $receivableAdjustments,
        private readonly CentralFinancePromotionService $promotions,
        private readonly CentralFinancePaymentImportService $paymentImports,
        private readonly CentralFinanceExpenseImportService $expenseImports,
        private readonly CentralFinanceImportBatchService $importBatches,
        private readonly CentralFinanceImportBatchPresentationService $importBatchPresentation,
        private readonly CentralFinanceOperatingDocumentService $documents,
        private readonly CentralFinanceReimbursementService $reimbursements,
        private readonly CentralFinanceInternalTransferService $transfers,
        private readonly CentralFinanceFundHandoverService $handovers,
        private readonly CentralFinanceHqFundingService $funding,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceCutoverReadinessService $cutoverReadiness,
        private readonly CentralFinanceFundAccountAdministrationService $accountAdministration,
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
        private readonly CentralFinanceLedgerPresentationService $ledgerPresentation,
        private readonly CentralFinanceCurrencySummaryService $currencySummaries,
        private readonly CentralFinanceReceiptViewModelFactory $receiptViewModels,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    public function dashboard(?Request $request = null): View
    {
        return $this->render('dashboard', $request ?? request());
    }

    public function receivables(?Request $request = null): View
    {
        return $this->render('receivables', $request ?? request());
    }

    public function promotions(): View
    {
        $actor = $this->actor();
        $groups = $this->configuration->configurableGroups($actor);
        $promotions = CentralFinancePromotion::on('mysql')->with('allocations.school')->whereIn('group_id', $groups->pluck('id'))->latest()->get();
        $schools = FinanceGroupSchool::on('mysql')->with('school')->whereIn('group_id', $groups->pluck('id'))->where('status', 'active')->get()->groupBy('group_id');
        $promotionClassifications = $promotions->mapWithKeys(fn (CentralFinancePromotion $promotion): array => [
            $promotion->id => $this->dataIsolation->classification('promotion', $promotion->id),
        ]);
        return view('central-finance.promotions', compact('groups', 'promotions', 'schools', 'promotionClassifications'));
    }

    public function storePromotion(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validate(['group_id'=>['required','integer'],'name'=>['required','string','max:191'],'code'=>['required','string','max:80','regex:/^[A-Za-z0-9_-]+$/'],'description'=>['nullable','string'],'discount_type'=>['required',Rule::in([CentralFinancePromotion::PERCENTAGE,CentralFinancePromotion::FIXED])],'discount_value'=>['required','regex:/^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,4})?$/'],'valid_from'=>['required','date_format:Y-m-d'],'valid_until'=>['nullable','date_format:Y-m-d'],'status'=>['required',Rule::in(CentralFinancePromotion::STATUSES)],'school_ids'=>['required','array','min:1'],'school_ids.*'=>['integer','distinct']]);
        try { $this->promotions->define($actor, (int) $data['group_id'], $data['school_ids'], $data); } catch (InvalidArgumentException|AuthorizationException $exception) { return back()->withErrors(['promotion' => __($exception->getMessage())])->withInput(); }
        return back()->with('success', __('Promotion definition created.'));
    }

    public function operating(?Request $request = null): View
    {
        return $this->render('operating', $request ?? request());
    }

    public function expenses(?Request $request = null): View
    {
        return $this->render('expenses', $request ?? request());
    }

    public function otherIncomeIndex(?Request $request = null): View
    {
        return $this->render('other-income', $request ?? request());
    }

    public function reimbursements(?Request $request = null): View
    {
        return $this->render('reimbursements', $request ?? request());
    }

    public function reimbursementDetail(int $reimbursement): View
    {
        [$actor, $school] = $this->currentReadSchool();
        $document = CentralFinanceReimbursementRequest::on('mysql')->where('school_id', $school->id)->findOrFail($reimbursement);
        $audits = CentralFinanceDocumentAudit::on('mysql')->where([
            'school_id' => $school->id, 'document_type' => 'reimbursement', 'document_id' => $document->id,
        ])->latest()->get();
        $canOperate = false;
        try { $this->workspace->requireOperatingSchool($actor); $canOperate = $this->cutovers->allowsCentralWrites($school->id); } catch (AuthorizationException) {}
        $canApprove = false;
        try { app(\App\Services\CentralFinanceSchoolScopeService::class)->assertCanApproveReimbursements($actor, $school->id); $canApprove = $canOperate; } catch (AuthorizationException) {}
        return view('central-finance.reimbursement-detail', [
            'document' => $document, 'school' => $school,
            'accounts' => $this->workspace->accessibleAccounts($actor, $school->id), 'audits' => $audits,
            'canApprove' => $canApprove, 'canWithdraw' => $canOperate && (int) $document->requested_by === (int) $actor->id,
            'canOperate' => $canOperate,
        ]);
    }

    public function accounts(?Request $request = null): View
    {
        return $this->render('accounts', $request ?? request());
    }

    public function manageFundAccount(Request $request, int $fundAccount): View
    {
        [$actor, $account] = $this->currentGroupAccountContext($fundAccount);
        $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $account->group_id);

        return $this->render('account-manage', $request, $fundAccount);
    }

    /** Read-only statement hub over the canonical Central Ledger. */
    public function accountStatements(?Request $request = null): View
    {
        $request ??= request();

        return $this->render('account-statements', $request, $request->integer('fund_account_id') ?: null);
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
        $this->currentHeadFinanceOperatingContext();
        return $this->render('funding', $request ?? request());
    }

    public function ledger(?Request $request = null): View
    {
        return $this->render('ledger', $request ?? request());
    }

    public function ledgerDetail(int $ledger): View
    {
        [$actor, $school, $schools, $accounts, $filters] = $this->readScope(request());
        $entry = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)
            ->with('fundAccount')->findOrFail($ledger);
        $this->ledgerPresentation->decorate(collect([$entry]));
        $related = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)
            ->where('source_id', $entry->source_id)
            ->where('id', '!=', $entry->id)->orderBy('entry_date')->orderBy('occurred_at')->orderBy('id')->get();
        $this->ledgerPresentation->decorate($related);
        return view('central-finance.ledger-detail', compact('actor', 'school', 'entry', 'related'));
    }

    public function ledgerSource(int $ledger): View
    {
        [$actor, $school, $schools, $accounts, $filters] = $this->readScope(request());
        $entry = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)
            ->with('fundAccount')->findOrFail($ledger);
        $source = $this->ledgerPresentation->source($entry);
        $audits = $source['model']
            ? $this->sourceAudits($source['model'], $entry->school_id)
            : collect();
        return view('central-finance.ledger-source', compact('actor', 'school', 'entry', 'source', 'audits'));
    }

    public function reports(?Request $request = null): View
    {
        return $this->render('reports', $request ?? request());
    }

    /** Read-only batch history; confirmation remains on the existing import flows. */
    public function imports(?Request $request = null): View
    {
        return $this->render('imports', $request ?? request());
    }

    public function importBatchDetail(string $batch): View
    {
        $actor = $this->actor();
        $batch = $this->scopedImportBatch($actor, $batch);
        $school = $this->workspace->assertCanViewSchool($actor, (int) $batch->school_id);
        $state = $this->importBatchPresentation->state($batch);
        $correctedUploadUrl = $this->importBatchPresentation->correctedUploadUrl($batch);

        return view('central-finance.import-batch-detail', compact('batch', 'school', 'state', 'correctedUploadUrl'));
    }

    public function downloadImportErrors(string $batch): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor = $this->actor();
        $batch = $this->scopedImportBatch($actor, $batch);
        $rows = collect($batch->preview_data ?? [])->filter(fn (array $row): bool => ($row['status'] ?? null) === 'error');

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Row', 'Status', 'Errors']);
            foreach ($rows as $row) {
                fputcsv($output, [$row['row_number'] ?? '', 'error', implode('; ', array_map('strval', $row['errors'] ?? []))]);
            }
            fclose($output);
        }, 'central_finance_import_errors_'.$batch->batch_uuid.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A navigation hub for the existing scoped read exports. */
    public function exports(?Request $request = null): View
    {
        return $this->render('exports', $request ?? request());
    }

    public function studentLedger(Request $request): View { return $this->render('student-ledger', $request); }
    public function paymentHistory(Request $request): View { return $this->render('payments', $request); }
    public function receipt(int $payment): View
    {
        [$actor, $school, $document, $receipt, $audits] = $this->receiptData($payment);
        return view('central-finance.receipt', compact('actor', 'school', 'document', 'receipt', 'audits'));
    }

    /** Immutable payment detail is the only correction-action surface. */
    public function paymentDetail(int $payment): View
    {
        [$actor, $school, $document, $receipt, $audits] = $this->receiptData($payment);
        $document->loadMissing('refunds.refundedBy', 'reversal.reversedBy');
        // Layer 2 corrections are still one-receivable documents.  Never let
        // their legacy services attempt to infer a line inside a P0 parent.
        $canCorrect = $document->receivable_id !== null;
        try {
            [, $operatingSchool] = $this->currentCorrectionContext();
            $canCorrect = $canCorrect && (int) $operatingSchool->id === (int) $school->id
                && $this->dataIsolation->isWorkflowWritable('payment', (int) $document->id)
                && $this->cutovers->allowsCentralWrites((int) $school->id);
        } catch (AuthorizationException) {
            // Read-only detail remains available to the existing authorized scope.
        }
        $refundTotal = (float) $document->refunds->sum('amount');
        $remainingRefundable = $document->reversal ? 0.0 : max(0, (float) $document->amount - $refundTotal);
        $refundToken = 'ui-payment-refund-'.Str::uuid();
        $reversalToken = 'ui-payment-reversal-'.Str::uuid();
        $correctionUnavailableReason = $document->receivable_id === null
            ? __('Refund and reversal for a multi-receivable parent payment require the allocation-aware correction release. This payment remains read-only until then.')
            : null;
        return view('central-finance.payment-detail', compact('actor', 'school', 'document', 'receipt', 'audits', 'canCorrect', 'refundTotal', 'remainingRefundable', 'refundToken', 'reversalToken', 'correctionUnavailableReason'));
    }

    public function refundDetail(int $payment, int $refund): View
    {
        [$actor, $school, $document, $receipt, $audits] = $this->receiptData($payment);
        $refundDocument = $document->refunds->firstWhere('id', $refund);
        abort_unless($refundDocument !== null, 404);
        return view('central-finance.payment-refund-detail', compact('actor', 'school', 'document', 'receipt', 'refundDocument', 'audits'));
    }

    public function reversalDetail(int $payment, int $reversal): View
    {
        [$actor, $school, $document, $receipt, $audits] = $this->receiptData($payment);
        $reversalDocument = $document->reversal;
        abort_unless($reversalDocument !== null && (int) $reversalDocument->id === $reversal, 404);
        return view('central-finance.payment-reversal-detail', compact('actor', 'school', 'document', 'receipt', 'reversalDocument', 'audits'));
    }

    public function receivableDetail(int $receivable): View
    {
        [$actor, $school] = $this->currentReadSchool();
        $document = CentralFinanceReceivable::on('mysql')->with([
            'studentProfile', 'payments.receipt', 'payments.refunds.fundAccount',
        ])->where('school_id', $school->id)->findOrFail($receivable);
        $adjustments = CentralFinanceReceivableAdjustment::on('mysql')
            ->where('receivable_id', $document->id)->latest('adjusted_at')->get();
        $lifecycleTotals = [
            'promotion' => $adjustments->where('adjustment_type', CentralFinanceReceivableAdjustmentService::PROMOTION)->sum('amount_delta'),
            'waiver' => $adjustments->where('adjustment_type', CentralFinanceReceivableAdjustmentService::WAIVER)->sum('amount_delta'),
            'correction' => $adjustments->where('adjustment_type', CentralFinanceReceivableAdjustmentService::CORRECTION)->sum('amount_delta'),
        ];
        $audits = CentralFinanceDocumentAudit::on('mysql')->where('school_id', $school->id)
            ->where('document_type', 'central_receivable_adjustment')
            ->whereIn('document_id', $adjustments->pluck('id'))->latest()->get();
        $canOperate = false;
        $eligiblePromotions = collect();

        try {
            [$correctionActor] = $this->currentCorrectionContext();
            $this->dataIsolation->assertWorkflowWritable('receivable', (int) $document->id);
            $canOperate = $this->cutovers->allowsCentralWrites($school->id);
            if ($canOperate) $eligiblePromotions = $this->promotions->eligibleFor($correctionActor, $document, CarbonImmutable::now('Asia/Yangon'));
        } catch (AuthorizationException) {
            // Read-only Central Finance users may inspect the immutable history.
        }

        $adjustmentIdempotencyKey = 'ui-receivable-'.Str::uuid();

        return view('central-finance.receivable-detail', compact('actor', 'school', 'document', 'adjustments', 'audits', 'canOperate', 'eligiblePromotions', 'adjustmentIdempotencyKey', 'lifecycleTotals'));
    }

    public function expenseDetail(int $expense): View
    {
        return $this->operatingDocumentDetail('expense', $expense);
    }

    public function otherIncomeDetail(int $income): View
    {
        return $this->operatingDocumentDetail('other_income', $income);
    }
    public function staff(Request $request): View { return $this->render('staff', $request); }
    public function categories(Request $request): View { return $this->render('categories', $request); }
    public function audits(Request $request): View
    {
        return $this->render('audits', $request);
    }
    public function fundAccountReport(Request $request, int $fundAccount): View { return $this->render('account-report', $request, $fundAccount); }
    public function fundAccountStatement(Request $request, int $fundAccount): View { return $this->render('account-statement', $request, $fundAccount); }

    public function exportAudits(Request $request, string $format)
    {
        [$actor, $school, $schools, $accounts, $filters] = $this->readScope($request);
        $audits = $this->scopedAuditQuery($school, $schools, $filters)->orderByDesc('created_at')->get();
        $actors = CentralFinanceUser::on('mysql')->whereIn('id', $audits->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        $schoolNames = $schools->pluck('name', 'id');
        $rows = $audits->map(fn (CentralFinanceDocumentAudit $audit) => [
            $audit->created_at?->format('Y-m-d H:i:s'), $schoolNames[$audit->school_id] ?? '', $actors[$audit->actor_id]?->full_name ?? '',
            $audit->document_type, $audit->document_id, $audit->action, $audit->reason,
            json_encode($audit->before_values, JSON_UNESCAPED_UNICODE), json_encode($audit->after_values, JSON_UNESCAPED_UNICODE),
        ])->all();
        return $this->downloadReadExport(new CentralFinanceReadExport('Central Finance Audit Log', [
            'Timestamp','School','Actor','Module','Document ID','Action','Reason','Before','After',
        ], $rows), 'central_finance_audit_log', $format);
    }

    /** Standard Ledger export uses precisely the Central read-model filters. */
    public function exportLedger(Request $request, string $format)
    {
        $actor = $this->actor(); $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor); $accounts = $this->workspace->readableAccounts($actor, $school?->id);
        $filters = $this->validatedReadFilters($request, $school, $accounts);
        $entries = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)->with('fundAccount')->orderBy('entry_date')->orderBy('occurred_at')->orderBy('id')->get();
        $schoolNames = $schools->pluck('name', 'id'); $operators = CentralFinanceUser::on('mysql')->whereIn('id', $entries->pluck('created_by')->filter()->unique())->get()->mapWithKeys(fn ($user) => [$user->id => $user->full_name]);
        $categories = $this->ledgerCategoryNames($entries);
        $this->ledgerPresentation->decorate($entries);
        $rows = $entries->map(fn ($entry) => [
            $entry->entry_date?->format('Y-m-d'), $entry->occurred_at?->format('Y-m-d H:i'), $schoolNames[$entry->school_id] ?? '', trim(($entry->fundAccount?->account_name ?? '').' · '.($entry->fundAccount?->account_code ?? '')),
            $entry->direction_label, max((float) $entry->money_in, (float) $entry->money_out), $entry->operating_label,
            $categories[$entry->source_type.':'.$entry->source_id] ?? '', $entry->readable_source, $entry->readable_document_number,
            $operators[$entry->created_by] ?? '', $entry->currency, $entry->memo,
        ])->all();
        return $this->downloadReadExport(new CentralFinanceReadExport('Standard Ledger', ['Transaction Date','Recorded At','School','Fund Account','Direction','Amount','Operating Classification','Category','Source','Reference','Operator','Currency','Description'], $rows), 'central_standard_ledger', $format);
    }

    /** Payment/receipt export intentionally contains no guardian/contact data. */
    public function exportPayments(Request $request, string $format)
    {
        $actor = $this->actor(); $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor); $accounts = $this->workspace->readableAccounts($actor, $school?->id);
        $filters = $this->validatedReadFilters($request, $school, $accounts);
        $payments = $this->scopedPaymentQuery($school, $schools, $accounts, $filters)->with($this->paymentReadRelations(false))->orderBy('paid_at')->get();
        $schoolNames = $schools->pluck('name', 'id');
        $rows = $payments->map(fn ($payment) => [
            $payment->paid_at?->format('Y-m-d H:i'), $schoolNames[$payment->school_id] ?? '', $payment->receivable?->studentProfile?->student_name ?? ($payment->relationLoaded('allocations') ? $payment->allocations->first()?->receivable?->studentProfile?->student_name : null),
            $payment->receivable?->studentProfile?->admission_no ?? ($payment->relationLoaded('allocations') ? $payment->allocations->first()?->receivable?->studentProfile?->admission_no : null), $payment->fundAccount?->account_code, $payment->fundAccount?->account_name,
            $payment->payment_reference, $payment->receipt?->receipt_no, $payment->payment_method, $payment->currency, (float) $payment->amount,
        ])->all();
        return $this->downloadReadExport(new CentralFinanceReadExport('Payment Receipts', ['Payment Effective Date','School','Student','Student Code','Fund Account Code','Fund Account','Payment Reference','Receipt No','Method','Currency','Amount'], $rows), 'central_payment_receipts', $format);
    }

    /** Export uses exactly the scoped read model rendered by each worklist. */
    public function exportOperatingDocuments(Request $request, string $type, string $format)
    {
        abort_unless(in_array($type, ['expense', 'other_income'], true), 404);
        [$actor, $school, $schools, $accounts, $filters] = $this->readScope($request);
        $documents = $this->scopedOperatingDocumentsQuery($type, $school, $schools, $accounts, $filters)
            ->with('category')->orderBy($type === 'expense' ? 'expense_date' : 'income_date')->get();
        $schoolNames = $schools->pluck('name', 'id');
        $operators = CentralFinanceUser::on('mysql')->whereIn('id', $documents->pluck('created_by')->filter()->unique())
            ->get()->mapWithKeys(fn ($user) => [$user->id => $user->full_name]);
        $rows = $documents->map(function ($document) use ($type, $schoolNames, $operators) {
            return [
                ($type === 'expense' ? $document->expense_date : $document->income_date)?->format('Y-m-d'),
                $schoolNames[$document->school_id] ?? '', $document->fund_account_id,
                $document->category?->name, $document->payment_method, $document->reference_no,
                $type === 'other_income' ? $document->payer : ($document->reimbursed_by ?? ''),
                $document->currency, (float) $document->amount, $operators[$document->created_by] ?? '',
                $document->trashed() ? __('Voided') : __('Active'), $document->description,
            ];
        })->all();
        $title = $type === 'expense' ? 'Central Expenses' : 'Central Income';
        return $this->downloadReadExport(new CentralFinanceReadExport($title, [
            'Transaction Date','School','Fund Account ID','Category','Payment Method','Reference','Payee / Payer','Currency','Amount','Operator','Status','Description',
        ], $rows), $type === 'expense' ? 'central_expenses' : 'central_other_income', $format);
    }

    public function exportFundAccountReport(Request $request, int $fundAccount, string $format)
    {
        $actor = $this->actor(); $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor); $accounts = $this->workspace->readableAccounts($actor, $school?->id);
        abort_unless($accounts->contains('id', $fundAccount), 404);
        $filters = $this->validatedReadFilters($request, $school, $accounts); $filters['fund_account_id'] = $fundAccount;
        $account = $accounts->firstWhere('id', $fundAccount);
        $entries = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)->with('fundAccount')->orderBy('entry_date')->orderBy('occurred_at')->orderBy('id')->get();
        $schoolNames = $schools->pluck('name', 'id');
        $moneyIn = (float) $entries->sum('money_in'); $moneyOut = (float) $entries->sum('money_out');
        // A filtered report may omit historic rows; closing remains the canonical full account balance.
        $closing = $this->balances->currentBalance($account);
        $rows = [[
            'Summary', '', $schoolNames[$account->school_id] ?? 'HQ', $account->account_code, $account->account_name, '', '', $account->currency,
            (float) $account->opening_balance, $moneyIn, $moneyOut, $closing,
        ]];
        foreach ($entries as $entry) $rows[] = [
            'Transaction', $entry->entry_date?->format('Y-m-d'), $schoolNames[$entry->school_id] ?? '', $account->account_code, $account->account_name,
            $entry->source_type, $entry->reference_no, $entry->currency, null, (float) $entry->money_in, (float) $entry->money_out, null,
        ];
        return $this->downloadReadExport(new CentralFinanceReadExport('Fund Account Report', ['Record','Transaction Date','School','Account Code','Fund Account','Source','Reference','Currency','Opening Balance','Money In','Money Out','Current Physical Balance'], $rows), 'central_fund_account_report_'.$account->account_code, $format);
    }

    /** A statement export retains the canonical opening-plus-Ledger balance. */
    public function exportFundAccountStatement(Request $request, int $fundAccount, string $format)
    {
        $actor = $this->actor();
        $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor);
        $accounts = $this->viewableFundAccounts($actor, $school?->id);
        abort_unless($accounts->contains('id', $fundAccount), 404);

        $filters = $this->validatedReadFilters($request, $school, $accounts, $schools);
        $filters['fund_account_id'] = $fundAccount;
        $account = $accounts->firstWhere('id', $fundAccount);
        [$openingBalance, $entries] = $this->accountStatementData($school, $schools, $accounts, $filters, $account);
        $operators = CentralFinanceUser::on('mysql')->whereIn('id', $entries->pluck('created_by')->filter()->unique())
            ->get()->mapWithKeys(fn (CentralFinanceUser $user) => [$user->id => $user->full_name]);

        $rows = [['Opening / prior balance', '', '', '', '', '', $account->currency, '', '', $openingBalance]];
        foreach ($entries as $entry) {
            $rows[] = [
                'Ledger entry', $entry->entry_date?->format('Y-m-d'), $entry->source_type, $entry->memo,
                $operators[$entry->created_by] ?? '', $entry->reference_no, $entry->currency,
                (float) $entry->money_in, (float) $entry->money_out, (float) $entry->running_balance,
            ];
        }

        return $this->downloadReadExport(new CentralFinanceReadExport('Fund Account Statement', [
            'Record','Transaction Date','Source','Description','Operator','Reference','Currency','Money In','Money Out','Running Balance',
        ], $rows), 'central_fund_account_statement_'.$account->account_code, $format);
    }

    public function createCategory(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $category = app(\App\Services\CentralChartOfAccountsService::class)->save($actor, array_merge($request->all(), ['school_ids' => $request->input('school_ids', [])]));
        app(\App\Services\CentralFinanceBusinessContentTranslationService::class)->saveEnglish($actor, 'chart_account', $category->id, null, $category->name, (string) $request->input('english_name'), (string) $request->input('reason'));
        return back()->with('success', __('Chart of Accounts saved.'));
    }

    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $this->dataIsolation->assertProduction('category', $category);
        $actor = $this->actor();
        $before = CentralFinanceCategory::on('mysql')->findOrFail($category);
        $oldName = (string) $before->name;
        $saved = app(\App\Services\CentralChartOfAccountsService::class)->save($actor, array_merge($request->all(), ['school_ids' => $request->input('school_ids', [])]), $category);
        $translations = app(\App\Services\CentralFinanceBusinessContentTranslationService::class);
        if ($oldName !== $saved->name) $translations->markEnglishStale('chart_account', $saved->id, null, $saved->name);
        $translations->saveEnglish($actor, 'chart_account', $saved->id, null, $saved->name, (string) $request->input('english_name'), (string) $request->input('reason'));
        return back()->with('success', __('Chart of Accounts saved.'));
    }

    public function toggleCategory(Request $request, int $category): RedirectResponse
    {
        $item = CentralFinanceCategory::on('mysql')->findOrFail($category);
        $this->dataIsolation->assertProduction('category', $category);
        app(\App\Services\CentralChartOfAccountsService::class)->save($this->actor(), array_merge($item->only(['group_id','type','category_code','name']), [
            'is_active' => !$item->is_active, 'school_ids' => $item->schoolAllocations()->where('is_active',true)->pluck('school_id')->all(), 'reason' => $request->input('reason'),
        ]), $category);
        return back()->with('success', __('Chart of Accounts saved.'));
    }

    public function enterSchool(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validate(['school_id' => ['required', 'integer', 'min:1'], 'return_to' => ['nullable', 'string', 'max:2048']]);
        $schoolId = (int) $data['school_id'];
        $this->workspace->enterSchool($actor, $schoolId);
        return $this->centralNavigationRedirect($data['return_to'] ?? null);
    }

    public function exitSchool(Request $request): RedirectResponse
    {
        $this->workspace->exitSchool();
        $data = $request->validate(['return_to' => ['nullable', 'string', 'max:2048']]);
        return $this->centralNavigationRedirect($data['return_to'] ?? null);
    }

    public function createFundAccount(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'min:1'],
            'account_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'owner_holder' => ['nullable', 'string', 'max:191'],
            'account_name' => ['required', 'string', 'max:191'], 'currency' => ['required', Rule::in(CentralFinanceCurrency::ALLOWED)],
            'english_name' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['required', 'numeric', 'min:0'], 'opening_balance_date' => ['required', 'date_format:Y-m-d'],
            'opening_reason' => ['required', 'string', 'max:2000'],
            'account_type' => ['required', Rule::in([CentralFinanceFundAccount::TYPE_CASH, CentralFinanceFundAccount::TYPE_BANK, CentralFinanceFundAccount::TYPE_OTHER])],
            'bank_name' => ['nullable', 'string', 'max:191'], 'masked_account_identifier' => ['nullable', 'string', 'max:80'],
            'custodian_user_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['opening_balance_date'] = $this->transactionDate($data['opening_balance_date'], 'opening_balance_date')->toDateString();
        $account = $this->accountAdministration->createGroupAccount($actor, (int) $data['group_id'], $data);
        app(\App\Services\CentralFinanceBusinessContentTranslationService::class)->saveEnglish($actor, 'fund_account', $account->id, null, $account->account_name, (string) $request->input('english_name'), (string) $data['opening_reason']);

        return redirect()->route('central-finance.accounts.manage', ['fundAccount' => $account->id])
            ->with('success', __('Central / Group Fund Account created. Add explicit School allocations before use.'));
    }

    public function updateFundAccount(Request $request, int $fundAccount): RedirectResponse
    {
        [$actor, $account] = $this->currentGroupAccountContext($fundAccount);
        $this->dataIsolation->assertProduction('fund_account', $fundAccount);
        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:191'],
            'english_name' => ['nullable', 'string', 'max:2000'],
            'owner_holder' => ['nullable', 'string', 'max:191'],
            'account_type' => ['required', Rule::in([CentralFinanceFundAccount::TYPE_CASH, CentralFinanceFundAccount::TYPE_BANK, CentralFinanceFundAccount::TYPE_OTHER])],
            'bank_name' => ['nullable', 'string', 'max:191'], 'masked_account_identifier' => ['nullable', 'string', 'max:80'],
            'custodian_user_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:5000'], 'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $oldName = (string) $account->account_name;
        $this->accountAdministration->updateMasterData($actor, null, $account, $data);
        $account->refresh();
        $translations = app(\App\Services\CentralFinanceBusinessContentTranslationService::class);
        if ($oldName !== $account->account_name) $translations->markEnglishStale('fund_account', $account->id, null, $account->account_name);
        $translations->saveEnglish($actor, 'fund_account', $account->id, null, $account->account_name, (string) $request->input('english_name'), (string) ($data['reason'] ?? ''));
        return back()->with('success', __('Central Fund Account master data updated.'));
    }

    public function changeFundAccountStatus(Request $request, int $fundAccount): RedirectResponse
    {
        [$actor, $account] = $this->currentGroupAccountContext($fundAccount);
        $this->dataIsolation->assertProduction('fund_account', $fundAccount);
        $data = $request->validate([
            'status' => ['required', Rule::in([CentralFinanceFundAccount::STATUS_ACTIVE, CentralFinanceFundAccount::STATUS_INACTIVE, CentralFinanceFundAccount::STATUS_ARCHIVED])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->accountAdministration->changeStatus($actor, null, $account, $data['status'], $data['reason']);
        return back()->with('success', __('Central Fund Account lifecycle status updated.'));
    }

    public function syncFundAccountAssignments(Request $request, int $fundAccount): RedirectResponse
    {
        [$actor, $account] = $this->currentGroupAccountContext($fundAccount);
        $this->dataIsolation->assertProduction('fund_account', $fundAccount);
        $data = $request->validate(['authorized_user_ids' => ['nullable', 'array'], 'authorized_user_ids.*' => ['integer', 'distinct'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->accountAdministration->syncSchoolAssignments($actor, null, $account, $data['authorized_user_ids'] ?? [], $data['reason']);
        return back()->with('success', __('Central Fund Account assignments saved.'));
    }

    public function syncFundAccountSchoolAllocations(Request $request, int $fundAccount): RedirectResponse
    {
        [$actor, $account] = $this->currentGroupAccountContext($fundAccount);
        $this->dataIsolation->assertProduction('fund_account', $fundAccount);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.school_id' => ['required', 'integer', 'distinct'],
            'allocations.*.is_active' => ['nullable', 'boolean'],
        ]);
        $this->accountAdministration->syncSchoolAllocations($actor, null, $account, $data['allocations'], $data['reason']);
        return back()->with('success', __('Central Fund Account School allocations saved.'));
    }

    public function adjustFundAccountOpeningBalance(Request $request, int $fundAccount): RedirectResponse
    {
        [$actor, $account] = $this->currentGroupAccountContext($fundAccount);
        $this->dataIsolation->assertProduction('fund_account', $fundAccount);
        $data = $request->validate(['amount' => ['required', 'numeric', 'not_in:0'], 'effective_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);
        $data['effective_date'] = $this->transactionDate($data['effective_date'], 'effective_date')->toDateString();
        $this->accountAdministration->adjustOpeningBalance($actor, null, $account, (float) $data['amount'], $data['effective_date'], $data['reason']);
        return back()->with('success', __('Central Fund Account opening balance adjustment audited.'));
    }

    public function collect(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $this->workspace->assertHeadFinance($actor);
        $data = $request->validate([
            'receivable_id' => ['required', 'integer'], 'fund_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', 'string', 'max:40'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $receivable = CentralFinanceReceivable::on('mysql')->where('school_id', $school->id)->findOrFail($data['receivable_id']);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']);
        $this->assertProductionSubjects([['receivable', (int) $receivable->id], ['fund_account', (int) $account->id]]);
        $this->payments->collect($actor, $receivable->id, $account, (float) $data['amount'], $data['payment_method'], CarbonImmutable::now(), $this->workspace->idempotencyReference('ui-payment'), $data['payment_reference'] ?? null);
        return back()->with('success', __('Central payment collected.'));
    }

    public function refundPayment(Request $request, int $payment): RedirectResponse
    {
        [$actor, $school] = $this->currentCorrectionContext();
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'refund_method' => ['required', 'string', 'max:40'], 'effective_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'refund_reference' => ['nullable', 'string', 'max:100'], 'idempotency_key' => ['required', 'string', 'regex:/^ui-payment-refund-[A-Za-z0-9_.:-]{2,100}$/']]);
        $document = CentralFinancePayment::on('mysql')->where('school_id', $school->id)->findOrFail($payment);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($document->fund_account_id);
        $this->refunds->refund($actor, $document->id, $account, (float) $data['amount'], $data['refund_method'], $this->transactionDate($data['effective_date'], 'effective_date'), $data['reason'], CarbonImmutable::now(), $data['idempotency_key'], $data['refund_reference'] ?? null);
        return back()->with('success', __('Central payment refund recorded.'));
    }

    public function reversePayment(Request $request, int $payment): RedirectResponse
    {
        [$actor, $school] = $this->currentCorrectionContext();
        $data = $request->validate(['effective_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'reversal_reference' => ['nullable', 'string', 'max:100'], 'idempotency_key' => ['required', 'string', 'regex:/^ui-payment-reversal-[A-Za-z0-9_.:-]{2,100}$/']]);
        $document = CentralFinancePayment::on('mysql')->where('school_id', $school->id)->findOrFail($payment);
        $this->reversals->reverse($actor, $document->id, $this->transactionDate($data['effective_date'], 'effective_date'), $data['reason'], CarbonImmutable::now(), $data['idempotency_key'], $data['reversal_reference'] ?? null);
        return back()->with('success', __('Central payment reversal recorded.'));
    }

    public function correctReceivable(Request $request, int $receivable): RedirectResponse
    {
        [$actor, $school] = $this->currentCorrectionContext();
        $data = $request->validate([
            'amount_delta' => ['required', 'regex:/^-?(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,4})?$/', 'not_in:0'],
            'reason' => ['required', 'string', 'max:2000'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'regex:/^ui-receivable-[A-Za-z0-9_.:-]{2,100}$/'],
        ]);
        $document = CentralFinanceReceivable::on('mysql')->where('school_id', $school->id)->findOrFail($receivable);
        try { $this->receivableAdjustments->correct($actor, $document->id, $data['amount_delta'], $data['reason'], $this->transactionDate($data['effective_date'], 'effective_date'), CarbonImmutable::now(), $data['idempotency_key']); } catch (InvalidArgumentException $exception) { return back()->withErrors(['amount_delta' => __($exception->getMessage())])->withInput(); }

        return redirect()->route('central-finance.receivables.show', $document->id)
            ->with('success', __('Central receivable correction recorded.'));
    }

    public function waiveReceivable(Request $request, int $receivable): RedirectResponse
    {
        [$actor, $school] = $this->currentCorrectionContext();
        $data = $request->validate(['amount' => ['required', 'regex:/^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,4})?$/', 'not_in:0'], 'reason' => ['required', 'string', 'max:2000'], 'effective_date' => ['required', 'date_format:Y-m-d'], 'idempotency_key' => ['required', 'string', 'regex:/^ui-receivable-[A-Za-z0-9_.:-]{2,100}$/']]);
        $document = CentralFinanceReceivable::on('mysql')->where('school_id', $school->id)->findOrFail($receivable);
        try { $this->receivableAdjustments->waive($actor, $document->id, $data['amount'], $data['reason'], $this->transactionDate($data['effective_date'], 'effective_date'), CarbonImmutable::now(), $data['idempotency_key']); } catch (InvalidArgumentException $exception) { return back()->withErrors(['amount' => __($exception->getMessage())])->withInput(); }
        return redirect()->route('central-finance.receivables.show', $document->id)->with('success', __('Central receivable waiver recorded.'));
    }

    public function voidReceivable(Request $request, int $receivable): RedirectResponse
    {
        [$actor, $school] = $this->currentCorrectionContext();
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'effective_date' => ['required', 'date_format:Y-m-d'], 'idempotency_key' => ['required', 'string', 'regex:/^ui-receivable-[A-Za-z0-9_.:-]{2,100}$/']]);
        $document = CentralFinanceReceivable::on('mysql')->where('school_id', $school->id)->findOrFail($receivable);
        try { $this->receivableAdjustments->void($actor, $document->id, $data['reason'], $this->transactionDate($data['effective_date'], 'effective_date'), CarbonImmutable::now(), $data['idempotency_key']); } catch (InvalidArgumentException $exception) { return back()->withErrors(['reason' => __($exception->getMessage())])->withInput(); }
        return redirect()->route('central-finance.receivables.show', $document->id)->with('success', __('Central receivable void recorded.'));
    }

    public function applyReceivablePromotion(Request $request, int $receivable): RedirectResponse
    {
        [$actor, $school] = $this->currentCorrectionContext();
        $data = $request->validate(['promotion_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:2000'], 'effective_date' => ['required', 'date_format:Y-m-d'], 'idempotency_key' => ['required', 'string', 'regex:/^ui-receivable-[A-Za-z0-9_.:-]{2,100}$/']]);
        $document = CentralFinanceReceivable::on('mysql')->where('school_id', $school->id)->findOrFail($receivable);
        try { $this->promotions->apply($actor, $document->id, (int) $data['promotion_id'], $this->transactionDate($data['effective_date'], 'effective_date'), (string) ($data['reason'] ?? ''), CarbonImmutable::now(), $data['idempotency_key']); } catch (InvalidArgumentException|AuthorizationException $exception) { return back()->withErrors(['promotion_id' => __($exception->getMessage())])->withInput(); }
        return redirect()->route('central-finance.receivables.show', $document->id)->with('success', __('Promotion applied to receivable.'));
    }

    public function paymentImportTemplate()
    {
        return Excel::download(new CentralPaymentImportTemplateExport(), 'central_payment_import_template_v1.xlsx');
    }

    public function previewPaymentImport(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['payment_import' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv']]);
        try {
            $batch = $this->paymentImports->previewUploaded($actor, $school->id, $data['payment_import']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['payment_import' => __($exception->getMessage())]);
        }

        return redirect()->route('central-finance.payments.index', ['import_batch' => $batch->token])
            ->with('success', __('Central payment import preview created. Confirm only after reviewing every row.'));
    }

    public function confirmPaymentImport(string $batch): RedirectResponse
    {
        [$actor] = $this->currentOperatingContext();
        $this->assertProductionImportBatch($batch);
        $this->paymentImports->confirm($actor, $batch);

        return redirect()->route('central-finance.payments.index')->with('success', __('Central payment import confirmed.'));
    }

    public function discardPaymentImport(Request $request, string $batch): RedirectResponse
    {
        [$actor] = $this->currentOperatingContext();
        $this->assertProductionImportBatch($batch);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->importBatches->discard($actor, $batch, $reason);
        return redirect()->route('central-finance.payments.index')->with('success', __('Central payment import preview discarded.'));
    }

    public function expenseImportTemplate()
    {
        return Excel::download(new CentralExpenseImportTemplateExport(), 'central_expense_import_template_v1.xlsx');
    }

    public function previewExpenseImport(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['expense_import' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv']]);
        try {
            $batch = $this->expenseImports->previewUploaded($actor, $school->id, $data['expense_import']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['expense_import' => __($exception->getMessage())]);
        }

        return redirect()->route('central-finance.operations', ['operation' => 'expense', 'import_batch' => $batch->token])
            ->with('success', __('Central Expense import preview created. Confirm only after reviewing every row.'));
    }

    public function confirmExpenseImport(string $batch): RedirectResponse
    {
        [$actor] = $this->currentOperatingContext();
        $this->assertProductionImportBatch($batch);
        $this->expenseImports->confirm($actor, $batch);

        return redirect()->route('central-finance.operations', ['operation' => 'expense'])->with('success', __('Central Expense import confirmed.'));
    }

    public function discardExpenseImport(Request $request, string $batch): RedirectResponse
    {
        [$actor] = $this->currentOperatingContext();
        $this->assertProductionImportBatch($batch);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->importBatches->discard($actor, $batch, $reason);
        return redirect()->route('central-finance.operations', ['operation' => 'expense'])->with('success', __('Central Expense import preview discarded.'));
    }

    public function expense(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        // Preserve the existing direct-write contract while the normal UI
        // supplies an explicit Transaction Date. Older approved clients get
        // today's Yangon business date rather than an audit timestamp.
        $request->mergeIfMissing(['transaction_date' => CentralFinanceBusinessDate::today()->toDateString()]);
        $data = $request->validate(['category_id'=>['required','integer'],'fund_account_id'=>['required','integer'],'amount'=>['required','numeric','gt:0'],'payment_method'=>['required','string','max:40'],'transaction_date'=>['required','date_format:Y-m-d'],'reference_no'=>['nullable','string','max:100'],'description'=>['nullable','string','max:2000']]);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']);
        $this->assertProductionSubjects([['category', (int) $data['category_id']], ['fund_account', (int) $account->id]]);
        $this->documents->createExpense($actor, $school->id, (int) $data['category_id'], $account, (float) $data['amount'], $data['payment_method'], $this->transactionDate($data['transaction_date']), $this->workspace->idempotencyReference('ui-expense'), $data['reference_no'] ?? null, $data['description'] ?? null);
        return back()->with('success', __('Central expense recorded.'));
    }

    public function otherIncome(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $request->mergeIfMissing(['transaction_date' => CentralFinanceBusinessDate::today()->toDateString()]);
        $data = $request->validate(['category_id'=>['required','integer'],'fund_account_id'=>['required','integer'],'amount'=>['required','numeric','gt:0'],'payment_method'=>['required','string','max:40'],'transaction_date'=>['required','date_format:Y-m-d'],'reference_no'=>['nullable','string','max:100'],'payer'=>['nullable','string','max:191'],'description'=>['nullable','string','max:2000']]);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']);
        $this->assertProductionSubjects([['category', (int) $data['category_id']], ['fund_account', (int) $account->id]]);
        $this->documents->createOtherIncome($actor, $school->id, (int) $data['category_id'], $account, (float) $data['amount'], $data['payment_method'], $this->transactionDate($data['transaction_date']), $this->workspace->idempotencyReference('ui-income'), $data['reference_no'] ?? null, $data['payer'] ?? null, $data['description'] ?? null);
        return back()->with('success', __('Central income recorded.'));
    }

    public function updateExpense(Request $request, int $expense): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $document = CentralFinanceExpense::on('mysql')->where('school_id', $school->id)->findOrFail($expense);
        $data = $request->validate(['category_id' => ['required','integer'], 'description' => ['nullable','string','max:2000'], 'reason' => ['required','string','max:255']]);
        $this->assertProductionSubjects([['expense', (int) $document->id], ['category', (int) $data['category_id']]]);
        $this->documents->updateExpenseDetails($actor, $document->id, $data, $data['reason']);
        return redirect()->route('central-finance.expenses.show', $document->id)->with('success', __('Expense details updated.'));
    }

    public function updateOtherIncome(Request $request, int $income): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $document = CentralFinanceOtherIncome::on('mysql')->where('school_id', $school->id)->findOrFail($income);
        $data = $request->validate(['category_id' => ['required','integer'], 'payer' => ['nullable','string','max:191'], 'description' => ['nullable','string','max:2000'], 'reason' => ['required','string','max:255']]);
        $this->assertProductionSubjects([['other_income', (int) $document->id], ['category', (int) $data['category_id']]]);
        $this->documents->updateOtherIncomeDetails($actor, $document->id, $data, $data['reason']);
        return redirect()->route('central-finance.other-income.show', $document->id)->with('success', __('Income details updated.'));
    }

    public function voidExpense(Request $request, int $expense): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $document = CentralFinanceExpense::on('mysql')->where('school_id', $school->id)->findOrFail($expense);
        $this->dataIsolation->assertProduction('expense', (int) $document->id);
        $data = $request->validate(['reason' => ['required','string','max:255']]);
        $this->documents->voidExpense($actor, $document->id, $data['reason'], CarbonImmutable::now());
        return redirect()->route('central-finance.expenses.show', $document->id)->with('success', __('Expense reversed.'));
    }

    public function voidOtherIncome(Request $request, int $income): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $document = CentralFinanceOtherIncome::on('mysql')->where('school_id', $school->id)->findOrFail($income);
        $this->dataIsolation->assertProduction('other_income', (int) $document->id);
        $data = $request->validate(['reason' => ['required','string','max:255']]);
        $this->documents->voidOtherIncome($actor, $document->id, $data['reason'], CarbonImmutable::now());
        return redirect()->route('central-finance.other-income.show', $document->id)->with('success', __('Income reversed.'));
    }

    public function reimbursement(Request $request): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data = $request->validate(['category_id'=>['required','integer'],'amount'=>['required','numeric','gt:0'],'currency'=>['required', Rule::in(CentralFinanceCurrency::ALLOWED)],'reference_no'=>['nullable','string','max:100'],'description'=>['required','string','max:2000'],'reason'=>['required','string','max:255']]);
        $this->dataIsolation->assertProduction('category', (int) $data['category_id']);
        $this->reimbursements->submit($actor, $school->id, (int) $data['category_id'], (float) $data['amount'], $data['currency'], $this->workspace->idempotencyReference('ui-reimbursement'), $data['reason'], $data['reference_no'] ?? null, $data['description']);
        return back()->with('success', __('Central reimbursement submitted.'));
    }

    public function approveReimbursement(Request $request, int $reimbursement): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $data=$request->validate(['fund_account_id'=>['required','integer'],'payment_method'=>['required','string','max:40'],'reason'=>['required','string','max:255']]);
        $document=CentralFinanceReimbursementRequest::on('mysql')->where('school_id',$school->id)->findOrFail($reimbursement);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']);
        $this->assertProductionSubjects([['reimbursement', (int) $document->id], ['fund_account', (int) $account->id]]);
        $this->reimbursements->approve($actor,$document->id,$account,$data['payment_method'],CarbonImmutable::now(),$data['reason']);
        return back()->with('success', __('Central reimbursement approved.'));
    }

    public function rejectReimbursement(Request $request, int $reimbursement): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $document = CentralFinanceReimbursementRequest::on('mysql')->where('school_id', $school->id)->findOrFail($reimbursement);
        $this->dataIsolation->assertProduction('reimbursement', (int) $document->id);
        $data = $request->validate(['reason' => ['required','string','max:255']]);
        $this->reimbursements->reject($actor, $document->id, $data['reason'], CarbonImmutable::now());
        return back()->with('success', __('Central reimbursement rejected.'));
    }

    public function withdrawReimbursement(Request $request, int $reimbursement): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $document = CentralFinanceReimbursementRequest::on('mysql')->where('school_id', $school->id)->findOrFail($reimbursement);
        $this->dataIsolation->assertProduction('reimbursement', (int) $document->id);
        $data = $request->validate(['reason' => ['required','string','max:255']]);
        $this->reimbursements->withdraw($actor, $document->id, $data['reason'], CarbonImmutable::now());
        return back()->with('success', __('Central reimbursement withdrawn.'));
    }

    public function cancelReimbursement(Request $request, int $reimbursement): RedirectResponse
    {
        [$actor, $school] = $this->currentOperatingContext();
        $document = CentralFinanceReimbursementRequest::on('mysql')->where('school_id', $school->id)->findOrFail($reimbursement);
        $this->dataIsolation->assertProduction('reimbursement', (int) $document->id);
        $data = $request->validate(['reason' => ['required','string','max:255']]);
        $this->reimbursements->cancel($actor, $document->id, $data['reason'], CarbonImmutable::now());
        return back()->with('success', __('Central reimbursement cancelled.'));
    }

    public function transfer(Request $request): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $data=$request->validate(['source_account_id'=>['required','integer'],'destination_account_id'=>['required','integer','different:source_account_id'],'amount'=>['required','numeric','gt:0'],'reference_no'=>['nullable','string','max:100'],'reason'=>['required','string','max:255']]);
        $source = CentralFinanceFundAccount::on('mysql')->findOrFail($data['source_account_id']);
        $destination = CentralFinanceFundAccount::on('mysql')->findOrFail($data['destination_account_id']);
        $this->assertProductionSubjects([['fund_account', (int) $source->id], ['fund_account', (int) $destination->id]]);
        $this->transfers->transfer($actor,$school->id,$source,$destination,(float)$data['amount'],CarbonImmutable::now(),$this->workspace->idempotencyReference('ui-transfer'),$data['reason'],$data['reference_no'] ?? null);
        return back()->with('success', __('Central bank transfer confirmed.'));
    }

    public function handover(Request $request): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $data=$request->validate(['receiver_user_id'=>['required','integer', Rule::notIn([$actor->id])],'source_account_id'=>['required','integer'],'destination_account_id'=>['required','integer','different:source_account_id'],'amount'=>['required','numeric','gt:0'],'reference_no'=>['nullable','string','max:100']]);
        $receiver=CentralFinanceUser::on('mysql')->findOrFail($data['receiver_user_id']);
        $source = CentralFinanceFundAccount::on('mysql')->findOrFail($data['source_account_id']);
        $destination = CentralFinanceFundAccount::on('mysql')->findOrFail($data['destination_account_id']);
        $this->assertProductionSubjects([['fund_account', (int) $source->id], ['fund_account', (int) $destination->id]]);
        $this->handovers->request($actor,$receiver,$school->id,$source,$destination,(float)$data['amount'],CarbonImmutable::now(),$this->workspace->idempotencyReference('ui-handover'),$data['reference_no'] ?? null);
        return back()->with('success', __('Central Fund Handover is pending.'));
    }

    public function resolveHandover(Request $request, int $handover, string $action): RedirectResponse
    {
        [$actor,$school]=$this->currentOperatingContext(); $document=CentralFinanceFundHandover::on('mysql')->where('school_id',$school->id)->findOrFail($handover);
        $this->dataIsolation->assertProduction('fund_handover', (int) $document->id);
        if ($action === 'confirm') $this->handovers->confirm($actor,$document->id,CarbonImmutable::now());
        elseif ($action === 'reject') $this->handovers->reject($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        else $this->handovers->cancel($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        return back()->with('success', __('Central Fund Handover updated.'));
    }

    public function storeFunding(Request $request): RedirectResponse
    {
        [$actor,$school]=$this->currentHeadFinanceOperatingContext(); $data=$request->validate(['source_account_id'=>['required','integer'],'destination_account_id'=>['required','integer','different:source_account_id'],'amount'=>['required','numeric','gt:0'],'reference_no'=>['nullable','string','max:100']]);
        $source = CentralFinanceFundAccount::on('mysql')->findOrFail($data['source_account_id']);
        $destination = CentralFinanceFundAccount::on('mysql')->findOrFail($data['destination_account_id']);
        $this->assertProductionSubjects([['fund_account', (int) $source->id], ['fund_account', (int) $destination->id]]);
        $this->funding->request($actor,$school->id,$source,$destination,(float)$data['amount'],CarbonImmutable::now(),$this->workspace->idempotencyReference('ui-funding'),$data['reference_no'] ?? null);
        return back()->with('success', __('Central HQ Funding is pending.'));
    }

    public function resolveFunding(Request $request, int $funding, string $action): RedirectResponse
    {
        [$actor,$school]=$this->currentHeadFinanceOperatingContext(); $document=CentralFinanceHqFundingRequest::on('mysql')->where('school_id',$school->id)->findOrFail($funding);
        $this->dataIsolation->assertProduction('hq_funding', (int) $document->id);
        if ($action === 'confirm') $this->funding->confirm($actor,$document->id,CarbonImmutable::now());
        elseif ($action === 'reject') $this->funding->reject($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        else $this->funding->cancel($actor,$document->id,$request->validate(['reason'=>['required','string','max:255']])['reason'],CarbonImmutable::now());
        return back()->with('success', __('Central HQ Funding updated.'));
    }

    /** @return array{0: CentralFinanceUser, 1: \App\Models\School} */
    private function currentOperatingContext(): array
    {
        $actor = $this->actor();
        $school = $this->workspace->requireOperatingSchool($actor);
        $this->dataIsolation->assertProduction('school', (int) $school->id);

        return [$actor, $school];
    }

    /**
     * Payment corrections are Head-Finance-only and may use a trusted QA
     * School selection. This is intentionally narrower than normal operating
     * context: no School staff or request-controlled QA toggle gains access.
     *
     * @return array{0: CentralFinanceUser, 1: \App\Models\School}
     */
    private function currentCorrectionContext(): array
    {
        $actor = $this->actor();
        $this->workspace->assertHeadFinance($actor);
        $school = $this->workspace->currentSchool($actor, true);
        if ($school === null) {
            throw new AuthorizationException('Select an authorized School before correcting a Central payment.');
        }
        $school = $this->workspace->assertCanOperateSchool($actor, (int) $school->id);
        $this->dataIsolation->assertWorkflowWritable('school', (int) $school->id);

        return [$actor, $school];
    }

    /** @return array{0: CentralFinanceUser, 1: CentralFinanceFundAccount} */
    private function currentGroupAccountContext(int $fundAccountId): array
    {
        $actor = $this->actor();
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($fundAccountId);
        $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $account->group_id);

        return [$actor, $account];
    }

    /** @return array{0: CentralFinanceUser, 1: \App\Models\School} */
    private function currentHeadFinanceOperatingContext(): array
    {
        [$actor, $school] = $this->currentOperatingContext();
        $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $school);
        return [$actor, $school];
    }

    /** @param list<array{0:string,1:int}> $subjects */
    private function assertProductionSubjects(array $subjects): void
    {
        foreach ($subjects as [$subjectType, $subjectId]) {
            $this->dataIsolation->assertProduction($subjectType, $subjectId);
        }
    }

    private function assertProductionImportBatch(string $token): void
    {
        $batch = CentralFinanceImportBatch::on('mysql')->where('token', $token)->firstOrFail();
        $this->dataIsolation->assertProduction('import_batch', (int) $batch->id);
    }

    private function actor(): CentralFinanceUser { $user=Auth::user(); abort_unless($user,403); return $this->workspace->actor($user); }

    /** @return array{0:CentralFinanceUser,1:?\App\Models\School,2:\Illuminate\Support\Collection,3:\Illuminate\Support\Collection,4:array<string,mixed>} */
    private function readScope(Request $request): array
    {
        $actor = $this->actor();
        $includeQaTest = $this->effectiveIncludeQaTest($request, $actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        $schools = $this->workspace->accessibleSchools($actor, $includeQaTest);
        $accounts = $this->workspace->readableAccounts($actor, $school?->id, $includeQaTest);
        $filters = $this->validatedReadFilters($request, $school, $accounts, $schools);
        $filters['include_qa_test'] = $includeQaTest;

        return [$actor, $school, $schools, $accounts, $filters];
    }

    private function operatingDocumentDetail(string $type, int $id): View
    {
        $actor = $this->actor();
        $includeQaTest = $this->effectiveIncludeQaTest(request(), $actor);
        $class = $type === 'expense' ? CentralFinanceExpense::class : CentralFinanceOtherIncome::class;
        $documentQuery = $class::on('mysql')->withTrashed()->with('category');
        $this->dataIsolation->apply($documentQuery, $type, $includeQaTest);
        $document = $documentQuery->findOrFail($id);
        // Resolve the owning School from the canonical document, not the
        // selected workspace context. This lets an authorised Head Finance
        // user inspect All Schools details without weakening school scope.
        $school = $this->workspace->assertCanViewSchool($actor, (int) $document->school_id);
        $accounts = $this->workspace->readableAccounts($actor, $school->id, $includeQaTest);
        abort_unless($accounts->pluck('id')->contains((int) $document->fund_account_id), 403);
        $audits = CentralFinanceDocumentAudit::on('mysql')->where([
            'school_id' => $school->id, 'document_type' => $type, 'document_id' => $document->id,
        ])->latest()->get();
        $canOperate = false;
        try {
            $this->workspace->requireOperatingSchool($actor);
            $canOperate = $this->dataIsolation->isProduction($type, (int) $document->id)
                && $this->cutovers->allowsCentralWrites($school->id) && !$document->trashed();
        } catch (AuthorizationException) {
            // Read-only users can still inspect the canonical history.
        }
        $categoriesQuery = CentralFinanceCategory::on('mysql')->availableForSchool($school->id)->forActor($actor,[$school->id],true)
            ->forCashDirection($type === 'expense' ? CentralFinanceCategory::EXPENSE : CentralFinanceCategory::INCOME);
        $this->applyReadVisibility($categoriesQuery, 'category', $school, $includeQaTest);
        $categories = $categoriesQuery->orderBy('name')->get();
        $ledgerSource = $type === 'expense' ? 'central_expense' : 'central_other_income';

        return view('central-finance.operating-document-detail', compact('actor', 'school', 'type', 'document', 'audits', 'canOperate', 'categories', 'ledgerSource'));
    }

    /** @param array<string,mixed> $filters */
    private function scopedOperatingDocumentsQuery(string $type, ?\App\Models\School $school, \Illuminate\Support\Collection $schools, \Illuminate\Support\Collection $accounts, array $filters)
    {
        $class = $type === 'expense' ? CentralFinanceExpense::class : CentralFinanceOtherIncome::class;
        $dateColumn = $type === 'expense' ? 'expense_date' : 'income_date';
        $query = $class::on('mysql')->withTrashed()->with('category');
        $this->applyReadVisibility($query, $type === 'expense' ? 'expense' : 'other_income', $school, (bool) ($filters['include_qa_test'] ?? false));
        if ($school) $query->where('school_id', $school->id);
        elseif ($schools->isNotEmpty()) $query->whereIn('school_id', $schools->pluck('id'));
        else $query->whereRaw('1 = 0');
        $query->whereIn('fund_account_id', $accounts->pluck('id'));
        if (!empty($filters['school_id'])) $query->where('school_id', (int) $filters['school_id']);
        if (!empty($filters['fund_account_id'])) $query->where('fund_account_id', (int) $filters['fund_account_id']);
        if (!empty($filters['category_id'])) $query->where('category_id', (int) $filters['category_id']);
        if (!empty($filters['operator_id'])) $query->where('created_by', (int) $filters['operator_id']);
        if (!empty($filters['payment_method'])) $query->where('payment_method', $filters['payment_method']);
        if (!empty($filters['currency'])) $query->where('currency', $filters['currency']);
        if (!empty($filters['reference'])) $query->where('reference_no', 'like', '%'.$filters['reference'].'%');
        if (!empty($filters['from'])) $query->whereDate($dateColumn, '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate($dateColumn, '<=', $filters['to']);

        return $query;
    }

    private function render(string $page, Request $request, ?int $requestedAccountId = null): View
    {
        $actor=$this->actor(); $includeQaTest=$this->effectiveIncludeQaTest($request,$actor); $school=$this->workspace->currentSchool($actor,$includeQaTest); $schools=$this->workspace->accessibleSchools($actor,$includeQaTest); $canAccessAllSchools=!$this->workspace->isSchoolStaffPrincipal($actor); $schoolFinanceFacade=$this->workspace->usesSchoolFinanceFacade($actor);
        // Read models intentionally use strict selected-School accounts. Keep
        // the broader authorised operation set separate: Head Finance may
        // still collect a School's fee into an authorised HQ account.
        $accounts=$this->workspace->readableAccounts($actor,$school?->id,$includeQaTest);
        $operationAccounts=$this->workspace->accessibleAccounts($actor,$school?->id);
        // Lifecycle history remains readable. Only account-directory/report pages
        // include inactive or archived accounts; transaction selectors still use
        // the active-only Workspace service above.
        if (in_array($page, ['accounts', 'account-manage', 'account-report', 'account-statement', 'account-statements'], true)) {
            $accounts = $this->viewableFundAccounts($actor, $school?->id, $includeQaTest);
        }
        if (in_array($page, ['ledger', 'audits'], true)) {
            $accounts = $this->workspace->readableAccounts($actor, $school?->id, $includeQaTest);
        }
        if ($page === 'account-statements' && $requestedAccountId === null) {
            $requestedAccountId = $accounts->first()?->id;
        }
        $schoolId=$school?->id;
        $filters=$this->validatedReadFilters($request, $school, $accounts, $schools);
        $filters['include_qa_test']=$includeQaTest;
        if ($requestedAccountId !== null) {
            abort_unless($accounts->contains('id', $requestedAccountId), 404);
            $filters['fund_account_id'] = $requestedAccountId;
        }
        $filteredLedger=$this->scopedLedgerQuery($school, $schools, $accounts, $filters);
        $ledger=(clone $filteredLedger)->orderByDesc('entry_date')->orderByDesc('occurred_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $filteredLedgerEntries = (clone $filteredLedger)->get();
        $currencyTotals = $this->currencySummaries->ledger($filteredLedgerEntries);
        $physicalAccountTotals = $this->balances->physicalSummaryByCurrency($accounts);
        $reportSchoolComparison = collect();
        $reportTrend = collect();
        $reportCategoryAnalysis = collect();
        if ($page === 'reports') {
            $categoryDetails = $this->ledgerCategoryDetails($filteredLedgerEntries);
            $reportSchoolComparison = $filteredLedgerEntries
                ->groupBy(fn (CentralFinanceLedgerEntry $entry): string => $entry->school_id.'|'.$entry->currency)
                ->map(function ($entries): array {
                    $first = $entries->first();
                    return ['school_id' => (int) $first->school_id, 'currency' => $first->currency,
                        'money_in' => (float) $entries->sum('money_in'), 'money_out' => (float) $entries->sum('money_out'),
                        'income' => (float) $entries->sum('operating_income'), 'expense' => (float) $entries->sum('operating_expense')];
                })->sortBy(fn (array $row): string => str_pad((string) $row['school_id'], 10, '0', STR_PAD_LEFT).'|'.$row['currency'])->values();
            $reportTrend = $filteredLedgerEntries
                ->groupBy(fn (CentralFinanceLedgerEntry $entry): string => $entry->entry_date->format('Y-m-d').'|'.$entry->currency)
                ->map(function ($entries): array {
                    $first = $entries->first();
                    return ['date' => $first->entry_date->format('Y-m-d'), 'currency' => $first->currency,
                        'income' => (float) $entries->sum('operating_income'), 'expense' => (float) $entries->sum('operating_expense')];
                })->sortBy('date')->values();
            $reportCategoryAnalysis = $filteredLedgerEntries
                ->filter(fn (CentralFinanceLedgerEntry $entry): bool => (float) $entry->money_in !== 0.0 || (float) $entry->money_out !== 0.0)
                ->groupBy(function (CentralFinanceLedgerEntry $entry) use ($categoryDetails): string {
                    $category = $categoryDetails[$entry->source_type.':'.$entry->source_id] ?? ['id' => null, 'name' => __('Uncategorized')];
                    return $entry->school_id.'|'.$entry->currency.'|'.($category['id'] ?? 'none').'|'.$category['name'];
                })->map(function ($entries) use ($categoryDetails): array {
                    $first = $entries->first();
                    $category = $categoryDetails[$first->source_type.':'.$first->source_id] ?? ['id' => null, 'name' => __('Uncategorized')];
                    return ['school_id' => (int) $first->school_id, 'currency' => $first->currency,
                        'category_id' => $category['id'], 'category' => $category['name'],
                        'category_code' => $category['code'] ?? '', 'account_type' => $category['type'] ?? '',
                        'money_in' => (float) $entries->sum('money_in'), 'money_out' => (float) $entries->sum('money_out'),
                        'net_movement' => (float) $entries->sum('money_in') - (float) $entries->sum('money_out'),
                        'income' => (float) $entries->sum('operating_income'), 'expense' => (float) $entries->sum('operating_expense')];
                })->sortBy('category')->values();
        }
        $canOperate=false; if($school){try{$this->workspace->requireOperatingSchool($actor);$canOperate=$this->dataIsolation->isProduction('school',(int)$school->id)&&$this->cutovers->allowsCentralWrites($school->id);}catch(AuthorizationException){$canOperate=false;}}
        $schoolUsersQuery = $schoolId ? CentralFinanceUser::on('mysql')->whereIn('id',
            \Illuminate\Support\Facades\DB::connection('mysql')->table('central_finance_user_school_scopes as scopes')
                ->join('finance_group_users as group_users', 'group_users.central_user_id', '=', 'scopes.user_id')
                ->join('finance_groups as groups', 'groups.id', '=', 'group_users.group_id')
                ->join('finance_group_schools as group_schools', 'group_schools.group_id', '=', 'groups.id')
                ->where('scopes.school_id', $schoolId)
                ->where('scopes.user_id', '!=', $actor->id)
                ->where('scopes.can_view', true)
                ->where('scopes.can_operate', true)
                ->where('group_users.status', 'active')
                ->where('groups.status', 'active')
                ->where('group_schools.school_id', $schoolId)
                ->where('group_schools.status', 'active')
                ->pluck('scopes.user_id')->unique()
        ) : null;
        if ($schoolUsersQuery) $this->dataIsolation->applyCentralStaffUsers($schoolUsersQuery, (int) $schoolId, $includeQaTest);
        $schoolUsers = $schoolUsersQuery ? $schoolUsersQuery->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']) : collect();
        $handoverDestinationUserIds = collect();
        if ($page === 'handovers' && $schoolId && $operationAccounts->isNotEmpty()) {
            $handoverDestinationUserIds = $operationAccounts->mapWithKeys(fn (CentralFinanceFundAccount $account): array => [
                $account->id => $schoolUsers
                    ->filter(fn (CentralFinanceUser $candidate): bool => $this->accountScopes->canOperate($candidate, $account, (int) $schoolId))
                    ->pluck('id')
                    ->values(),
            ]);
            $eligibleHandoverUserIds = $handoverDestinationUserIds->flatten()->unique();
            $schoolUsers = $schoolUsers->whereIn('id', $eligibleHandoverUserIds)->values();
        }
        $configurableGroups = $this->configuration->configurableGroups($actor);
        $canConfigureAccounts = $configurableGroups->isNotEmpty();
        $canConfigureSchool = false;
        if ($school !== null) {
            try {
                $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $school);
                $canConfigureSchool = true;
            } catch (AuthorizationException) {
                $canConfigureSchool = false;
            }
        }
        $receivableQuery = $schoolId ? CentralFinanceReceivable::on('mysql')->with('studentProfile')->where('school_id', $schoolId) : null;
        if ($receivableQuery) {
            $this->applyReadVisibility($receivableQuery, 'receivable', $school, $includeQaTest);
            $receivableQuery
                ->when($filters['receivable_status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where('currency', $currency))
                ->when($filters['student'] ?? null, fn ($query, $student) => $query->whereHas('studentProfile', fn ($profile) => $profile->where(fn ($nested) => $nested
                    ->where('student_name', 'like', "%{$student}%")
                    ->orWhere('admission_no', 'like', "%{$student}%")
                    ->orWhere('student_code', 'like', "%{$student}%"))));
        }
        $receivables = $receivableQuery ? (clone $receivableQuery)->orderBy('due_date')->paginate(25, ['*'], 'receivables_page')->withQueryString() : collect();
        $receivableCurrencyTotals = $this->currencySummaries->receivables($receivableQuery ? (clone $receivableQuery)->get() : []);
        $aging = collect();
        if ($receivableQuery) {
            $today = CarbonImmutable::today();
            $aging = collect($this->currencySummaries->aging((clone $receivableQuery)->whereIn('status', [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL])->get(['due_date', 'amount_due', 'amount_paid', 'currency']), $today));
        }
        $profileQuery=$schoolId ? CentralFinanceStudentProfile::on('mysql')->with(['receivables' => fn ($query) => $query->latest()->with('payments.receipt')])->where('school_id',$schoolId) : null;
        if ($profileQuery) {
            $this->applyReadVisibility($profileQuery, 'student_profile', $school, $includeQaTest);
            if ($school && $this->dataIsolation->isQaTestSchool((int) $school->id)) {
                $this->dataIsolation->applyTenantMetadataForSchoolWorkflow($profileQuery, 'student', (int) $school->id, 'tenant_student_id');
            } else {
                $this->dataIsolation->applyTenantMetadata($profileQuery, 'student', (int) $schoolId, $includeQaTest, 'tenant_student_id');
            }
        }
        $profiles=$profileQuery ? $profileQuery->when($filters['student'] ?? null, fn ($query, $student) => $query->where(fn ($nested) => $nested->where('student_name','like',"%{$student}%")->orWhere('admission_no','like',"%{$student}%")->orWhere('student_code','like',"%{$student}%")))->latest()->paginate(25, ['*'], 'students_page')->withQueryString() : collect();
        if ($profiles instanceof LengthAwarePaginator) {
            $profiles->getCollection()->each(fn (CentralFinanceStudentProfile $profile) => $profile->setAttribute('currency_totals', $this->currencySummaries->receivables($profile->receivables)));
        }
        $paymentsQuery = $this->scopedPaymentQuery($school, $schools, $accounts, $filters)->with($this->paymentReadRelations());
        if ($page === 'payments' && $request->string('view')->toString() === 'refunds') {
            $paymentsQuery->where(fn ($query) => $query->whereHas('refunds')->orWhereHas('reversal'));
        }
        $payments=$paymentsQuery->latest('paid_at')->paginate(25, ['*'], 'payments_page')->withQueryString();
        $paymentProfileQuery = $schoolId ? CentralFinanceStudentProfile::on('mysql')->where('school_id', $schoolId) : null;
        if ($paymentProfileQuery) {
            $this->applyReadVisibility($paymentProfileQuery, 'student_profile', $school, $includeQaTest);
            if ($school && $this->dataIsolation->isQaTestSchool((int) $school->id)) {
                $this->dataIsolation->applyTenantMetadataForSchoolWorkflow($paymentProfileQuery, 'student', (int) $school->id, 'tenant_student_id');
            } else {
                $this->dataIsolation->applyTenantMetadata($paymentProfileQuery, 'student', (int) $schoolId, $includeQaTest, 'tenant_student_id');
            }
            $paymentProfileQuery
                ->when($filters['payment_class'] ?? null, fn ($query, $class) => $query->where('class_name', $class))
                ->when($filters['payment_student'] ?? null, fn ($query, $student) => $query->where(fn ($nested) => $nested
                    ->where('student_name', 'like', "%{$student}%")
                    ->orWhere('admission_no', 'like', "%{$student}%")
                    ->orWhere('student_code', 'like', "%{$student}%")))
                ->with('receivables');
        }
        $paymentProfiles = $paymentProfileQuery ? $paymentProfileQuery->orderBy('student_name')->get(['id','student_name','student_code','admission_no','class_name','section_name']) : collect();
        $paymentProfiles->each(fn (CentralFinanceStudentProfile $profile) => $profile->setAttribute('currency_totals', $this->currencySummaries->receivables($profile->receivables)));
        $paymentClassQuery = $schoolId ? CentralFinanceStudentProfile::on('mysql')->where('school_id', $schoolId) : null;
        if ($paymentClassQuery) {
            $this->applyReadVisibility($paymentClassQuery, 'student_profile', $school, $includeQaTest);
            if ($school && $this->dataIsolation->isQaTestSchool((int) $school->id)) {
                $this->dataIsolation->applyTenantMetadataForSchoolWorkflow($paymentClassQuery, 'student', (int) $school->id, 'tenant_student_id');
            } else {
                $this->dataIsolation->applyTenantMetadata($paymentClassQuery, 'student', (int) $schoolId, $includeQaTest, 'tenant_student_id');
            }
        }
        $paymentClasses = $paymentClassQuery ? $paymentClassQuery->whereNotNull('class_name')->where('class_name', '!=', '')->distinct()->orderBy('class_name')->pluck('class_name') : collect();
        $paymentReceivableQuery=$schoolId ? CentralFinanceReceivable::on('mysql')->where('school_id',$schoolId)->whereIn('status',['open','partial']) : null;
        if ($paymentReceivableQuery) $this->applyReadVisibility($paymentReceivableQuery, 'receivable', $school, $includeQaTest);
        $paymentReceivables=$paymentReceivableQuery ? $paymentReceivableQuery->orderBy('student_profile_id')->orderBy('due_date')->get(['id','student_profile_id','description','amount_due','amount_paid','currency']) : collect();
        $categoryQuery=CentralFinanceCategory::on('mysql')->forActor($actor,$schoolId ? [$schoolId] : $schools->pluck('id')->all());
        if ($page === 'categories' && $canConfigureAccounts && Schema::connection('mysql')->hasColumn('central_finance_categories','group_id')) {
            $categoryQuery = CentralFinanceCategory::on('mysql')->where(function ($query) use ($configurableGroups, $schools): void {
                $query->whereIn('group_id', $configurableGroups->pluck('id'))->orWhere(fn ($legacy) => $legacy->whereNull('group_id')->whereIn('school_id',$schools->pluck('id')));
            })->with('schoolAllocations');
        }
        $this->applyReadVisibility($categoryQuery, 'category', $school, $includeQaTest);
        $categories=$categoryQuery->orderBy('type')->orderBy('name')->get();
        $operators=CentralFinanceUser::on('mysql')->whereIn('id', (clone $filteredLedger)->distinct()->pluck('created_by')->filter())->orderBy('first_name')->get(['id','first_name','last_name','email']);
        if ($page === 'ledger' && method_exists($ledger, 'getCollection')) {
            $this->ledgerPresentation->decorate($ledger->getCollection());
        }
        $ledgerCategories = $page === 'ledger' && method_exists($ledger, 'getCollection')
            ? $this->ledgerCategoryNames($ledger->getCollection()) : [];
        $staffQuery=$schoolId && $canConfigureSchool ? CentralFinanceUser::on('mysql')->whereIn('id', \Illuminate\Support\Facades\DB::connection('mysql')->table('central_finance_user_school_scopes')->where('school_id',$schoolId)->where('can_view',true)->pluck('user_id'))->with(['authorizedFundAccounts' => fn ($query) => $query->whereHas('schoolAllocations', fn ($allocations) => $allocations->where('school_id', $schoolId)->effective())]) : null;
        if ($staffQuery) $this->dataIsolation->applyCentralStaffUsers($staffQuery, (int) $schoolId, $includeQaTest);
        $staff=$staffQuery ? $staffQuery->orderBy('first_name')->paginate(25, ['*'], 'staff_page')->withQueryString() : collect();
        $groupCustodianOptions = $canConfigureAccounts
            ? $this->configuration->groupAccountCustodians($actor, $configurableGroups)
                ->map(fn ($users) => $users->map(fn (CentralFinanceUser $user): array => [
                    'id' => (int) $user->id,
                    'label' => trim($user->full_name) ?: ($user->email ?: '#'.$user->id),
                ])->values()->all())
                ->all()
            : [];
        $accountReport=null;
        $allocationSchools=collect();
        $accountAssignableUsers=collect();
        if ($requestedAccountId !== null) {
            $accountReport=$accounts->firstWhere('id',$requestedAccountId);
            abort_unless($accountReport !== null, 404);
            $accountRelations = ['custodian', 'authorizedUsers'];
            if ($this->accountAvailability->allocationSchemaAvailable()) $accountRelations[] = 'schoolAllocations';
            $accountReport->load($accountRelations);
            $accountReport->setAttribute('current_balance', $this->balances->currentBalance($accountReport));
            $accountReport->setAttribute('school_activity', $schoolId
                ? $this->balances->schoolActivity($accountReport, $schoolId)
                : null);

            $groupSchoolIds = \Illuminate\Support\Facades\DB::connection('mysql')
                ->table('finance_group_schools')
                ->where('group_id', $accountReport->group_id)
                ->where('status', 'active')
                ->pluck('school_id');
            $allocationSchools = $schools->whereIn('id', $groupSchoolIds)->values();
            $allocatedSchoolIds = $accountReport->relationLoaded('schoolAllocations')
                ? $accountReport->schoolAllocations
                    ->filter(fn ($allocation) => $allocation->is_active && $allocation->status === 'active')
                    ->pluck('school_id')
                : collect();
            $eligibleUserIds = \Illuminate\Support\Facades\DB::connection('mysql')
                ->table('central_finance_user_school_scopes as scopes')
                ->join('finance_group_users as group_users', 'group_users.central_user_id', '=', 'scopes.user_id')
                ->where('group_users.group_id', $accountReport->group_id)
                ->where('group_users.status', 'active')
                ->whereIn('scopes.school_id', $allocatedSchoolIds)
                ->where('scopes.can_view', true)
                ->where('scopes.can_operate', true)
                ->pluck('scopes.user_id')
                ->push($actor->id)
                ->unique();
            $accountAssignableUsers = CentralFinanceUser::on('mysql')->whereIn('id', $eligibleUserIds)
                ->orderBy('first_name')->get(['id','school_id','first_name','last_name','email']);
        }
        $accountDirectory = null;
        if ($page === 'accounts') {
            $accountRelations = ['custodian', 'authorizedUsers'];
            if ($this->accountAvailability->allocationSchemaAvailable()) $accountRelations[] = 'schoolAllocations';
            $directoryQuery = CentralFinanceFundAccount::on('mysql')->with($accountRelations)
                ->whereIn('id', $accounts->pluck('id'));
            $this->applyReadVisibility($directoryQuery, 'fund_account', $school, $includeQaTest);
            if (!empty($filters['account_owner'])) $directoryQuery->where('owner_type', $filters['account_owner']);
            if (!empty($filters['account_type'])) $directoryQuery->where('account_type', $filters['account_type']);
            if (!empty($filters['account_status'])) $directoryQuery->where('status', $filters['account_status']);
            if (!empty($filters['currency'])) $directoryQuery->where('currency', $filters['currency']);
            if (!empty($filters['custodian_user_id'])) $directoryQuery->where('custodian_user_id', (int) $filters['custodian_user_id']);
            if (!empty($filters['account_search'])) $directoryQuery->where(fn ($query) => $query->where('account_code', 'like', '%'.$filters['account_search'].'%')->orWhere('account_name', 'like', '%'.$filters['account_search'].'%'));
            $accountDirectory = $directoryQuery->orderBy('account_code')->paginate(25, ['*'], 'accounts_page')->withQueryString();
            $accountDirectory->getCollection()->each(function (CentralFinanceFundAccount $account) use ($schoolId): void {
                $account->setAttribute('current_balance', $this->balances->currentBalance($account));
                $account->setAttribute('school_activity', $schoolId ? $this->balances->schoolActivity($account, $schoolId) : null);
            });
        }
        $statementEntries = null; $statementOpeningBalance = null; $statementTotals = null; $statementLedgerCategories = [];
        if (in_array($page, ['account-statement', 'account-statements'], true) && $accountReport) {
            [$statementOpeningBalance, $allStatementEntries] = $this->accountStatementData($school, $schools, $accounts, $filters, $accountReport);
            $this->ledgerPresentation->decorate($allStatementEntries);
            $statementLedgerCategories = $this->ledgerCategoryNames($allStatementEntries);
            $pageNumber = LengthAwarePaginator::resolveCurrentPage('statement_page');
            $perPage = 25;
            $statementEntries = new LengthAwarePaginator($allStatementEntries->forPage($pageNumber, $perPage)->values(), $allStatementEntries->count(), $perPage, $pageNumber, ['path' => $request->url(), 'pageName' => 'statement_page', 'query' => $request->query()]);
            $statementTotals = ['money_in' => (float) $allStatementEntries->sum('money_in'), 'money_out' => (float) $allStatementEntries->sum('money_out')];
        }
        $accountAudits = $accountReport ? CentralFinanceDocumentAudit::on('mysql')->where('document_type', 'fund_account')->where('document_id', $accountReport->id)->latest()->get() : collect();
        $audits = $page === 'audits'
            ? $this->scopedAuditQuery($school, $schools, $filters)->latest()->paginate(25, ['*'], 'audits_page')->withQueryString()
            : collect();
        if ($page === 'audits' && method_exists($audits, 'getCollection')) {
            $auditActors = CentralFinanceUser::on('mysql')->whereIn('id', $audits->getCollection()->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
            $audits->getCollection()->each(function (CentralFinanceDocumentAudit $audit) use ($auditActors): void {
                $audit->setAttribute('actor_name', $auditActors[$audit->actor_id]?->full_name ?? '—');
            });
        }
        $auditOperators = $page === 'audits'
            ? CentralFinanceUser::on('mysql')->whereIn('id', $this->scopedAuditQuery($school, $schools, [])->distinct()->pluck('actor_id')->filter())->orderBy('first_name')->get(['id','first_name','last_name','email'])
            : collect();
        $paymentImportBatch = null; $expenseImportBatch = null;
        if ($page === 'payments' && $schoolId && $request->filled('import_batch')) {
            $paymentImportBatchQuery = CentralFinanceImportBatch::on('mysql')->where([
                'token' => $request->string('import_batch')->toString(), 'school_id' => $schoolId,
                'uploaded_by' => $actor->id, 'import_type' => 'payment',
            ]);
            $this->applyReadVisibility($paymentImportBatchQuery, 'import_batch', $school, $includeQaTest);
            $paymentImportBatch = $paymentImportBatchQuery->firstOrFail();
        }
        if ($page === 'operating' && $request->string('operation')->toString() === 'expense' && $schoolId && $request->filled('import_batch')) {
            $expenseImportBatchQuery = CentralFinanceImportBatch::on('mysql')->where([
                'token' => $request->string('import_batch')->toString(), 'school_id' => $schoolId,
                'uploaded_by' => $actor->id, 'import_type' => 'expense',
            ]);
            $this->applyReadVisibility($expenseImportBatchQuery, 'import_batch', $school, $includeQaTest);
            $expenseImportBatch = $expenseImportBatchQuery->firstOrFail();
        }
        $cutoverChecklist = $school && $canConfigureSchool ? $this->cutoverReadiness->checklist($school) : collect();
        $cutoverRecord = $schoolId ? CentralFinanceSchoolCutover::on('mysql')->where('school_id', $schoolId)->first() : null;
        $importBatches = collect();
        // Keep the Central Finance read shell renderable during an additive-schema rollout.
        // The import-history hub is empty until its own table exists; it never falls back to
        // tenant data or pretends that an import batch exists.
        if ($page === 'imports' && Schema::connection('mysql')->hasTable('central_finance_import_batches')) {
            $importBatchesQuery = CentralFinanceImportBatch::on('mysql')->with([]);
            $this->applyReadVisibility($importBatchesQuery, 'import_batch', $school, $includeQaTest);
            if ($school) $importBatchesQuery->where('school_id', $school->id);
            elseif ($schools->isNotEmpty()) $importBatchesQuery->whereIn('school_id', $schools->pluck('id'));
            else $importBatchesQuery->whereRaw('1 = 0');
            $importBatches = $importBatchesQuery->latest()->paginate(25, ['*'], 'imports_page')->withQueryString();
            $importBatches->getCollection()->each(function (CentralFinanceImportBatch $batch): void {
                $state = $this->importBatchPresentation->state($batch);
                $batch->setAttribute('display_state', $state['key']);
                $batch->setAttribute('display_status', $state['label']);
                $batch->setAttribute('display_badge', $state['badge']);
                $batch->setAttribute('corrected_upload_url', $this->importBatchPresentation->correctedUploadUrl($batch));
            });
        }
        $operationsType = $page === 'expenses' ? 'expense' : ($page === 'other-income' ? 'other_income' : null);
        $operatingDocuments = $operationsType ? $this->scopedOperatingDocumentsQuery($operationsType, $school, $schools, $accounts, $filters)
            ->latest($operationsType === 'expense' ? 'expense_date' : 'income_date')->paginate(25, ['*'], 'operations_page')->withQueryString() : collect();
        $operationOperators = $operationsType ? CentralFinanceUser::on('mysql')->whereIn('id', (clone $this->scopedOperatingDocumentsQuery($operationsType, $school, $schools, $accounts, $filters))->distinct()->pluck('created_by')->filter())->orderBy('first_name')->get() : collect();
        $operationCategoryQuery = $operationsType ? CentralFinanceCategory::on('mysql')->forActor($actor,$school ? [$school->id] : $schools->pluck('id')->all())->where('is_active',true)->forCashDirection($operationsType === 'expense' ? CentralFinanceCategory::EXPENSE : CentralFinanceCategory::INCOME) : null;
        if ($operationCategoryQuery) $this->applyReadVisibility($operationCategoryQuery, 'category', $school, $includeQaTest);
        $operationCategories = $operationCategoryQuery ? $operationCategoryQuery->orderBy('name')->get() : collect();
        $reimbursementQuery = CentralFinanceReimbursementRequest::on('mysql')->with('category');
        $this->applyReadVisibility($reimbursementQuery, 'reimbursement', $school, $includeQaTest);
        if ($school) $reimbursementQuery->where('school_id', $school->id); elseif ($schools->isNotEmpty()) $reimbursementQuery->whereIn('school_id', $schools->pluck('id')); else $reimbursementQuery->whereRaw('1 = 0');
        if (!empty($filters['reimbursement_status'])) $reimbursementQuery->where('status', $filters['reimbursement_status']);
        if (!empty($filters['requester_id'])) $reimbursementQuery->where('requested_by', (int) $filters['requester_id']);
        if (!empty($filters['category_id'])) $reimbursementQuery->where('category_id', (int) $filters['category_id']);
        if (isset($filters['amount_min'])) $reimbursementQuery->where('amount', '>=', (float) $filters['amount_min']);
        if (isset($filters['amount_max'])) $reimbursementQuery->where('amount', '<=', (float) $filters['amount_max']);
        if (!empty($filters['from'])) $reimbursementQuery->whereDate('created_at', '>=', $filters['from']);
        if (!empty($filters['to'])) $reimbursementQuery->whereDate('created_at', '<=', $filters['to']);
        $fallbackReimbursementQuery = $schoolId ? CentralFinanceReimbursementRequest::on('mysql')->where('school_id',$schoolId) : null;
        if ($fallbackReimbursementQuery) $this->applyReadVisibility($fallbackReimbursementQuery, 'reimbursement', $school, $includeQaTest);
        $reimbursements = $page === 'reimbursements' ? $reimbursementQuery->latest()->paginate(25, ['*'], 'reimbursements_page')->withQueryString() : ($fallbackReimbursementQuery ? $fallbackReimbursementQuery->latest()->get() : collect());
        $reimbursementRequesters = $page === 'reimbursements' ? CentralFinanceUser::on('mysql')->whereIn('id', (clone $reimbursementQuery)->distinct()->pluck('requested_by'))->orderBy('first_name')->get() : collect();
        $reimbursementCategoryQuery = $page === 'reimbursements' ? CentralFinanceCategory::on('mysql')->forActor($actor,$school ? [$school->id] : $schools->pluck('id')->all(),$canOperate)->where('is_active',true)->forCashDirection(CentralFinanceCategory::EXPENSE) : null;
        if ($reimbursementCategoryQuery) $this->applyReadVisibility($reimbursementCategoryQuery, 'category', $school, $includeQaTest);
        $reimbursementCategories = $reimbursementCategoryQuery ? $reimbursementCategoryQuery->orderBy('name')->get() : collect();
        $canApproveReimbursements = false;
        if ($school) try { app(\App\Services\CentralFinanceSchoolScopeService::class)->assertCanApproveReimbursements($actor, $school->id); $canApproveReimbursements = $canOperate; } catch (AuthorizationException) {}
        $expenseCategoryQuery=$schoolId?CentralFinanceCategory::on('mysql')->availableForSchool($schoolId)->forActor($actor,[$schoolId],true)->forCashDirection('expense'):null;
        $incomeCategoryQuery=$schoolId?CentralFinanceCategory::on('mysql')->availableForSchool($schoolId)->forActor($actor,[$schoolId],true)->forCashDirection('income'):null;
        $expenseQuery=$schoolId?CentralFinanceExpense::on('mysql')->where('school_id',$schoolId):null;
        $incomeQuery=$schoolId?CentralFinanceOtherIncome::on('mysql')->where('school_id',$schoolId):null;
        $handoverQuery = null;
        if ($page === 'handovers') {
            $handoverQuery = CentralFinanceFundHandover::on('mysql')->with([
                'sender:id,first_name,last_name,email',
                'receiver:id,first_name,last_name,email',
                'sourceAccount:id,account_code,account_name,currency',
                'destinationAccount:id,account_code,account_name,currency',
            ]);
            if ($schoolId) $handoverQuery->where('school_id', $schoolId);
            elseif ($schools->isNotEmpty()) $handoverQuery->whereIn('school_id', $schools->pluck('id'));
            else $handoverQuery->whereRaw('1 = 0');
        }
        $fundingQuery=$schoolId?CentralFinanceHqFundingRequest::on('mysql')->where('school_id',$schoolId):null;
        foreach ([[$expenseCategoryQuery,'category'],[$incomeCategoryQuery,'category']] as [$isolatedQuery,$subjectType]) if ($isolatedQuery) $this->applyReadVisibility($isolatedQuery, $subjectType, $school, $includeQaTest);
        foreach ([[$expenseQuery,'expense'],[$incomeQuery,'other_income'],[$handoverQuery,'fund_handover'],[$fundingQuery,'hq_funding']] as [$isolatedQuery,$subjectType]) if ($isolatedQuery) $this->applyReadVisibility($isolatedQuery, $subjectType, $school, $includeQaTest);
        $data=['page'=>$page,'actor'=>$actor,'school'=>$school,'schools'=>$schools,'schoolNames'=>$schools->pluck('name','id'),'canAccessAllSchools'=>$canAccessAllSchools,'accounts'=>$accounts,'operationAccounts'=>$operationAccounts,'handoverDestinationUserIds'=>$handoverDestinationUserIds,'accountDirectory'=>$accountDirectory,'statementEntries'=>$statementEntries,'statementOpeningBalance'=>$statementOpeningBalance,'statementTotals'=>$statementTotals,'accountAudits'=>$accountAudits,'canOperate'=>$canOperate,'canApproveReimbursements'=>$canApproveReimbursements,'canConfigureAccounts'=>$canConfigureAccounts,'canConfigureSchool'=>$canConfigureSchool,'configurableGroups'=>$configurableGroups,'groupCustodianOptions'=>$groupCustodianOptions,'allocationSchools'=>$allocationSchools,'accountAssignableUsers'=>$accountAssignableUsers,'cutoverStatus'=>$schoolId?$this->cutovers->statusForSchool($schoolId):null,'cutoverRecord'=>$cutoverRecord,'cutoverChecklist'=>$cutoverChecklist,'schoolUsers'=>$schoolUsers,'operators'=>$operators,'auditOperators'=>$auditOperators,'ledger'=>$ledger,'ledgerCategories'=>$ledgerCategories,'currencyTotals'=>$currencyTotals,'physicalAccountTotals'=>$physicalAccountTotals,'reportSchoolComparison'=>$reportSchoolComparison,'reportTrend'=>$reportTrend,'reportCategoryAnalysis'=>$reportCategoryAnalysis,'receivableCurrencyTotals'=>$receivableCurrencyTotals,'filters'=>$filters,'includeQaTest'=>$includeQaTest,'canIncludeQaTest'=>$this->dataIsolation->canIncludeQaTest($actor),'receivables'=>$receivables,'aging'=>$aging,'profiles'=>$profiles,'payments'=>$payments,'paymentProfiles'=>$paymentProfiles,'paymentClasses'=>$paymentClasses,'paymentReceivables'=>$paymentReceivables,'categories'=>$categories,'staff'=>$staff,'audits'=>$audits,'accountReport'=>$accountReport,'paymentImportBatch'=>$paymentImportBatch,'expenseImportBatch'=>$expenseImportBatch,'importBatches'=>$importBatches,'expenseCategories'=>$expenseCategoryQuery?->get()??collect(),'incomeCategories'=>$incomeCategoryQuery?->get()??collect(),'expenses'=>$expenseQuery?->latest()->get()??collect(),'otherIncomes'=>$incomeQuery?->latest()->get()??collect(),'operatingDocuments'=>$operatingDocuments,'operationOperators'=>$operationOperators,'operationCategories'=>$operationCategories,'reimbursements'=>$reimbursements,'reimbursementRequesters'=>$reimbursementRequesters,'reimbursementCategories'=>$reimbursementCategories,'handovers'=>$handoverQuery?->latest()->get()??collect(),'fundingRequests'=>$fundingQuery?->latest()->get()??collect()];
        $data['schoolFinanceFacade'] = $schoolFinanceFacade;
        $transferQuery = null;
        if ($page === 'transfers') {
            $transferQuery = CentralFinanceInternalTransfer::on('mysql')->where('source_type', 'direct_bank_transfer')
                ->where(function ($query) use ($accounts): void {
                    $query->whereIn('source_account_id', $accounts->pluck('id'))
                        ->orWhereIn('destination_account_id', $accounts->pluck('id'));
                })->with(['reversalTransfer', 'sourceAccount:id,account_code,account_name,currency', 'destinationAccount:id,account_code,account_name,currency']);
            if ($schoolId) $transferQuery->where('school_id', $schoolId);
            elseif ($schools->isNotEmpty()) $transferQuery->whereIn('school_id', $schools->pluck('id'));
            else $transferQuery->whereRaw('1 = 0');
        }
        if ($transferQuery) $this->applyReadVisibility($transferQuery, 'internal_transfer', $school, $includeQaTest);
        $data['transfers'] = $transferQuery ? $transferQuery->latest()->get() : collect();

        return view('central-finance.workspace',$data);
    }

    /** Keep context changes inside trusted Central Finance paths only. */
    private function centralNavigationRedirect(?string $returnTo): RedirectResponse
    {
        $parts = is_string($returnTo) ? parse_url($returnTo) : false;
        $centralPath = '/central-finance';
        $sameHost = is_array($parts) && (($parts['host'] ?? null) === parse_url(url('/central-finance'), PHP_URL_HOST));
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($sameHost && ($path === $centralPath || str_starts_with($path, $centralPath.'/'))) {
            return redirect()->to($returnTo);
        }
        return redirect()->route('central-finance.dashboard');
    }

    /** @return array<string, mixed> */
    private function validatedReadFilters(Request $request, ?\App\Models\School $school, \Illuminate\Support\Collection $accounts, ?\Illuminate\Support\Collection $schools = null): array
    {
        $data=$request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'school_id'=>['nullable','integer'],'fund_account_id'=>['nullable','integer'],'source'=>['nullable','string','max:80'],'category_id'=>['nullable','integer'],'operator_id'=>['nullable','integer'],'requester_id'=>['nullable','integer'],'amount_min'=>['nullable','numeric','min:0'],'amount_max'=>['nullable','numeric','gte:amount_min'],'reimbursement_status'=>['nullable', Rule::in([CentralFinanceReimbursementRequest::PENDING, CentralFinanceReimbursementRequest::APPROVED, CentralFinanceReimbursementRequest::REJECTED, CentralFinanceReimbursementRequest::WITHDRAWN, CentralFinanceReimbursementRequest::CANCELLED])],'payment_method'=>['nullable','string','max:40'],'student'=>['nullable','string','max:191'],'reference'=>['nullable','string','max:100'],'receipt_no'=>['nullable','string','max:100'],'receipt_status'=>['nullable', Rule::in(['active','refunded'])], 'currency'=>['nullable', Rule::in(CentralFinanceCurrency::ALLOWED)],'payment_class'=>['nullable','string','max:191'],'payment_student'=>['nullable','string','max:191'],'receivable_status'=>['nullable', Rule::in([CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL, CentralFinanceReceivable::PAID, CentralFinanceReceivable::WAIVED, CentralFinanceReceivable::CANCELLED, CentralFinanceReceivable::VOIDED])], 'account_owner'=>['nullable', Rule::in([CentralFinanceFundAccount::OWNER_HQ, CentralFinanceFundAccount::OWNER_SCHOOL])], 'account_type'=>['nullable', Rule::in([CentralFinanceFundAccount::TYPE_CASH, CentralFinanceFundAccount::TYPE_BANK, CentralFinanceFundAccount::TYPE_OTHER])], 'account_status'=>['nullable', Rule::in([CentralFinanceFundAccount::STATUS_ACTIVE, CentralFinanceFundAccount::STATUS_INACTIVE, CentralFinanceFundAccount::STATUS_ARCHIVED])], 'custodian_user_id'=>['nullable','integer'], 'account_search'=>['nullable','string','max:191'], 'direction'=>['nullable', Rule::in(['money_in','money_out','internal_transfer'])], 'operating_classification'=>['nullable', Rule::in(['income','expense','neutral'])], 'audit_action'=>['nullable','string','max:80'], 'audit_module'=>['nullable','string','max:80'], 'audit_document'=>['nullable','string','max:100'], 'include_qa_test'=>['nullable','boolean']]);
        if ($school && isset($data['school_id']) && (int) $data['school_id'] !== $school->id) abort(404);
        if (!$school && isset($data['school_id']) && $schools && !$schools->contains('id', (int) $data['school_id'])) abort(404);
        if (isset($data['fund_account_id']) && !$accounts->contains('id',(int)$data['fund_account_id'])) abort(404);
        return $data;
    }

    private function applyLedgerFilters($query, array $filters)
    {
        if (!empty($filters['from'])) $query->whereDate('entry_date','>=',$filters['from']);
        if (!empty($filters['to'])) $query->whereDate('entry_date','<=',$filters['to']);
        if (!empty($filters['source'])) $query->where('source_type', $filters['source']);
        if (!empty($filters['operator_id'])) $query->where('created_by', (int) $filters['operator_id']);
        if (!empty($filters['reference'])) $query->where('reference_no', 'like', '%'.$filters['reference'].'%');
        if (!empty($filters['currency'])) $query->where('currency', $filters['currency']);
        if (!empty($filters['category_id'])) {
            $expenseIds = CentralFinanceExpense::on('mysql')->withTrashed()->where('category_id', (int) $filters['category_id'])->pluck('expense_uuid');
            $incomeIds = CentralFinanceOtherIncome::on('mysql')->withTrashed()->where('category_id', (int) $filters['category_id'])->pluck('income_uuid');
            $query->where(function ($sources) use ($expenseIds, $incomeIds): void {
                $sources->where(function ($expenses) use ($expenseIds): void { $expenses->whereIn('source_type', ['central_expense', 'central_expense_void'])->whereIn('source_id', $expenseIds); })
                    ->orWhere(function ($incomes) use ($incomeIds): void { $incomes->whereIn('source_type', ['central_other_income', 'central_other_income_void'])->whereIn('source_id', $incomeIds); });
            });
        }
        if (!empty($filters['school_id'])) $query->where('school_id',(int)$filters['school_id']);
        if (!empty($filters['fund_account_id'])) $query->where('fund_account_id',(int)$filters['fund_account_id']);
        if (($filters['direction'] ?? null) === 'money_in') $query->where('money_in', '>', 0)->where('transaction_type', '!=', CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER);
        if (($filters['direction'] ?? null) === 'money_out') $query->where('money_out', '>', 0)->where('transaction_type', '!=', CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER);
        if (($filters['direction'] ?? null) === 'internal_transfer') $query->where('transaction_type', CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER);
        if (($filters['operating_classification'] ?? null) === 'income') $query->where('operating_income', '!=', 0);
        if (($filters['operating_classification'] ?? null) === 'expense') $query->where('operating_expense', '!=', 0);
        if (($filters['operating_classification'] ?? null) === 'neutral') $query->where('operating_income', 0)->where('operating_expense', 0);
        return $query;
    }

    /** Central audit rows share the same trusted School scope as read models. */
    private function scopedAuditQuery(?\App\Models\School $school, \Illuminate\Support\Collection $schools, array $filters)
    {
        $query = CentralFinanceDocumentAudit::on('mysql');
        if ($school) $query->where('school_id', $school->id);
        elseif ($schools->isNotEmpty()) $query->whereIn('school_id', $schools->pluck('id'));
        else $query->whereRaw('1 = 0');
        if (!empty($filters['from'])) $query->whereDate('created_at', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('created_at', '<=', $filters['to']);
        if (!empty($filters['school_id'])) $query->where('school_id', (int) $filters['school_id']);
        if (!empty($filters['operator_id'])) $query->where('actor_id', (int) $filters['operator_id']);
        if (!empty($filters['audit_action'])) $query->where('action', $filters['audit_action']);
        if (!empty($filters['audit_module'])) $query->where('document_type', $filters['audit_module']);
        if (!empty($filters['audit_document'])) $query->where(fn ($nested) => $nested->where('document_id', 'like', '%'.$filters['audit_document'].'%')->orWhere('reason', 'like', '%'.$filters['audit_document'].'%'));
        return $query;
    }

    /**
     * The Ledger's source resolver supplies a concrete model. Map that model
     * to the existing append-only audit facts; never manufacture a second
     * audit trail from Ledger data.
     */
    private function sourceAudits(\Illuminate\Database\Eloquent\Model $source, int $schoolId)
    {
        $type = match ($source::class) {
            CentralFinancePayment::class => 'central_payment',
            \App\Models\CentralFinancePaymentRefund::class => 'central_payment_refund',
            CentralFinanceExpense::class => 'expense',
            CentralFinanceOtherIncome::class => 'other_income',
            CentralFinanceFundHandover::class => 'fund_handover',
            CentralFinanceHqFundingRequest::class => 'hq_funding',
            \App\Models\CentralFinanceInternalTransfer::class => 'internal_transfer',
            default => null,
        };

        return $type === null
            ? collect()
            : CentralFinanceDocumentAudit::on('mysql')->where([
                'school_id' => $schoolId,
                'document_type' => $type,
                'document_id' => $source->getKey(),
            ])->latest()->get();
    }

    /** Central-only Ledger scope shared by the rendered page and export. */
    private function scopedLedgerQuery(?\App\Models\School $school, \Illuminate\Support\Collection $schools, \Illuminate\Support\Collection $accounts, array $filters)
    {
        $query = CentralFinanceLedgerEntry::on('mysql');
        $this->applyReadVisibility($query, 'ledger', $school, (bool) ($filters['include_qa_test'] ?? false));
        if ($school) $query->where('school_id', $school->id);
        elseif ($schools->isNotEmpty()) $query->whereIn('school_id', $schools->pluck('id'));
        else $query->whereRaw('1 = 0');
        // A School scope is never a substitute for an explicit Fund Account scope.
        $query->whereIn('fund_account_id', $accounts->pluck('id'));
        if (!empty($filters['fund_account_id'])) $query->where('fund_account_id', (int) $filters['fund_account_id']);
        return $this->applyLedgerFilters($query, $filters);
    }

    /**
     * @return array{0: float, 1: \Illuminate\Support\Collection<int, CentralFinanceLedgerEntry>}
     */
    private function accountStatementData(?\App\Models\School $school, \Illuminate\Support\Collection $schools, \Illuminate\Support\Collection $accounts, array $filters, CentralFinanceFundAccount $account): array
    {
        $canonical = CentralFinanceLedgerEntry::on('mysql')->where('fund_account_id', $account->id);
        $this->applyReadVisibility($canonical, 'ledger', $school, (bool) ($filters['include_qa_test'] ?? false));
        if ($school) {
            $canonical->where('school_id', $school->id);
        } elseif ($schools->isNotEmpty()) {
            $canonical->whereIn('school_id', $schools->pluck('id'));
        } else {
            $canonical->whereRaw('1 = 0');
        }

        // A School-scoped statement is an activity slice, not a second copy of
        // the physical account balance. Its running movement therefore starts
        // at zero; the account-wide statement retains the audited opening.
        $openingBalance = $school ? 0.0 : (float) $account->opening_balance;
        if (!empty($filters['from'])) {
            $prior = (clone $canonical)->whereDate('entry_date', '<', $filters['from']);
            $openingBalance += (float) $prior->sum('money_in') - (float) $prior->sum('money_out');
        }

        $canonicalInRange = clone $canonical;
        if (!empty($filters['from'])) $canonicalInRange->whereDate('entry_date', '>=', $filters['from']);
        if (!empty($filters['to'])) $canonicalInRange->whereDate('entry_date', '<=', $filters['to']);
        $running = $openingBalance;
        $runningBalances = [];
        $canonicalInRange->orderBy('entry_date')->orderBy('occurred_at')->orderBy('id')->get()->each(function (CentralFinanceLedgerEntry $entry) use (&$running, &$runningBalances): void {
            $running += (float) $entry->money_in - (float) $entry->money_out;
            $runningBalances[$entry->id] = round($running, 4);
        });

        $entries = $this->scopedLedgerQuery($school, $schools, $accounts, $filters)
            ->orderByDesc('entry_date')->orderByDesc('occurred_at')->orderByDesc('id')->get();
        $entries->each(fn (CentralFinanceLedgerEntry $entry) => $entry->setAttribute('running_balance', $runningBalances[$entry->id] ?? $openingBalance));

        return [$openingBalance, $entries];
    }

    /** Central-only Payment/Receipt scope shared by the rendered page and export. */
    private function scopedPaymentQuery(?\App\Models\School $school, \Illuminate\Support\Collection $schools, \Illuminate\Support\Collection $accounts, array $filters)
    {
        $query = CentralFinancePayment::on('mysql');
        $this->applyReadVisibility($query, 'payment', $school, (bool) ($filters['include_qa_test'] ?? false));
        if ($school) $query->where('school_id', $school->id);
        elseif ($schools->isNotEmpty()) $query->whereIn('school_id', $schools->pluck('id'));
        else $query->whereRaw('1 = 0');
        $query->whereIn('fund_account_id', $accounts->pluck('id'));
        if (!empty($filters['school_id'])) $query->where('school_id', (int) $filters['school_id']);
        if (!empty($filters['fund_account_id'])) $query->where('fund_account_id', (int) $filters['fund_account_id']);
        if (!empty($filters['from'])) $query->whereDate('paid_at', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('paid_at', '<=', $filters['to']);
        if (!empty($filters['student'])) {
            $query->where(function ($payments) use ($filters): void {
                $student = fn ($profile) => $profile->where(fn ($nested) => $nested->where('student_name', 'like', "%{$filters['student']}%")->orWhere('admission_no', 'like', "%{$filters['student']}%"));
                $payments->whereHas('receivable.studentProfile', $student);
                if (Schema::connection('mysql')->hasTable('central_finance_payment_allocations')) {
                    $payments->orWhereHas('allocations.receivable.studentProfile', $student);
                }
            });
        }
        if (!empty($filters['reference'])) $query->where('payment_reference','like',"%{$filters['reference']}%");
        if (!empty($filters['currency'])) $query->where('currency', $filters['currency']);
        if (!empty($filters['receipt_no'])) $query->whereHas('receipt', fn ($receipt) => $receipt->where('receipt_no', 'like', "%{$filters['receipt_no']}%"));
        if (($filters['receipt_status'] ?? null) === 'active') $query->whereDoesntHave('refunds');
        if (($filters['receipt_status'] ?? null) === 'refunded') $query->whereHas('refunds');
        return $query;
    }

    /** @return array{0:CentralFinanceUser,1:\App\Models\School,2:CentralFinancePayment,3:CentralFinanceReceiptViewModel,4:\Illuminate\Support\Collection} */
    private function receiptData(int $payment): array
    {
        $actor = $this->actor();
        // Resolve the owning School from the canonical Payment rather than
        // from an Official-only account list.  A trusted actor may open an
        // exact QA School receipt, but still cannot use a guessed ID to cross
        // a School boundary.
        $document = CentralFinancePayment::on('mysql')->with($this->paymentReadRelations())->findOrFail($payment);
        $school = $this->workspace->assertCanViewSchool($actor, (int) $document->school_id);
        // The scoped workspace collection intentionally selects only identity
        // columns (id/name/code). A receipt also needs non-financial School
        // presentation fields, so rehydrate this *already-authorized* School
        // from the trusted central registry rather than weakening the scope
        // query or falling back to a generic logo.
        $school = School::on('mysql')->findOrFail($school->id);
        $includeQaTest = $this->effectiveIncludeQaTest(request(), $actor)
            || $this->dataIsolation->isQaTestSchool((int) $school->id);
        $accounts = $this->workspace->readableAccounts($actor, $school->id, $includeQaTest);
        abort_unless($accounts->pluck('id')->contains((int) $document->fund_account_id), 404);
        $audits = CentralFinanceDocumentAudit::on('mysql')->where('school_id', $school->id)
            ->where(function ($query) use ($document): void {
                $query->where(fn ($paymentAudit) => $paymentAudit->where('document_type', 'central_payment')->where('document_id', $document->id))
                    ->orWhere(fn ($refundAudit) => $refundAudit->where('document_type', 'central_payment_refund')->whereIn('document_id', $document->refunds->pluck('id')));
                if ($document->reversal) {
                    $query->orWhere(fn ($reversalAudit) => $reversalAudit->where('document_type', 'central_payment_reversal')->where('document_id', $document->reversal->id));
                }
            })->latest()->get();
        return [$actor, $school, $document, $this->receiptViewModels->make($document, $school), $audits];
    }

    /** @return list<string> */
    private function paymentReadRelations(bool $withCorrections = true): array
    {
        $relations = ['receipt', 'receivable.studentProfile', 'receivable.payments.refunds', 'fundAccount', 'receivedBy'];
        if ($withCorrections) $relations = array_merge($relations, ['refunds.fundAccount', 'refunds.refundedBy', 'reversal.fundAccount', 'reversal.reversedBy']);
        if (Schema::connection('mysql')->hasTable('central_finance_payment_allocations')) {
            $relations[] = 'allocations.receivable.studentProfile';
        }
        return $relations;
    }

    /**
     * A detail page may be read by a School-scoped Finance user, but it never
     * accepts a School ID from the URL. The selected School is always resolved
     * through the existing Central Finance session and scope service.
     *
     * @return array{0:CentralFinanceUser,1:\App\Models\School}
     */
    private function currentReadSchool(): array
    {
        $actor = $this->actor();
        $includeQaTest = $this->effectiveIncludeQaTest(request(), $actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        abort_unless($school !== null, 404);

        return [$actor, $school];
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

    /** @return array<string,array{id:?int,name:string}> */
    private function ledgerCategoryDetails(\Illuminate\Support\Collection $entries): array
    {
        $expenseIds = $entries->whereIn('source_type', ['central_expense','central_expense_void'])->pluck('source_id')->unique()->filter();
        $incomeIds = $entries->whereIn('source_type', ['central_other_income','central_other_income_void'])->pluck('source_id')->unique()->filter();
        $details = [];
        CentralFinanceExpense::on('mysql')->withTrashed()->with('category')->whereIn('expense_uuid', $expenseIds)->get()->each(function ($item) use (&$details): void {
            $value = ['id' => $item->category?->id, 'name' => $item->category?->name ?? __('Uncategorized'), 'code' => $item->category?->category_code, 'type' => $item->category?->type];
            $details['central_expense:'.$item->expense_uuid] = $details['central_expense_void:'.$item->expense_uuid] = $value;
        });
        CentralFinanceOtherIncome::on('mysql')->withTrashed()->with('category')->whereIn('income_uuid', $incomeIds)->get()->each(function ($item) use (&$details): void {
            $value = ['id' => $item->category?->id, 'name' => $item->category?->name ?? __('Uncategorized'), 'code' => $item->category?->category_code, 'type' => $item->category?->type];
            $details['central_other_income:'.$item->income_uuid] = $details['central_other_income_void:'.$item->income_uuid] = $value;
        });
        return $details;
    }

    private function scopedImportBatch(CentralFinanceUser $actor, string $identifier): CentralFinanceImportBatch
    {
        $includeQaTest = $this->effectiveIncludeQaTest(request(), $actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        $schoolIds = $this->workspace->accessibleSchools($actor, $includeQaTest)->pluck('id');
        $query = CentralFinanceImportBatch::on('mysql')
            ->whereIn('school_id', $schoolIds)
            ->where(fn ($query) => $query->where('token', $identifier)->orWhere('batch_uuid', $identifier));
        $this->applyReadVisibility($query, 'import_batch', $school, $includeQaTest);

        return $query->firstOrFail();
    }

    /**
     * QA/Test data becomes readable only inside the actor's already trusted
     * selected QA School. The explicit request flag is evaluated first, so a
     * School staff identity still cannot elevate itself into an All Schools
     * QA-history view.
     */
    private function effectiveIncludeQaTest(Request $request, CentralFinanceUser $actor): bool
    {
        return $this->dataIsolation->includeQaTest($request, $actor)
            || $this->workspace->isQaTestSchoolContext($actor);
    }

    /**
     * Selected QA Schools deliberately use the stricter exact-QA predicate,
     * not the broad privileged history predicate. This keeps Zixuan usable as
     * a full QA School without revealing unclassified, Official, or archived
     * records through a School-scoped page.
     */
    private function applyReadVisibility(\Illuminate\Database\Eloquent\Builder $query, string $subjectType, ?School $school, bool $includeQaTest): void
    {
        if ($school !== null && $this->dataIsolation->isQaTestSchool((int) $school->id)) {
            $this->dataIsolation->applySchoolWorkflow($query, $subjectType, (int) $school->id);

            return;
        }

        $this->dataIsolation->apply($query, $subjectType, $includeQaTest);
    }

    private function downloadReadExport(CentralFinanceReadExport $export, string $basename, string $format)
    {
        abort_unless(in_array($format, ['xlsx','csv'], true), 404);
        return Excel::download($export, $basename.'_'.now()->format('Ymd_His').'.'.$format, $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX);
    }

    private function transactionDate(string $value, string $field = 'transaction_date'): CarbonImmutable
    {
        try {
            return CentralFinanceBusinessDate::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => [$exception->getMessage()]]);
        }
    }

    /** @return \Illuminate\Support\Collection<int,CentralFinanceFundAccount> */
    private function viewableFundAccounts(CentralFinanceUser $actor, ?int $schoolId, bool $includeQaTest = false): \Illuminate\Support\Collection
    {
        $accounts = $this->workspace->readableAccounts($actor, $schoolId, $includeQaTest);
        // A selected QA School must remain an exact-QA workspace. Head
        // Finance can leave that School context to manage Group control-plane
        // accounts, but those Official accounts are never mixed into the QA
        // School directory merely because the actor can configure the Group.
        if ($schoolId !== null && $this->dataIsolation->isQaTestSchool($schoolId)) {
            return $accounts;
        }
        $configurableGroupIds = $this->configuration->configurableGroups($actor)->pluck('id');
        if ($configurableGroupIds->isEmpty()) {
            return $accounts;
        }

        // Group-account configuration must remain reachable before the first
        // School allocation is created. This is a control-plane read only;
        // transaction use still goes through the canonical School allocation
        // and Finance-scope predicate.
        $managedQuery = CentralFinanceFundAccount::on('mysql')
            ->whereIn('group_id', $configurableGroupIds)
            ->orderBy('account_name');
        $this->dataIsolation->apply($managedQuery, 'fund_account', $includeQaTest);

        return $accounts->merge($managedQuery->get())->unique('id')->sortBy('account_name')->values();
    }
}
