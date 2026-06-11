<?php

namespace App\Http\Controllers;

use App\Models\FinanceCategory;
use App\Repositories\Expense\ExpenseInterface;
use App\Repositories\ExpenseCategory\ExpenseCategoryInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use App\Services\SessionYearsTrackingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Throwable;
use Carbon\Carbon;

class ExpenseController extends Controller
{

    private ExpenseInterface $expense;
    private ExpenseCategoryInterface $expenseCategory;
    private SessionYearInterface $sessionYear;
    private CachingService $cache;
    private SessionYearsTrackingsService $sessionYearsTrackingsService;

    public function __construct(ExpenseInterface $expense, ExpenseCategoryInterface $expenseCategory, SessionYearInterface $sessionYear, CachingService $cache, SessionYearsTrackingsService $sessionYearsTrackingsService)
    {
        $this->expense = $expense;
        $this->expenseCategory = $expenseCategory;
        $this->sessionYear = $sessionYear;
        $this->cache = $cache;
        $this->sessionYearsTrackingsService = $sessionYearsTrackingsService;
    }

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noAnyPermissionThenRedirect(['expense-create', 'expense-list']);

        $expenseCategory = $this->expenseCategory->builder()->pluck('name', 'id')->toArray();
        $sessionYear = $this->sessionYear->builder()->pluck('name', 'id');
        $sessionYearFullData = $this->sessionYear->builder()->get();
        $current_session_year = app(CachingService::class)->getDefaultSessionYear();

        $financeCategories = FinanceCategory::where('type', 'expense')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name', 'id')
            ->toArray();

        $months = sessionYearWiseMonth();

        return view('expense.index', compact('expenseCategory', 'financeCategories', 'sessionYear', 'current_session_year', 'months', 'sessionYearFullData'));
    }


    public function create()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noPermissionThenRedirect('expense-create');
    }


    public function store(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-create');
        $request->validate([
            'ref_no' => [
                'nullable',
                Rule::unique('expenses', 'ref_no')
                    ->where(function ($query) use ($request) {
                        return $query->where('session_year_id', $request->session_year_id)->whereNull('vehicle_id')->whereNull('staff_id');
                    })
            ],
            'amount' => 'required|numeric|min:0',
            'transaction_currency' => 'nullable|string|size:3|in:MMK,USD,CNY',
            'original_amount' => 'nullable|numeric|min:0',
            'exchange_rate_snapshot' => 'nullable|numeric|min:0',
        ], [
            'ref_no.unique' => 'Reference number already exists for the selected session year.'
        ]);
        try {
            DB::beginTransaction();
            $schoolSettings = $this->cache->getSchoolSettings();
            
            // ========== 多货币处理 ==========
            $transactionCurrency = strtoupper($request->transaction_currency ?? 'MMK');
            $exchangeRate = (float)($request->exchange_rate_snapshot ?? 1);
            $originalAmount = (float)($request->original_amount ?? $request->amount);
            $amount = (float)$request->amount; // amount 保存 MMK 等值
            
            if ($transactionCurrency === 'MMK') {
                $originalAmount = $amount;
                $exchangeRate = 1;
            } else {
                if ($originalAmount <= 0) {
                    $originalAmount = $amount / $exchangeRate;
                }
            }
            $amountMmk = $amount;
            // =================================
            
            $data = [
                'category_id' => $request->category_id,
                'finance_category_id' => $request->finance_category_id ?: null,
                'title' => $request->title,
                'ref_no' => $request->ref_no,
                'amount' => $amount,
                'date' => $request->date
                    ? Carbon::createFromFormat($schoolSettings['date_format'], $request->date)->format('Y-m-d')
                    : null,
                'description' => $request->description,
                'session_year_id' => $request->session_year_id,
                'transaction_currency' => $transactionCurrency,
                'original_amount' => $originalAmount,
                'exchange_rate_snapshot' => $exchangeRate,
                'amount_mmk' => $amountMmk,
            ];

            $expense = $this->expense->create($data);

            $sessionYear = $this->cache->getDefaultSessionYear();
            $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\Expense', $expense->id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);

            DB::commit();
            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Expense Controller -> Store Method");
            ResponseService::errorResponse();
        }
    }


    public function show($id)
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noPermissionThenRedirect('expense-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'date');
        $order = request('order', 'DESC');
        $search = request('search');
        $category_id = request('category_id');
        $session_year_id = request('session_year_id');
        $month = request('month');

        // $sql = $this->expense->builder()->with('category')->select('*', DB::raw('SUM(amount) as total_salary'))->groupBy('month', 'date')->where(function ($query) use ($search) {
        //         $query->when($search, function ($query) use ($search) {
        //             $query->where(function ($query) use ($search) {
        //                 $query->where('title', 'LIKE', "%$search%")->orWhere('ref_no', 'LIKE', "%$search%")->orWhere('amount', 'LIKE', "%$search%")->orWhere('date', 'LIKE', "%$search%")->orWhere('description', 'LIKE', "%$search%")->orWhereHas('category', function ($q) use ($search) {
        //                         $q->Where('name', 'LIKE', "%$search%");
        //                     });
        //             });
        //         });
        //     });

        $sql = $this->expense->builder()->with('category', 'finance_category')->whereNull('vehicle_id')->where(function ($query) use ($search) {
            $query->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'LIKE', "%$search%")->orWhere('ref_no', 'LIKE', "%$search%")->orWhere('amount', 'LIKE', "%$search%")->orWhere('date', 'LIKE', "%$search%")->orWhere('description', 'LIKE', "%$search%")->orWhereHas('category', function ($q) use ($search) {
                        $q->Where('name', 'LIKE', "%$search%");
                    })->orWhereHas('finance_category', function ($q) use ($search) {
                        $q->Where('name', 'LIKE', "%$search%");
                    });
                });
            });
        });

        if ($category_id) {
            if ($category_id != 'salary') {
                $sql->where('category_id', $category_id)->whereNull('staff_id');
            } else {
                $sql->whereNotNull('staff_id');

            }
        }

        if ($session_year_id) {
            $sql->where('session_year_id', $session_year_id);
        }

        if ($month) {
            $sql->whereMonth('date', $month);
        }

        $total = $sql->get()->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            if (!$row->month) {
                $operate .= BootstrapTableService::editButton(route('expense.update', $row->id));
                $operate .= BootstrapTableService::deleteButton(route('expense.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['amount'] = $row->amount;
            if ($row->staff_id) {
                $tempRow['category.name'] = 'Salary';
            }
            $tempRow['finance_category_name'] = $row->finance_category->name ?? null;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }


    public function update(Request $request, $id)
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-edit');
        $request->validate([
            'ref_no' => [
                'nullable',
                Rule::unique('expenses', 'ref_no')
                    ->ignore($id) // Ignore current expense ID
                    ->where(function ($query) use ($request) {
                        return $query->where('session_year_id', $request->session_year_id)
                            ->whereNull('vehicle_id')
                            ->whereNull('staff_id');
                    }),
            ],
            'amount' => 'required|numeric|min:0',
            'transaction_currency' => 'nullable|string|size:3|in:MMK,USD,CNY',
            'original_amount' => 'nullable|numeric|min:0',
            'exchange_rate_snapshot' => 'nullable|numeric|min:0',
        ], [
            'ref_no.unique' => 'Reference number already exists for the selected session year.'
        ]);
        try {
            DB::beginTransaction();
            $schoolSettings = $this->cache->getSchoolSettings();
            
            // ========== 多货币处理 ==========
            $transactionCurrency = strtoupper($request->transaction_currency ?? 'MMK');
            $exchangeRate = (float)($request->exchange_rate_snapshot ?? 1);
            $originalAmount = (float)($request->original_amount ?? $request->amount);
            $amount = (float)$request->amount; // amount 保存 MMK 等值
            
            if ($transactionCurrency === 'MMK') {
                $originalAmount = $amount;
                $exchangeRate = 1;
            } else {
                if ($originalAmount <= 0) {
                    $originalAmount = $amount / $exchangeRate;
                }
            }
            $amountMmk = $amount;
            // =================================
            
            $data = [
                'category_id' => $request->category_id,
                'finance_category_id' => $request->finance_category_id ?: null,
                'title' => $request->title,
                'ref_no' => $request->ref_no,
                'amount' => $amount,
                'date' => $request->date
                    ? Carbon::createFromFormat($schoolSettings['date_format'], $request->date)->format('Y-m-d')
                    : null,
                'description' => $request->description,
                'session_year_id' => $request->session_year_id,
                'transaction_currency' => $transactionCurrency,
                'original_amount' => $originalAmount,
                'exchange_rate_snapshot' => $exchangeRate,
                'amount_mmk' => $amountMmk,
            ];
            $this->expense->update($id, $data);
            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Expense Controller -> Update Method");
            ResponseService::errorResponse();
        }
    }


    public function destroy($id)
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-delete');

        try {
            DB::beginTransaction();
            $this->expense->deleteById($id);
            $sessionYear = $this->cache->getDefaultSessionYear();
            $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\Expense', $id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);
            DB::commit();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Expense Controller -> Destroy Method");
            ResponseService::errorResponse();
        }
    }

    public function filter_graph($session_year_id)
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noAnyPermissionThenSendJson(['expense-create', 'expense-list']);

        try {
            $expense_months = [];
            $expense_amount = [];
            if ($session_year_id == 'undefined' || $session_year_id == '') {
                $session_year_id = $this->cache->getDefaultSessionYear()->id;
            }

            $expense = $this->expense->builder()->select(DB::raw('MONTH(date) as month'), DB::raw('SUM(amount) as total_amount'))->where('session_year_id', $session_year_id)
                ->groupBy(DB::raw('MONTH(date)'));
            $expense = $expense->get()->pluck('total_amount', 'month')->toArray();

            $months = sessionYearWiseMonth();

            foreach ($months as $key => $month) {
                if (isset($expense[$key])) {
                    // $expense_months[] = substr($months[$key], 0, 3);
                    $expense_months[] = $months[$key];
                    $expense_amount[] = $expense[$key];
                }
            }
            $data = [
                'expense_months' => $expense_months,
                'expense_amount' => $expense_amount
            ];

            ResponseService::successResponse('Data Fetched Successfully', $data);
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Expense Controller -> Filter Method");
            ResponseService::errorResponse();
        }
    }
}
