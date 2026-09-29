<?php

namespace App\Http\Controllers;

use App\Exceptions\FinanceGroupTenantUnavailableException;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceCurrencySummaryService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceOptionalFeeAssignmentService;
use App\Services\CentralFinanceReceiptViewModelFactory;
use App\Services\CentralFinanceSchoolCutoverService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Student-first collection read model. Front Desk collection declarations
 * and Head Finance confirmations have their own explicit lifecycle routes.
 */
final class CentralFinanceStudentCollectionController extends Controller
{
    private const OPTIONAL_ATTEMPTS_SESSION_KEY = 'central_finance_student_optional_item_attempts';

    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceCurrencySummaryService $currencySummaries,
        private readonly CentralFinanceReceiptViewModelFactory $receiptViewModels,
        private readonly CentralFinanceOptionalFeeAssignmentService $optionalFees,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    public function collection(Request $request): View
    {
        $actor = $this->actor();
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor)
            || $this->workspace->isQaTestSchoolContext($actor);
        $canIncludeQaTest = $this->dataIsolation->canIncludeQaTest($actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        $schools = $this->workspace->accessibleSchools($actor, $includeQaTest);
        $schoolFinanceFacade = $this->workspace->usesSchoolFinanceFacade($actor);
        if ($school === null) {
            return view('central-finance.student-collection.index', compact('school', 'schools', 'schoolFinanceFacade', 'includeQaTest', 'canIncludeQaTest'));
        }
        $schoolQaTest = $this->dataIsolation->isQaTestSchool((int) $school->id);

        $search = trim((string) $request->query('search', ''));
        $class = trim((string) $request->query('class', ''));
        $profilesQuery = CentralFinanceStudentProfile::on('mysql')
            ->with(['receivables' => function ($query) use ($includeQaTest, $school, $schoolQaTest): void {
                $this->applySchoolRecordFilter($query, 'receivable', (int) $school->id, $schoolQaTest, $includeQaTest);
            }])
            ->where('school_id', $school->id);
        $this->applySchoolRecordFilter($profilesQuery, 'student_profile', (int) $school->id, $schoolQaTest, $includeQaTest);
        $this->applyStudentMetadataFilter($profilesQuery, (int) $school->id, $schoolQaTest, $includeQaTest);
        $profiles = $profilesQuery
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
        $this->attachPendingConfirmationAmounts($profiles->getCollection(), (int) $school->id, $schoolQaTest, $includeQaTest);
        $profiles->getCollection()->each(function (CentralFinanceStudentProfile $profile) use ($school): void {
            $profile->setAttribute('currency_totals', $this->currencySummaries->receivables($profile->receivables));
            $profile->setAttribute('workflow_eligible', $this->profileIsWorkflowEligible($profile, (int) $school->id));
        });
        $classesQuery = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id);
        $this->applySchoolRecordFilter($classesQuery, 'student_profile', (int) $school->id, $schoolQaTest, $includeQaTest);
        $this->applyStudentMetadataFilter($classesQuery, (int) $school->id, $schoolQaTest, $includeQaTest);
        $classes = $classesQuery->whereNotNull('class_name')->where('class_name', '!=', '')->distinct()->orderBy('class_name')->pluck('class_name');
        $canCollect = $this->canCollect($actor, $school->id);
        $canSubmitPending = $this->canSubmitPending($actor, $school->id);
        $cutoverStatus = $this->cutovers->statusForSchool((int) $school->id);

        return view('central-finance.student-collection.index', compact('school', 'schools', 'profiles', 'classes', 'search', 'class', 'canCollect', 'canSubmitPending', 'cutoverStatus', 'schoolFinanceFacade', 'includeQaTest', 'canIncludeQaTest'));
    }

    public function show(int $profile): View
    {
        $actor = $this->actor();
        $unfilteredProfile = CentralFinanceStudentProfile::on('mysql')->findOrFail($profile);
        $school = $this->workspace->assertCanViewSchool($actor, (int) $unfilteredProfile->school_id);
        $profile = $this->profileForSchool($profile, (int) $school->id);
        $schoolQaTest = $this->dataIsolation->isQaTestSchool((int) $school->id);
        $profile->load(['receivables' => function ($query) use ($school, $schoolQaTest): void {
            $this->applySchoolRecordFilter($query, 'receivable', (int) $school->id, $schoolQaTest, false);
            $query->orderBy('due_date')->with(['payments.receipt', 'payments.refunds', 'payments.fundAccount', 'payments.receivedBy']);
        }]);
        $this->attachPendingConfirmationAmounts(collect([$profile]), (int) $school->id, $schoolQaTest, false);
        $profile->setAttribute('currency_totals', $this->currencySummaries->receivables($profile->receivables));
        // Direct read URLs do not mutate the selected operating context. A
        // write remains available only when this profile belongs to the
        // actor's current, explicitly selected School.
        $currentSchool = $this->workspace->currentSchool($actor);
        $isCurrentSchool = $currentSchool !== null && (int) $currentSchool->id === (int) $school->id;
        $workflowEligible = $this->profileIsWorkflowEligible($profile, (int) $school->id);
        $canCollect = $workflowEligible && $isCurrentSchool && $this->canCollect($actor, $school->id);
        $canSubmitPending = $workflowEligible && $isCurrentSchool && $this->canSubmitPending($actor, $school->id);
        $cutoverStatus = $this->cutovers->statusForSchool((int) $school->id);
        $schoolFinanceFacade = $this->workspace->usesSchoolFinanceFacade($actor);
        $optionalItems = collect();
        $optionalAttemptUuid = null;
        if ($canCollect || $canSubmitPending) {
            try {
                $optionalItems = $this->optionalFees->eligible($actor, $profile);
                if ($optionalItems->isNotEmpty()) {
                    $optionalAttemptUuid = (string) Str::uuid();
                    $this->storeOptionalAttempt($optionalAttemptUuid, $actor, $school->id, $profile->id);
                }
            } catch (AuthorizationException|FinanceGroupTenantUnavailableException|ModelNotFoundException) {
                // A read-capable Principal or an unavailable mapped Head
                // tenant identity/student must never hide the Central profile.
            }
        }

        return view('central-finance.student-collection.show', compact('school', 'profile', 'canCollect', 'canSubmitPending', 'cutoverStatus', 'schoolFinanceFacade', 'optionalItems', 'optionalAttemptUuid'));
    }

    /**
     * Direct posting has been retired.  The Front Desk declares a collection
     * first; Head Finance reviews that immutable declaration in the pending
     * collection workspace.  Keep a safe redirect for old GET bookmarks.
     */
    public function review(int $profile, int $receivable): RedirectResponse
    {
        return redirect()->route('central-finance.pending-collections.index')
            ->with('warning', __('Direct collection posting is retired. Review the Front Desk pending collection instead.'));
    }

    public function collect(Request $request, int $profile, int $receivable): RedirectResponse
    {
        abort(410, __('Direct collection posting is retired. Submit a pending collection and let Head Finance confirm it.'));
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
        [$actor, $school] = $this->optionalItemContext();
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

    private function actor(): CentralFinanceUser
    {
        $user = Auth::user();
        abort_unless($user !== null, 403);
        return $this->workspace->actor($user);
    }

    private function profileForSchool(int $profileId, int $schoolId): CentralFinanceStudentProfile
    {
        $query = CentralFinanceStudentProfile::on('mysql')->where('school_id', $schoolId);
        $schoolQaTest = $this->dataIsolation->isQaTestSchool($schoolId);
        $this->applySchoolRecordFilter($query, 'student_profile', $schoolId, $schoolQaTest, false);
        $this->applyStudentMetadataFilter($query, $schoolId, $schoolQaTest, false);
        return $query->findOrFail($profileId);
    }

    private function profileIsWorkflowEligible(CentralFinanceStudentProfile $profile, int $schoolId): bool
    {
        try {
            $this->dataIsolation->assertWorkflowWritable('student_profile', (int) $profile->id);

            return $this->dataIsolation->isTenantMetadataWorkflowWritable('student', $schoolId, (int) $profile->tenant_student_id);
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function applySchoolRecordFilter($query, string $subjectType, int $schoolId, bool $schoolQaTest, bool $includeQaTest): void
    {
        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }
        if ($schoolQaTest) {
            $this->dataIsolation->applySchoolWorkflow($query, $subjectType, $schoolId);
            return;
        }
        $this->dataIsolation->apply($query, $subjectType, $includeQaTest);
    }

    private function applyStudentMetadataFilter($query, int $schoolId, bool $schoolQaTest, bool $includeQaTest): void
    {
        if ($schoolQaTest) {
            $this->dataIsolation->applyTenantMetadataForSchoolWorkflow($query, 'student', $schoolId, 'tenant_student_id');
            return;
        }
        $this->dataIsolation->applyTenantMetadata($query, 'student', $schoolId, $includeQaTest, 'tenant_student_id');
    }

    /**
     * Pending collections reserve collection capacity but are never treated as
     * canonical paid money.  The read model exposes both values explicitly.
     */
    private function attachPendingConfirmationAmounts(iterable $profiles, int $schoolId, bool $schoolQaTest, bool $includeQaTest): void
    {
        $receivables = collect($profiles)->flatMap(fn (CentralFinanceStudentProfile $profile) => $profile->receivables)->values();
        if ($receivables->isEmpty()) return;

        $query = CentralFinancePendingCollection::on('mysql')
            ->selectRaw('receivable_id, SUM(amount) AS pending_confirmation_amount')
            ->where('school_id', $schoolId)
            ->whereIn('receivable_id', $receivables->pluck('id')->all())
            ->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD]);
        $this->applySchoolRecordFilter($query, 'pending_collection', $schoolId, $schoolQaTest, $includeQaTest);
        $reserved = $query->groupBy('receivable_id')->pluck('pending_confirmation_amount', 'receivable_id');

        $receivables->each(fn (CentralFinanceReceivable $receivable) => $receivable->setAttribute(
            'pending_confirmation_amount',
            (float) ($reserved[$receivable->id] ?? 0),
        ));
    }

    private function canCollect(CentralFinanceUser $actor, int $schoolId): bool
    {
        try {
            $this->dataIsolation->assertWorkflowWritable('school', $schoolId);
            $this->workspace->assertCanOperateSchool($actor, $schoolId);
            $this->workspace->assertHeadFinance($actor);
            return $this->cutovers->allowsCentralWrites($schoolId);
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function canSubmitPending(CentralFinanceUser $actor, int $schoolId): bool
    {
        try {
            $this->dataIsolation->assertWorkflowWritable('school', $schoolId);
            $this->workspace->assertCanSubmitCollectionsSchool($actor, $schoolId);
            return $this->cutovers->allowsCentralWrites($schoolId);
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** @return array{0:CentralFinanceUser,1:\App\Models\School} */
    private function optionalItemContext(): array
    {
        $actor = $this->actor();
        $school = $this->workspace->currentSchool($actor);
        abort_unless($school !== null, 403);
        $this->dataIsolation->assertWorkflowWritable('school', (int) $school->id);
        try {
            $this->workspace->assertCanOperateSchool($actor, $school->id);
            $this->workspace->assertHeadFinance($actor);
        } catch (AuthorizationException) {
            $this->workspace->assertCanSubmitCollectionsSchool($actor, $school->id);
        }
        $this->cutovers->assertCentralWritesAllowed($school->id);
        return [$actor, $school];
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
