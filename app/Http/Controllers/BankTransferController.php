<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\FundHandover;
use App\Services\BootstrapTableService;
use App\Services\FinanceAccountAccessService;
use App\Services\FundAccountBalanceService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;

class BankTransferController extends Controller
{
    public function index()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noAnyPermissionThenRedirect(['expense-create', 'expense-list']);

        $bankAccounts = app(FinanceAccountAccessService::class)
            ->accessibleAccounts(Auth::user())
            ->where('is_active', true)
            ->orderBy('account_name')
            ->get();

        return view('bank-account.transfer.index', compact('bankAccounts'));
    }

    public function list(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noAnyPermissionThenSendJson(['expense-create', 'expense-list']);

        $offset = $request->input('offset', 0);
        $limit  = $request->input('limit', 10);
        $sort   = $request->input('sort', 'transfer_date');
        $order  = $request->input('order', 'DESC');
        $search = $request->input('search');

        $accountIds = app(FinanceAccountAccessService::class)->accessibleAccounts(Auth::user())->pluck('id');
        $sql = BankTransfer::owner()
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
            if ($row->status === 'completed'
                && $accountIds->contains($row->from_account_id)
                && $accountIds->contains($row->to_account_id)) {
                $operate .= BootstrapTableService::deleteButton(route('bank-transfers.destroy', $row->id));
            }

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

    public function store(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-create');

        $request->validate([
            'from_account_id' => 'required|exists:bank_accounts,id',
            'to_account_id'   => 'required|exists:bank_accounts,id|different:from_account_id',
            'amount'          => 'required|numeric|min:0.01',
            'transfer_date'   => 'required|date',
            'reference_no'    => 'nullable|string|max:100',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $access = app(FinanceAccountAccessService::class);
        $fromAccount = $access->authorize(Auth::user(), (int) $request->from_account_id);
        $toAccount   = $access->authorize(Auth::user(), (int) $request->to_account_id);

        try {
            DB::beginTransaction();

            $schoolId = Auth::user()->school_id;

            // Same currency check: only allow transfers between same-currency accounts
            if ($fromAccount->currency !== $toAccount->currency) {
                return response()->json([
                    'error'   => true,
                    'message' => __('Cannot transfer between accounts with different currencies.'),
                ], 422);
            }

            // Shared with FundHandover confirmation so every completed
            // transfer uses one canonical balance calculation.
            $currentBalance = app(FundAccountBalanceService::class)->currentBalance($fromAccount);

            if ($currentBalance < $request->amount) {
                return response()->json([
                    'error'   => true,
                    'message' => __('Insufficient balance in source account. Current balance: :balance', [
                        'balance' => number_format($currentBalance, 2),
                    ]),
                ], 422);
            }

            $data = $request->only([
                'from_account_id', 'to_account_id', 'amount',
                'transfer_date', 'reference_no', 'notes',
            ]);
            $data['school_id']  = $schoolId;
            $data['status']     = 'completed';
            $data['created_by'] = Auth::id();

            BankTransfer::create($data);

            DB::commit();
            ResponseService::successResponse(__('Bank transfer created successfully'));
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'BankTransferController -> Store');
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-create');

        $access = app(FinanceAccountAccessService::class);
        $transfer = BankTransfer::owner()->findOrFail($id);
        abort_if(
            FundHandover::where('bank_transfer_id', $transfer->id)->where('status', FundHandover::STATUS_CONFIRMED)->exists(),
            422,
            'A confirmed fund handover is immutable and cannot be cancelled as an immediate transfer.',
        );
        abort_unless(
            $access->canAccessAccount(Auth::user(), $transfer->from_account)
            && $access->canAccessAccount(Auth::user(), $transfer->to_account),
            403,
        );

        try {
            if ($transfer->status !== 'completed') {
                return response()->json([
                    'error'   => true,
                    'message' => __('Only completed transfers can be cancelled.'),
                ], 422);
            }

            $transfer->update(['status' => 'cancelled']);
            $transfer->delete(); // soft delete

            ResponseService::successResponse(__('Bank transfer cancelled successfully'));
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'BankTransferController -> Destroy');
            ResponseService::errorResponse();
        }
    }
}
