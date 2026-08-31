<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountOpeningBalanceAudit;
use App\Models\CentralFinanceSchoolStaffIdentity;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupSchool;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Verifies configuration only; it never creates accounts, balances, or transactions. */
final class CentralFinanceCutoverReadinessService
{
    public function __construct(
        private readonly CentralFinanceConfigurationAuthorizationService $authorization,
        private readonly CentralFinanceStudentProfileSyncService $studentProfiles,
        private readonly CentralFinanceReceivableSyncService $receivables,
    ) {}

    /**
     * Read-only, trusted-registry readiness report.  This deliberately calls
     * neither a tenant writer nor a Central Finance document service.
     *
     * @return list<array{key:string,label:string,status:string,reason:string,reason_params:array<string,string>}>
     */
    public function checklist(School $requestedSchool): array
    {
        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $checks = [];
        $groupIds = FinanceGroupSchool::on('mysql')->where('school_id', $school->id)->where('status', 'active')
            ->whereHas('group', fn ($query) => $query->where('status', 'active'))->pluck('group_id');
        $checks[] = $this->check('group_scope', 'Group and School scope', !$groupIds->isEmpty(), 'The School is not an active Finance Group member.');

        $accounts = $groupIds->isEmpty() ? collect() : CentralFinanceFundAccount::on('mysql')->active()
            ->where('owner_type', CentralFinanceFundAccount::OWNER_SCHOOL)->where('school_id', $school->id)
            ->whereIn('group_id', $groupIds)->get();
        $checks[] = $this->check('fund_accounts', 'Active Fund Accounts', !$accounts->isEmpty(), 'Create at least one active Central School Fund Account.');

        $headIsAssigned = false;
        $accountantIsAssigned = false;
        $openingBalancesValid = !$accounts->isEmpty();
        foreach ($accounts as $account) {
            $initial = CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where([
                'fund_account_id' => $account->id, 'change_type' => CentralFinanceFundAccountOpeningBalanceAudit::INITIAL,
            ])->first();
            $latest = CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where('fund_account_id', $account->id)->latest('id')->first();
            if (!$initial || !$latest || (float) $latest->new_opening_balance !== (float) $account->opening_balance) {
                $openingBalancesValid = false;
            }
            $assignedIds = DB::connection('mysql')->table('central_finance_fund_account_users')
                ->where('fund_account_id', $account->id)->where('can_view', true)->where('can_operate', true)->pluck('user_id');
            foreach (CentralFinanceUser::on('mysql')->whereIn('id', $assignedIds)->get() as $actor) {
                if ($this->authorization->isHeadFinanceOperatingForSchool($actor, $school, (int) $account->group_id)) {
                    $headIsAssigned = true;
                }
                if ($this->isActiveSchoolAccountant($actor, $school)) {
                    $accountantIsAssigned = true;
                }
            }
        }
        $checks[] = $this->check('opening_balances', 'Opening Balance audit', $openingBalancesValid, 'Every active Fund Account needs a signed initial opening-balance audit matching its configured balance.');
        $checks[] = $this->check('head_finance', 'Central Head Finance', $headIsAssigned, 'An authorized Head Finance user must have operate scope and an assigned Fund Account.');
        $checks[] = $this->check('school_accountant', 'School Accountant', $accountantIsAssigned, 'An active School Accountant identity with an assigned Fund Account is required.');

        foreach ($this->syncChecks($school) as $check) {
            $checks[] = $check;
        }

        $coreTables = ['central_finance_payments', 'central_finance_expenses', 'central_finance_other_incomes', 'central_finance_internal_transfers', 'central_finance_fund_handovers', 'central_finance_hq_funding_requests', 'central_finance_ledger_entries'];
        $missingCore = array_values(array_filter($coreTables, fn (string $table): bool => !Schema::connection('mysql')->hasTable($table)));
        $checks[] = $this->check(
            'finance_safety',
            'Central Finance core services',
            $missingCore === [],
            $missingCore === [] ? '' : 'Central Finance schema is incomplete: :tables.',
            ['tables' => implode(', ', $missingCore)],
        );

        return $checks;
    }

    public function assertReadyForCentral(School $school): void
    {
        foreach ($this->checklist($school) as $check) {
            if ($check['status'] === 'blocked') {
                throw new LogicException($check['reason']);
            }
        }
    }

    /** @return list<array{key:string,label:string,status:string,reason:string,reason_params:array<string,string>}> */
    private function syncChecks(School $school): array
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_receivables')
            || !Schema::connection('mysql')->hasColumn('central_finance_school_cutovers', 'receivable_sync_effective_at')) {
            return [];
        }

        // A Fresh Start boundary is a configuration prerequisite in its own
        // right. Check it before source reconciliation so a School can never
        // become ready merely because it has no profiles yet.
        $cutoff = app(CentralFinanceTenantFeeAssignmentSource::class)->freshStartCutoff($school->id);
        $checks = [
            $this->check(
                'receivable_cutoff',
                'Fresh Start receivable cutoff',
                $cutoff !== null,
                'Set an explicit approved cutover-effective datetime before Central Receivable readiness.',
            ),
        ];
        if ($cutoff === null || !Schema::connection('mysql')->hasTable('central_finance_student_profiles')) {
            return $checks;
        }

        try {
            $profiles = $this->studentProfiles->reconcileSchool($school);
            $profileHealthy = $profiles['missing_in_central'] === [] && $profiles['stale_in_central'] === [] && $profiles['mismatched'] === [];
            $profileReason = 'Student Profile reconciliation must have missing=0, stale=0, and mismatched=0.';
        } catch (\Throwable) {
            $profileHealthy = false;
            $profileReason = 'Student Profile reconciliation is unavailable for this trusted School source.';
        }
        $failedProfiles = Schema::connection('mysql')->hasTable('central_finance_sync_events')
            && DB::connection('mysql')->table('central_finance_sync_events')->where('school_id', $school->id)->where('status', 'failed')->exists();
        $checks[] = $this->check('student_profiles', 'Student Profile reconciliation', $profileHealthy && !$failedProfiles, $failedProfiles ? 'Resolve failed Student Profile sync events before cutover.' : $profileReason);

        if (!Schema::connection('mysql')->hasTable('central_finance_receivable_sync_events')) {
            return $checks;
        }
        try {
            $receivables = $this->receivables->reconcileSchool($school);
            $receivableHealthy = $receivables['missing_in_central'] === [] && $receivables['stale_in_central'] === [] && $receivables['mismatched'] === [] && $receivables['blocked_paid'] === [];
            $receivableReason = 'Receivable reconciliation must have no missing, stale, mismatched, or blocked paid sources.';
        } catch (\Throwable) {
            $receivableHealthy = false;
            $receivableReason = 'Receivable reconciliation is unavailable for this trusted School source.';
        }
        $failedReceivables = DB::connection('mysql')->table('central_finance_receivable_sync_events')->where('school_id', $school->id)->where('status', 'failed')->exists();
        $checks[] = $this->check('receivables', 'Receivable sync health', $receivableHealthy && !$failedReceivables, $failedReceivables ? 'Resolve failed Receivable sync events before cutover.' : $receivableReason);

        return $checks;
    }

    private function isActiveSchoolAccountant(CentralFinanceUser $actor, School $school): bool
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_school_staff_identities')) {
            return false;
        }

        return CentralFinanceSchoolStaffIdentity::on('mysql')->where([
            'central_user_id' => $actor->id,
            'school_id' => $school->id,
            'status' => 'active',
        ])->whereExists(function ($query) use ($actor, $school): void {
            $query->selectRaw('1')->from('central_finance_user_school_scopes')
                ->whereColumn('central_finance_user_school_scopes.user_id', 'central_finance_school_staff_identities.central_user_id')
                ->where('central_finance_user_school_scopes.user_id', $actor->id)
                ->where('central_finance_user_school_scopes.school_id', $school->id)
                ->where('central_finance_user_school_scopes.can_view', true)
                ->where('central_finance_user_school_scopes.can_operate', true);
        })->exists();
    }

    /** @return array{key:string,label:string,status:string,reason:string,reason_params:array<string,string>} */
    private function check(string $key, string $label, bool $passes, string $reason, array $reasonParameters = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $passes ? 'pass' : 'blocked',
            'reason' => $passes ? '' : $reason,
            'reason_params' => $passes ? [] : $reasonParameters,
        ];
    }
}
