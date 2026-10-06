<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinancePendingCollectionAllocation;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Services\CentralFinancePendingCollectionConfirmationService;
use App\Services\CentralFinancePendingCollectionService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceWorkspaceService;
use App\Services\FinanceOperatingContextService;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Thin HTTP adapter: Front Desk can declare a collection; only Head Finance confirms it. */
final class CentralFinancePendingCollectionController extends Controller
{
    private const ATTEMPTS_SESSION_KEY = 'central_finance_pending_collection_attempts';

    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinancePendingCollectionService $pending, private readonly CentralFinancePendingCollectionConfirmationService $confirmation, private readonly FinanceOperatingContextService $operatingContext, private readonly CentralFinanceDataIsolationService $dataIsolation) {}

    public function frontDeskIndex(Request $request): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor)
            || $this->workspace->isQaTestSchoolContext($actor);
        $canIncludeQaTest = $this->dataIsolation->canIncludeQaTest($actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        if ($school === null) {
            $school = $this->operatingContext->currentSchool(Auth::user());
            if ($school !== null) session()->put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, $school->id);
        }
        abort_unless($school !== null, 403);
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $school->id);
        $pendingQuery = CentralFinancePendingCollection::on('mysql')->with(['studentProfile', 'receivable'])
            ->where(['school_id' => $school->id, 'collected_by' => $actor->id]);
        // Front Desk workflow visibility follows only the trusted current
        // School. QA/Test Schools see their explicit QA workflow; Official
        // Schools retain the normal Official-only filter and no QA toggle.
        $this->dataIsolation->applySchoolWorkflow($pendingQuery, 'pending_collection', (int) $school->id);
        $pending = $pendingQuery->latest('submitted_at')->paginate(20)->withQueryString();
        return view('central-finance.pending-collections.front-desk-index', compact('school', 'pending', 'includeQaTest', 'canIncludeQaTest'));
    }

    public function review(int $profile, int $receivable): View
    {
        return $this->reviewForReceivables($profile, [$receivable]);
    }

    public function reviewMultiple(Request $request, int $profile): View
    {
        $data = $request->validate(['receivable_ids' => ['required', 'array', 'min:1'], 'receivable_ids.*' => ['required', 'integer']]);
        return $this->reviewForReceivables($profile, $data['receivable_ids']);
    }

    public function submit(Request $request, int $profile, int $receivable): RedirectResponse
    {
        $request->merge(['allocations' => [$receivable => $request->input('amount')]]);
        return $this->submitMultiple($request, $profile);
    }

    public function submitMultiple(Request $request, int $profile): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->dataIsolation->assertWorkflowWritable('student_profile', $profile);
        $data = $request->validate(['pending_attempt_uuid' => ['required', 'uuid'], 'allocations' => ['required', 'array', 'min:1'], 'allocations.*' => ['required', 'regex:/^[0-9]+(?:\\.[0-9]{1,4})?$/'], 'payment_method' => ['required', 'string', 'max:40'], 'payment_reference' => ['nullable', 'string', 'max:100'], 'intended_fund_account_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:2000']]);
        $attempt = session()->get(self::ATTEMPTS_SESSION_KEY.'.'.$data['pending_attempt_uuid']);
        $selected = collect($attempt['receivable_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $submitted = collect($data['allocations'])->mapWithKeys(fn ($amount, $id) => [(int) $id => (string) $amount])->sortKeys();
        abort_unless(is_array($attempt) && (int) ($attempt['actor_id'] ?? 0) === $actor->id && (int) ($attempt['profile_id'] ?? 0) === $profile && $selected === $submitted->keys()->values()->all(), 403);
        $lines = $submitted->map(fn (string $amount, int $receivableId) => ['receivable_id' => $receivableId, 'amount' => $amount])->values()->all();
        try {
            $pending = $this->pending->submitAllocations($actor, $profile, $lines, $data['payment_method'], CarbonImmutable::now('Asia/Yangon'), 'front-desk:'.$data['pending_attempt_uuid'], $data['intended_fund_account_id'] ?? null, $data['payment_reference'] ?? null, $data['note'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['collection' => $exception->getMessage()]);
        }
        return redirect()->route('central-finance.pending-collections.collection-receipt', $pending)
            ->with('success', __('Collection Receipt created: :no. Finance confirmation is still pending.', ['no' => $pending->acknowledgement_no]));
    }

    /** @param list<mixed> $requestedReceivableIds */
    private function reviewForReceivables(int $profileId, array $requestedReceivableIds): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $school = $this->workspace->currentSchool($actor) ?? $this->operatingContext->currentSchool(Auth::user());
        if ($school !== null) session()->put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, $school->id);
        abort_unless($school !== null, 403);
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $school->id);
        $profileQuery = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id);
        $this->dataIsolation->applySchoolWorkflow($profileQuery, 'student_profile', (int) $school->id);
        $profile = $profileQuery->findOrFail($profileId);
        $ids = collect($requestedReceivableIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->sort()->values()->all();
        abort_if($ids === [], 422, __('Select at least one receivable.'));
        $query = CentralFinanceReceivable::on('mysql')->where(['school_id' => $school->id, 'student_profile_id' => $profile->id])->whereIn('status', [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL])->whereIn('id', $ids);
        $this->dataIsolation->applySchoolWorkflow($query, 'receivable', (int) $school->id);
        $receivables = $query->orderBy('id')->get();
        if ($receivables->count() !== count($ids) || $receivables->pluck('currency')->map(fn ($currency) => strtoupper((string) $currency))->unique()->count() !== 1) abort(422, __('Selected receivables must belong to one Student and currency.'));
        foreach ($receivables as $receivable) {
            $pending = $this->pendingReservation((int) $school->id, (int) $receivable->id);
            $receivable->setAttribute('pending_confirmation_amount', $pending);
            $receivable->setAttribute('available_to_collect', CentralFinanceDecimal::max(
                CentralFinanceDecimal::subtract(
                    CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid),
                    (string) $pending,
                ),
                '0',
            ));
        }
        $accounts = $this->workspace->collectionBankAccountsForSchoolWorkflow($actor, (int) $school->id)->filter(fn (CentralFinanceFundAccount $account) => strtoupper($account->currency) === strtoupper($receivables->first()->currency));
        $attemptUuid = (string) Str::uuid();
        session()->put(self::ATTEMPTS_SESSION_KEY.'.'.$attemptUuid, ['actor_id' => $actor->id, 'school_id' => $school->id, 'profile_id' => $profile->id, 'receivable_ids' => $ids]);
        $receivable = $receivables->first(); // Backward-compatible view data for legacy direct route tests.
        return view('central-finance.pending-collections.review', compact('school', 'profile', 'receivable', 'receivables', 'accounts', 'attemptUuid'));
    }

    private function pendingReservation(int $schoolId, int $receivableId): string
    {
        if (Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')) {
            $query = CentralFinancePendingCollectionAllocation::on('mysql')->where(['school_id' => $schoolId, 'receivable_id' => $receivableId])->whereHas('pendingCollection', fn ($pending) => $pending->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD]));
            $this->dataIsolation->applySchoolWorkflow($query, 'pending_collection_allocation', $schoolId);
            return CentralFinanceDecimal::normalize((string) $query->sum('amount'));
        }
        $query = CentralFinancePendingCollection::on('mysql')->where(['school_id' => $schoolId, 'receivable_id' => $receivableId])->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD]);
        $this->dataIsolation->applySchoolWorkflow($query, 'pending_collection', $schoolId);
        return CentralFinanceDecimal::normalize((string) $query->sum('amount'));
    }

    public function headFinanceIndex(Request $request): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor)
            || $this->workspace->isQaTestSchoolContext($actor);
        $canIncludeQaTest = $this->dataIsolation->canIncludeQaTest($actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        if ($school === null) {
            $school = $this->operatingContext->currentSchool(Auth::user());
            if ($school !== null) session()->put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, $school->id);
        }
        abort_unless($school !== null, 403);
        $this->workspace->assertHeadFinance($actor);
        $relations = ['studentProfile', 'receivable', 'intendedFundAccount', 'collectedBy'];
        if (Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')) $relations[] = 'allocations.receivable';
        $pendingQuery = CentralFinancePendingCollection::on('mysql')->with($relations)
            ->where('school_id', $school->id)->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD]);
        // A selected permanent QA School reviews its own QA Run workflow. The
        // ordinary finance read-model filter deliberately excludes Run
        // members so they can never enter Official totals or ledgers.
        $this->dataIsolation->applySchoolWorkflow($pendingQuery, 'pending_collection', (int) $school->id);
        $pending = $pendingQuery->latest('submitted_at')->paginate(30)->withQueryString();
        $pending->getCollection()->each(function (CentralFinancePendingCollection $row) use ($includeQaTest): void {
            $eligible = $this->dataIsolation->isProduction('pending_collection', (int) $row->id);
            if ($includeQaTest) {
                try {
                    $this->dataIsolation->assertWorkflowWritable('pending_collection', (int) $row->id);
                    $eligible = true;
                } catch (\Throwable) {
                    $eligible = false;
                }
            }
            $row->setAttribute('workflow_eligible', $eligible);
        });
        $accounts = $this->workspace->accessibleAccounts($actor, $school->id, $includeQaTest);
        return view('central-finance.pending-collections.head-finance-index', compact('school', 'pending', 'accounts', 'includeQaTest', 'canIncludeQaTest'));
    }

    public function hold(Request $request, CentralFinancePendingCollection $pending): RedirectResponse { return $this->reviewAction($request, $pending, 'hold'); }
    public function reject(Request $request, CentralFinancePendingCollection $pending): RedirectResponse { return $this->reviewAction($request, $pending, 'reject'); }

    public function confirm(Request $request, CentralFinancePendingCollection $pending): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $this->dataIsolation->assertWorkflowWritable('pending_collection', (int) $pending->id);
        $data = $request->validate(['fund_account_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail((int) $data['fund_account_id']);
        try {
            $this->confirmation->confirm($actor, $pending->id, $account, CarbonImmutable::now(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            // Existing pre-V2 declarations can legitimately expose a bad
            // reference at confirmation.  Preserve the document and return
            // a normal actionable validation response, never a 500.
            throw ValidationException::withMessages(['confirmation' => $exception->getMessage()]);
        }
        return back()->with('success', __('Pending collection confirmed and official receipt issued.'));
    }

    /** Printable customer acknowledgement, distinct from the canonical receipt. */
    public function collectionReceipt(CentralFinancePendingCollection $pending): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $school = $this->workspace->assertCanViewSchool($actor, (int) $pending->school_id);
        if (!$this->workspace->canReviewPendingCollections($actor) && (int) $pending->collected_by !== (int) $actor->id) {
            abort(403);
        }
        $relations = ['studentProfile', 'receivable', 'intendedFundAccount', 'collectedBy', 'confirmedBy', 'confirmedPayment.receipt'];
        if (Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')) $relations[] = 'allocations.receivable';
        $pending->load($relations);
        return view('central-finance.pending-collections.collection-receipt', compact('pending', 'school'));
    }

    private function reviewAction(Request $request, CentralFinancePendingCollection $pending, string $action): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $this->dataIsolation->assertWorkflowWritable('pending_collection', (int) $pending->id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action === 'hold' ? $this->pending->hold($actor, $pending->id, $data['reason']) : $this->pending->reject($actor, $pending->id, $data['reason']);
        return back()->with('success', __('Pending collection updated.'));
    }
}
