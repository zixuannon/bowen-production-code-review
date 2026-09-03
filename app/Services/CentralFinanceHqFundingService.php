<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceHqFundingRequest;
use App\Models\CentralFinanceInternalTransfer;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** HQ↔School funding is a pending Central document, never income or expense. */
final class CentralFinanceHqFundingService
{
    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinanceLedgerService $ledger,
        private readonly CentralFinanceDocumentAuditService $audits,
        private readonly CentralFinanceInternalTransferService $transfers,
    ) {}

    public function request(CentralFinanceUser $actor, int $schoolId, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination, float $amount, CarbonImmutable $occurredAt, string $idempotencyReference, ?string $referenceNo = null): CentralFinanceHqFundingRequest
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
        $this->assertInput($amount, $idempotencyReference, $referenceNo);
        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $source, $destination, $amount, $occurredAt, $idempotencyReference, $referenceNo): CentralFinanceHqFundingRequest {
            $this->schools->assertCanOperate($actor, $schoolId);
            $this->accounts->assertCanOperate($actor, $source);
            $source = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($source->id);
            $destination = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($destination->id);
            $direction = $this->direction($schoolId, $source, $destination);
            $key = hash('sha256', 'central_hq_funding|'.$schoolId.'|'.$idempotencyReference);
            $existing = CentralFinanceHqFundingRequest::on('mysql')->where('idempotency_key', $key)->first();
            if ($existing !== null) return $existing;
            $this->assertReferenceFree($schoolId, $referenceNo);
            $funding = CentralFinanceHqFundingRequest::on('mysql')->create([
                'school_id' => $schoolId, 'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
                'direction' => $direction, 'idempotency_key' => $key, 'reference_no' => $referenceNo,
                'funding_date' => $occurredAt->toDateString(), 'currency' => strtoupper($source->currency), 'amount' => $amount,
                'status' => CentralFinanceHqFundingRequest::PENDING, 'requested_by' => $actor->id,
            ]);
            $this->audits->record($actor, $funding, 'hq_funding', 'requested', null, null, $this->snapshot($funding));
            return $funding;
        });
    }

    public function confirm(CentralFinanceUser $headFinance, int $fundingId, CarbonImmutable $occurredAt): CentralFinanceHqFundingRequest
    {
        return DB::connection('mysql')->transaction(function () use ($headFinance, $fundingId, $occurredAt): CentralFinanceHqFundingRequest {
            $funding = CentralFinanceHqFundingRequest::on('mysql')->lockForUpdate()->findOrFail($fundingId);
            if ($funding->status !== CentralFinanceHqFundingRequest::PENDING) throw new InvalidArgumentException('Only a pending HQ funding request can be confirmed.');
            $this->schools->assertCanConfirmFunding($headFinance, $funding->school_id);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $funding->school_id);
            $source = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($funding->source_account_id);
            $destination = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($funding->destination_account_id);
            $this->accounts->assertCanOperate($headFinance, $source);
            $this->accounts->assertCanOperate($headFinance, $destination);
            $this->direction($funding->school_id, $source, $destination);
            $transfer = CentralFinanceInternalTransfer::on('mysql')->create([
                'school_id' => $funding->school_id, 'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
                'source_type' => 'hq_funding', 'source_id' => $funding->funding_uuid, 'reference_no' => $funding->reference_no,
                'transfer_date' => $occurredAt->toDateString(), 'currency' => strtoupper($source->currency), 'amount' => $funding->amount,
                'status' => 'confirmed', 'created_by' => $funding->requested_by, 'confirmed_by' => $headFinance->id, 'confirmed_at' => $occurredAt,
            ]);
            $this->ledger->recordInternalTransfer($headFinance, $source, $destination, $funding->school_id, 'central_internal_transfer', $transfer->transfer_uuid, (float) $funding->amount, $occurredAt, $funding->reference_no);
            $before = $this->snapshot($funding);
            $funding->status = CentralFinanceHqFundingRequest::CONFIRMED; $funding->resolved_by = $headFinance->id; $funding->resolved_at = $occurredAt; $funding->internal_transfer_id = $transfer->id; $funding->save();
            $this->audits->record($headFinance, $funding, 'hq_funding', 'confirmed', null, $before, $this->snapshot($funding));
            return $funding;
        });
    }

    public function reject(CentralFinanceUser $headFinance, int $fundingId, string $reason, CarbonImmutable $occurredAt): CentralFinanceHqFundingRequest { return $this->resolve($headFinance, $fundingId, CentralFinanceHqFundingRequest::REJECTED, $reason, $occurredAt, true); }
    public function cancel(CentralFinanceUser $actor, int $fundingId, string $reason, CarbonImmutable $occurredAt): CentralFinanceHqFundingRequest { return $this->resolve($actor, $fundingId, CentralFinanceHqFundingRequest::CANCELLED, $reason, $occurredAt, false); }

    public function reverse(CentralFinanceUser $headFinance, int $fundingId, string $reason, CarbonImmutable $occurredAt): CentralFinanceHqFundingRequest
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A HQ funding reversal reason is required.');
        return DB::connection('mysql')->transaction(function () use ($headFinance, $fundingId, $reason, $occurredAt): CentralFinanceHqFundingRequest {
            $funding = CentralFinanceHqFundingRequest::on('mysql')->lockForUpdate()->findOrFail($fundingId);
            if ($funding->status !== CentralFinanceHqFundingRequest::CONFIRMED || $funding->internal_transfer_id === null || $funding->reversal_internal_transfer_id !== null) throw new InvalidArgumentException('Only an unreversed confirmed HQ funding document can be reversed.');
            $this->schools->assertCanConfirmFunding($headFinance, (int) $funding->school_id);
            $reversal = $this->transfers->reverse($headFinance, (int) $funding->internal_transfer_id, $reason, $occurredAt, ['hq_funding']);
            $before = $this->snapshot($funding);
            $funding->reversal_internal_transfer_id = $reversal->id;
            $funding->save();
            $this->audits->record($headFinance, $funding, 'hq_funding', 'reversed', trim($reason), $before, $this->snapshot($funding));
            return $funding;
        });
    }

    private function resolve(CentralFinanceUser $actor, int $id, string $status, string $reason, CarbonImmutable $occurredAt, bool $mustConfirmFunding): CentralFinanceHqFundingRequest
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A HQ funding resolution reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $id, $status, $reason, $occurredAt, $mustConfirmFunding): CentralFinanceHqFundingRequest {
            $funding = CentralFinanceHqFundingRequest::on('mysql')->lockForUpdate()->findOrFail($id);
            if ($funding->status !== CentralFinanceHqFundingRequest::PENDING) throw new InvalidArgumentException('Only a pending HQ funding request can be resolved.');
            if ($mustConfirmFunding) $this->schools->assertCanConfirmFunding($actor, $funding->school_id);
            elseif ((int) $funding->requested_by !== $actor->id) throw new AuthorizationException('Only the requester can cancel this HQ funding request.');
            else { $this->schools->assertCanOperate($actor, $funding->school_id); $this->accounts->assertCanOperate($actor, CentralFinanceFundAccount::on('mysql')->active()->findOrFail($funding->source_account_id)); }
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $funding->school_id);
            $before = $this->snapshot($funding);
            $funding->status = $status; $funding->resolved_by = $actor->id; $funding->resolved_at = $occurredAt; $funding->resolution_reason = trim($reason); $funding->save();
            $this->audits->record($actor, $funding, 'hq_funding', $status, trim($reason), $before, $this->snapshot($funding));
            return $funding;
        });
    }

    private function direction(int $schoolId, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination): string
    {
        if (strtoupper($source->currency) !== strtoupper($destination->currency)) throw new InvalidArgumentException('目前仅支持同币种资金移动，跨币种兑换尚未启用。');
        if ($source->id === $destination->id) throw new InvalidArgumentException('HQ funding requires distinct active accounts.');
        if ($source->owner_type === CentralFinanceFundAccount::OWNER_HQ && $source->school_id === null && $destination->owner_type === CentralFinanceFundAccount::OWNER_SCHOOL && $this->availability->isAccountAvailableForSchool($destination, $schoolId)) return CentralFinanceHqFundingRequest::HQ_TO_SCHOOL;
        if ($destination->owner_type === CentralFinanceFundAccount::OWNER_HQ && $destination->school_id === null && $source->owner_type === CentralFinanceFundAccount::OWNER_SCHOOL && $this->availability->isAccountAvailableForSchool($source, $schoolId)) return CentralFinanceHqFundingRequest::SCHOOL_TO_HQ;
        throw new InvalidArgumentException('HQ funding requires exactly one HQ account and one account in the current School.');
    }
    private function assertInput(float $amount, string $idempotencyReference, ?string &$referenceNo): void { $referenceNo=$referenceNo===null?null:trim($referenceNo); if($amount<=0||!is_finite($amount)||!preg_match('/^[A-Za-z0-9_.:-]{2,100}$/',$idempotencyReference)||($referenceNo!==null&&$referenceNo!==''&&!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/',$referenceNo))) throw new InvalidArgumentException('Central HQ funding input is invalid.'); $referenceNo=$referenceNo?:null; }
    private function assertReferenceFree(int $schoolId, ?string $referenceNo): void { if($referenceNo!==null&&CentralFinanceHqFundingRequest::on('mysql')->where(['school_id'=>$schoolId,'reference_no'=>$referenceNo])->exists()) throw new InvalidArgumentException('This HQ funding reference is already reserved for this School.'); }
    /** @return array<string,mixed> */ private function snapshot(CentralFinanceHqFundingRequest $funding): array { return $funding->only(['school_id','source_account_id','destination_account_id','direction','amount','currency','reference_no','status','internal_transfer_id','reversal_internal_transfer_id','resolution_reason']); }
}
