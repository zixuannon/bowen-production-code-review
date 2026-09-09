<?php

namespace App\Services;

use App\Models\CentralFinanceCollectionHandoverBatch;
use App\Models\CentralFinanceCollectionHandoverItem;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Owns custody/reconciliation only; it never posts Payment, Receipt, or Ledger. */
final class CentralFinanceCollectionHandoverService
{
    public const MAX_ITEMS = 50;

    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceDocumentAuditService $audits,
    ) {}

    public function create(CentralFinanceUser $actor, string $channel, string $currency, string $reference, string $idempotencyKey, string $declaredAmount, ?string $note = null): CentralFinanceCollectionHandoverBatch
    {
        $school = $this->school($actor);
        $this->assertChannel($channel);
        $this->assertRequired($reference, 'A handover reference is required.');
        $this->assertRequired($idempotencyKey, 'A handover idempotency key is required.');
        $this->assertAmount($declaredAmount);
        return DB::connection('mysql')->transaction(function () use ($actor, $school, $channel, $currency, $reference, $idempotencyKey, $declaredAmount, $note) {
            $existing = CentralFinanceCollectionHandoverBatch::on('mysql')->where('idempotency_key', hash('sha256', $idempotencyKey))->lockForUpdate()->first();
            if ($existing) {
                $sameRequest = (int) $existing->school_id === (int) $school->id
                    && (int) $existing->collector_id === (int) $actor->id
                    && (string) $existing->payment_channel === $channel
                    && strtoupper((string) $existing->currency) === strtoupper($currency)
                    && (string) $existing->reference === trim($reference)
                    && bccomp((string) $existing->declared_handed_over_amount, $declaredAmount, 4) === 0;
                if (!$sameRequest) throw new InvalidArgumentException('The handover idempotency key belongs to a different request.');
                return $existing;
            }
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->create([
                'school_id' => $school->id, 'collector_id' => $actor->id, 'payment_channel' => $channel,
                'currency' => strtoupper($currency), 'reference' => trim($reference),
                'idempotency_key' => hash('sha256', $idempotencyKey), 'declared_handed_over_amount' => $declaredAmount,
                'expected_amount' => 0, 'status' => CentralFinanceCollectionHandoverBatch::DRAFT, 'note' => $note,
            ]);
            $this->audits->record($actor, $batch, 'collection_handover', 'draft', null, null, $batch->toArray());
            return $batch;
        });
    }

    public function add(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch, int $pendingId): CentralFinanceCollectionHandoverItem
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $batch, $pendingId) {
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->lockForUpdate()->findOrFail($batch->id);
            $this->assertOwner($actor, $batch);
            if ($batch->status !== CentralFinanceCollectionHandoverBatch::DRAFT) throw new InvalidArgumentException('Only draft handovers can be edited.');
            if ($batch->items()->where('status', CentralFinanceCollectionHandoverItem::ATTACHED)->count() >= self::MAX_ITEMS) throw new InvalidArgumentException('A handover cannot contain more than 50 collections.');
            $pending = CentralFinancePendingCollection::on('mysql')->lockForUpdate()->findOrFail($pendingId);
            if ((int) $pending->school_id !== (int) $batch->school_id || (int) $pending->collected_by !== (int) $batch->collector_id || strtoupper((string) $pending->currency) !== strtoupper((string) $batch->currency) || (string) $pending->payment_method !== (string) $batch->payment_channel || $pending->status !== CentralFinancePendingCollection::SUBMITTED) {
                throw new InvalidArgumentException('Pending collection is not eligible for this handover.');
            }
            if (CentralFinanceCollectionHandoverItem::on('mysql')->where('pending_collection_id', $pending->id)
                ->where('handover_batch_id', '!=', $batch->id)
                ->where('status', '!=', CentralFinanceCollectionHandoverItem::REMOVED)
                ->whereHas('batch', fn ($q) => $q->whereNotIn('status', [CentralFinanceCollectionHandoverBatch::REJECTED, CentralFinanceCollectionHandoverBatch::CANCELLED]))->exists()) {
                throw new InvalidArgumentException('Pending collection already belongs to a handover.');
            }
            $existing = $batch->items()->where('pending_collection_id', $pending->id)->first();
            if ($existing) {
                if ($existing->status === CentralFinanceCollectionHandoverItem::REMOVED) {
                    $existing->update(['status' => CentralFinanceCollectionHandoverItem::ATTACHED, 'failure_reason' => null]);
                    $this->audits->record($actor, $batch, 'collection_handover', 'item_reattached', null, null, ['pending_collection_id' => $pending->id]);
                }
                return $existing->fresh();
            }
            $item = CentralFinanceCollectionHandoverItem::on('mysql')->create(['handover_batch_id' => $batch->id, 'pending_collection_id' => $pending->id, 'expected_amount_snapshot' => $pending->amount, 'currency_snapshot' => $pending->currency, 'status' => CentralFinanceCollectionHandoverItem::ATTACHED]);
            $this->audits->record($actor, $batch, 'collection_handover', 'item_attached', null, null, ['pending_collection_id' => $pending->id]);
            return $item;
        });
    }

    public function remove(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch, CentralFinanceCollectionHandoverItem $item): CentralFinanceCollectionHandoverItem
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $batch, $item) {
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->lockForUpdate()->findOrFail($batch->id);
            $this->assertOwner($actor, $batch);
            if ($batch->status !== CentralFinanceCollectionHandoverBatch::DRAFT) throw new InvalidArgumentException('Only draft handovers can be edited.');
            $item = CentralFinanceCollectionHandoverItem::on('mysql')->lockForUpdate()->findOrFail($item->id);
            if ((int) $item->handover_batch_id !== (int) $batch->id || $item->status !== CentralFinanceCollectionHandoverItem::ATTACHED) {
                throw new InvalidArgumentException('The handover item cannot be removed.');
            }
            $item->update(['status' => CentralFinanceCollectionHandoverItem::REMOVED]);
            $this->audits->record($actor, $batch, 'collection_handover', 'item_removed', null, ['pending_collection_id' => $item->pending_collection_id], null);
            return $item->fresh();
        });
    }

    public function submit(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch): CentralFinanceCollectionHandoverBatch
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $batch) {
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->lockForUpdate()->with('items')->findOrFail($batch->id);
            $this->assertOwner($actor, $batch);
            $activeItems = $batch->items->where('status', CentralFinanceCollectionHandoverItem::ATTACHED);
            if ($batch->status !== CentralFinanceCollectionHandoverBatch::DRAFT || $activeItems->isEmpty()) throw new InvalidArgumentException('A non-empty draft handover is required.');
            $expected = $activeItems->sum(fn ($item) => (float) $item->expected_amount_snapshot);
            if (bccomp((string) $expected, (string) $batch->declared_handed_over_amount, 4) !== 0) throw new InvalidArgumentException('Declared amount must equal the server-calculated expected amount.');
            $batch->update(['status' => CentralFinanceCollectionHandoverBatch::SUBMITTED, 'expected_amount' => $expected, 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audits->record($actor, $batch, 'collection_handover', 'submitted', null, null, $batch->fresh()->toArray());
            return $batch->fresh();
        });
    }

    public function hold(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch, string $reason): CentralFinanceCollectionHandoverBatch
    {
        return $this->review($actor, $batch, CentralFinanceCollectionHandoverBatch::HELD, $reason);
    }

    public function reject(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch, string $reason): CentralFinanceCollectionHandoverBatch
    {
        return $this->review($actor, $batch, CentralFinanceCollectionHandoverBatch::REJECTED, $reason);
    }

    public function cancel(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch, string $reason): CentralFinanceCollectionHandoverBatch
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A cancellation reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $batch, $reason) {
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->lockForUpdate()->findOrFail($batch->id);
            $this->assertOwner($actor, $batch);
            if ($batch->status !== CentralFinanceCollectionHandoverBatch::DRAFT) throw new InvalidArgumentException('Only draft handovers can be cancelled.');
            $batch->update(['status' => CentralFinanceCollectionHandoverBatch::CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancelled_reason' => trim($reason)]);
            $this->audits->record($actor, $batch, 'collection_handover', 'cancelled', trim($reason), null, $batch->fresh()->toArray());
            return $batch->fresh();
        });
    }

    private function review(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch, string $status, string $reason): CentralFinanceCollectionHandoverBatch
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A review reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $batch, $status, $reason) {
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->lockForUpdate()->findOrFail($batch->id);
            $this->workspace->assertHeadFinance($actor);
            $this->workspace->assertCanOperateSchool($actor, (int) $batch->school_id);
            if (!in_array($batch->status, [CentralFinanceCollectionHandoverBatch::SUBMITTED, CentralFinanceCollectionHandoverBatch::HELD], true)) {
                throw new InvalidArgumentException('Only submitted or held handovers can be reviewed.');
            }
            $now = now();
            $fields = ['status' => $status, 'reviewed_by' => $actor->id, 'reviewed_at' => $now];
            if ($status === CentralFinanceCollectionHandoverBatch::HELD) $fields += ['held_by' => $actor->id, 'held_at' => $now, 'held_reason' => trim($reason)];
            if ($status === CentralFinanceCollectionHandoverBatch::REJECTED) $fields += ['rejected_by' => $actor->id, 'rejected_at' => $now, 'rejected_reason' => trim($reason)];
            $before = $batch->toArray();
            $batch->update($fields);
            $this->audits->record($actor, $batch, 'collection_handover', $status, trim($reason), $before, $batch->fresh()->toArray());
            return $batch->fresh();
        });
    }

    private function school(CentralFinanceUser $actor) { $school = $this->workspace->currentSchool($actor); if (!$school) throw new AuthorizationException('Select an authorized School.'); $this->workspace->assertCanSubmitCollectionsSchool($actor, (int) $school->id); $this->cutovers->assertCentralWritesAllowed((int) $school->id); return $school; }
    private function assertOwner(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch): void { $this->school($actor); if ((int) $batch->collector_id !== (int) $actor->id) throw new AuthorizationException('Only the submitting collector may edit this handover.'); }
    private function assertChannel(string $channel): void { if (!in_array($channel, ['Cash', 'Bank Transfer', 'QR / Wallet'], true)) throw new InvalidArgumentException('Unsupported payment channel.'); }
    private function assertRequired(string $value, string $message): void { if (trim($value) === '') throw new InvalidArgumentException($message); }
    private function assertAmount(string $value): void { if (!is_numeric($value) || (float) $value <= 0) throw new InvalidArgumentException('Amount is invalid.'); }
}
