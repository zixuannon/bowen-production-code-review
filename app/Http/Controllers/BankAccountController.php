<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\OptionalFee;
use App\Models\OtherIncome;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use App\Services\FinanceAccountAccessService;
use App\Services\FinanceAuthorizationService;
use App\Services\FinanceTransactionRegisterService;
use App\Services\FundAccountBalanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;

class BankAccountController extends Controller
{
    public function index()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-fund-account-view');

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
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-fund-account-view');

        $offset = $request->input('offset', 0);
        $limit  = $request->input('limit', 10);
        $sort   = $request->input('sort', 'id');
        $order  = $request->input('order', 'DESC');
        $search = $request->input('search');

        $sql = app(FinanceAccountAccessService::class)->scope(Auth::user())->withTrashed();

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

        $cashFlowSummaries = app(FundAccountBalanceService::class)->cashFlowSummaries($rows);

        $bulkData = [];
        $bulkData['total'] = $total;
        $dataRows  = [];
        $no        = 1;

        foreach ($rows as $row) {
            $cashFlow = $cashFlowSummaries->get($row->id);

            $operate = '';
            if (!$row->trashed()) {
                $operate .= BootstrapTableService::viewButton(route('bank-accounts.show', $row->id));
                if (app(FinanceAccountAccessService::class)->canManageAccounts(Auth::user())) {
                    $operate .= BootstrapTableService::editButton(route('bank-accounts.edit', $row->id));
                    if (!$row->is_default) {
                        $operate .= BootstrapTableService::deleteButton(route('bank-accounts.destroy', $row->id));
                    }
                }
            }

            $tempRow = $row->toArray();
            $tempRow['opening_balance_date'] = $row->opening_balance_date
                ? $row->opening_balance_date->format('d-m-Y')
                : null;
            $tempRow['no']              = $no++;
            $tempRow['account_type_name'] = $row->account_type;
            $tempRow['money_in_total']  = round($cashFlow['money_in'], 2);
            $tempRow['money_out_total'] = round($cashFlow['money_out'], 2);
            $tempRow['current_balance'] = round($cashFlow['current_balance'], 2);
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
        abort_unless(app(FinanceAccountAccessService::class)->canManageAccounts(Auth::user()), 403);
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-fund-account-manage');

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
            $data['opening_balance_date'] = $this->normalizeOpeningBalanceDate($request->opening_balance_date);
            $data['is_active']  = $request->has('is_active') ? (bool)$request->is_active : true;
            $data['is_default'] = $request->has('is_default') ? (bool)$request->is_default : false;
            $data['created_by'] = Auth::id();

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
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-fund-account-view');

        $bankAccount = app(FinanceAccountAccessService::class)->scope(Auth::user())->withTrashed()->findOrFail($id);

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

        $otherIncomes = OtherIncome::with('creator:id,first_name,last_name')
            ->where('bank_account_id', $id)->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderByDesc('date')->limit(100)->get();

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

        $totalOtherIncome = OtherIncome::where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))->sum('amount') ?? 0;

        $totalExpenses = Expense::where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->sum('amount') ?? 0;

        $cashFlow = app(FundAccountBalanceService::class)->cashFlowSummary($bankAccount);
        $totalIncome = $cashFlow['operating_income'];
        $totalExpenses = $cashFlow['operating_expense'];
        $totalTransferIn = $cashFlow['internal_in'];
        $totalTransferOut = $cashFlow['internal_out'];
        $currentBalance = $cashFlow['current_balance'];

        // ================ Transaction Ledger ================
        // Load all records (ASC by raw date, then by id for stable ordering)
        $ledgerCompulsory = CompulsoryFee::with('student:id,first_name,last_name')
            ->where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ledgerOptional = OptionalFee::with('student:id,first_name,last_name')
            ->where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ledgerOtherIncome = OtherIncome::with('creator:id,first_name,last_name')
            ->where('bank_account_id', $id)->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderBy('date', 'asc')->orderBy('id', 'asc')->get();

        $ledgerExpenses = Expense::with('category:id,name')
            ->where('bank_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ledgerTransfersIn = BankTransfer::with('from_account:id,account_name')
            ->where('to_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->where('status', 'completed')
            ->orderBy('transfer_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ledgerTransfersOut = BankTransfer::with('to_account:id,account_name')
            ->where('from_account_id', $id)
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->where('status', 'completed')
            ->orderBy('transfer_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ledgerGroupTransfers = app(FinanceTransactionRegisterService::class)
            ->register(Auth::user(), ['bank_account_id' => $id, 'type' => 'bank_transfer'])['rows']
            ->where('source_type', 'group_transfer')
            ->values();

        // Count total records (excl. opening) to decide display limit
        $ledgerTotalCount = $ledgerCompulsory->count() + $ledgerOptional->count() + $ledgerOtherIncome->count() + $ledgerExpenses->count()
                          + $ledgerTransfersIn->count() + $ledgerTransfersOut->count() + $ledgerGroupTransfers->count();

        $ledgerRows = collect();

        // 1. Opening Balance — always first before any transaction
        $ledgerRows->push([
            'raw_date'   => '0000-00-00', // ensures always first in sort
            'date'       => $bankAccount->opening_balance_date ?? $bankAccount->created_at,
            'type'       => __('Opening Balance'),
            'type_key'   => 'opening',
            'description'=> __('Opening Balance'),
            'payee'      => '-',
            'ref_id'     => '-',
            'income'     => 0,
            'expense'    => 0,
        ]);

        // 2. Compulsory Fee Income
        foreach ($ledgerCompulsory as $fee) {
            $studentName = $fee->student->full_name ?? null;
            $ledgerRows->push([
                'raw_date'   => $fee->getRawOriginal('date') ?? '',
                'date'       => $fee->date,
                'type'       => __('Compulsory Fee Income'),
                'type_key'   => 'compulsory',
                'description'=> $studentName ?: ('Student #' . $fee->student_id),
                'payee'      => $studentName ?: '-',
                'ref_id'     => $fee->id,
                'income'     => (float)$fee->amount,
                'expense'    => 0,
            ]);
        }

        // 3. Transfer In
        foreach ($ledgerTransfersIn as $transfer) {
            $fromName = $transfer->from_account->account_name ?? '-';
            $refNo    = $transfer->reference_no ? ('#' . $transfer->reference_no) : '';
            $ledgerRows->push([
                'raw_date'   => $transfer->getRawOriginal('transfer_date') ?? '',
                'date'       => $transfer->transfer_date,
                'type'       => __('Transfer In'),
                'type_key'   => 'transfer_in',
                'description'=> __('From :account', ['account' => $fromName]) . $refNo,
                'payee'      => $fromName,
                'ref_id'     => 'TR-' . $transfer->id,
                'income'     => (float)$transfer->amount,
                'expense'    => 0,
            ]);
        }

        // 4. Optional Fee Income
        foreach ($ledgerOptional as $fee) {
            $studentName = $fee->student->full_name ?? null;
            $ledgerRows->push([
                'raw_date'   => $fee->getRawOriginal('date') ?? '',
                'date'       => $fee->date,
                'type'       => __('Optional Fee Income'),
                'type_key'   => 'optional',
                'description'=> $studentName ?: ('Student #' . $fee->student_id),
                'payee'      => $studentName ?: '-',
                'ref_id'     => $fee->id,
                'income'     => (float)$fee->amount,
                'expense'    => 0,
            ]);
        }

        // 5. Other Income
        foreach ($ledgerOtherIncome as $income) {
            $ledgerRows->push([
                'raw_date' => $income->getRawOriginal('date') ?? '', 'date' => $income->date,
                'type' => __('Other Income'), 'type_key' => 'other_income',
                'description' => $income->description, 'payee' => $income->payer,
                'ref_id' => $income->reference_no ?: ('OI-' . $income->id),
                'income' => (float) $income->amount, 'expense' => 0,
            ]);
        }

        // 5. Expenses
        foreach ($ledgerExpenses as $expense) {
            $ledgerRows->push([
                'raw_date'   => $expense->getRawOriginal('date') ?? '',
                'date'       => $expense->date,
                'type'       => __('Expense'),
                'type_key'   => 'expense',
                'description'=> $expense->title ?? ('Expense #' . $expense->id),
                'payee'      => $expense->category->name ?? '-',
                'ref_id'     => $expense->id,
                'income'     => 0,
                'expense'    => (float)$expense->amount,
            ]);
        }

        // 6. Transfer Out
        foreach ($ledgerTransfersOut as $transfer) {
            $toName = $transfer->to_account->account_name ?? '-';
            $refNo  = $transfer->reference_no ? ('#' . $transfer->reference_no) : '';
            $ledgerRows->push([
                'raw_date'   => $transfer->getRawOriginal('transfer_date') ?? '',
                'date'       => $transfer->transfer_date,
                'type'       => __('Transfer Out'),
                'type_key'   => 'transfer_out',
                'description'=> __('To :account', ['account' => $toName]) . $refNo,
                'payee'      => $toName,
                'ref_id'     => 'TR-' . $transfer->id,
                'income'     => 0,
                'expense'    => (float)$transfer->amount,
            ]);
        }

        foreach ($ledgerGroupTransfers as $transfer) {
            $isIn = (float) $transfer['money_in'] > 0;
            $counterparty = $transfer['counterparty'] ?: __('HQ Fund Account');
            $ledgerRows->push([
                'raw_date' => $transfer['date'],
                'date' => $transfer['date'],
                'type' => $isIn ? __('Transfer In') : __('Transfer Out'),
                'type_key' => $isIn ? 'transfer_in' : 'transfer_out',
                'description' => $transfer['description'],
                'payee' => $counterparty,
                'ref_id' => $transfer['reference'] ?: ('GTR-' . $transfer['source_id']),
                'income' => (float) $transfer['money_in'],
                'expense' => (float) $transfer['money_out'],
            ]);
        }

        // Sort: raw_date ASC, type_key order ensures stable same-day ordering
        $typeOrder = ['opening' => 0, 'compulsory' => 1, 'transfer_in' => 2, 'optional' => 3, 'other_income' => 4, 'expense' => 5, 'transfer_out' => 6];
        $ledgerRows = $ledgerRows->sort(function ($a, $b) use ($typeOrder) {
            $dateCmp = strcmp($a['raw_date'], $b['raw_date']);
            if ($dateCmp !== 0) return $dateCmp;
            $typeCmp = ($typeOrder[$a['type_key']] ?? 9) <=> ($typeOrder[$b['type_key']] ?? 9);
            if ($typeCmp !== 0) return $typeCmp;
            return ($a['ref_id'] ?? '') <=> ($b['ref_id'] ?? '');
        })->values();

        // Compute Running Balance (forward pass)
        $running = 0;
        $ledgerRows = $ledgerRows->map(function ($row) use (&$running, $bankAccount) {
            if ($row['type_key'] === 'opening') {
                $running = (float)$bankAccount->opening_balance;
            } else {
                $running += $row['income'] - $row['expense'];
            }
            $row['running_balance'] = round($running, 2);
            return $row;
        });

        // Reverse for display (newest first)
        $ledgerRows = $ledgerRows->reverse()->values();

        // Apply display limit if too many rows
        $ledgerDisplayLimit = 500;
        $ledgerLimited = $ledgerTotalCount > $ledgerDisplayLimit;

        if ($ledgerLimited) {
            $ledgerRows = $ledgerRows->take($ledgerDisplayLimit);
        }
        // ================ End Transaction Ledger ================

        return view('bank-account.show', compact(
            'bankAccount',
            'compulsoryFees',
            'optionalFees',
            'otherIncomes',
            'expenses',
            'totalCompulsory',
            'totalOptional',
            'totalOtherIncome',
            'totalIncome',
            'totalExpenses',
            'totalTransferIn',
            'totalTransferOut',
            'currentBalance',
            'ledgerRows',
            'ledgerTotalCount',
            'ledgerLimited',
            'ledgerDisplayLimit'
        ));
    }

    public function edit($id)
    {
        $access = app(FinanceAccountAccessService::class);
        abort_unless($access->canManageAccounts(Auth::user()), 403);
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-fund-account-manage');

        $bankAccount = $access->scope(Auth::user())->findOrFail($id);

        return response()->json([
            'error' => false,
            'data'  => $bankAccount,
        ]);
    }

    public function update(Request $request, $id)
    {
        $access = app(FinanceAccountAccessService::class);
        abort_unless($access->canManageAccounts(Auth::user()), 403);
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-fund-account-manage');

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

        $bankAccount = $access->scope(Auth::user())->findOrFail($id);

        try {
            DB::beginTransaction();

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
            $data['opening_balance_date'] = $this->normalizeOpeningBalanceDate($request->opening_balance_date);
            $data['is_active']  = $request->has('is_active') ? (bool)$request->is_active : false;
            $data['is_default'] = $request->has('is_default') ? (bool)$request->is_default : false;
            $data['updated_by'] = Auth::id();

            // Log opening balance adjustment if changed
            $newBalance = (float) ($request->opening_balance ?? 0);
            $newBalanceDate = $data['opening_balance_date'];
            $oldBalance = (float) $bankAccount->opening_balance;
            $oldBalanceDate = $bankAccount->opening_balance_date?->toDateString();

            $balanceChanged = abs($newBalance - $oldBalance) > 0.001;
            $dateChanged = $newBalanceDate !== $oldBalanceDate;

            if ($balanceChanged || $dateChanged) {
                $reason = $request->adjustment_reason;
                if (empty(trim($reason ?? ''))) {
                    return response()->json([
                        'error'   => true,
                        'message' => __('Please provide a reason for the opening balance change.'),
                    ], 422);
                }

                \App\Models\BankAccountBalanceAdjustment::create([
                    'bank_account_id'           => $bankAccount->id,
                    'old_opening_balance'       => $oldBalance,
                    'new_opening_balance'       => $newBalance,
                    'old_opening_balance_date'  => $oldBalanceDate,
                    'new_opening_balance_date'  => $newBalanceDate,
                    'changed_by'                => Auth::id(),
                    'reason'                    => trim($reason),
                ]);
            }

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
        $access = app(FinanceAccountAccessService::class);
        abort_unless($access->canManageAccounts(Auth::user()), 403);
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-fund-account-manage');

        $bankAccount = $access->scope(Auth::user())->findOrFail($id);

        try {
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

    private function normalizeOpeningBalanceDate(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
            return Carbon::createFromFormat('!d-m-Y', $date)->toDateString();
        }

        return Carbon::parse($date)->toDateString();
    }
}
