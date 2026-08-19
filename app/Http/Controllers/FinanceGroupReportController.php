<?php

namespace App\Http\Controllers;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupUser;
use App\Models\User;
use App\Services\FinanceGroupReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/** Dedicated central, read-only Group Finance reporting route. */
class FinanceGroupReportController extends Controller
{
    public function __construct(private readonly FinanceGroupReportService $reports) {}

    public function register(Request $request, FinanceGroup $financeGroup): View
    {
        [$groupUser, $filters] = $this->authorized($request, $financeGroup, 'view_reports');
        $result = $this->reports->register($groupUser, $filters);

        return view('finance-groups.reports.index', compact('financeGroup', 'result', 'filters'));
    }

    public function export(Request $request, FinanceGroup $financeGroup): StreamedResponse
    {
        [$groupUser, $filters] = $this->authorized($request, $financeGroup, 'export_reports');
        $result = $this->reports->register($groupUser, $filters);

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Ledger Key', 'Date', 'School', 'Type', 'Reference', 'Fund Account', 'Money In', 'Money Out', 'Operating Income', 'Operating Expense', 'Internal Transfer']);
            foreach ($result['rows'] as $row) {
                fputcsv($out, [$row['ledger_key'], $row['posting_date'], $row['school_name'], $row['transaction_class'], $row['reference_no'], $row['fund_account_name'], $row['money_in'], $row['money_out'], $row['operating_income'], $row['operating_expense'], $row['internal_transfer_amount']]);
            }
            fclose($out);
        }, 'finance-group-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: FinanceGroupUser, 1: array<string,mixed>} */
    private function authorized(Request $request, FinanceGroup $group, string $capability): array
    {
        $auth = Auth::user();
        abort_unless($auth && $auth->school_id === null, 403);
        $central = User::on('mysql')->find($auth->id);
        abort_unless($central && $central->school_id === null, 403);
        $groupUser = FinanceGroupUser::query()->where('group_id', $group->id)->where('central_user_id', $central->id)->where('status', 'active')->first();
        abort_unless($groupUser, 403);
        abort_unless(app(\App\Services\FinanceGroupScopeService::class)->accessibleSchools($groupUser, $capability)->isNotEmpty(), 403);
        return [$groupUser, $request->only(['school_id', 'from', 'to', 'type', 'reference', 'keyword', 'bank_account_id'])];
    }
}
