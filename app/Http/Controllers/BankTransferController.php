<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Services\BootstrapTableService;
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

        $schoolId = Auth::user()->school_id;

        $bankAccounts = BankAccount::where('school_id', $schoolId)
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

        $sql = BankTransfer::owner()->with(['from_account:id,account_name', 'to_account:id,account_name']);

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
            if ($row->status === 'completed') {
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

        try {
            DB::beginTransaction();

            $schoolId = Auth::user()->school_id;

            // Ensure both accounts belong to the same school
            $fromAccount = BankAccount::where('school_id', $schoolId)->findOrFail($request->from_account_id);
            $toAccount   = BankAccount::where('school_id', $schoolId)->findOrFail($request->to_account_id);

            // Same currency check: only allow transfers between same-currency accounts
            if ($fromAccount->currency !== $toAccount->currency) {
                return response()->json([
                    'error'   => true,
                    'message' => __('Cannot transfer between accounts with different currencies.'),
                ], 422);
            }

            // Negative balance check: ensure from_account has sufficient funds
            $compulsoryIncome = (float) \App\Models\CompulsoryFee::where('bank_account_id', $fromAccount->id)
                ->where('school_id', $schoolId)->sum('amount');
            $optionalIncome = (float) \App\Models\OptionalFee::where('bank_account_id', $fromAccount->id)
                ->where('school_id', $schoolId)->sum('amount');
            $expenses = (float) \App\Models\Expense::where('bank_account_id', $fromAccount->id)
                ->where('school_id', $schoolId)->sum('amount');
            $transferIn = (float) BankTransfer::where('to_account_id', $fromAccount->id)
                ->where('school_id', $schoolId)->where('status', 'completed')->sum('amount');
            $transferOut = (float) BankTransfer::where('from_account_id', $fromAccount->id)
                ->where('school_id', $schoolId)->where('status', 'completed')->sum('amount');

            $currentBalance = (float)$fromAccount->opening_balance + $compulsoryIncome + $optionalIncome
                            + $transferIn - $expenses - $transferOut;

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

        try {
            $transfer = BankTransfer::owner()->findOrFail($id);

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
