<?php

namespace App\Services;

use App\Models\CentralFinanceSchoolCutover;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

/**
 * Per-School source-of-truth switch. A missing row is deliberately legacy
 * once this schema exists, so Central Finance can never take a School over by
 * accident. Central Finance remains read-only until this additive schema has
 * been installed and the School has explicitly entered the central state.
 */
final class CentralFinanceSchoolCutoverService
{
    /** Fresh Start dates are Finance business dates, not application-server dates. */
    public const FRESH_START_TIMEZONE = 'Asia/Yangon';

    public function __construct(
        private readonly CentralFinanceCutoverReadinessService $readiness,
        private readonly CentralFinanceConfigurationAuthorizationService $configurationAuthorization,
        private readonly CentralFinanceDocumentAuditService $audits,
    ) {}

    public static function parseReceivableSyncEffectiveAt(string $value): CarbonImmutable
    {
        return self::parseFreshStartBusinessTime($value);
    }

    /**
     * MySQL returns timestamp columns in the connection session timezone.
     * Finance source dates and the configured cutoff are both Yangon business
     * dates, so they must never be interpreted using APP_TIMEZONE.
     */
    public static function parseFreshStartBusinessTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::FRESH_START_TIMEZONE);
    }

    public function statusForSchool(int $schoolId): string
    {
        if ($schoolId < 1 || !Schema::connection('mysql')->hasTable('central_finance_school_cutovers')) {
            return CentralFinanceSchoolCutover::LEGACY;
        }

        return (string) (CentralFinanceSchoolCutover::on('mysql')
            ->where('school_id', $schoolId)
            ->value('status') ?: CentralFinanceSchoolCutover::LEGACY);
    }

    public function allowsCentralWrites(int $schoolId): bool
    {
        return Schema::connection('mysql')->hasTable('central_finance_school_cutovers')
            && $this->statusForSchool($schoolId) === CentralFinanceSchoolCutover::CENTRAL;
    }

    public function assertCentralWritesAllowed(int $schoolId): void
    {
        if (!$this->allowsCentralWrites($schoolId)) {
            throw new AuthorizationException('Central Finance is read-only until this School is in the central cutover state.');
        }
    }

    public function assertTenantFinanceWritesAllowed(User $actor): void
    {
        if ((int) $actor->school_id < 1) {
            throw new AuthorizationException('A trusted School Finance identity is required.');
        }

        // Tenant-local school IDs are not Central registry IDs. The current
        // tenant connection is initialized by the trusted School login/host
        // middleware, so resolve the Central School through its registered
        // database name rather than a locally scoped users.school_id.
        $database = trim((string) config('database.connections.school.database'));
        if ($database === '') {
            throw new AuthorizationException('A trusted School tenant connection is required.');
        }
        $school = School::on('mysql')->where('database_name', $database)->firstOrFail();

        if ($this->statusForSchool((int) $school->id) === CentralFinanceSchoolCutover::CENTRAL) {
            throw new AuthorizationException('Legacy tenant Finance is read-only after Central Finance cutover.');
        }
    }

    /**
     * Records the explicit Fresh Start source boundary before readiness. It is
     * intentionally not inferred from now(), ready_at, or a request database.
     */
    public function setReceivableSyncEffectiveAt(CentralFinanceUser $actor, School $requestedSchool, CarbonImmutable $effectiveAt, string $reason): CentralFinanceSchoolCutover
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A signed Fresh Start receivable cutoff reason is required.');
        }
        if (!Schema::connection('mysql')->hasTable('central_finance_school_cutovers')
            || !Schema::connection('mysql')->hasColumn('central_finance_school_cutovers', 'receivable_sync_effective_at')) {
            throw new LogicException('The Fresh Start receivable cutoff schema is not installed.');
        }

        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $this->configurationAuthorization->assertCanConfigureCutover($actor, $school);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $effectiveAt, $reason): CentralFinanceSchoolCutover {
            $row = CentralFinanceSchoolCutover::on('mysql')->where('school_id', $school->id)->lockForUpdate()->first();
            if ($row !== null && $row->status !== CentralFinanceSchoolCutover::LEGACY) {
                throw new LogicException('The Fresh Start receivable cutoff is immutable after a School is marked ready.');
            }
            if ($this->hasRealCentralFinancialActivity((int) $school->id)) {
                throw new LogicException('The receivable cutoff is immutable after Central Finance activity exists.');
            }
            $before = $row === null ? null : $this->auditSnapshot($row);
            $row ??= new CentralFinanceSchoolCutover(['school_id' => $school->id, 'status' => CentralFinanceSchoolCutover::LEGACY]);
            $row->fill([
                'receivable_sync_effective_at' => $effectiveAt,
                'receivable_sync_effective_by' => $actor->id,
                'receivable_sync_effective_reason' => trim($reason),
            ])->save();
            $this->audits->record(
                $actor,
                $row,
                'central_finance_school_cutover',
                'cutoff_updated',
                mb_substr(trim($reason), 0, 255),
                $before,
                $this->auditSnapshot($row),
            );

            return $row->fresh();
        });
    }

    public function transition(CentralFinanceUser $actor, School $requestedSchool, string $target, string $reason): CentralFinanceSchoolCutover
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A signed cutover transition reason is required.');
        }
        if (!in_array($target, [CentralFinanceSchoolCutover::LEGACY, CentralFinanceSchoolCutover::READY, CentralFinanceSchoolCutover::CENTRAL], true)) {
            throw new InvalidArgumentException('The Central Finance cutover state is invalid.');
        }
        if (!Schema::connection('mysql')->hasTable('central_finance_school_cutovers')) {
            throw new LogicException('The Central Finance cutover schema is not installed.');
        }

        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $this->configurationAuthorization->assertCanConfigureCutover($actor, $school);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $target, $reason): CentralFinanceSchoolCutover {
            $row = CentralFinanceSchoolCutover::on('mysql')->where('school_id', $school->id)->lockForUpdate()->first();
            $current = $row?->status ?? CentralFinanceSchoolCutover::LEGACY;
            if ($current === $target) {
                throw new LogicException('The School is already in the requested Central Finance status.');
            }
            if ($current === CentralFinanceSchoolCutover::CENTRAL && $target === CentralFinanceSchoolCutover::LEGACY && $this->hasRealCentralFinancialActivity($school->id)) {
                throw new LogicException('A School with Central Finance transactions cannot silently return to legacy Finance.');
            }
            if (!in_array($current.'>'.$target, [
                'legacy>ready', 'ready>legacy', 'ready>central', 'central>legacy',
            ], true)) {
                throw new LogicException('This Central Finance cutover transition is not permitted.');
            }
            if (($current === CentralFinanceSchoolCutover::LEGACY && $target === CentralFinanceSchoolCutover::READY)
                || ($current === CentralFinanceSchoolCutover::READY && $target === CentralFinanceSchoolCutover::CENTRAL)) {
                $this->readiness->assertReadyForCentral($school);
            }

            $before = $row === null ? null : $this->auditSnapshot($row);
            $row ??= new CentralFinanceSchoolCutover(['school_id' => $school->id]);
            $row->status = $target;
            if ($target === CentralFinanceSchoolCutover::READY) {
                $row->ready_by = $actor->id;
                $row->ready_at = now();
            }
            $row->cutover_at = $target === CentralFinanceSchoolCutover::CENTRAL ? now() : null;
            $row->approved_by = $target === CentralFinanceSchoolCutover::CENTRAL ? $actor->id : null;
            $row->save();
            $after = $this->auditSnapshot($row);
            $after['transition_reason'] = trim($reason);
            $this->audits->record(
                $actor,
                $row,
                'central_finance_school_cutover',
                'status_transitioned',
                mb_substr(trim($reason), 0, 255),
                $before,
                $after,
            );

            return $row->fresh();
        });
    }

    public function hasRealCentralFinancialActivity(int $schoolId): bool
    {
        foreach ([
            'central_finance_payments', 'central_finance_expenses', 'central_finance_other_incomes',
            'central_finance_internal_transfers', 'central_finance_fund_handovers',
            'central_finance_hq_funding_requests', 'central_finance_ledger_entries',
        ] as $table) {
            if (Schema::connection('mysql')->hasTable($table)
                && DB::connection('mysql')->table($table)->where('school_id', $schoolId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(CentralFinanceSchoolCutover $row): array
    {
        return [
            'school_id' => (int) $row->school_id,
            'status' => (string) $row->status,
            'receivable_sync_effective_at' => $row->getRawOriginal('receivable_sync_effective_at'),
            'receivable_sync_effective_by' => $row->receivable_sync_effective_by === null ? null : (int) $row->receivable_sync_effective_by,
            'receivable_sync_effective_reason' => $row->receivable_sync_effective_reason,
            'ready_by' => $row->ready_by === null ? null : (int) $row->ready_by,
            'ready_at' => $row->getRawOriginal('ready_at'),
            'cutover_at' => $row->getRawOriginal('cutover_at'),
            'approved_by' => $row->approved_by === null ? null : (int) $row->approved_by,
        ];
    }
}
