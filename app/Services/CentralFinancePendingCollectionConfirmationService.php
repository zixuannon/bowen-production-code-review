<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Head Finance confirmation adapter. The canonical payment service remains the only money-posting path. */
final class CentralFinancePendingCollectionConfirmationService
{
    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceSchoolCutoverService $cutovers, private readonly CentralFinancePaymentService $payments, private readonly CentralFinanceDocumentAuditService $audits) {}

    public function confirm(CentralFinanceUser $actor, int $pendingId, CentralFinanceFundAccount $actualAccount, CarbonImmutable $confirmedAt, string $reason): CentralFinancePendingCollection
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A confirmation reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $pendingId, $actualAccount, $confirmedAt, $reason): CentralFinancePendingCollection {
            $pending = CentralFinancePendingCollection::on('mysql')->lockForUpdate()->findOrFail($pendingId);
            $this->workspace->assertHeadFinance($actor);
            $this->workspace->assertCanOperateSchool($actor, (int) $pending->school_id);
            $this->cutovers->assertCentralWritesAllowed((int) $pending->school_id);
            if ($pending->status === CentralFinancePendingCollection::CONFIRMED) return $pending;
            if (!in_array($pending->status, [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD], true)) throw new InvalidArgumentException('Only a submitted or held collection can be confirmed.');
            $before = $pending->only(['status','confirmed_payment_id','confirmed_by','confirmed_at']);
            $result = $this->payments->collect($actor, (int) $pending->receivable_id, $actualAccount, (float) $pending->amount, (string) $pending->payment_method, $confirmedAt, 'pending-collection:'.$pending->pending_collection_uuid, $pending->payment_reference, $pending->note);
            $pending->update(['status' => CentralFinancePendingCollection::CONFIRMED, 'confirmed_payment_id' => $result['payment']->id, 'confirmed_by' => $actor->id, 'confirmed_at' => $confirmedAt, 'reviewed_by' => $actor->id, 'reviewed_at' => $confirmedAt, 'review_reason' => trim($reason)]);
            $this->audits->record($actor, $pending, 'pending_collection', 'confirmed', trim($reason), $before, $pending->only(['status','confirmed_payment_id','confirmed_by','confirmed_at']));
            return $pending->fresh();
        });
    }
}
