<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundHandover;
use App\Models\CentralFinanceInternalTransfer;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Pending handovers are custody requests; only confirmation appends a Ledger movement. */
final class CentralFinanceFundHandoverService
{
    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly CentralFinanceLedgerService $ledger,
        private readonly CentralFinanceDocumentAuditService $audits,
        private readonly CentralFinanceInternalTransferService $transfers,
    ) {}

    public function request(CentralFinanceUser $sender, CentralFinanceUser $receiver, int $schoolId, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination, float $amount, CarbonImmutable $occurredAt, string $idempotencyReference, ?string $referenceNo = null): CentralFinanceFundHandover
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
        $this->assertInput($amount, $idempotencyReference, $referenceNo);
        return DB::connection('mysql')->transaction(function () use ($sender, $receiver, $schoolId, $source, $destination, $amount, $occurredAt, $idempotencyReference, $referenceNo): CentralFinanceFundHandover {
            $this->schools->assertCanOperate($sender, $schoolId);
            $this->schools->assertCanOperate($receiver, $schoolId);
            $this->accounts->assertCanOperate($sender, $source);
            $this->accounts->assertCanOperate($receiver, $destination);
            $source = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($source->id);
            $destination = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($destination->id);
            $this->assertSameSchool($schoolId, $source, $destination);
            $key = hash('sha256', 'central_fund_handover|'.$schoolId.'|'.$idempotencyReference);
            $existing = CentralFinanceFundHandover::on('mysql')->where('idempotency_key', $key)->first();
            if ($existing !== null) return $existing;
            $this->assertReferenceFree($schoolId, $referenceNo);
            $handover = CentralFinanceFundHandover::on('mysql')->create([
                'school_id' => $schoolId, 'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
                'sender_user_id' => $sender->id, 'receiver_user_id' => $receiver->id, 'idempotency_key' => $key,
                'reference_no' => $referenceNo, 'handover_date' => $occurredAt->toDateString(), 'currency' => strtoupper($source->currency),
                'amount' => $amount, 'status' => CentralFinanceFundHandover::PENDING, 'requested_by' => $sender->id,
            ]);
            $this->audits->record($sender, $handover, 'fund_handover', 'requested', null, null, $this->snapshot($handover));
            return $handover;
        });
    }

    public function confirm(CentralFinanceUser $receiver, int $handoverId, CarbonImmutable $occurredAt): CentralFinanceFundHandover
    {
        return DB::connection('mysql')->transaction(function () use ($receiver, $handoverId, $occurredAt): CentralFinanceFundHandover {
            $handover = CentralFinanceFundHandover::on('mysql')->lockForUpdate()->findOrFail($handoverId);
            if ($handover->status !== CentralFinanceFundHandover::PENDING) throw new InvalidArgumentException('Only a pending Fund Handover can be confirmed.');
            if ((int) $handover->receiver_user_id !== $receiver->id) throw new AuthorizationException('Only the designated receiver can confirm this Fund Handover.');
            $this->schools->assertCanOperate($receiver, $handover->school_id);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $handover->school_id);
            $source = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($handover->source_account_id);
            $destination = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($handover->destination_account_id);
            $this->accounts->assertCanOperate($receiver, $destination);
            $sender = CentralFinanceUser::on('mysql')->findOrFail($handover->sender_user_id);
            $this->schools->assertCanOperate($sender, $handover->school_id);
            $this->accounts->assertCanOperate($sender, $source);
            $this->assertSameSchool($handover->school_id, $source, $destination);
            $transfer = CentralFinanceInternalTransfer::on('mysql')->create([
                'school_id' => $handover->school_id, 'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
                'source_type' => 'fund_handover', 'source_id' => $handover->handover_uuid, 'reference_no' => $handover->reference_no,
                'transfer_date' => $occurredAt->toDateString(), 'currency' => strtoupper($source->currency), 'amount' => $handover->amount,
                'status' => 'confirmed', 'created_by' => $handover->requested_by, 'confirmed_by' => $receiver->id, 'confirmed_at' => $occurredAt,
            ]);
            $this->ledger->recordInternalTransferWithSplitCustody($sender, $receiver, $source, $destination, $handover->school_id, 'central_internal_transfer', $transfer->transfer_uuid, (float) $handover->amount, $occurredAt, $handover->reference_no);
            $before = $this->snapshot($handover);
            $handover->status = CentralFinanceFundHandover::CONFIRMED;
            $handover->resolved_by = $receiver->id;
            $handover->resolved_at = $occurredAt;
            $handover->internal_transfer_id = $transfer->id;
            $handover->save();
            $this->audits->record($receiver, $handover, 'fund_handover', 'confirmed', null, $before, $this->snapshot($handover));
            return $handover;
        });
    }

    public function reject(CentralFinanceUser $receiver, int $handoverId, string $reason, CarbonImmutable $occurredAt): CentralFinanceFundHandover
    {
        return $this->resolve($receiver, $handoverId, CentralFinanceFundHandover::REJECTED, $reason, $occurredAt, true);
    }

    public function cancel(CentralFinanceUser $sender, int $handoverId, string $reason, CarbonImmutable $occurredAt): CentralFinanceFundHandover
    {
        return $this->resolve($sender, $handoverId, CentralFinanceFundHandover::CANCELLED, $reason, $occurredAt, false);
    }

    public function reverse(CentralFinanceUser $actor, int $handoverId, string $reason, CarbonImmutable $occurredAt): CentralFinanceFundHandover
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A Fund Handover reversal reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $handoverId, $reason, $occurredAt): CentralFinanceFundHandover {
            $handover = CentralFinanceFundHandover::on('mysql')->lockForUpdate()->findOrFail($handoverId);
            if ($handover->status !== CentralFinanceFundHandover::CONFIRMED || $handover->internal_transfer_id === null || $handover->reversal_internal_transfer_id !== null) throw new InvalidArgumentException('Only an unreversed confirmed Fund Handover can be reversed.');
            // Reversal moves both accounts, so the actor must hold the existing
            // operating scope for both; split-custody confirmation is not reused.
            $this->schools->assertCanOperate($actor, (int) $handover->school_id);
            $reversal = $this->transfers->reverse($actor, (int) $handover->internal_transfer_id, $reason, $occurredAt, ['fund_handover']);
            $before = $this->snapshot($handover);
            $handover->reversal_internal_transfer_id = $reversal->id;
            $handover->save();
            $this->audits->record($actor, $handover, 'fund_handover', 'reversed', trim($reason), $before, $this->snapshot($handover));
            return $handover;
        });
    }

    private function resolve(CentralFinanceUser $actor, int $id, string $status, string $reason, CarbonImmutable $occurredAt, bool $receiver): CentralFinanceFundHandover
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A Fund Handover resolution reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $id, $status, $reason, $occurredAt, $receiver): CentralFinanceFundHandover {
            $handover = CentralFinanceFundHandover::on('mysql')->lockForUpdate()->findOrFail($id);
            if ($handover->status !== CentralFinanceFundHandover::PENDING) throw new InvalidArgumentException('Only a pending Fund Handover can be resolved.');
            if (($receiver && (int) $handover->receiver_user_id !== $actor->id) || (!$receiver && (int) $handover->sender_user_id !== $actor->id)) throw new AuthorizationException('The central actor is not the authorized Fund Handover participant.');
            $this->schools->assertCanOperate($actor, $handover->school_id);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $handover->school_id);
            $account = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($receiver ? $handover->destination_account_id : $handover->source_account_id);
            $this->accounts->assertCanOperate($actor, $account);
            $before = $this->snapshot($handover);
            $handover->status = $status; $handover->resolved_by = $actor->id; $handover->resolved_at = $occurredAt; $handover->resolution_reason = trim($reason); $handover->save();
            $this->audits->record($actor, $handover, 'fund_handover', $status, trim($reason), $before, $this->snapshot($handover));
            return $handover;
        });
    }

    private function assertSameSchool(int $schoolId, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination): void
    {
        if (strtoupper($source->currency) !== strtoupper($destination->currency)) throw new InvalidArgumentException('目前仅支持同币种资金移动，跨币种兑换尚未启用。');
        if ($source->id === $destination->id || $source->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL || $destination->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL || (int) $source->school_id !== $schoolId || (int) $destination->school_id !== $schoolId) throw new InvalidArgumentException('A Central Fund Handover requires distinct active accounts in one School.');
    }
    private function assertInput(float $amount, string $idempotencyReference, ?string &$referenceNo): void { $referenceNo=$referenceNo===null?null:trim($referenceNo); if($amount<=0||!is_finite($amount)||!preg_match('/^[A-Za-z0-9_.:-]{2,100}$/',$idempotencyReference)||($referenceNo!==null&&$referenceNo!==''&&!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/',$referenceNo))) throw new InvalidArgumentException('Central Fund Handover input is invalid.'); $referenceNo=$referenceNo?:null; }
    private function assertReferenceFree(int $schoolId, ?string $referenceNo): void { if($referenceNo!==null&&CentralFinanceFundHandover::on('mysql')->where(['school_id'=>$schoolId,'reference_no'=>$referenceNo])->exists()) throw new InvalidArgumentException('This Fund Handover reference is already reserved for this School.'); }
    /** @return array<string,mixed> */ private function snapshot(CentralFinanceFundHandover $handover): array { return $handover->only(['school_id','source_account_id','destination_account_id','sender_user_id','receiver_user_id','amount','currency','reference_no','status','internal_transfer_id','reversal_internal_transfer_id','resolution_reason']); }
}
