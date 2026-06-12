<?php

namespace App\Http\Controllers;

use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\FinanceCategory;
use App\Models\OptionalFee;
use App\Models\SessionYear;
use App\Models\Students;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Auth;

class FinanceReportController extends Controller
{
    /**
     * Show the Finance Report (read-only).
     *
     * Income = actual received payments (status=Success) only.
     * Outstanding = reference value, NOT included in income.
     *
     * Safe compulsory category: one fees_id may have multiple
     * fees_class_types (optional=0). We build a map that avoids
     * double-counting: if all fct share the same non-null
     * finance_category_id, use it; otherwise group under
     * "Compulsory Fees" or "Uncategorized".
     */
    public function index()
    {
        $request = request();

        ResponseService::noPermissionThenRedirect('fees-paid');

        $schoolId = Auth::user()->school_id;
        $cache    = app(CachingService::class);

        // ---- Default date range: current month ----
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());
        $typeFilter     = $request->get('type', 'all');        // all | income | expense
        $categoryFilter = $request->get('finance_category_id'); // null = all

        // ---- Finance Categories for filter dropdown ----
        $financeCategories = FinanceCategory::orderBy('sort_order')->orderBy('name')->get();

        // ---- 1. Build safe fees_id → category map (compulsory) ----
        $feeIdCategoryMap = $this->buildCompulsoryCategoryMap();

        // ---- 2. Compulsory Income (status=Success) ----
        $compulsoryQuery = CompulsoryFee::where('compulsory_fees.status', 'Success')
            ->where('compulsory_fees.school_id', $schoolId)
            ->whereBetween('compulsory_fees.date', [$from, $to])
            ->join('fees_paids', 'compulsory_fees.fees_paid_id', '=', 'fees_paids.id');

        $compulsoryRows = $compulsoryQuery->get(['compulsory_fees.amount', 'compulsory_fees.id', 'fees_paids.fees_id']);

        // Attach category name to each compulsory row (one row = one payment, one category)
        $compulsoryRows->each(function ($row) use ($feeIdCategoryMap) {
            $row->_category = $feeIdCategoryMap[$row->fees_id] ?? __('Compulsory Fees');
        });

        // ---- 3. Optional Income (status=Success) ----
        $optionalQuery = OptionalFee::where('optional_fees.status', 'Success')
            ->where('optional_fees.school_id', $schoolId)
            ->whereBetween('optional_fees.date', [$from, $to]);

        $optionalRows = $optionalQuery->get(['optional_fees.amount', 'optional_fees.id', 'optional_fees.fees_class_id']);

        // Resolve optional category names in one batch
        $feesClassIds = $optionalRows->pluck('fees_class_id')->unique()->filter()->values()->toArray();
        $optionalCategoryMap = [];
        if (!empty($feesClassIds)) {
            $fctRecords = FeesClassType::whereIn('id', $feesClassIds)
                ->with('finance_category')
                ->get();
            foreach ($fctRecords as $fct) {
                $optionalCategoryMap[$fct->id] = $fct->finance_category->name ?? __('Uncategorized');
            }
        }

        // Attach category name to each optional row
        $optionalRows->each(function ($row) use ($optionalCategoryMap) {
            $row->_category = $optionalCategoryMap[$row->fees_class_id] ?? __('Uncategorized');
        });

        // ---- 4. Expenses ----
        $expenseQuery = Expense::where('school_id', $schoolId)
            ->whereBetween('date', [$from, $to]);

        $expenseRows = $expenseQuery->get(['id', 'amount', 'amount_mmk', 'finance_category_id']);

        // Resolve expense category names in one batch
        $expenseCatIds = $expenseRows->pluck('finance_category_id')->unique()->filter()->values()->toArray();
        $expenseCategoryMap = [];
        if (!empty($expenseCatIds)) {
            $fcRecords = FinanceCategory::whereIn('id', $expenseCatIds)->get();
            foreach ($fcRecords as $fc) {
                $expenseCategoryMap[$fc->id] = $fc->name;
            }
        }

        // Attach category name and MMK amount to each expense row
        $expenseRows->each(function ($row) use ($expenseCategoryMap) {
            $row->_category = $row->finance_category_id
                ? ($expenseCategoryMap[$row->finance_category_id] ?? __('Uncategorized'))
                : __('Uncategorized');
            $row->_mmk_amount = ($row->amount_mmk > 0) ? $row->amount_mmk : $row->amount;
        });

        // ---- 5. Apply filters to raw rows BEFORE computing summary totals ----
        $filteredCompulsory = $compulsoryRows;
        $filteredOptional   = $optionalRows;
        $filteredExpense    = $expenseRows;

        if ($typeFilter === 'income') {
            $filteredExpense = collect();
        } elseif ($typeFilter === 'expense') {
            $filteredCompulsory = collect();
            $filteredOptional   = collect();
        }

        if ($categoryFilter) {
            $filteredCompulsory = $filteredCompulsory->filter(fn($r) => $r->_category === $categoryFilter);
            $filteredOptional   = $filteredOptional->filter(fn($r) => $r->_category === $categoryFilter);
            $filteredExpense    = $filteredExpense->filter(fn($r) => $r->_category === $categoryFilter);
        }

        // ---- 6. Summary totals from FILTERED rows (P2-2: cards follow filters) ----
        $totalCompulsoryIncome = $filteredCompulsory->sum('amount');
        $totalOptionalIncome   = $filteredOptional->sum('amount');
        $totalIncome           = $totalCompulsoryIncome + $totalOptionalIncome;

        $totalExpense = $filteredExpense->sum(fn($r) => $r->_mmk_amount ?? 0);

        $netIncome = $totalIncome - $totalExpense;

        // ---- 7. Aggregate into category table rows from FILTERED rows ----
        $categoryRows = collect();

        // Compulsory by category
        $compulsoryByCat = [];
        foreach ($filteredCompulsory as $row) {
            $catName = $row->_category;
            if (!isset($compulsoryByCat[$catName])) {
                $compulsoryByCat[$catName] = ['amount' => 0, 'count' => 0];
            }
            $compulsoryByCat[$catName]['amount'] += $row->amount;
            $compulsoryByCat[$catName]['count']++;
        }
        foreach ($compulsoryByCat as $cat => $data) {
            $categoryRows->push([
                'category' => $cat,
                'type'     => 'Income',
                'source'   => 'Compulsory',
                'amount'   => $data['amount'],
                'count'    => $data['count'],
            ]);
        }

        // Optional by category
        $optionalByCat = [];
        foreach ($filteredOptional as $row) {
            $catName = $row->_category;
            if (!isset($optionalByCat[$catName])) {
                $optionalByCat[$catName] = ['amount' => 0, 'count' => 0];
            }
            $optionalByCat[$catName]['amount'] += $row->amount;
            $optionalByCat[$catName]['count']++;
        }
        foreach ($optionalByCat as $cat => $data) {
            $categoryRows->push([
                'category' => $cat,
                'type'     => 'Income',
                'source'   => 'Optional',
                'amount'   => $data['amount'],
                'count'    => $data['count'],
            ]);
        }

        // Expense by category
        $expenseByCat = [];
        foreach ($filteredExpense as $row) {
            $catName = $row->_category;
            if (!isset($expenseByCat[$catName])) {
                $expenseByCat[$catName] = ['amount' => 0, 'count' => 0];
            }
            $expenseByCat[$catName]['amount'] += $row->_mmk_amount;
            $expenseByCat[$catName]['count']++;
        }
        foreach ($expenseByCat as $cat => $data) {
            $categoryRows->push([
                'category' => $cat,
                'type'     => 'Expense',
                'source'   => 'Expense',
                'amount'   => $data['amount'],
                'count'    => $data['count'],
            ]);
        }

        // ---- 8. Current Outstanding (reference only, NOT affected by income/expense filters) ----
        $currentOutstanding = $this->computeOutstandingReference($schoolId);

        // ---- 9. Calculate percentages ----
        $grandTotal = $totalIncome + $totalExpense;
        $categoryRows = $categoryRows->map(function ($row) use ($grandTotal) {
            $row['percentage'] = $grandTotal > 0 ? round(($row['amount'] / $grandTotal) * 100, 1) : 0;
            return $row;
        });

        // ---- 10. Detect if filters are active (for view indicator) ----
        $hasFilter = ($typeFilter !== 'all') || ($categoryFilter !== null);

        return view('finance-report.index', compact(
            'categoryRows',
            'totalIncome',
            'totalExpense',
            'netIncome',
            'totalCompulsoryIncome',
            'totalOptionalIncome',
            'currentOutstanding',
            'from',
            'to',
            'typeFilter',
            'categoryFilter',
            'financeCategories',
            'hasFilter',
        ));
    }

    /**
     * Build a fees_id → category_name map that avoids double-counting.
     *
     * For each fees_id with optional=0 fee class types:
     *   - If all fct share the same non-null finance_category_id → use that category name
     *   - If multiple different categories → "Compulsory Fees"
     *   - If finance_category_id is null → "Uncategorized"
     *
     * NOTE: If the test data does not have finance_category_id assigned
     * on fees_class_types records, all compulsory income will display as
     * "Uncategorized". This is a data configuration issue, not a code bug.
     * Set finance_category_id on relevant fees_class_types to fix.
     */
    private function buildCompulsoryCategoryMap(): array
    {
        $rows = FeesClassType::where('optional', 0)
            ->with('finance_category')
            ->get(['id', 'fees_id', 'finance_category_id']);

        $map = [];

        foreach ($rows->groupBy('fees_id') as $feesId => $group) {
            $catIds = $group->pluck('finance_category_id')->unique()->filter()->values();

            if ($catIds->isEmpty()) {
                // All null → Uncategorized
                $map[$feesId] = __('Uncategorized');
            } elseif ($catIds->count() === 1) {
                // Single category → use its name
                $catId = $catIds->first();
                $cat   = $group->first()->finance_category;
                $map[$feesId] = $cat ? $cat->name : __('Uncategorized');
            } else {
                // Multiple different categories → group under Compulsory Fees
                $map[$feesId] = __('Compulsory Fees');
            }
        }

        return $map;
    }

    /**
     * Compute current outstanding across all students (reference only).
     *
     * Follows the same logic as OutstandingFeesController but
     * aggregates school-wide without per-student details.
     */
    private function computeOutstandingReference($schoolId): float
    {
        $cache    = app(CachingService::class);
        $sessionYear = $cache->getDefaultSessionYear($schoolId);
        if (!$sessionYear) {
            return 0;
        }

        $students = Students::where('school_id', $schoolId)->get();

        if ($students->isEmpty()) {
            return 0;
        }

        // Collect class_ids
        $studentUserIds = [];
        $studentClassMap = []; // user_id → class_id
        foreach ($students as $stu) {
            $userId  = $stu->user_id;
            $classId = $stu->class_section->class_id ?? $stu->class_id;
            $studentUserIds[]         = $userId;
            $studentClassMap[$userId] = $classId;
        }

        $uniqueClassIds = array_unique(array_filter(array_values($studentClassMap)));
        if (empty($uniqueClassIds)) {
            return 0;
        }

        // Fee structures for these classes
        $allFees = Fee::whereIn('class_id', $uniqueClassIds)
            ->where('session_year_id', $sessionYear->id)
            ->with(['fees_class_type' => function ($q) {
                $q->where('optional', 0);
            }])
            ->get();

        // fees by class_id
        $feesByClass = [];
        foreach ($allFees as $fee) {
            $feesByClass[$fee->class_id][] = $fee;
        }

        $allFeeIds = $allFees->pluck('id')->toArray();

        // Batch query compulsory paid
        $allCompulsoryPaid = collect();
        if (!empty($allFeeIds) && !empty($studentUserIds)) {
            $allCompulsoryPaid = CompulsoryFee::whereIn('student_id', $studentUserIds)
                ->where('status', 'Success')
                ->whereHas('fees_paid', function ($q) use ($allFeeIds) {
                    $q->whereIn('fees_id', $allFeeIds);
                })
                ->get();
        }

        $paidByStudent = [];
        foreach ($allCompulsoryPaid as $paid) {
            $paidByStudent[$paid->student_id]['total'][] = $paid->amount;
        }

        $totalOutstanding = 0;

        foreach ($students as $stu) {
            $userId  = $stu->user_id;
            $classId = $studentClassMap[$userId] ?? null;
            $classFees = $classId ? ($feesByClass[$classId] ?? []) : [];

            $expected = 0;
            foreach ($classFees as $fee) {
                $expected += $fee->fees_class_type->sum(function ($item) {
                    return ($item->fee_amount_mmk > 0) ? $item->fee_amount_mmk : $item->amount;
                });
            }

            $paid = isset($paidByStudent[$userId])
                ? array_sum($paidByStudent[$userId]['total'])
                : 0;

            $totalOutstanding += max(0, $expected - $paid);
        }

        return $totalOutstanding;
    }
}
