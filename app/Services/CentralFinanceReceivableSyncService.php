<?php

namespace App\Services;

use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceReceivableSyncEvent;
use App\Models\CentralFinanceStudentProfile;
use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Projects tenant compulsory fee assignments into Central receivables.
 *
 * Tenant fee definitions remain authoritative. This service never touches a
 * tenant payment table, Fund Account, receipt, or Ledger. A changed or
 * removed source record is allowed to update/cancel only an unpaid Central
 * receivable; a paid document is fail-closed and queued for human review.
 */
final class CentralFinanceReceivableSyncService
{
    public const SOURCE_TYPE = 'tenant_fee_assignment';

    public function __construct(private readonly CentralFinanceTenantFeeAssignmentSource $source) {}

    /** @return list<CentralFinanceReceivable> */
    public function syncProfile(CentralFinanceStudentProfile $profile): array
    {
        $profile = CentralFinanceStudentProfile::on('mysql')->findOrFail($profile->id);
        $this->assertSchema();

        try {
            $allRows = $this->source->allForProfile($profile);
        } catch (Throwable $exception) {
            $this->recordFailure($profile, 'profile:'.$profile->id, 'source_read_failed');
            throw $exception;
        }
        $this->resolveDeferredSourceFailures($profile);

        $expected = [];
        foreach ($allRows as $row) {
            if ($this->source->isWithinFreshStartCutoff($profile, $row)) {
                $expected[(string) $row['source_id']] = $row;
            }
        }

        $synced = [];
        foreach ($expected as $row) $synced[] = $this->syncOne($profile, $row);

        $existing = CentralFinanceReceivable::on('mysql')->where([
            'school_id' => $profile->school_id,
            'student_profile_id' => $profile->id,
            'source_type' => self::SOURCE_TYPE,
        ])->get();
        foreach ($existing as $receivable) {
            if (!array_key_exists((string) $receivable->source_id, $expected)) {
                if ($this->tracksReceivableWithinCutoff($profile, $receivable, $allRows)) {
                    $synced[] = $this->cancelMissingSource($profile, $receivable);
                }
            }
        }

        return collect($synced)->unique('id')->values()->all();
    }

    /** @return array{created:int,updated:int,cancelled:int,duplicate:int,blocked_paid:int,failed:int} */
    public function syncSchool(School $requestedSchool): array
    {
        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $summary = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'duplicate' => 0, 'blocked_paid' => 0, 'failed' => 0];
        foreach (CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)->orderBy('id')->get() as $profile) {
            try {
                $before = (int) CentralFinanceReceivableSyncEvent::on('mysql')->max('id');
                $this->syncProfile($profile);
                foreach (CentralFinanceReceivableSyncEvent::on('mysql')->where('id', '>', $before)->pluck('status') as $status) {
                    if (array_key_exists($status, $summary)) $summary[$status]++;
                }
            } catch (Throwable) {
                $summary['failed']++;
            }
        }
        return $summary;
    }

    /**
     * Read-only reconciliation. No profile, receivable, event, payment, or
     * Ledger rows are created or modified.
     *
     * @return array{source_count:int,central_count:int,missing_in_central:list<string>,stale_in_central:list<string>,mismatched:list<string>,cancelled_source:list<string>,blocked_paid:list<string>}
     */
    public function reconcileSchool(School $requestedSchool): array
    {
        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $sourceCount = 0; $centralCount = 0; $missing = []; $stale = []; $mismatched = []; $cancelled = []; $blocked = [];
        foreach (CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)->orderBy('id')->get() as $profile) {
            $allRows = $this->source->allForProfile($profile);
            $sourceById = collect($allRows)
                ->filter(fn (array $row): bool => $this->source->isWithinFreshStartCutoff($profile, $row))
                ->keyBy(static fn (array $row): string => (string) $row['source_id']);
            $existing = CentralFinanceReceivable::on('mysql')->where([
                'school_id' => $school->id, 'student_profile_id' => $profile->id, 'source_type' => self::SOURCE_TYPE,
            ])->get()
                ->filter(fn (CentralFinanceReceivable $r): bool => $this->tracksReceivableWithinCutoff($profile, $r, $allRows))
                ->keyBy(static fn (CentralFinanceReceivable $r): string => (string) $r->source_id);
            $sourceCount += $sourceById->count(); $centralCount += $existing->count();
            foreach ($sourceById as $sourceId => $row) {
                $receivable = $existing->get($sourceId); $identity = $profile->source_uuid.':'.$sourceId;
                if ($receivable === null) { $missing[] = $identity; continue; }
                if ($receivable->source_updated_at !== null && CarbonImmutable::parse($receivable->source_updated_at)->lessThan($row['updated_at'])) $stale[] = $identity;
                if (!$this->matches($receivable, $row)) $mismatched[] = $identity;
            }
            foreach ($existing as $sourceId => $receivable) {
                $identity = $profile->source_uuid.':'.$sourceId;
                if (!$sourceById->has($sourceId) && $receivable->status !== CentralFinanceReceivable::CANCELLED) {
                    $cancelled[] = $identity;
                    if ((float) $receivable->amount_paid > 0) $blocked[] = $identity;
                }
            }
        }
        return ['source_count' => $sourceCount, 'central_count' => $centralCount, 'missing_in_central' => $missing, 'stale_in_central' => $stale, 'mismatched' => $mismatched, 'cancelled_source' => $cancelled, 'blocked_paid' => $blocked];
    }

    /** @param array{source_id:string,description:string,due_date:?string,currency:string,amount:float,created_at:CarbonImmutable,updated_at:CarbonImmutable} $row */
    private function syncOne(CentralFinanceStudentProfile $profile, array $row): CentralFinanceReceivable
    {
        $this->assertSourceRow($row);
        $currency = \App\Support\CentralFinanceCurrency::normalize((string) $row['currency']);
        $payload = ['profile_source_uuid' => strtolower((string) $profile->source_uuid), 'source_id' => (string) $row['source_id'], 'description' => (string) $row['description'], 'due_date' => $row['due_date'], 'currency' => $currency, 'amount' => (float) $row['amount'], 'updated_at' => $row['updated_at']->utc()->format('Y-m-d\\TH:i:s.u\\Z')];
        return DB::connection('mysql')->transaction(function () use ($profile, $row, $payload, $currency): CentralFinanceReceivable {
            [$event, $duplicate] = $this->beginEvent($profile, (string) $row['source_id'], $payload);
            $receivable = CentralFinanceReceivable::on('mysql')->where(['school_id' => $profile->school_id, 'student_profile_id' => $profile->id, 'source_type' => self::SOURCE_TYPE, 'source_id' => (string) $row['source_id']])->lockForUpdate()->first();
            if ($duplicate && $receivable !== null) return $receivable;
            if ($receivable === null) {
                $receivable = CentralFinanceReceivable::on('mysql')->create(['receivable_uuid' => (string) Str::uuid(), 'school_id' => $profile->school_id, 'student_profile_id' => $profile->id, 'source_type' => self::SOURCE_TYPE, 'source_id' => (string) $row['source_id'], 'description' => $row['description'], 'due_date' => $row['due_date'], 'currency' => $currency, 'amount_due' => $row['amount'], 'source_amount_due' => $row['amount'], 'finance_adjustment_amount' => 0, 'amount_paid' => 0, 'status' => CentralFinanceReceivable::OPEN, 'source_updated_at' => $row['updated_at'], 'source_created_at' => $row['created_at'], 'last_synced_at' => now()]);
                $this->complete($event, 'created');
                return $receivable;
            }
            $paid = (float) $receivable->amount_paid;
            $adjustment = (float) $receivable->finance_adjustment_amount;
            $effective = (float) $row['amount'] + $adjustment;
            $financialChange = (float) ($receivable->source_amount_due ?? $receivable->amount_due) !== (float) $row['amount'] || strtoupper((string) $receivable->currency) !== strtoupper((string) $row['currency']);
            $paidConflict = (float) $row['amount'] < $paid
                || strtoupper((string) $receivable->currency) !== strtoupper((string) $row['currency']);
            if ($effective < $paid || ($paid > 0 && $financialChange && $paidConflict)) {
                $this->complete($event, 'blocked_paid', 'paid_receivable_source_conflict');
                return $receivable;
            }
            $receivable->fill(['description' => $row['description'], 'due_date' => $row['due_date'], 'currency' => $currency, 'source_amount_due' => $row['amount'], 'amount_due' => $effective, 'status' => $paid === 0.0 ? CentralFinanceReceivable::OPEN : ($paid >= $effective ? CentralFinanceReceivable::PAID : CentralFinanceReceivable::PARTIAL), 'source_updated_at' => $row['updated_at'], 'source_created_at' => $row['created_at'], 'last_synced_at' => now()])->save();
            $this->complete($event, 'updated');
            return $receivable;
        });
    }

    private function cancelMissingSource(CentralFinanceStudentProfile $profile, CentralFinanceReceivable $requested): CentralFinanceReceivable
    {
        $payload = ['profile_source_uuid' => strtolower((string) $profile->source_uuid), 'source_id' => (string) $requested->source_id, 'source_missing' => true, 'last_known_source_updated_at' => $requested->source_updated_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z')];
        return DB::connection('mysql')->transaction(function () use ($profile, $requested, $payload): CentralFinanceReceivable {
            [$event, $duplicate] = $this->beginEvent($profile, (string) $requested->source_id, $payload);
            $receivable = CentralFinanceReceivable::on('mysql')->lockForUpdate()->findOrFail($requested->id);
            if ($duplicate || $receivable->status === CentralFinanceReceivable::CANCELLED) return $receivable;
            if ((float) $receivable->amount_paid > 0) { $this->complete($event, 'blocked_paid', 'paid_receivable_source_removed'); return $receivable; }
            $receivable->update(['status' => CentralFinanceReceivable::CANCELLED, 'last_synced_at' => now()]);
            $this->complete($event, 'cancelled');
            return $receivable;
        });
    }

    /** @return array{0:CentralFinanceReceivableSyncEvent,1:bool} */
    private function beginEvent(CentralFinanceStudentProfile $profile, string $sourceId, array $payload): array
    {
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $idempotency = hash('sha256', implode('|', [$profile->school_id, $profile->id, self::SOURCE_TYPE, $sourceId, $payloadHash]));
        $event = CentralFinanceReceivableSyncEvent::on('mysql')->where('idempotency_key', $idempotency)->lockForUpdate()->first();
        if ($event !== null && in_array($event->status, ['created', 'updated', 'cancelled', 'blocked_paid'], true)) { $event->increment('attempts'); return [$event, true]; }
        if ($event !== null) { $event->update(['status' => 'processing', 'error_code' => null, 'attempts' => $event->attempts + 1]); return [$event, false]; }
        return [CentralFinanceReceivableSyncEvent::on('mysql')->create(['school_id' => $profile->school_id, 'student_profile_id' => $profile->id, 'source_type' => self::SOURCE_TYPE, 'source_id' => $sourceId, 'source_version' => $payloadHash, 'idempotency_key' => $idempotency, 'payload_hash' => $payloadHash, 'status' => 'processing']), false];
    }

    private function complete(CentralFinanceReceivableSyncEvent $event, string $status, ?string $errorCode = null): void { $event->update(['status' => $status, 'error_code' => $errorCode, 'processed_at' => now()]); }

    private function recordFailure(CentralFinanceStudentProfile $profile, string $sourceId, string $errorCode): void
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_receivable_sync_events')) return;
        $payloadHash = hash('sha256', implode('|', [$profile->school_id, $profile->id, $sourceId, $errorCode]));
        $key = hash('sha256', implode('|', [$profile->school_id, $profile->id, 'failure', $sourceId, $payloadHash]));
        $event = CentralFinanceReceivableSyncEvent::on('mysql')->where('idempotency_key', $key)->first();
        if ($event) { $event->update(['status' => 'failed', 'error_code' => $errorCode, 'attempts' => $event->attempts + 1]); return; }
        CentralFinanceReceivableSyncEvent::on('mysql')->create(['school_id' => $profile->school_id, 'student_profile_id' => $profile->id, 'source_type' => self::SOURCE_TYPE, 'source_id' => $sourceId, 'source_version' => $payloadHash, 'idempotency_key' => $key, 'payload_hash' => $payloadHash, 'status' => 'failed', 'error_code' => $errorCode]);
    }

    private function resolveDeferredSourceFailures(CentralFinanceStudentProfile $profile): void
    {
        CentralFinanceReceivableSyncEvent::on('mysql')->where([
            'school_id' => $profile->school_id,
            'student_profile_id' => $profile->id,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => 'profile:'.$profile->id,
            'status' => 'failed',
        ])->update(['status' => 'retried', 'processed_at' => now(), 'error_code' => null]);
    }

    /** @param array{source_id:string,description:string,due_date:?string,currency:string,amount:float,created_at:CarbonImmutable,updated_at:CarbonImmutable} $row */
    private function matches(CentralFinanceReceivable $receivable, array $row): bool
    {
        return $receivable->status !== CentralFinanceReceivable::CANCELLED && $receivable->description === $row['description'] && optional($receivable->due_date)->format('Y-m-d') === $row['due_date'] && strtoupper((string) $receivable->currency) === strtoupper($row['currency']) && (float) $receivable->amount_due === (float) $row['amount'];
    }

    /** @param array{source_id:string,description:string,due_date:?string,currency:string,amount:float,updated_at:CarbonImmutable} $row */
    private function assertSourceRow(array $row): void
    {
        try { \App\Support\CentralFinanceCurrency::normalize((string) $row['currency']); } catch (InvalidArgumentException) { throw new RuntimeException('Tenant fee assignment is not valid for Central Finance.'); }
        if ((float) $row['amount'] < 0 || !isset($row['created_at']) || !preg_match('/^[0-9]+$/', (string) $row['source_id'])) throw new RuntimeException('Tenant fee assignment is not valid for Central Finance.');
    }

    /** @param list<array{source_id:string,created_at:CarbonImmutable}> $allRows */
    private function tracksReceivableWithinCutoff(CentralFinanceStudentProfile $profile, CentralFinanceReceivable $receivable, array $allRows): bool
    {
        foreach ($allRows as $row) {
            if ((string) $row['source_id'] === (string) $receivable->source_id) {
                return $this->source->isWithinFreshStartCutoff($profile, $row);
            }
        }
        $cutoff = $this->source->freshStartCutoff($profile->school_id);
        return $cutoff !== null
            && $receivable->source_created_at !== null
            && CarbonImmutable::parse($receivable->source_created_at)->greaterThanOrEqualTo($cutoff);
    }

    private function assertSchema(): void
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_receivable_sync_events')) throw new RuntimeException('The Central Finance Receivable sync schema is not installed.');
        if (!Schema::connection('mysql')->hasColumn('central_finance_receivables', 'source_created_at')) throw new RuntimeException('The Central Finance Fresh Start cutoff schema is not installed.');
    }
}
