<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\OptionalFee;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;

class BankAccountController extends Controller
{
    public function index()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noAnyPermissionThenRedirect(['expense-create', 'expense-list']);

        $accountTypes = [
            'bank'          => __('Bank'),
            'cash'          => __('Cash'),
            'mobile_wallet' => __('Mobile Wallet'),
        ];

        return view('bank-account.index', compact('accountTypes'));
    }

    public function list(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noAnyPermissionThenSendJson(['expense-create', 'expense-list']);

        $offset = $request->input('offset', 0);
        $limit  = $request->input('limit', 10);
        $sort   = $request->input('sort', 'id');
        $order  = $request->input('order', 'DESC');
        $search = $request->input('search');

        $sql = BankAccount::owner()->withTrashed();

        if ($search) {
            $sql->where(function ($q) use ($search) {
                $q->where('account_name', 'LIKE', "%{$search}%")
                    ->orWhere('account_number', 'LIKE', "%{$search}%")
                    ->orWhere('bank_name', 'LIKE', "%{$search}%");
            });
        }

        // Exclude soft-deleted unless explicitly requested
        if (!$request->has('trashed')) {
            $sql->whereNull('deleted_at');
        }

        $total = $sql->count();

        if ($offset >= $total && $total > 0) {
            $offset = floor(($total - 1) / $limit) * $limit;
        }

        $rows = $sql->orderBy($sort, $order)
            ->skip($offset)
            ->take($limit)
            ->get();

        $schoolId = Auth::user()->school_id;

        // Eager-load income/expense sums for each account
        $bankAccountIds = $rows->pluck('id');

        // Compulsory fee income sums
        $compulsoryIncome = CompulsoryFee::whereIn('bank_account_id', $bankAccountIds)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->selectRaw('bank_account_id, SUM(amount) as total')
            ->groupBy('bank_account_id')
            ->pluck('total', 'bank_account_id');

        // Optional fee income sums
        $optionalIncome = OptionalFee::whereIn('bank_account_id', $bankAccountIds)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->selectRaw('bank_account_id, SUM(amount) as total')
            ->groupBy('bank_account_id')
            ->pluck('total', 'bank_account_id');

        // Expense sums
        $expenseSums = Expense::whereIn('bank_account_id', $bankAccountIds)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->selectRaw('bank_account_id, SUM(amount) as total')
            ->groupBy('bank_account_id')
            ->pluck('total', 'bank_account_id');

        $bulkData = [];
        $bulkData['total'] = $total;
        $dataRows  = [];
        $no        = 1;

        foreach ($rows as $row) {
            $compulsory = (float)($compulsoryIncome[$row->id] ?? 0);
            $optional   = (float)($optionalIncome[$row->id] ?? 0);
            $expenses   = (float)($expenseSums[$row->id] ?? 0);
            $income     = $compulsory + $optional;
            $balance    = (float)$row->opening_balance + $income - $expenses;

            $operate = '';
            if (!$row->trashed()) {
                $operate .= BootstrapTableService::editButton(route('bank-accounts.edit', $row->id));
                if (!$row->is_default) {
                    $operate .= BootstrapTableService::deleteButton(route('bank-accounts.destroy', $row->id));
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no']              = $no++;
            $tempRow['account_type_name'] = $row->account_type;
            $tempRow['income_total']    = number_format($income, 2);
            $tempRow['expense_total']   = number_format($expenses, 2);
            $tempRow['current_balance'] = number_format($balance, 2);
            $tempRow['status_badge']    = $row->is_active ? '<span class="badge badge-success">' . __('Active') . '</span>' : '<span class="badge badge-secondary">' . __('Inactive') . '</span>';
            $tempRow['default_badge']   = $row->is_default ? '<span class="badge badge-info">' . __('Default') . '</span>' : '';
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
            'account_name'        => 'required|string|max:255',
            'account_number'      => 'nullable|string|max:100',
            'bank_name'           => 'nullable|string|max:255',
            'account_type'        => 'required|in:bank,cash,mobile_wallet',
            'currency'            => 'required|in:MMK,USD,CNY',
            'opening_balance'     => 'nullable|numeric|min:0',
            'opening_balance_date'=> 'nullable|date',
            'is_active'           => 'nullable|boolean',
            'is_default'          => 'nullable|boolean',
            'notes'               => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $schoolId = Auth::user()->school_id;

            // If this is set as default, unset other defaults
            if ($request->is_default) {
                BankAccount::where('school_id', $schoolId)
                    ->update(['is_default' => false]);
            }

            $data = $request->only([
                'account_name', 'account_number', 'bank_name',
                'account_type', 'currency', 'opening_balance',
                'opening_balance_date', 'is_active', 'is_default', 'notes',
            ]);
            $data['school_id'] = $schoolId;
            $data['opening_balance']      = $request->opening_balance ?? 0;
            $data['opening_balance_date'] = $request->opening_balance_date ?: null;
            $data['is_active']  = $request->has('is_active') ? (bool)$request->is_active : true;
            $data['is_default'] = $request->has('is_default') ? (bool)$request->is_default : false;

            BankAccount::create($data);

            DB::commit();
            ResponseService::successResponse(__('Bank account created successfully'));
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'BankAccountController -> Store');
            ResponseService::errorResponse();
        }
    }

    public function show($id)
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noPermissionThenRedirect('expense-list');

        $bankAccount = BankAccount::owner()->withTrashed()->findOrFail($id);

        $schoolId = Auth::user()->school_id;

        // Compulsory fee income for this account
        $compulsoryFees = CompulsoryFee::with('student:id,first_name,last_name')
            ->where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderBy('date', 'desc')
            ->limit(100)
            ->get();

        // Optional fee income for this account
        $optionalFees = OptionalFee::with('student:id,first_name,last_name')
            ->where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderBy('date', 'desc')
            ->limit(100)
            ->get();

        // Expenses for this account
        $expenses = Expense::with('category:id,name', 'staff:id,id')
            ->where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderBy('date', 'desc')
            ->limit(100)
            ->get();

        // Totals
        $totalCompulsory = CompulsoryFee::where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->sum('amount') ?? 0;

        $totalOptional = OptionalFee::where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->sum('amount') ?? 0;

        $totalExpenses = Expense::where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->sum('amount') ?? 0;

        $totalIncome = (float)$totalCompulsory + (float)$totalOptional;
        $currentBalance = (float)$bankAccount->opening_balance + $totalIncome - (float)$totalExpenses;

        return view('bank-account.show', compact(
            'bankAccount',
            'compulsoryFees',
            'optionalFees',
            'expenses',
            'totalCompulsory',
            'totalOptional',
            'totalIncome',
            'totalExpenses',
            'currentBalance'
        ));
    }

    public function edit($id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-create');

        $bankAccount = BankAccount::owner()->findOrFail($id);

        return response()->json([
            'error' => false,
            'data'  => $bankAccount,
        ]);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-create');

        $request->validate([
            'account_name'        => 'required|string|max:255',
            'account_number'      => 'nullable|string|max:100',
            'bank_name'           => 'nullable|string|max:255',
            'account_type'        => 'required|in:bank,cash,mobile_wallet',
            'currency'            => 'required|in:MMK,USD,CNY',
            'opening_balance'     => 'nullable|numeric|min:0',
            'opening_balance_date'=> 'nullable|date',
            'is_active'           => 'nullable|boolean',
            'is_default'          => 'nullable|boolean',
            'notes'               => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $bankAccount = BankAccount::owner()->findOrFail($id);
            $schoolId    = Auth::user()->school_id;

            // If set as default, unset other defaults
            if ($request->is_default) {
                BankAccount::where('school_id', $schoolId)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            $data = $request->only([
                'account_name', 'account_number', 'bank_name',
                'account_type', 'currency', 'opening_balance',
                'opening_balance_date', 'is_active', 'is_default', 'notes',
            ]);
            $data['opening_balance']      = $request->opening_balance ?? 0;
            $data['opening_balance_date'] = $request->opening_balance_date ?: null;
            $data['is_active']  = $request->has('is_active') ? (bool)$request->is_active : false;
            $data['is_default'] = $request->has('is_default') ? (bool)$request->is_default : false;

            $bankAccount->update($data);

            DB::commit();
            ResponseService::successResponse(__('Bank account updated successfully'));
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'BankAccountController -> Update');
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-create');

        try {
            $bankAccount = BankAccount::owner()->findOrFail($id);

            if ($bankAccount->is_default) {
                return response()->json([
                    'error'   => true,
                    'message' => __('Cannot delete default bank account.'),
                ], 422);
            }

            $bankAccount->update(['is_active' => false]);
            $bankAccount->delete(); // soft delete

            ResponseService::successResponse(__('Bank account deleted successfully'));
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'BankAccountController -> Destroy');
            ResponseService::errorResponse();
        }
    }
}
