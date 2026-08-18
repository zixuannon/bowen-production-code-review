<?php

namespace App\Services;

use App\Exceptions\FinanceGroupTenantUnavailableException;
use App\Models\FinanceGroupSchool;
use App\Models\FinanceGroupUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Read-only Group Finance aggregation over canonical tenant Ledger V1 rows.
 * It never writes a Group ledger, adjusts balances, or establishes a tenant
 * connection from request-controlled database input.
 */
class FinanceGroupReportService
{
    public function __construct(
        private readonly FinanceGroupScopeService $scope,
        private readonly FinanceLedgerV1Service $ledger,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, float>, schools: Collection<int, array<string, mixed>>, incomplete: Collection<int, array<string, mixed>>}
     */
    public function register(FinanceGroupUser $groupUser, array $filters = []): array
    {
        $memberships = $this->selectedMemberships($groupUser, $filters);
        if ($memberships->isEmpty()) {
            throw new AuthorizationException('This Group user has no active reporting scope.');
        }

        if (isset($filters['bank_account_id']) && !isset($filters['school_id'])) {
            throw ValidationException::withMessages([
                'bank_account_id' => [__('A Fund Account filter requires one authorized School.')],
            ]);
        }

        $rows = collect();
        $schools = collect();
        $incomplete = collect();
        foreach ($memberships as $membership) {
            try {
                $register = $this->scope->readLedgerAsTenantIdentity(
                    $groupUser,
                    $membership,
                    $this->ledger,
                    $this->tenantFilters($filters),
                );
                $school = $membership->school;
                $schoolName = $school?->name ?? ('School #' . $membership->school_id);
                $rows = $rows->concat($register['rows']->map(fn (array $row) => array_merge($row, [
                    'group_id' => $groupUser->group_id,
                    'school_name' => $schoolName,
                ])));
                $schools->push([
                    'school_id' => $membership->school_id,
                    'school_name' => $schoolName,
                    'status' => 'complete',
                    'operating_income' => (float) $register['summary']['operating_income'],
                    'operating_expense' => (float) $register['summary']['operating_expense'],
                    'internal_transfer_amount' => (float) $register['summary']['internal_transfer_amount'],
                ]);
            } catch (AuthorizationException|ValidationException|ModelNotFoundException|\LogicException $exception) {
                // A malformed or unauthorized request must be rejected, never
                // presented as a report with one tenant silently omitted.
                throw $exception;
            } catch (FinanceGroupTenantUnavailableException $exception) {
                // A Group total with one omitted tenant must never look complete.
                // Keep the technical exception out of user-facing report data.
                $school = $membership->school;
                $incomplete->push([
                    'school_id' => $membership->school_id,
                    'school_name' => $school?->name ?? ('School #' . $membership->school_id),
                    'status' => 'incomplete',
                    'reason' => __('Tenant report data is unavailable or its identity mapping needs attention.'),
                ]);
            } catch (Throwable $exception) {
                // Schema/connection failures are also surfaced as incomplete;
                // they do not change the report totals into a false "complete"
                // consolidation and the tenant connection is restored by scope.
                $school = $membership->school;
                $incomplete->push([
                    'school_id' => $membership->school_id,
                    'school_name' => $school?->name ?? ('School #' . $membership->school_id),
                    'status' => 'incomplete',
                    'reason' => __('Tenant report data is unavailable or its identity mapping needs attention.'),
                ]);
            }
        }

        if ($rows->pluck('ledger_key')->duplicates()->isNotEmpty()) {
            throw new \LogicException('Group Ledger V1 aggregation contains a duplicate canonical source identity.');
        }

        $rows = $rows->sortByDesc(fn (array $row) => $row['posting_date'] . ':' . $row['ledger_key'])->values();
        $summary = [
            'operating_income' => (float) $rows->sum('operating_income'),
            'operating_expense' => (float) $rows->sum('operating_expense'),
            'internal_transfer_amount' => (float) $rows->sum('internal_transfer_amount'),
        ];
        $summary['operating_net'] = $summary['operating_income'] - $summary['operating_expense'];

        return compact('rows', 'summary', 'schools', 'incomplete');
    }

    /** @param array<string, mixed> $filters
     * @return Collection<int, FinanceGroupSchool>
     */
    private function selectedMemberships(FinanceGroupUser $groupUser, array $filters): Collection
    {
        $memberships = $this->scope->accessibleSchools($groupUser, 'view_reports')->load('school');
        if (!isset($filters['school_id'])) {
            return $memberships;
        }

        $schoolId = (int) $filters['school_id'];
        $selected = $memberships->where('school_id', $schoolId)->values();
        if ($selected->isEmpty()) {
            throw new AuthorizationException('The requested School is outside the Group reporting scope.');
        }

        return $selected;
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function tenantFilters(array $filters): array
    {
        return collect($filters)->only([
            'from', 'to', 'type', 'reference', 'keyword', 'bank_account_id',
        ])->all();
    }
}
