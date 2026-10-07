<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUnidentifiedDeposit;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceFundAccountSchoolAvailabilityService;
use App\Services\CentralFinanceSchoolCutoverService;
use App\Services\CentralFinanceUnidentifiedDepositService;
use App\Services\CentralFinanceWorkspaceService;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Head-Finance-only group cash desk; it never fabricates a School for unknown money. */
final class CentralFinanceUnidentifiedDepositController extends Controller
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
        private readonly CentralFinanceUnidentifiedDepositService $deposits,
        private readonly CentralFinanceDataIsolationService $isolation,
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
    ) {}

    public function index(?Request $request = null): View
    {
        $request ??= request();
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $groups = $this->configuration->configurableGroups($actor);
        $includeQaTest = $this->isolation->includeQaTest($request, $actor);
        // Receiving unknown money cannot depend on assigning a School first.
        // Explicit Group control is the authority; School allocation remains
        // mandatory when the deposit is later settled against a receivable.
        $accountQuery = CentralFinanceFundAccount::on('mysql')->where('owner_type', CentralFinanceFundAccount::OWNER_HQ)
            ->whereNull('school_id')->where('account_type', CentralFinanceFundAccount::TYPE_BANK)
            ->whereIn('group_id', $groups->pluck('id'));
        $this->isolation->apply($accountQuery, 'fund_account', $includeQaTest);
        $accounts = (clone $accountQuery)->active()->orderBy('account_name')->get()
            ->each(fn ($account) => $account->setAttribute('is_qa_test', $this->isolation->classification('fund_account', $account->id) === 'qa_test'));
        $readableAccountIds = $accountQuery->pluck('id');
        $query = CentralFinanceUnidentifiedDeposit::on('mysql')->with(['fundAccount', 'allocations.payment.receipt'])
            ->whereIn('group_id', $groups->pluck('id'))->whereIn('fund_account_id', $readableAccountIds);
        $this->isolation->apply($query, 'unidentified_deposit', $includeQaTest);
        $deposits = $query->latest('received_date')->paginate(30)->withQueryString();
        $deposits->getCollection()->each(fn ($deposit) => $deposit->setAttribute('is_qa_test', $this->isolation->classification('unidentified_deposit', $deposit->id) === 'qa_test'));
        $schools = $this->workspace->accessibleSchools($actor, true);
        return view('central-finance.unidentified-deposits.index', compact('accounts', 'deposits', 'schools', 'includeQaTest'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $data = $request->validate([
            'fund_account_id' => ['required', 'integer'], 'amount' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'received_date' => ['required', 'date_format:Y-m-d'], 'idempotency_reference' => ['required', 'regex:/^[A-Za-z0-9_.:-]{2,100}$/'],
            'bank_reference' => ['nullable', 'string', 'max:100'], 'known_payer' => ['nullable', 'string', 'max:191'], 'description' => ['nullable', 'string', 'max:2000'],
            'manual_identity' => ['nullable', 'required_without:bank_reference', 'string', 'max:100'],
            'manual_reason' => ['nullable', 'required_without:bank_reference', 'string', 'max:2000'],
        ]);
        try {
            $deposit = $this->deposits->record($actor, CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']), (string) $data['amount'], CarbonImmutable::parse($data['received_date'].' 00:00:00', 'Asia/Yangon'), $data['idempotency_reference'], $data['bank_reference'] ?? null, $data['description'] ?? null, $data['known_payer'] ?? null, $data['manual_identity'] ?? null, $data['manual_reason'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['deposit' => $exception->getMessage()]);
        }
        return back()->with('success', __('Unidentified Deposit recorded: :id', ['id' => $deposit->deposit_uuid]));
    }

    public function match(Request $request, CentralFinanceUnidentifiedDeposit $deposit): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $data = $request->validate([
            'receivable_id' => ['required', 'integer'], 'amount' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'reason' => ['required', 'string', 'max:2000'], 'idempotency_reference' => ['required', 'regex:/^[A-Za-z0-9_.:-]{2,100}$/'],
        ]);
        try {
            $this->deposits->match($actor, $deposit->id, (int) $data['receivable_id'], (string) $data['amount'], CarbonImmutable::now('Asia/Yangon'), $data['reason'], $data['idempotency_reference']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['match' => $exception->getMessage()]);
        }
        return back()->with('success', __('Deposit allocated. Payment and Receipt recorded without another bank receipt.'));
    }

    /** Scoped, read-only selector. Submission repeats every financial guard under locks. */
    public function receivables(Request $request, CentralFinanceUnidentifiedDeposit $deposit): JsonResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $this->configuration->assertHeadFinanceCanConfigureGroup($actor, (int) $deposit->group_id);
        $data = $request->validate(['school_id' => ['required', 'integer'], 'student' => ['required', 'string', 'min:1', 'max:191']]);
        $school = $this->workspace->assertCanOperateSchool($actor, (int) $data['school_id']);
        $this->configuration->assertHeadFinanceCanConfigureSchool($actor, $school);
        $this->cutovers->assertCentralWritesAllowed($school->id);
        $account = $this->workspace->accessibleAccountsForSchoolWorkflow($actor, $school->id)->firstWhere('id', $deposit->fund_account_id);
        abort_unless($account && (int) $account->group_id === (int) $deposit->group_id, 403);
        $this->availability->assertAccountAvailableForSchool($account, $school->id);
        $this->isolation->assertFundAccountMatchesSchoolWorkflow($school->id, $account->id);
        $depositClass = $this->isolation->classification('unidentified_deposit', $deposit->id);
        abort_unless(in_array($depositClass, ['production', 'qa_test'], true)
            && $depositClass === $this->isolation->classification('fund_account', $account->id)
            && ($depositClass === 'qa_test') === $this->isolation->isQaTestSchool($school->id), 403);
        $term = trim($data['student']);
        abort_if($term === '', 422);
        $query = CentralFinanceReceivable::on('mysql')->with('studentProfile')->where('school_id', $school->id)
            ->where('currency', $deposit->currency)->whereIn('status', [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL])
            ->whereHas('studentProfile', function ($profiles) use ($term, $school): void {
                $profiles->where('school_id', $school->id)->whereNull('source_deleted_at')
                    ->where(function ($identity) use ($term): void {
                        $identity->where('admission_no', $term)->orWhere('student_name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%');
                        if (\Illuminate\Support\Facades\Schema::connection('mysql')->hasColumn('central_finance_student_profiles', 'student_code')) $identity->orWhere('student_code', $term);
                    });
            });
        $this->isolation->applySchoolWorkflow($query, 'receivable', $school->id);
        $results = $query->orderBy('id')->limit(50)->get()->map(function ($receivable): array {
            $reserved = app(\App\Services\CentralFinancePendingCollectionService::class)->reservedAmount($receivable->id, false);
            $available = CentralFinanceDecimal::max(CentralFinanceDecimal::subtract(CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid), $reserved), '0');
            return ['id' => $receivable->id, 'student' => $receivable->studentProfile->student_name,
                'student_code' => $receivable->studentProfile->student_code, 'gr' => $receivable->studentProfile->admission_no,
                'description' => $receivable->description, 'currency' => $receivable->currency, 'available' => $available, 'reserved' => $reserved];
        })->filter(fn (array $row): bool => CentralFinanceDecimal::compare($row['available'], '0') > 0)->values();
        return response()->json(['receivables' => $results]);
    }

}
