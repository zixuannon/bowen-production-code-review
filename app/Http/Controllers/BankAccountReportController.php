<?php

namespace App\Http\Controllers;

use App\Exports\BankAccountSummaryExport;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\OptionalFee;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class BankAccountReportController extends Controller
{
    /**
     * Show the Bank Account Monthly Summary Report.
     */
    public function index()
    {
        ResponseService::noPermissionThenRedirect('expense-list');

        $request     = request();
        $dateFrom    = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo      = $request->get('date_to', now()->toDateString());
        $bankAccountId = $request->get('bank_account_id');

        $schoolId     = Auth::user()->school_id;
        $bankAccounts = BankAccount::where('school_id', $schoolId)
            ->orderBy('account_name')
            ->get();

        $rows = $this->buildSummaryRows($dateFrom, $dateTo, $bankAccountId);

        $totalOpening = array_sum(array_column($rows, 'period_opening_balance'));
        $totalIncome  = array_sum(array_column($rows, 'period_income'));
        $totalExpense = array_sum(array_column($rows, 'period_expense'));
        $totalTransferIn  = array_sum(array_column($rows, 'transfer_in_during'));
        $totalTransferOut = array_sum(array_column($rows, 'transfer_out_during'));
        $totalNetTransfer = array_sum(array_column($rows, 'net_transfer'));
        $totalClosing = array_sum(array_column($rows, 'closing_balance'));

        return view('bank-account-report.index', compact(
            'rows', 'dateFrom', 'dateTo', 'bankAccountId', 'bankAccounts',
            'totalOpening', 'totalIncome', 'totalExpense',
            'totalTransferIn', 'totalTransferOut', 'totalNetTransfer',
            'totalClosing',
        ));
    }

    /**
     * Export the Bank Account Summary Report as Excel (.xlsx).
     */
    public function export()
    {
        ResponseService::noPermissionThenRedirect('expense-list');

        $request     = request();
        $dateFrom    = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo      = $request->get('date_to', now()->toDateString());
        $bankAccountId = $request->get('bank_account_id');

        $rows = $this->buildSummaryRows($dateFrom, $dateTo, $bankAccountId);

        $filename = 'bank_account_summary_' . $dateFrom . '_' . $dateTo . '.xlsx';

        return Excel::download(
            new BankAccountSummaryExport($rows, $dateFrom, $dateTo, $bankAccountId),
            $filename
        );
    }

    /**
     * Build summary rows shared by index() and export().
     *
     * For each bank account, computes:
     *
     *   period_opening_balance
     *     = bank_account.opening_balance
     *     + SUM(compulsory_fees.amount before dateFrom)
     *     + SUM(optional_fees.amount   before dateFrom)
     *     + SUM(transfer_in            before dateFrom)
     *     - SUM(expenses.amount        before dateFrom)
     *     - SUM(transfer_out           before dateFrom)
     *
     *   period_income
     *     = SUM(compulsory_fees.amount in [dateFrom, dateTo])
     *     + SUM(optional_fees.amount   in [dateFrom, dateTo])
     *     (Transfer In NOT included)
     *
     *   period_expense
     *     = SUM(expenses.amount in [dateFrom, dateTo])
     *     (Transfer Out NOT included)
     *
     *   closing_balance
     *     = period_opening_balance + period_income - period_expense
     *     + transfer_in_during - transfer_out_during
     *
     * @param  string       $dateFrom
     * @param  string       $dateTo
     * @param  string|null  $bankAccountId  Single account filter, or null for all
     * @return array
     */
    private function buildSummaryRows(string $dateFrom, string $dateTo, ?string $bankAccountId): array
    {
        $schoolId = Auth::user()->school_id;

        $query = BankAccount::where('school_id', $schoolId);
        if ($bankAccountId) {
            $query->where('id', $bankAccountId);
        }
        $accounts = $query->orderBy('account_name')->get();

        $rows = [];

        foreach ($accounts as $account) {
            // ── Income before period ──
            $compulsoryBefore = (float) CompulsoryFee::where('bank_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->where('date', '<', $dateFrom)
                ->sum('amount');

            $optionalBefore = (float) OptionalFee::where('bank_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->where('date', '<', $dateFrom)
                ->sum('amount');

            $expenseBefore = (float) Expense::where('bank_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->where('date', '<', $dateFrom)
                ->sum('amount');

            // ── Transfer before period ──
            $transferInBefore = (float) BankTransfer::where('to_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->where('transfer_date', '<', $dateFrom)
                ->sum('amount');

            $transferOutBefore = (float) BankTransfer::where('from_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->where('transfer_date', '<', $dateFrom)
                ->sum('amount');

            // Period Opening Balance
            $periodOpening = (float) $account->opening_balance
                           + $compulsoryBefore
                           + $optionalBefore
                           + $transferInBefore
                           - $expenseBefore
                           - $transferOutBefore;

            // ── During period ──
            $compulsoryDuring = (float) CompulsoryFee::where('bank_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->sum('amount');

            $optionalDuring = (float) OptionalFee::where('bank_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->sum('amount');

            $expenseDuring = (float) Expense::where('bank_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->sum('amount');

            // ── Transfer during period (NOT included in income/expense) ──
            $transferInDuring = (float) BankTransfer::where('to_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->whereBetween('transfer_date', [$dateFrom, $dateTo])
                ->sum('amount');

            $transferOutDuring = (float) BankTransfer::where('from_account_id', $account->id)
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->whereBetween('transfer_date', [$dateFrom, $dateTo])
                ->sum('amount');

            $periodIncome  = $compulsoryDuring + $optionalDuring;
            $periodExpense = $expenseDuring;
            $netTransfer   = $transferInDuring - $transferOutDuring;
            $closingBalance = $periodOpening + $periodIncome - $periodExpense + $netTransfer;

            $rows[] = [
                'id'                     => $account->id,
                'account_name'           => $account->account_name,
                'bank_name'              => $account->bank_name ?: '-',
                'currency'               => $account->currency,
                'period_opening_balance' => round($periodOpening, 2),
                'period_income'          => round($periodIncome, 2),
                'period_expense'         => round($periodExpense, 2),
                'transfer_in_during'     => round($transferInDuring, 2),
                'transfer_out_during'    => round($transferOutDuring, 2),
                'net_transfer'           => round($netTransfer, 2),
                'closing_balance'        => round($closingBalance, 2),
            ];
        }

        return $rows;
    }
}
