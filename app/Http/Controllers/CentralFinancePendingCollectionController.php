<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Services\CentralFinancePendingCollectionConfirmationService;
use App\Services\CentralFinancePendingCollectionService;
use App\Services\CentralFinanceWorkspaceService;
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

    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinancePendingCollectionService $pending, private readonly CentralFinancePendingCollectionConfirmationService $confirmation) {}

    public function frontDeskIndex(): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $school = $this->workspace->currentSchool($actor);
        abort_unless($school !== null, 403);
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $school->id);
        $pending = CentralFinancePendingCollection::on('mysql')->with(['studentProfile', 'receivable'])
            ->where(['school_id' => $school->id, 'collected_by' => $actor->id])->latest('submitted_at')->paginate(20);
        return view('central-finance.pending-collections.front-desk-index', compact('school', 'pending'));
    }

    public function review(int $profile, int $receivable): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $school = $this->workspace->currentSchool($actor);
        abort_unless($school !== null, 403);
        $this->workspace->assertCanSubmitCollectionsSchool($actor, $school->id);
        $profile = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)->findOrFail($profile);
        $receivable = CentralFinanceReceivable::on('mysql')->where(['school_id' => $school->id, 'student_profile_id' => $profile->id])->whereIn('status', [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL])->findOrFail($receivable);
        $accounts = $this->workspace->readableAccounts($actor, $school->id)->filter(fn (CentralFinanceFundAccount $account) => strtoupper($account->currency) === strtoupper($receivable->currency));
        $attemptUuid = (string) Str::uuid();
        session()->put(self::ATTEMPTS_SESSION_KEY.'.'.$attemptUuid, [
            'actor_id' => $actor->id, 'school_id' => $school->id, 'profile_id' => $profile->id, 'receivable_id' => $receivable->id,
        ]);
        return view('central-finance.pending-collections.review', compact('school', 'profile', 'receivable', 'accounts', 'attemptUuid'));
    }

    public function submit(Request $request, int $profile, int $receivable): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $data = $request->validate(['pending_attempt_uuid' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', 'string', 'max:40'], 'payment_reference' => ['nullable', 'string', 'max:100'], 'intended_fund_account_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:2000']]);
        $attempt = session()->get(self::ATTEMPTS_SESSION_KEY.'.'.$data['pending_attempt_uuid']);
        abort_unless(is_array($attempt) && (int) ($attempt['actor_id'] ?? 0) === $actor->id && (int) ($attempt['profile_id'] ?? 0) === $profile && (int) ($attempt['receivable_id'] ?? 0) === $receivable, 403);
        $pending = $this->pending->submit($actor, $profile, $receivable, (float) $data['amount'], $data['payment_method'], CarbonImmutable::now(), 'front-desk:'.$data['pending_attempt_uuid'], $data['intended_fund_account_id'] ?? null, $data['payment_reference'] ?? null, $data['note'] ?? null);
        return redirect()->route('central-finance.pending-collections.front-desk.index')->with('success', __('Pending collection submitted: :no. This is not an official receipt.', ['no' => $pending->acknowledgement_no]));
    }

    public function headFinanceIndex(): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $school = $this->workspace->requireOperatingSchool($actor);
        $pending = CentralFinancePendingCollection::on('mysql')->with(['studentProfile', 'receivable', 'intendedFundAccount'])
            ->where('school_id', $school->id)->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD])->latest('submitted_at')->paginate(30);
        $accounts = $this->workspace->accessibleAccounts($actor, $school->id);
        return view('central-finance.pending-collections.head-finance-index', compact('school', 'pending', 'accounts'));
    }

    public function hold(Request $request, CentralFinancePendingCollection $pending): RedirectResponse { return $this->reviewAction($request, $pending, 'hold'); }
    public function reject(Request $request, CentralFinancePendingCollection $pending): RedirectResponse { return $this->reviewAction($request, $pending, 'reject'); }

    public function confirm(Request $request, CentralFinancePendingCollection $pending): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $data = $request->validate(['fund_account_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail((int) $data['fund_account_id']);
        $this->confirmation->confirm($actor, $pending->id, $account, CarbonImmutable::now(), $data['reason']);
        return back()->with('success', __('Pending collection confirmed and official receipt issued.'));
    }

    private function reviewAction(Request $request, CentralFinancePendingCollection $pending, string $action): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action === 'hold' ? $this->pending->hold($actor, $pending->id, $data['reason']) : $this->pending->reject($actor, $pending->id, $data['reason']);
        return back()->with('success', __('Pending collection updated.'));
    }
}
