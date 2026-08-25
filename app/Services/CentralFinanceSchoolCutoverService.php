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
        $schoolId = (int) $actor->school_id;
        if ($schoolId < 1) {
            throw new AuthorizationException('A trusted School Finance identity is required.');
        }

        // The actor is resolved by the tenant auth guard; this central lookup
        // only confirms the immutable School registry identity. No request
        // parameter or database name participates in this decision.
        School::on('mysql')->whereKey($schoolId)->firstOrFail();

        if ($this->statusForSchool($schoolId) === CentralFinanceSchoolCutover::CENTRAL) {
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
        $this->configurationAuthorization->assertHeadFinanceCanConfigureSchool($actor, $school);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $effectiveAt, $reason): CentralFinanceSchoolCutover {
            $row = CentralFinanceSchoolCutover::on('mysql')->where('school_id', $school->id)->lockForUpdate()->first();
            if ($row !== null && $row->status !== CentralFinanceSchoolCutover::LEGACY) {
                throw new LogicException('The Fresh Start receivable cutoff is immutable after a School is marked ready.');
            }
            $row ??= new CentralFinanceSchoolCutover(['school_id' => $school->id, 'status' => CentralFinanceSchoolCutover::LEGACY]);
            $row->fill([
                'receivable_sync_effective_at' => $effectiveAt,
                'receivable_sync_effective_by' => $actor->id,
                'receivable_sync_effective_reason' => trim($reason),
            ])->save();

            return $row->fresh();
        });
    }

    public function transition(CentralFinanceUser $actor, School $requestedSchool, string $target): CentralFinanceSchoolCutover
    {
        if (!in_array($target, [CentralFinanceSchoolCutover::LEGACY, CentralFinanceSchoolCutover::READY, CentralFinanceSchoolCutover::CENTRAL], true)) {
            throw new InvalidArgumentException('The Central Finance cutover state is invalid.');
        }
        if (!Schema::connection('mysql')->hasTable('central_finance_school_cutovers')) {
            throw new LogicException('The Central Finance cutover schema is not installed.');
        }

        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $this->configurationAuthorization->assertHeadFinanceCanConfigureSchool($actor, $school);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $target): CentralFinanceSchoolCutover {
            $row = CentralFinanceSchoolCutover::on('mysql')->where('school_id', $school->id)->lockForUpdate()->first();
            $current = $row?->status ?? CentralFinanceSchoolCutover::LEGACY;
            if ($current === $target) {
                return $row ?? CentralFinanceSchoolCutover::on('mysql')->create(['school_id' => $school->id, 'status' => $target]);
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

            $row ??= new CentralFinanceSchoolCutover(['school_id' => $school->id]);
            $row->status = $target;
            if ($target === CentralFinanceSchoolCutover::READY) {
                $row->ready_by = $actor->id;
                $row->ready_at = now();
            }
            $row->cutover_at = $target === CentralFinanceSchoolCutover::CENTRAL ? now() : null;
            $row->approved_by = $target === CentralFinanceSchoolCutover::CENTRAL ? $actor->id : null;
            $row->save();

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
}
