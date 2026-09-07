<?php

namespace App\Services;

use App\Models\CentralFinanceCollectionHandoverBatch;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceCollectionHandoverItem;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Atomically confirms every item; downstream money posting remains canonical. */
final class CentralFinanceHeadFinanceHandoverConfirmService
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinancePendingCollectionConfirmationService $pendingConfirmation,
        private readonly CentralFinanceDocumentAuditService $audits,
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinanceFundAccountScopeService $accountScopes,
    ) {}

    public function confirm(CentralFinanceUser $actor, int $batchId, CentralFinanceFundAccount $account, string $actualAmount, string $reason, ?CarbonImmutable $confirmedAt = null): CentralFinanceCollectionHandoverBatch
    {
        if (trim($reason) === '' || !is_numeric($actualAmount) || (float) $actualAmount < 0) throw new InvalidArgumentException('A reason and valid actual amount are required.');
        $confirmedAt ??= CarbonImmutable::now();
        return DB::connection('mysql')->transaction(function () use ($actor, $batchId, $account, $actualAmount, $reason, $confirmedAt) {
            $batch = CentralFinanceCollectionHandoverBatch::on('mysql')->with(['items.pendingCollection', 'items'])->lockForUpdate()->findOrFail($batchId);
            $this->workspace->assertHeadFinance($actor);
            $this->workspace->assertCanOperateSchool($actor, (int) $batch->school_id);
            $this->cutovers->assertCentralWritesAllowed((int) $batch->school_id);
            if ($batch->status === CentralFinanceCollectionHandoverBatch::CONFIRMED) return $batch;
            if ($batch->status !== CentralFinanceCollectionHandoverBatch::SUBMITTED) throw new InvalidArgumentException('Only submitted handovers can be confirmed.');
            $this->assertAccount($actor, $account, $batch);
            $expected = (float) $batch->expected_amount;
            if (abs((float) $actualAmount - $expected) > 0.00005 || abs((float) $batch->declared_handed_over_amount - $expected) > 0.00005) throw new InvalidArgumentException('Expected, declared, and actual amounts must match.');
            foreach ($batch->items->sortBy('id') as $item) {
                $pending = CentralFinancePendingCollection::on('mysql')->lockForUpdate()->findOrFail($item->pending_collection_id);
                if ($pending->status === CentralFinancePendingCollection::CONFIRMED) continue;
                $this->pendingConfirmation->confirm($actor, (int) $pending->id, $account, $confirmedAt, $reason);
                $item->update(['status' => CentralFinanceCollectionHandoverItem::CONFIRMED, 'confirmed_payment_id' => $pending->fresh()->confirmed_payment_id]);
            }
            $before = $batch->toArray();
            $batch->update(['status' => CentralFinanceCollectionHandoverBatch::CONFIRMED, 'actual_handed_over_amount' => $actualAmount, 'difference_amount' => (float) $actualAmount - $expected, 'confirmed_by' => $actor->id, 'confirmed_at' => $confirmedAt]);
            $this->audits->record($actor, $batch, 'collection_handover', 'confirmed', trim($reason), $before, $batch->fresh()->toArray());
            return $batch->fresh();
        });
    }

    private function assertAccount(CentralFinanceUser $actor, CentralFinanceFundAccount $account, CentralFinanceCollectionHandoverBatch $batch): void
    {
        if (!$account->exists || !$account->getRawOriginal('is_active')) throw new InvalidArgumentException('Fund Account is not active.');
        $this->availability->assertAccountAvailableForSchool($account, (int) $batch->school_id);
        $this->accountScopes->assertCanOperate($actor, $account);
        if (strtoupper((string) $account->currency) !== strtoupper((string) $batch->currency)) throw new InvalidArgumentException('Fund Account is outside the handover currency.');
    }
}
