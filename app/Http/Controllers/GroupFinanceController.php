<?php

namespace App\Http\Controllers;

use App\Exceptions\FinanceGroupTenantUnavailableException;
use App\Models\FinanceGroup;
use App\Models\FinanceGroupSchool;
use App\Services\FinanceGroupReportService;
use App\Services\FinanceGroupScopeService;
use App\Services\GroupFinanceAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Central Head Finance/Accountant read-only entry point.
 *
 * This controller deliberately does not set school_database_name, impersonate
 * a tenant user, or redirect into generic tenant routes.  A selected School is
 * read through its existing explicit Group tenant identity and that identity's
 * normal Fund Account scope only.
 */
class GroupFinanceController extends Controller
{
    public function __construct(
        private readonly GroupFinanceAccessService $access,
        private readonly FinanceGroupScopeService $scope,
        private readonly FinanceGroupReportService $reports,
    ) {
    }

    public function index(): RedirectResponse
    {
        $authenticated = Auth::user();
        abort_unless($authenticated, 403);
        $group = $this->access->firstReportGroup($authenticated);
        abort_unless($group, 403);

        return redirect()->route('group-finance.show', $group);
    }

    public function show(Request $request, FinanceGroup $financeGroup): View
    {
        [$groupUser, $schools, $selected] = $this->context($request, $financeGroup);
        $operatingSchools = $this->scope->accessibleSchools($groupUser, 'operate_finance')->load('school');
        $filters = $this->filters($request, $selected);
        $result = $this->reports->register($groupUser, $filters);
        $accounts = [];

        if ($selected) {
            try {
                $accounts = $this->scope->accessibleActiveTenantAccountsForGroupUser(
                    $groupUser,
                    $selected->school_id,
                    'view_reports',
                );
            } catch (FinanceGroupTenantUnavailableException) {
                // The report itself renders this School as incomplete.  Do not
                // make a missing identity look like a valid all-account scope.
                $accounts = [];
            }
        }

        return view('group-finance.show', compact('financeGroup', 'groupUser', 'schools', 'operatingSchools', 'selected', 'filters', 'result', 'accounts'));
    }

    public function export(Request $request, FinanceGroup $financeGroup): StreamedResponse
    {
        [$groupUser, , $selected] = $this->context($request, $financeGroup, 'export_reports');
        $result = $this->reports->register($groupUser, $this->filters($request, $selected));

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Ledger Key', 'Date', 'School', 'Type', 'Reference', 'Fund Account', 'Money In', 'Money Out', 'Operating Income', 'Operating Expense', 'Internal Transfer']);
            foreach ($result['rows'] as $row) {
                fputcsv($out, [$row['ledger_key'], $row['posting_date'], $row['school_name'], $row['transaction_class'], $row['reference_no'], $row['fund_account_name'], $row['money_in'], $row['money_out'], $row['operating_income'], $row['operating_expense'], $row['internal_transfer_amount']]);
            }
            fclose($out);
        }, 'group-finance-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: \App\Models\FinanceGroupUser, 1: \Illuminate\Support\Collection<int, FinanceGroupSchool>, 2: ?FinanceGroupSchool} */
    private function context(Request $request, FinanceGroup $group, string $capability = 'view_reports'): array
    {
        $authenticated = Auth::user();
        abort_unless($authenticated, 403);
        $groupUser = $this->access->reportUserFor($authenticated, $group);
        $schools = $this->scope->accessibleSchools($groupUser, $capability)->load('school');
        abort_unless($schools->isNotEmpty(), 403);

        $schoolId = $request->integer('school_id') ?: null;
        $selected = $schoolId ? $schools->firstWhere('school_id', $schoolId) : null;
        if ($schoolId && !$selected) {
            abort(403);
        }

        return [$groupUser, $schools, $selected];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, ?FinanceGroupSchool $selected): array
    {
        $filters = $request->only(['from', 'to', 'type', 'reference', 'keyword', 'bank_account_id']);
        if ($selected) {
            $filters['school_id'] = $selected->school_id;
        }

        return $filters;
    }
}
