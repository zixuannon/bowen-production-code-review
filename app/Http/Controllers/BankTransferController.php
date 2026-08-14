<?php

namespace App\Http\Controllers;

use App\Models\BankTransfer;
use App\Services\BankTransferService;
use App\Services\BootstrapTableService;
use App\Services\FinanceAccountAccessService;
use App\Services\FinanceAuthorizationService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        return view('bank-account.transfer.index', compact('bankAccounts'));
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

    public function store(Request $request, BankTransferService $transfers)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-transfer-create');

        $request->validate([
            'from_account_id' => 'required|exists:bank_accounts,id',
            'to_account_id'   => 'required|exists:bank_accounts,id|different:from_account_id',
            'amount'          => 'required|numeric|min:0.01',
            'transfer_date'   => 'required|date',
            'reference_no'    => 'nullable|string|max:100',
            'notes'           => 'nullable|string|max:1000',
        ]);

        try {
            $transfer = $transfers->create(Auth::user(), $request->only([
                'from_account_id', 'to_account_id', 'amount',
                'transfer_date', 'reference_no', 'notes',
            ]));

            return response()->json([
                'error' => false,
                'message' => __('Bank transfer created successfully'),
                'id' => $transfer->id,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException | HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            ResponseService::logErrorResponse($exception, 'BankTransferController -> Store');
            return response()->json(['error' => true, 'message' => __('Error Occurred')], 500);
        }
    }

    public function destroy($id, BankTransferService $transfers)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-transfer-create');

        try {
            $transfer = BankTransfer::owner()->findOrFail($id);
            $transfers->cancel(Auth::user(), $transfer);

            return response()->json([
                'error' => false,
                'message' => __('Bank transfer cancelled successfully'),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException | HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            ResponseService::logErrorResponse($exception, 'BankTransferController -> Destroy');
            return response()->json(['error' => true, 'message' => __('Error Occurred')], 500);
        }
    }
}
