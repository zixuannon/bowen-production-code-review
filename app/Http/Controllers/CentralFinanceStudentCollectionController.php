<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceCurrencySummaryService;
use App\Services\CentralFinanceOptionalFeeAssignmentService;
use App\Services\CentralFinancePaymentService;
use App\Services\CentralFinanceReceiptViewModelFactory;
use App\Services\CentralFinanceSchoolCutoverService;
use App\Services\CentralFinanceWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Student-first collection flow.  It deliberately delegates every money
 * mutation to CentralFinancePaymentService, which owns canonical receipt and
 * ledger posting semantics.
 */
final class CentralFinanceStudentCollectionController extends Controller
{
    private const ATTEMPTS_SESSION_KEY = 'central_finance_student_collection_attempts';
    private const OPTIONAL_ATTEMPTS_SESSION_KEY = 'central_finance_student_optional_item_attempts';

    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinancePaymentService $payments,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceCurrencySummaryService $currencySummaries,
        private readonly CentralFinanceReceiptViewModelFactory $receiptViewModels,
        private readonly CentralFinanceOptionalFeeAssignmentService $optionalFees,
    ) {}

    public function collection(Request $request): View
    {
        $actor = $this->actor();
        $school = $this->workspace->currentSchool($actor);
        $schools = $this->workspace->accessibleSchools($actor);
        $schoolFinanceFacade = $this->workspace->usesSchoolFinanceFacade($actor);
        if ($school === null) {
            return view('central-finance.student-collection.index', compact('school', 'schools', 'schoolFinanceFacade'));
        }

        $search = trim((string) $request->query('search', ''));
        $class = trim((string) $request->query('class', ''));
        $profiles = CentralFinanceStudentProfile::on('mysql')
            ->with('receivables')
            ->where('school_id', $school->id)
            ->when($class !== '', fn ($query) => $query->where('class_name', $class))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('student_name', 'like', "%{$search}%")
                    ->orWhere('admission_no', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%")
                    ->orWhere('guardian_name', 'like', "%{$search}%")
                    ->orWhere('guardian_mobile', 'like', "%{$search}%");
            }))
            ->orderBy('student_name')
            ->paginate(20)
            ->withQueryString();
        $profiles->getCollection()->each(fn (CentralFinanceStudentProfile $profile) => $profile->setAttribute('currency_totals', $this->currencySummaries->receivables($profile->receivables)));
        $classes = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)
            ->whereNotNull('class_name')->where('class_name', '!=', '')->distinct()->orderBy('class_name')->pluck('class_name');
        $canCollect = $this->canCollect($actor, $school->id);
        $cutoverStatus = $this->cutovers->statusForSchool((int) $school->id);

        return view('central-finance.student-collection.index', compact('school', 'schools', 'profiles', 'classes', 'search', 'class', 'canCollect', 'cutoverStatus', 'schoolFinanceFacade'));
    }

    public function show(int $profile): View
    {
        [$actor, $school] = $this->readContext();
        $profile = $this->profileForSchool($profile, $school->id);
        $profile->load(['receivables' => fn ($query) => $query->orderBy('due_date')->with(['payments.receipt', 'payments.refunds', 'payments.fundAccount', 'payments.receivedBy'])]);
        $profile->setAttribute('currency_totals', $this->currencySummaries->receivables($profile->receivables));
        $canCollect = $this->canCollect($actor, $school->id);
        $cutoverStatus = $this->cutovers->statusForSchool((int) $school->id);
        $schoolFinanceFacade = $this->workspace->usesSchoolFinanceFacade($actor);
        $optionalItems = collect();
        $optionalAttemptUuid = null;
        if ($canCollect) {
            try {
                $optionalItems = $this->optionalFees->eligible($actor, $profile);
                Log::warning('Central optional fee eligibility resolved.', [
                    'central_actor_id' => $actor->id,
                    'school_id' => $school->id,
                    'student_profile_id' => $profile->id,
                    'eligible_item_count' => $optionalItems->count(),
                ]);
                if ($optionalItems->isNotEmpty()) {
                    $optionalAttemptUuid = (string) Str::uuid();
                    $this->storeOptionalAttempt($optionalAttemptUuid, $actor, $school->id, $profile->id);
                }
            } catch (AuthorizationException $exception) {
                // A read-capable Principal or an unavailable mapped Head
                // tenant identity must never turn this read page into a 500.
                Log::warning('Central optional fee eligibility was denied.', [
                    'central_actor_id' => $actor->id,
                    'school_id' => $school->id,
                    'student_profile_id' => $profile->id,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return view('central-finance.student-collection.show', compact('school', 'profile', 'canCollect', 'cutoverStatus', 'schoolFinanceFacade', 'optionalItems', 'optionalAttemptUuid'));
    }

    public function review(int $profile, int $receivable): View
    {
        [$actor, $school] = $this->operatingContext();
        $profile = $this->profileForSchool($profile, $school->id);
        $receivable = $this->collectableReceivable($receivable, $profile);
        $attemptUuid = (string) Str::uuid();
        $this->storeAttempt($attemptUuid, $actor, $school->id, $profile->id, $receivable->id);
        $accounts = $this->workspace->accessibleAccounts($actor, $school->id)
            ->filter(fn (CentralFinanceFundAccount $account) => strtoupper($account->currency) === strtoupper($receivable->currency));
        $cutoverStatus = $this->cutovers->statusForSchool((int) $school->id);
        $schoolFinanceFacade = $this->workspace->usesSchoolFinanceFacade($actor);

        return view('central-finance.student-collection.review', compact('school', 'profile', 'receivable', 'accounts', 'attemptUuid', 'cutoverStatus', 'schoolFinanceFacade'));
    }

    public function collect(Request $request, int $profile, int $receivable): RedirectResponse
    {
        [$actor, $school] = $this->operatingContext();
        $profile = $this->profileForSchool($profile, $school->id);
        $receivable = $this->collectableReceivable($receivable, $profile);
        $data = $request->validate([
            'payment_attempt_uuid' => ['required', 'uuid'],
            'fund_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'max:40'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->assertAttempt($data['payment_attempt_uuid'], $actor, $school->id, $profile->id, $receivable->id);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']);
        $result = $this->payments->collect(
            $actor,
            $receivable->id,
            $account,
            (float) $data['amount'],
            $data['payment_method'],
            CarbonImmutable::now(),
            'student-collection-'.$data['payment_attempt_uuid'],
            $data['payment_reference'] ?: null,
            $data['note'] ?: null,
        );

        return redirect()->route('central-finance.student-collection.success', $result['payment']->id);
    }

    public function success(int $payment): View
    {
        [$actor, $school] = $this->readContext();
        $payment = CentralFinancePayment::on('mysql')->with(['receipt', 'receivable.studentProfile', 'fundAccount', 'receivedBy'])
            ->where('school_id', $school->id)->findOrFail($payment);
        $receipt = $this->receiptViewModels->make($payment, $school);
        $cutoverStatus = $this->cutovers->statusForSchool((int) $school->id);
        $schoolFinanceFacade = $this->workspace->usesSchoolFinanceFacade($actor);

        return view('central-finance.student-collection.success', compact('school', 'payment', 'receipt', 'cutoverStatus', 'schoolFinanceFacade'));
    }

    public function addOptionalItems(Request $request, int $profile): RedirectResponse
    {
        [$actor, $school] = $this->operatingContext();
        $profile = $this->profileForSchool($profile, $school->id);
        $data = $request->validate([
            'optional_attempt_uuid' => ['required', 'uuid'],
            'optional_fee_ids' => ['required', 'array', 'min:1'],
            'optional_fee_ids.*' => ['required', 'integer'],
        ]);
        $attempt = $this->assertOptionalAttempt($data['optional_attempt_uuid'], $actor, $school->id, $profile->id);
        if (!empty($attempt['receivableIds'])) {
            return redirect()->route('central-finance.student-collection.show', $profile->id)
                ->with('success', __('Optional fee items are already available in Student Collection.'));
        }
        $receivables = $this->optionalFees->add($actor, $profile, $data['optional_fee_ids']);
        session()->put(self::OPTIONAL_ATTEMPTS_SESSION_KEY.'.'.$data['optional_attempt_uuid'].'.receivableIds', $receivables->pluck('id')->all());

        return redirect()->route('central-finance.student-collection.show', $profile->id)
            ->with('success', __('Optional fee items were added to Student Collection.'));
    }

    /** @return array{0: CentralFinanceUser, 1: \App\Models\School} */
    private function readContext(): array
    {
        $actor = $this->actor();
        $school = $this->workspace->currentSchool($actor);
        if ($school === null) throw new AuthorizationException('Select an authorized School before viewing a Student collection record.');
        return [$actor, $school];
    }

    /** @return array{0: CentralFinanceUser, 1: \App\Models\School} */
    private function operatingContext(): array
    {
        $actor = $this->actor();
        $school = $this->workspace->requireOperatingSchool($actor);
        $this->cutovers->assertCentralWritesAllowed($school->id);
        return [$actor, $school];
    }

    private function actor(): CentralFinanceUser
    {
        $user = Auth::user();
        abort_unless($user !== null, 403);
        return $this->workspace->actor($user);
    }

    private function profileForSchool(int $profileId, int $schoolId): CentralFinanceStudentProfile
    {
        return CentralFinanceStudentProfile::on('mysql')->where('school_id', $schoolId)->findOrFail($profileId);
    }

    private function collectableReceivable(int $receivableId, CentralFinanceStudentProfile $profile): CentralFinanceReceivable
    {
        return CentralFinanceReceivable::on('mysql')->where('school_id', $profile->school_id)
            ->where('student_profile_id', $profile->id)
            ->whereIn('status', [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL])
            ->findOrFail($receivableId);
    }

    private function canCollect(CentralFinanceUser $actor, int $schoolId): bool
    {
        try {
            $this->workspace->assertCanOperateSchool($actor, $schoolId);
            return $this->cutovers->allowsCentralWrites($schoolId);
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function storeAttempt(string $attemptUuid, CentralFinanceUser $actor, int $schoolId, int $profileId, int $receivableId): void
    {
        session()->put(self::ATTEMPTS_SESSION_KEY.'.'.$attemptUuid, compact('schoolId', 'profileId', 'receivableId') + ['actorId' => $actor->id]);
    }

    private function assertAttempt(string $attemptUuid, CentralFinanceUser $actor, int $schoolId, int $profileId, int $receivableId): void
    {
        $attempt = session(self::ATTEMPTS_SESSION_KEY.'.'.$attemptUuid);
        abort_unless(is_array($attempt)
            && (int) ($attempt['actorId'] ?? 0) === (int) $actor->id
            && (int) ($attempt['schoolId'] ?? 0) === $schoolId
            && (int) ($attempt['profileId'] ?? 0) === $profileId
            && (int) ($attempt['receivableId'] ?? 0) === $receivableId, 403);
    }

    private function storeOptionalAttempt(string $attemptUuid, CentralFinanceUser $actor, int $schoolId, int $profileId): void
    {
        session()->put(self::OPTIONAL_ATTEMPTS_SESSION_KEY.'.'.$attemptUuid, compact('schoolId', 'profileId') + ['actorId' => $actor->id]);
    }

    /** @return array<string,mixed> */
    private function assertOptionalAttempt(string $attemptUuid, CentralFinanceUser $actor, int $schoolId, int $profileId): array
    {
        $attempt = session(self::OPTIONAL_ATTEMPTS_SESSION_KEY.'.'.$attemptUuid);
        abort_unless(is_array($attempt)
            && (int) ($attempt['actorId'] ?? 0) === (int) $actor->id
            && (int) ($attempt['schoolId'] ?? 0) === $schoolId
            && (int) ($attempt['profileId'] ?? 0) === $profileId, 403);
        return $attempt;
    }
}
