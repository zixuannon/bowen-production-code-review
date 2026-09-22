<?php

namespace App\Http\Controllers;

use App\Models\BankTransfer;
use App\Services\BootstrapTableService;
use App\Services\FinanceAccountAccessService;
use App\Services\FinanceAuthorizationService;
use App\Services\ResponseService;
use App\Services\TrustedSchoolScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BankTransferController extends Controller
{
    public function index()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-transfer-view');

        $bankAccounts = app(FinanceAccountAccessService::class)
            ->accessibleAccounts(Auth::user())
            ->where('is_active', true)
            ->orderBy('account_name')
            ->get();

        // Legacy transfer rows remain readable, but all writes are retired.
        $legacyFinanceReadOnly = true;
        return view('bank-account.transfer.index', compact('bankAccounts', 'legacyFinanceReadOnly'));
    }

    public function list(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-transfer-view');

        $offset = $request->input('offset', 0);
        $limit  = $request->input('limit', 10);
        $sort   = $request->input('sort', 'transfer_date');
        $order  = $request->input('order', 'DESC');
        $search = $request->input('search');

        $accountIds = app(FinanceAccountAccessService::class)->accessibleAccounts(Auth::user())->pluck('id');
        // Bank transfers are retained as a legacy historical register. Its
        // scope must be the trusted current school, not the model's legacy
        // role-derived owner scope.
        $schoolId = app(TrustedSchoolScopeService::class)->schoolIdFor(Auth::user());
        $sql = BankTransfer::query()->where('school_id', $schoolId)
            ->where(function ($query) use ($accountIds) {
                $query->whereIn('from_account_id', $accountIds)->orWhereIn('to_account_id', $accountIds);
            })
            ->with(['from_account:id,account_name', 'to_account:id,account_name']);

        if ($search) {
            $sql->where(function ($q) use ($search) {
                $q->where('reference_no', 'LIKE', "%{$search}%")
                  ->orWhere('notes', 'LIKE', "%{$search}%");
            });
        }

        $total = $sql->count();

        if ($offset >= $total && $total > 0) {
            $offset = floor(($total - 1) / $limit) * $limit;
        }

        $rows = $sql->orderBy($sort, $order)
            ->skip($offset)
            ->take($limit)
            ->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $dataRows  = [];
        $no        = 1;

        foreach ($rows as $row) {
            $operate = '';
            // A Cashier may see a transfer touching an assigned account, but
            // cancellation changes both sides. Do not present an action that
            // the server must reject unless the user controls both accounts.
            // The legacy register is historical-only; do not offer reversal.

            $tempRow = $row->toArray();
            $tempRow['no']              = $no++;
            $tempRow['from_account_name'] = $row->from_account->account_name ?? '-';
            $tempRow['to_account_name']   = $row->to_account->account_name ?? '-';
            $tempRow['status_badge']      = $row->status === 'completed'
                ? '<span class="badge badge-success">' . __('Completed') . '</span>'
                : '<span class="badge badge-secondary">' . __('Cancelled') . '</span>';
            $tempRow['operate']         = $operate;

            $dataRows[] = $tempRow;
        }

        $bulkData['rows'] = $dataRows;

        return response()->json($bulkData);
    }

    public function store(Request $request): never
    {
        app(\App\Services\LegacyFinanceRetirementService::class)->rejectWrite('Bank Transfer create');
    }

    public function destroy(int $id): never
    {
        app(\App\Services\LegacyFinanceRetirementService::class)->rejectWrite('Bank Transfer cancellation');
    }
}
