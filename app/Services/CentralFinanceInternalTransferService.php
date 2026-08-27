<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceInternalTransfer;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Canonical direct Central Bank Transfer writer; never an operating document. */
final class CentralFinanceInternalTransferService
{
    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly CentralFinanceLedgerService $ledger,
        private readonly CentralFinanceDocumentAuditService $audits,
    ) {}

    public function transfer(CentralFinanceUser $actor, int $schoolId, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination, float $amount, CarbonImmutable $occurredAt, string $idempotencyReference, ?string $referenceNo = null): CentralFinanceInternalTransfer
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
        $this->assertInput($amount, $idempotencyReference, $referenceNo);
        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $source, $destination, $amount, $occurredAt, $idempotencyReference, $referenceNo): CentralFinanceInternalTransfer {
            $this->schools->assertCanOperate($actor, $schoolId);
            $this->accounts->assertCanOperate($actor, $source);
            $this->accounts->assertCanOperate($actor, $destination);
            $source = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($source->id);
            $destination = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($destination->id);
            $this->assertSameOperatingSchool($schoolId, $source, $destination);
            $key = hash('sha256', 'central_direct_transfer|'.$schoolId.'|'.$idempotencyReference);
            $existing = CentralFinanceInternalTransfer::on('mysql')->where(['source_type' => 'direct_bank_transfer', 'source_id' => $key])->first();
            if ($existing !== null) return $existing;

            $transfer = CentralFinanceInternalTransfer::on('mysql')->create([
                'school_id' => $schoolId, 'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
                'source_type' => 'direct_bank_transfer', 'source_id' => $key, 'reference_no' => $referenceNo,
                'transfer_date' => $occurredAt->toDateString(), 'currency' => strtoupper($source->currency), 'amount' => $amount,
                'status' => 'confirmed', 'created_by' => $actor->id, 'confirmed_by' => $actor->id, 'confirmed_at' => $occurredAt,
            ]);
            $this->ledger->recordInternalTransfer($actor, $source, $destination, $schoolId, 'central_internal_transfer', $transfer->transfer_uuid, $amount, $occurredAt, $referenceNo);
            $this->audits->record($actor, $transfer, 'internal_transfer', 'confirmed', null, null, $this->snapshot($transfer));
            return $transfer;
        });
    }

    private function assertSameOperatingSchool(int $schoolId, CentralFinanceFundAccount $source, CentralFinanceFundAccount $destination): void
    {
        if (strtoupper($source->currency) !== strtoupper($destination->currency)) {
            throw new InvalidArgumentException('目前仅支持同币种资金移动，跨币种兑换尚未启用。');
        }
        if ($source->id === $destination->id || $source->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL || $destination->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL
            || (int) $source->school_id !== $schoolId || (int) $destination->school_id !== $schoolId) {
            throw new InvalidArgumentException('A direct Central Bank Transfer requires two distinct active accounts in the current School.');
        }
    }

    private function assertInput(float $amount, string $idempotencyReference, ?string &$referenceNo): void
    {
        $referenceNo = $referenceNo === null ? null : trim($referenceNo);
        if ($amount <= 0 || !is_finite($amount) || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)
            || ($referenceNo !== null && $referenceNo !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $referenceNo))) {
            throw new InvalidArgumentException('Central Bank Transfer input is invalid.');
        }
        $referenceNo = $referenceNo ?: null;
    }

    /** @return array<string,mixed> */
    private function snapshot(CentralFinanceInternalTransfer $transfer): array
    {
        return $transfer->only(['school_id', 'source_account_id', 'destination_account_id', 'amount', 'currency', 'reference_no', 'source_type', 'source_id']);
    }
}
