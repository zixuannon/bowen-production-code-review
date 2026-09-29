<?php

namespace App\Services;

use App\Models\CentralFinancePendingCollection;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceReceivableAdjustment;
use App\Models\CentralFinanceUser;
use App\Support\CentralFinanceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only obligation changes; never creates a Payment, Ledger, or Fund Account effect. */
final class CentralFinanceReceivableAdjustmentService
{
    public const PROMOTION = 'promotion';
    public const WAIVER = 'waiver';
    public const CORRECTION = 'correction';
    public const VOID = 'void';

    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceDocumentAuditService $audits,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    public function correct(CentralFinanceUser $actor, int $receivableId, string $delta, string $reason, CarbonImmutable $effectiveDate, CarbonImmutable $recordedAt, string $key): CentralFinanceReceivableAdjustment
    {
        return $this->record($actor, $receivableId, self::CORRECTION, $delta, $reason, $effectiveDate, $recordedAt, $key);
    }

    /** $amount is a positive reduction of the remaining obligation. */
    public function waive(CentralFinanceUser $actor, int $receivableId, string $amount, string $reason, CarbonImmutable $effectiveDate, CarbonImmutable $recordedAt, string $key): CentralFinanceReceivableAdjustment
    {
        $amount = CentralFinanceDecimal::normalize($amount);
        if (CentralFinanceDecimal::compare($amount, '0') <= 0) throw new InvalidArgumentException('Waiver amount must be greater than zero.');
        return $this->record($actor, $receivableId, self::WAIVER, CentralFinanceDecimal::subtract('0', $amount), $reason, $effectiveDate, $recordedAt, $key);
    }

    public function void(CentralFinanceUser $actor, int $receivableId, string $reason, CarbonImmutable $effectiveDate, CarbonImmutable $recordedAt, string $key): CentralFinanceReceivableAdjustment
    {
        return $this->record($actor, $receivableId, self::VOID, null, $reason, $effectiveDate, $recordedAt, $key);
    }

    /** Used only by the Promotion service after it validates the definition. */
    public function applyPromotion(CentralFinanceUser $actor, int $receivableId, string $discountAmount, string $reason, CarbonImmutable $effectiveDate, CarbonImmutable $recordedAt, string $key): CentralFinanceReceivableAdjustment
    {
        $discountAmount = CentralFinanceDecimal::normalize($discountAmount);
        if (CentralFinanceDecimal::compare($discountAmount, '0') <= 0) throw new InvalidArgumentException('Promotion discount must be greater than zero.');
        return $this->record($actor, $receivableId, self::PROMOTION, CentralFinanceDecimal::subtract('0', $discountAmount), $reason, $effectiveDate, $recordedAt, $key);
    }

    private function record(CentralFinanceUser $actor, int $receivableId, string $type, ?string $delta, string $reason, CarbonImmutable $effectiveDate, CarbonImmutable $recordedAt, string $key): CentralFinanceReceivableAdjustment
    {
        if (!in_array($type, [self::PROMOTION, self::WAIVER, self::CORRECTION, self::VOID], true) || trim($reason) === '' || mb_strlen(trim($reason)) > 2000 || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $key)) throw new InvalidArgumentException('Receivable lifecycle input is invalid.');
        return DB::connection('mysql')->transaction(function () use ($actor, $receivableId, $type, $delta, $reason, $effectiveDate, $recordedAt, $key): CentralFinanceReceivableAdjustment {
            $receivable = CentralFinanceReceivable::on('mysql')->lockForUpdate()->findOrFail($receivableId);
            $this->workspace->assertHeadFinance($actor); $this->schools->assertCanOperate($actor, (int) $receivable->school_id);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $receivable->school_id);
            $this->dataIsolation->assertWorkflowWritable('receivable', (int) $receivable->id);
            $idempotencyKey = hash('sha256', implode('|', ['receivable-lifecycle', $receivable->school_id, $receivable->id, $key]));
            if (($existing = CentralFinanceReceivableAdjustment::on('mysql')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) !== null) return $existing;
            if (in_array($receivable->status, [CentralFinanceReceivable::VOIDED, CentralFinanceReceivable::CANCELLED], true)) throw new InvalidArgumentException('A voided or cancelled receivable cannot be adjusted.');
            $before = CentralFinanceDecimal::normalize((string) $receivable->amount_due); $paid = CentralFinanceDecimal::normalize((string) $receivable->amount_paid); $reserved = $this->activeReservationTotal($receivable);
            if ($type === self::VOID) { $this->assertVoidEligible($receivable, $paid, $reserved); $delta = CentralFinanceDecimal::subtract('0', $before); }
            else { $delta = CentralFinanceDecimal::normalize((string) $delta); if (CentralFinanceDecimal::compare($delta, '0') === 0) throw new InvalidArgumentException('Correction amount must not be zero.'); if (($type === self::PROMOTION || $type === self::WAIVER) && CentralFinanceDecimal::compare($delta, '0') >= 0) throw new InvalidArgumentException('Promotion and waiver amounts must reduce the receivable.'); if ($type === self::PROMOTION && (CentralFinanceDecimal::compare($paid, '0') !== 0 || CentralFinanceDecimal::compare($reserved, '0') !== 0)) throw new InvalidArgumentException('A Promotion can only be applied before payment or pending collection.'); if ($type === self::WAIVER && CentralFinanceDecimal::compare($reserved, '0') !== 0) throw new InvalidArgumentException('Resolve pending collections before waiving an outstanding receivable.'); }
            $after = CentralFinanceDecimal::add($before, $delta); $minimum = CentralFinanceDecimal::add($paid, $reserved);
            if (CentralFinanceDecimal::compare($after, '0') < 0 || CentralFinanceDecimal::compare($after, $minimum) < 0) throw new InvalidArgumentException('The net receivable cannot be below paid or pending collection amounts; use Refund or Reversal where applicable.');
            if ($type === self::PROMOTION && CentralFinanceDecimal::compare($after, '0') <= 0) throw new InvalidArgumentException('A full reduction must use the approved Waiver lifecycle, not a Promotion.');
            $adjustment = CentralFinanceReceivableAdjustment::on('mysql')->create(['adjustment_uuid' => (string) Str::uuid(), 'school_id' => $receivable->school_id, 'receivable_id' => $receivable->id, 'adjustment_type' => $type, 'amount_delta' => $delta, 'amount_before' => $before, 'amount_after' => $after, 'idempotency_key' => $idempotencyKey, 'reason' => trim($reason), 'effective_date' => $effectiveDate->toDateString(), 'adjusted_at' => $recordedAt, 'adjusted_by' => $actor->id]);
            $financeAdjustment = CentralFinanceDecimal::add((string) $receivable->finance_adjustment_amount, $delta); $status = $type === self::VOID ? CentralFinanceReceivable::VOIDED : $this->statusFor($after, $paid, $type);
            $receivable->update(['amount_due' => $after, 'finance_adjustment_amount' => $financeAdjustment, 'status' => $status]);
            $this->audits->record($actor, $adjustment, 'central_receivable_adjustment', $type, trim($reason), ['receivable_id' => $receivable->id, 'amount_due' => $before, 'amount_paid' => $paid], ['receivable_id' => $receivable->id, 'amount_due' => $after, 'amount_paid' => $paid, 'effective_date' => $effectiveDate->toDateString(), 'reserved_pending_amount' => $reserved]);
            $this->dataIsolation->inheritWorkflowClassification($actor, (int) $receivable->school_id, 'receivable_adjustment', (int) $adjustment->id);
            return $adjustment;
        });
    }

    private function activeReservationTotal(CentralFinanceReceivable $receivable): string
    {
        return CentralFinancePendingCollection::on('mysql')->where('receivable_id', $receivable->id)->whereIn('status', [CentralFinancePendingCollection::SUBMITTED, CentralFinancePendingCollection::HELD])->lockForUpdate()->pluck('amount')->reduce(static fn (string $total, mixed $amount): string => CentralFinanceDecimal::add($total, (string) $amount), CentralFinanceDecimal::normalize('0'));
    }

    private function assertVoidEligible(CentralFinanceReceivable $receivable, string $paid, string $reserved): void
    {
        if (CentralFinanceDecimal::compare($paid, '0') !== 0 || CentralFinanceDecimal::compare($reserved, '0') !== 0 || $receivable->payments()->exists()) throw new InvalidArgumentException('A receivable with payment or pending collection history requires Refund or Reversal instead of Void.');
    }

    private function statusFor(string $netAmount, string $paid, string $type): string
    {
        if ($type === self::WAIVER && CentralFinanceDecimal::compare($netAmount, '0') === 0) return CentralFinanceReceivable::WAIVED;
        if (CentralFinanceDecimal::compare($paid, '0') === 0) return CentralFinanceReceivable::OPEN;
        if (CentralFinanceDecimal::compare($netAmount, $paid) === 0) return $type === self::WAIVER ? CentralFinanceReceivable::WAIVED : CentralFinanceReceivable::PAID;
        return CentralFinanceReceivable::PARTIAL;
    }
}
