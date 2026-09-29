<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceUser;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Head Finance confirmation adapter. The canonical payment service remains the only money-posting path. */
final class CentralFinancePendingCollectionConfirmationService
{
    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceSchoolCutoverService $cutovers, private readonly CentralFinancePaymentService $payments, private readonly CentralFinanceDocumentAuditService $audits, private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability, private readonly CentralFinanceDataIsolationService $dataIsolation) {}

    public function confirm(CentralFinanceUser $actor, int $pendingId, CentralFinanceFundAccount $actualAccount, CarbonImmutable $confirmedAt, string $reason, bool $viaHandover = false): CentralFinancePendingCollection
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A confirmation reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $pendingId, $actualAccount, $confirmedAt, $reason, $viaHandover): CentralFinancePendingCollection {
            $pendingQuery = CentralFinancePendingCollection::on('mysql')->lockForUpdate();
            if (Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')) $pendingQuery->with('allocations');
            $pending = $pendingQuery->findOrFail($pendingId);
            $this->dataIsolation->assertWorkflowWritable('pending_collection', (int) $pending->id);
            $this->workspace->assertHeadFinance($actor);
            $this->workspace->assertCanOperateSchool($actor, (int) $pending->school_id);
            $this->cutovers->assertCentralWritesAllowed((int) $pending->school_id);
            if ($pending->status === CentralFinancePendingCollection::CONFIRMED) return $pending;
            if (!in_array($pending->status, [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD], true)) throw new InvalidArgumentException('Only a submitted or held collection can be confirmed.');
            $actualAccount = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($actualAccount->id);
            $this->availability->assertAccountAvailableForSchool($actualAccount, (int) $pending->school_id);
            $this->dataIsolation->assertFundAccountMatchesSchoolWorkflow((int) $pending->school_id, (int) $actualAccount->id);
            if (strtoupper((string) $actualAccount->currency) !== strtoupper((string) $pending->currency)) {
                throw new InvalidArgumentException('Fund Account currency does not match the Pending Collection.');
            }
            if ($pending->payment_method === CentralFinancePendingCollectionService::METHOD_BANK_TRANSFER) {
                if ($actualAccount->account_type !== 'bank' || (int) $pending->intended_fund_account_id !== (int) $actualAccount->id) {
                    throw new InvalidArgumentException('Bank Transfer must be confirmed against its intended active Bank Fund Account.');
                }
            }
            if ($pending->payment_method === CentralFinancePendingCollectionService::METHOD_CASH) {
                if (!$viaHandover) throw new InvalidArgumentException('Cash collections must be confirmed through a submitted Cash handover.');
                if ($actualAccount->account_type !== 'cash') throw new InvalidArgumentException('Cash handover requires an active Cash Fund Account.');
            }
            $before = $pending->only(['status','confirmed_payment_id','confirmed_by','confirmed_at']);
            $allocations = Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')
                ? $pending->allocations->map(fn ($line) => ['receivable_id' => $line->receivable_id, 'amount' => (string) $line->amount])->all()
                : ($pending->receivable_id === null ? [] : [['receivable_id' => $pending->receivable_id, 'amount' => (string) $pending->amount]]);
            if ($allocations === []) throw new InvalidArgumentException('This Pending Collection has no immutable allocation lines and cannot be confirmed.');
            $allocated = collect($allocations)->reduce(fn (string $sum, array $line): string => CentralFinanceDecimal::add($sum, $line['amount']), CentralFinanceDecimal::normalize('0'));
            if (CentralFinanceDecimal::compare($allocated, (string) $pending->amount) !== 0) {
                throw new InvalidArgumentException('Pending Collection allocation total does not match its parent amount.');
            }
            // The DB timestamp is a Yangon wall-clock collection timestamp.
            // Reparse the stored value in that business timezone instead of
            // converting an ORM/default-timezone cast across midnight.
            $collectedAt = CarbonImmutable::parse((string) $pending->getRawOriginal('collected_at'), 'Asia/Yangon');
            // Confirmation is an audit/review event.  The canonical Payment
            // and Ledger must keep the actual Front Desk collection time as
            // their accounting date, including across a period boundary.
            $result = $this->payments->collectAllocations($actor, $allocations, $actualAccount, (string) $pending->payment_method, $collectedAt, 'pending-collection:'.$pending->pending_collection_uuid, $pending->payment_reference, $pending->note, $confirmedAt);
            $pending->update(['status' => CentralFinancePendingCollection::CONFIRMED, 'confirmed_payment_id' => $result['payment']->id, 'confirmed_by' => $actor->id, 'confirmed_at' => $confirmedAt, 'reviewed_by' => $actor->id, 'reviewed_at' => $confirmedAt, 'review_reason' => trim($reason)]);
            $this->audits->record($actor, $pending, 'pending_collection', 'confirmed', trim($reason), $before, $pending->only(['status','confirmed_payment_id','confirmed_by','confirmed_at']));
            return $pending->fresh();
        });
    }
}
