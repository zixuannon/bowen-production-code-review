<?php

namespace App\Services;

use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Creates a non-financial Front Desk declaration. It never calls payment, receipt, ledger, or balance code. */
final class CentralFinancePendingCollectionService
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinanceDocumentAuditService $audits,
    ) {}

    public function submit(CentralFinanceUser $actor, int $profileId, int $receivableId, float $amount, string $method, CarbonImmutable $collectedAt, string $idempotencyReference, ?int $intendedFundAccountId = null, ?string $paymentReference = null, ?string $note = null): CentralFinancePendingCollection
    {
        if ($amount <= 0 || !is_finite($amount) || !preg_match('/^[A-Za-z0-9 _.-]{2,40}$/', $method) || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)) {
            throw new InvalidArgumentException('Pending collection input is invalid.');
        }
        $school = $this->workspace->currentSchool($actor);
        if ($school === null) throw new AuthorizationException('Select an authorized School before submitting a collection.');
        $this->workspace->assertCanSubmitCollectionsSchool($actor, (int) $school->id);
        $this->cutovers->assertCentralWritesAllowed((int) $school->id);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $profileId, $receivableId, $amount, $method, $collectedAt, $idempotencyReference, $intendedFundAccountId, $paymentReference, $note): CentralFinancePendingCollection {
            $profile = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)->lockForUpdate()->findOrFail($profileId);
            $receivable = CentralFinanceReceivable::on('mysql')->where(['school_id' => $school->id, 'student_profile_id' => $profile->id])->lockForUpdate()->findOrFail($receivableId);
            if (!in_array($receivable->status, [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL], true) || (float) $receivable->amount_paid + $amount > (float) $receivable->amount_due) {
                throw new InvalidArgumentException('Pending collection exceeds the current receivable outstanding balance.');
            }
            if ($intendedFundAccountId !== null) {
                $intended = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($intendedFundAccountId);
                $this->availability->assertAccountAvailableForSchool($intended, (int) $school->id);
                if (strtoupper((string) $intended->currency) !== strtoupper((string) $receivable->currency)) {
                    throw new InvalidArgumentException('The intended Fund Account currency does not match the receivable.');
                }
            }
            $key = hash('sha256', 'pending-collection|'.$school->id.'|'.$receivable->id.'|'.$idempotencyReference);
            $existing = CentralFinancePendingCollection::on('mysql')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) return $existing;
            $pending = CentralFinancePendingCollection::on('mysql')->create([
                'school_id' => $school->id, 'student_profile_id' => $profile->id, 'receivable_id' => $receivable->id,
                'intended_fund_account_id' => $intendedFundAccountId, 'idempotency_key' => $key,
                'acknowledgement_no' => 'PCA-'.$school->id.'-'.strtoupper(Str::random(12)),
                'status' => CentralFinancePendingCollection::SUBMITTED, 'amount' => $amount, 'currency' => $receivable->currency,
                'payment_method' => $method, 'payment_reference' => $paymentReference ? trim($paymentReference) : null, 'note' => $note ? trim($note) : null,
                'collected_at' => $collectedAt, 'collected_by' => $actor->id, 'submitted_by' => $actor->id, 'submitted_at' => now(),
            ]);
            $this->audits->record($actor, $pending, 'pending_collection', 'submitted', null, null, $this->snapshot($pending));
            return $pending;
        });
    }

    public function hold(CentralFinanceUser $actor, int $pendingId, string $reason): CentralFinancePendingCollection { return $this->review($actor, $pendingId, CentralFinancePendingCollection::HELD, $reason); }
    public function reject(CentralFinanceUser $actor, int $pendingId, string $reason): CentralFinancePendingCollection { return $this->review($actor, $pendingId, CentralFinancePendingCollection::REJECTED, $reason); }

    private function review(CentralFinanceUser $actor, int $pendingId, string $target, string $reason): CentralFinancePendingCollection
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A review reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $pendingId, $target, $reason): CentralFinancePendingCollection {
            $pending = CentralFinancePendingCollection::on('mysql')->lockForUpdate()->findOrFail($pendingId);
            $this->workspace->assertHeadFinance($actor);
            $this->workspace->assertCanOperateSchool($actor, (int) $pending->school_id);
            $this->cutovers->assertCentralWritesAllowed((int) $pending->school_id);
            if (!in_array($pending->status, [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD], true)) throw new InvalidArgumentException('Only a submitted or held collection can be reviewed.');
            $before = $this->snapshot($pending);
            $pending->update(['status' => $target, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => trim($reason)]);
            $this->audits->record($actor, $pending, 'pending_collection', $target, trim($reason), $before, $this->snapshot($pending));
            return $pending->fresh();
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(CentralFinancePendingCollection $pending): array { return $pending->only(['student_profile_id','receivable_id','intended_fund_account_id','confirmed_payment_id','status','amount','currency','payment_method','payment_reference','collected_at','collected_by','submitted_by','submitted_at','reviewed_by','reviewed_at','review_reason','confirmed_by','confirmed_at']); }
}
