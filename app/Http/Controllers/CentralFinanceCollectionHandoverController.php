<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceCollectionHandoverBatch;
use App\Models\CentralFinanceCollectionHandoverItem;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceCollectionHandoverService;
use App\Services\CentralFinanceHeadFinanceHandoverConfirmService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class CentralFinanceCollectionHandoverController extends Controller
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceCollectionHandoverService $handovers,
        private readonly CentralFinanceHeadFinanceHandoverConfirmService $confirmation,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    private function actor(): CentralFinanceUser
    {
        return $this->workspace->actor(Auth::user());
    }

    public function index(): View|RedirectResponse
    {
        $request = request();
        $actor = $this->actor();
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor);
        $canIncludeQaTest = $this->dataIsolation->canIncludeQaTest($actor);
        $school = $this->workspace->currentSchool($actor, $includeQaTest);
        if ($school === null) {
            return redirect()->route('central-finance.dashboard', [
                'return_to' => route('central-finance.collection-handovers.index'),
            ])->with('warning', __('Select an authorized School before opening Collection Handovers.'));
        }

        $isHeadFinance = $this->workspace->canReviewPendingCollections($actor);
        $school = $isHeadFinance
            ? $this->workspace->assertCanOperateSchool($actor, (int) $school->id)
            : $this->workspace->assertCanSubmitCollectionsSchool($actor, (int) $school->id);
        $workflowIncludeQaTest = $includeQaTest || (!$isHeadFinance && $this->dataIsolation->isQaTestSchool((int) $school->id));

        $batchQuery = CentralFinanceCollectionHandoverBatch::on('mysql')
            ->with(['items.pendingCollection.studentProfile'])
            ->where('school_id', $school->id);
        $this->dataIsolation->apply($batchQuery, 'collection_handover', $workflowIncludeQaTest);
        if (!$isHeadFinance) {
            $batchQuery->where('collector_id', $actor->id);
        }
        $batches = $batchQuery->latest()->paginate(20);
        $batches->getCollection()->each(fn (CentralFinanceCollectionHandoverBatch $batch) => $batch->setAttribute(
            'production_eligible', $this->isWorkflowEligible('collection_handover', (int) $batch->id)
        ));
        $productionSchool = $this->isWorkflowEligible('school', (int) $school->id);

        $eligiblePending = collect();
        $accounts = collect();
        if ($isHeadFinance) {
            $accounts = $this->workspace->accessibleAccounts($actor, (int) $school->id, $workflowIncludeQaTest);
        } else {
            $occupiedPendingIds = CentralFinanceCollectionHandoverItem::on('mysql')
                ->where('status', '!=', CentralFinanceCollectionHandoverItem::REMOVED)
                ->whereHas('batch', fn ($query) => $query
                    ->where('school_id', $school->id)
                    ->whereNotIn('status', [CentralFinanceCollectionHandoverBatch::REJECTED, CentralFinanceCollectionHandoverBatch::CANCELLED]))
                ->pluck('pending_collection_id');
            $eligiblePending = CentralFinancePendingCollection::on('mysql')
                ->with('studentProfile')
                ->where('school_id', $school->id)
                ->where('collected_by', $actor->id)
                ->where('status', CentralFinancePendingCollection::SUBMITTED)
                ->whereNotIn('id', $occupiedPendingIds)
                ->orderBy('submitted_at');
            $this->dataIsolation->apply($eligiblePending, 'pending_collection', $workflowIncludeQaTest);
            $eligiblePending = $eligiblePending->get();
        }

        return view('central-finance.collection-handovers.index', compact(
            'school', 'batches', 'eligiblePending', 'accounts', 'isHeadFinance', 'includeQaTest', 'canIncludeQaTest', 'productionSchool'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertProductionSchool();
        $data = $request->validate([
            'payment_channel' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'reference' => ['required', 'string', 'max:100'],
            'declared_handed_over_amount' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $batch = $this->validated(fn () => $this->handovers->create(
            $this->actor(), $data['payment_channel'], $data['currency'], $data['reference'],
            $data['idempotency_key'], (string) $data['declared_handed_over_amount'], $data['note'] ?? null
        ));
        return back()->with('success', __('Handover batch created: :reference', ['reference' => $batch->reference]));
    }

    public function add(Request $request, CentralFinanceCollectionHandoverBatch $batch): RedirectResponse
    {
        $this->dataIsolation->assertWorkflowWritable('collection_handover', (int) $batch->id);
        $data = $request->validate(['pending_collection_id' => ['required', 'integer']]);
        $this->validated(fn () => $this->handovers->add($this->actor(), $batch, (int) $data['pending_collection_id']));
        return back()->with('success', __('Collection added to handover.'));
    }

    public function remove(CentralFinanceCollectionHandoverBatch $batch, CentralFinanceCollectionHandoverItem $item): RedirectResponse
    {
        $this->dataIsolation->assertWorkflowWritable('collection_handover', (int) $batch->id);
        $this->validated(fn () => $this->handovers->remove($this->actor(), $batch, $item));
        return back()->with('success', __('Collection removed from handover.'));
    }

    public function submit(CentralFinanceCollectionHandoverBatch $batch): RedirectResponse
    {
        $this->dataIsolation->assertWorkflowWritable('collection_handover', (int) $batch->id);
        $this->validated(fn () => $this->handovers->submit($this->actor(), $batch));
        return back()->with('success', __('Handover submitted for review.'));
    }

    public function hold(Request $request, CentralFinanceCollectionHandoverBatch $batch): RedirectResponse
    {
        $this->dataIsolation->assertWorkflowWritable('collection_handover', (int) $batch->id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->validated(fn () => $this->handovers->hold($this->actor(), $batch, $data['reason']));
        return back()->with('success', __('Handover placed on hold.'));
    }

    public function reject(Request $request, CentralFinanceCollectionHandoverBatch $batch): RedirectResponse
    {
        $this->dataIsolation->assertWorkflowWritable('collection_handover', (int) $batch->id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->validated(fn () => $this->handovers->reject($this->actor(), $batch, $data['reason']));
        return back()->with('success', __('Handover rejected.'));
    }

    public function cancel(Request $request, CentralFinanceCollectionHandoverBatch $batch): RedirectResponse
    {
        $this->dataIsolation->assertWorkflowWritable('collection_handover', (int) $batch->id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->validated(fn () => $this->handovers->cancel($this->actor(), $batch, $data['reason']));
        return back()->with('success', __('Handover cancelled.'));
    }

    public function confirm(Request $request, CentralFinanceCollectionHandoverBatch $batch): RedirectResponse
    {
        $this->dataIsolation->assertProduction('collection_handover', (int) $batch->id);
        $data = $request->validate([
            'fund_account_id' => ['required', 'integer'],
            'actual_received_amount' => ['required', 'numeric', 'gte:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $account = CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']);
        $this->validated(fn () => $this->confirmation->confirm(
            $this->actor(), $batch->id, $account, (string) $data['actual_received_amount'], $data['reason']
        ));
        return back()->with('success', __('Handover confirmed.'));
    }

    private function validated(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['handover' => [__($exception->getMessage())]]);
        }
    }

    private function assertProductionSchool(): void
    {
        $actor = $this->actor();
        $school = $this->workspace->currentSchool($actor);
        abort_unless($school !== null, 403);
        $this->dataIsolation->assertWorkflowWritable('school', (int) $school->id);
    }

    private function isWorkflowEligible(string $subjectType, int $subjectId): bool
    {
        try {
            $this->dataIsolation->assertWorkflowWritable($subjectType, $subjectId);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
