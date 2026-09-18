<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Services\CentralFinancePendingCollectionConfirmationService;
use App\Services\CentralFinancePendingCollectionService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceWorkspaceService;
use App\Services\FinanceOperatingContextService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Thin HTTP adapter: Front Desk can declare a collection; only Head Finance confirms it. */
final class CentralFinancePendingCollectionController extends Controller
{
    private const ATTEMPTS_SESSION_KEY = 'central_finance_pending_collection_attempts';

    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinancePendingCollectionService $pending, private readonly CentralFinancePendingCollectionConfirmationService $confirmation, private readonly FinanceOperatingContextService $operatingContext, private readonly CentralFinanceDataIsolationService $dataIsolation) {}

    public function frontDeskIndex(Request $request): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor);
        $canIncludeQaTest = $this->dataIsolation->canIncludeQaTest($actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        if ($school === null) {
            $school = $this->operatingContext->currentSchool(Auth::user());
            if ($school !== null) session()->put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, $school->id);
        }
        abort_unless($school !== null, 403);
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $school->id);
        $schoolQaTest = $this->dataIsolation->isQaTestSchool((int) $school->id);
        $pendingQuery = CentralFinancePendingCollection::on('mysql')->with(['studentProfile', 'receivable'])
            ->where(['school_id' => $school->id, 'collected_by' => $actor->id]);
        // A scoped Front Desk can see its own QA workflow in a QA School.
        // Official dashboards and reports remain filtered by default.
        $this->dataIsolation->apply($pendingQuery, 'pending_collection', $includeQaTest || $schoolQaTest);
        $pending = $pendingQuery->latest('submitted_at')->paginate(20)->withQueryString();
        return view('central-finance.pending-collections.front-desk-index', compact('school', 'pending', 'includeQaTest', 'canIncludeQaTest'));
    }

    public function review(int $profile, int $receivable): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $school = $this->workspace->currentSchool($actor);
        if ($school === null) {
            $school = $this->operatingContext->currentSchool(Auth::user());
            if ($school !== null) session()->put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, $school->id);
        }
        abort_unless($school !== null, 403);
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $school->id);
        $profileQuery = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id);
        $this->dataIsolation->apply($profileQuery, 'student_profile');
        $profile = $profileQuery->findOrFail($profile);
        $receivableQuery = CentralFinanceReceivable::on('mysql')->where(['school_id' => $school->id, 'student_profile_id' => $profile->id])->whereIn('status', [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL]);
        $this->dataIsolation->apply($receivableQuery, 'receivable');
        $receivable = $receivableQuery->findOrFail($receivable);
        $accounts = $this->workspace->collectionBankAccounts($actor, (int) $school->id, $this->dataIsolation->isQaTestSchool((int) $school->id))
            ->filter(fn (CentralFinanceFundAccount $account) => strtoupper($account->currency) === strtoupper($receivable->currency));
        $attemptUuid = (string) Str::uuid();
        session()->put(self::ATTEMPTS_SESSION_KEY.'.'.$attemptUuid, [
            'actor_id' => $actor->id, 'school_id' => $school->id, 'profile_id' => $profile->id, 'receivable_id' => $receivable->id,
        ]);
        return view('central-finance.pending-collections.review', compact('school', 'profile', 'receivable', 'accounts', 'attemptUuid'));
    }

    public function submit(Request $request, int $profile, int $receivable): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->dataIsolation->assertWorkflowWritable('student_profile', $profile);
        $this->dataIsolation->assertWorkflowWritable('receivable', $receivable);
        $data = $request->validate(['pending_attempt_uuid' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', 'string', 'max:40'], 'payment_reference' => ['nullable', 'string', 'max:100'], 'intended_fund_account_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:2000']]);
        $attempt = session()->get(self::ATTEMPTS_SESSION_KEY.'.'.$data['pending_attempt_uuid']);
        abort_unless(is_array($attempt) && (int) ($attempt['actor_id'] ?? 0) === $actor->id && (int) ($attempt['profile_id'] ?? 0) === $profile && (int) ($attempt['receivable_id'] ?? 0) === $receivable, 403);
        $pending = $this->pending->submit($actor, $profile, $receivable, (float) $data['amount'], $data['payment_method'], CarbonImmutable::now(), 'front-desk:'.$data['pending_attempt_uuid'], $data['intended_fund_account_id'] ?? null, $data['payment_reference'] ?? null, $data['note'] ?? null);
        return redirect()->route('central-finance.pending-collections.collection-receipt', $pending)
            ->with('success', __('Collection Receipt created: :no. Finance confirmation is still pending.', ['no' => $pending->acknowledgement_no]));
    }

    public function headFinanceIndex(Request $request): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor);
        $canIncludeQaTest = $this->dataIsolation->canIncludeQaTest($actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        if ($school === null) {
            $school = $this->operatingContext->currentSchool(Auth::user());
            if ($school !== null) session()->put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, $school->id);
        }
        abort_unless($school !== null, 403);
        $this->workspace->assertHeadFinance($actor);
        $pendingQuery = CentralFinancePendingCollection::on('mysql')->with(['studentProfile', 'receivable', 'intendedFundAccount', 'collectedBy'])
            ->where('school_id', $school->id)->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD]);
        $this->dataIsolation->apply($pendingQuery, 'pending_collection', $includeQaTest);
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
        $this->confirmation->confirm($actor, $pending->id, $account, CarbonImmutable::now(), $data['reason']);
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
        $pending->load(['studentProfile', 'receivable', 'intendedFundAccount', 'collectedBy', 'confirmedBy', 'confirmedPayment.receipt']);
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
