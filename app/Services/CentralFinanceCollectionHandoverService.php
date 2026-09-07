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
            if ($existing) return $existing;
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->create([
                'school_id' => $school->id, 'collector_id' => $actor->id, 'payment_channel' => $channel,
                'currency' => strtoupper($currency), 'reference' => trim($reference),
                'idempotency_key' => hash('sha256', $idempotencyKey), 'declared_handed_over_amount' => $declaredAmount,
                'status' => CentralFinanceCollectionHandoverBatch::DRAFT, 'note' => $note,
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
            if ($batch->items()->count() >= self::MAX_ITEMS) throw new InvalidArgumentException('A handover cannot contain more than 50 collections.');
            $pending = CentralFinancePendingCollection::on('mysql')->lockForUpdate()->findOrFail($pendingId);
            if ((int) $pending->school_id !== (int) $batch->school_id || (int) $pending->collected_by !== (int) $batch->collector_id || strtoupper((string) $pending->currency) !== strtoupper((string) $batch->currency) || (string) $pending->payment_method !== (string) $batch->payment_channel || $pending->status !== CentralFinancePendingCollection::SUBMITTED) {
                throw new InvalidArgumentException('Pending collection is not eligible for this handover.');
            }
            if ($batch->items()->where('pending_collection_id', $pending->id)->exists()) return $batch->items()->where('pending_collection_id', $pending->id)->first();
            if (CentralFinanceCollectionHandoverItem::on('mysql')->where('pending_collection_id', $pending->id)->whereHas('batch', fn ($q) => $q->whereNotIn('status', [CentralFinanceCollectionHandoverBatch::REJECTED, CentralFinanceCollectionHandoverBatch::CANCELLED]))->exists()) throw new InvalidArgumentException('Pending collection already belongs to a handover.');
            return CentralFinanceCollectionHandoverItem::on('mysql')->create(['handover_batch_id' => $batch->id, 'pending_collection_id' => $pending->id, 'expected_amount_snapshot' => $pending->amount, 'currency_snapshot' => $pending->currency, 'status' => CentralFinanceCollectionHandoverItem::ATTACHED]);
        });
    }

    public function submit(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch): CentralFinanceCollectionHandoverBatch
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $batch) {
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->lockForUpdate()->with('items')->findOrFail($batch->id);
            $this->assertOwner($actor, $batch);
            if ($batch->status !== CentralFinanceCollectionHandoverBatch::DRAFT || $batch->items->isEmpty()) throw new InvalidArgumentException('A non-empty draft handover is required.');
            $expected = $batch->items->sum(fn ($item) => (float) $item->expected_amount_snapshot);
            if (bccomp((string) $expected, (string) $batch->declared_handed_over_amount, 4) !== 0) throw new InvalidArgumentException('Declared amount must equal the server-calculated expected amount.');
            $batch->update(['status' => CentralFinanceCollectionHandoverBatch::SUBMITTED, 'expected_amount' => $expected, 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audits->record($actor, $batch, 'collection_handover', 'submitted', null, null, $batch->fresh()->toArray());
            return $batch->fresh();
        });
    }

    private function school(CentralFinanceUser $actor) { $school = $this->workspace->currentSchool($actor); if (!$school) throw new AuthorizationException('Select an authorized School.'); $this->workspace->assertCanSubmitCollectionsSchool($actor, (int) $school->id); $this->cutovers->assertCentralWritesAllowed((int) $school->id); return $school; }
    private function assertOwner(CentralFinanceUser $actor, CentralFinanceCollectionHandoverBatch $batch): void { $this->school($actor); if ((int) $batch->collector_id !== (int) $actor->id) throw new AuthorizationException('Only the submitting collector may edit this handover.'); }
    private function assertChannel(string $channel): void { if (!in_array($channel, ['Cash', 'Bank Transfer', 'QR / Wallet'], true)) throw new InvalidArgumentException('Unsupported payment channel.'); }
    private function assertRequired(string $value, string $message): void { if (trim($value) === '') throw new InvalidArgumentException($message); }
    private function assertAmount(string $value): void { if (!is_numeric($value) || (float) $value <= 0) throw new InvalidArgumentException('Amount is invalid.'); }
}
