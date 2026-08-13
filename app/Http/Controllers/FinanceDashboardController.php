<?php

namespace App\Http\Controllers;

use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\FinanceCategory;
use App\Models\FeesPaid;
use App\Models\OptionalFee;
use App\Models\OtherIncome;
use App\Models\Students;
use App\Services\CachingService;
use App\Services\ResponseService;
use App\Services\FinanceAuthorizationService;
use Illuminate\Support\Facades\Auth;

class FinanceDashboardController extends Controller
{
    public function index()
    {
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-dashboard-view');

        $request   = request();
        $schoolId  = Auth::user()->school_id;
        $cache     = app(CachingService::class);
        $sessionYear = $cache->getDefaultSessionYear();
        $sessionYearId = $sessionYear->id ?? null;

        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        // ── Fee structures for session year ──
        $allFees   = collect();
        $allFeeIds = [];
        if ($sessionYearId) {
            $allFees = Fee::owner()->where('session_year_id', $sessionYearId)
                ->with(['fees_class_type.finance_category'])
                ->get();
            $allFeeIds = $allFees->pluck('id')->toArray();
        }

        // ── Income (date-filtered) ──
        // P2-3: Use pure date filter consistent with Finance Report.
        // Previously cross-filtered by session year fee structure which caused
        // income mismatch between Dashboard and Finance Report.
        $compulsoryIncome = CompulsoryFee::where('school_id', $schoolId)
            ->where('status', 'Success')
            ->whereBetween('date', [$from, $to])
            ->sum('amount');

        $optionalIncome = OptionalFee::where('school_id', $schoolId)
            ->where('status', 'Success')
            ->whereBetween('date', [$from, $to])
            ->sum('amount');

        $otherIncome = OtherIncome::where('school_id', $schoolId)
            ->whereBetween('date', [$from, $to])
            ->sum('amount');

        $totalIncome = $compulsoryIncome + $optionalIncome + $otherIncome;

        // ── Expense (date-filtered) ──
        $expenses = Expense::owner()
            ->whereBetween('date', [$from, $to])
            ->get();
        $totalExpense = $expenses->sum(fn($e) => ($e->amount_mmk > 0) ? $e->amount_mmk : $e->amount);
        $netIncome = $totalIncome - $totalExpense;

        // ── Collection Rate ──
        $totalExpected     = $this->computeTotalExpected($schoolId, $allFees);
        $allCompulsoryPaid = 0;
        if (!empty($allFeeIds)) {
            $allCompulsoryPaid = CompulsoryFee::where('school_id', $schoolId)
                ->where('status', 'Success')
                ->whereHas('fees_paid', fn($q) => $q->whereIn('fees_id', $allFeeIds))
                ->sum('amount');
        }
        $collectionRate = $totalExpected > 0 ? round(($allCompulsoryPaid / $totalExpected) * 100, 1) : 0;

        // ── Outstanding Overview ──
        $outstandingOverview = $this->computeOutstandingOverview($schoolId, $allFees, $allFeeIds);

        // ── Category Breakdown ──
        $categoryBreakdown = $this->computeCategoryBreakdown($schoolId, $from, $to);

        // ── Recent Payments ──
        $recentPayments = $this->getRecentPayments($schoolId, $allFeeIds, $from, $to);

        // ── Recent Expenses ──
        $recentExpenses = Expense::owner()
            ->whereBetween('date', [$from, $to])
            ->with(['finance_category'])
            ->orderBy('date', 'desc')
            ->limit(10)
            ->get();

        $hasFilter = ($from !== now()->startOfMonth()->toDateString()) || ($to !== now()->toDateString());

        return view('finance-dashboard.index', compact(
            'from', 'to', 'hasFilter',
            'totalIncome', 'compulsoryIncome', 'optionalIncome', 'otherIncome',
            'totalExpense', 'netIncome',
            'totalExpected', 'allCompulsoryPaid', 'collectionRate',
            'outstandingOverview',
            'categoryBreakdown',
            'recentPayments', 'recentExpenses',
        ));
    }

    // ── Private helpers ──

    private function computeTotalExpected(int $schoolId, $allFees): float
    {
        if ($allFees->isEmpty()) return 0;

        $classIds = $allFees->pluck('class_id')->unique()->toArray();

        $feesByClass = [];
        foreach ($allFees as $fee) {
            $feesByClass[$fee->class_id][] = $fee;
        }

        $students = Students::with('class_section')
            ->where('school_id', $schoolId)
            ->where(function ($q) use ($classIds) {
                $q->whereIn('class_id', $classIds)
                  ->orWhereHas('class_section', fn($sq) => $sq->whereIn('class_id', $classIds));
            })
            ->get();

        $total = 0;
        foreach ($students as $stu) {
            $cid = $stu->class_section->class_id ?? $stu->class_id;
            foreach (($feesByClass[$cid] ?? []) as $fee) {
                $total += $fee->fees_class_type->where('optional', 0)
                    ->sum(fn($it) => ($it->fee_amount_mmk > 0) ? $it->fee_amount_mmk : $it->amount);
            }
        }
        return $total;
    }

    private function computeOutstandingOverview(int $schoolId, $allFees, array $allFeeIds): array
    {
        $empty = [
            'students_with_outstanding' => 0, 'total_expected' => 0,
            'total_compulsory_paid' => 0, 'total_outstanding' => 0,
            'highest_student' => '', 'latest_payment_date' => '',
        ];
        if ($allFees->isEmpty() || empty($allFeeIds)) return $empty;

        $classIds = $allFees->pluck('class_id')->unique()->toArray();
        $feesByClass = [];
        foreach ($allFees as $fee) {
            $feesByClass[$fee->class_id][] = $fee;
        }

        $students = Students::with('class_section', 'user')
            ->where('school_id', $schoolId)
            ->where(function ($q) use ($classIds) {
                $q->whereIn('class_id', $classIds)
                  ->orWhereHas('class_section', fn($sq) => $sq->whereIn('class_id', $classIds));
            })
            ->get();

        $userIds = $students->pluck('user_id')->toArray();
        $paidByUser = CompulsoryFee::whereIn('student_id', $userIds)
            ->where('status', 'Success')
            ->whereHas('fees_paid', fn($q) => $q->whereIn('fees_id', $allFeeIds))
            ->get()
            ->groupBy('student_id');

        $withOutstanding = 0;
        $totalExpected   = 0;
        $totalPaid       = 0;
        $totalOut        = 0;
        $highestOut      = 0;
        $highestName     = '';
        $latestPay       = '';

        foreach ($students as $stu) {
            $uid   = $stu->user_id;
            $cid   = $stu->class_section->class_id ?? $stu->class_id;
            $expected = 0;
            foreach (($feesByClass[$cid] ?? []) as $fee) {
                $expected += $fee->fees_class_type->where('optional', 0)
                    ->sum(fn($it) => ($it->fee_amount_mmk > 0) ? $it->fee_amount_mmk : $it->amount);
            }
            $paid = $paidByUser->get($uid)?->sum('amount') ?? 0;
            $out  = max(0, $expected - $paid);

            $totalExpected += $expected;
            $totalPaid     += $paid;
            $totalOut      += $out;

            if ($out > 0) {
                $withOutstanding++;
                if ($out > $highestOut) {
                    $highestOut  = $out;
                    $highestName = $stu->user->full_name ?? 'N/A';
                }
            }
            $last = $paidByUser->get($uid)?->max('date') ?? '';
            if ($last && $last > $latestPay) {
                $latestPay = $last;
            }
        }

        return [
            'students_with_outstanding' => $withOutstanding,
            'total_expected'            => $totalExpected,
            'total_compulsory_paid'     => $totalPaid,
            'total_outstanding'         => $totalOut,
            'highest_student'           => $highestName,
            'latest_payment_date'       => $latestPay,
        ];
    }

    /**
     * Income/Expense category breakdown.
     * Income uses actual payment data (pure date filter, P2-3: consistent with Finance Report).
     * Expense uses actual expenses.
     */
    private function computeCategoryBreakdown(int $schoolId, string $from, string $to): array
    {
        // ── Income by Category (from actual payments in date range, proportional attribution) ──
        $incomeByCat = [];
        $totalIncomeCat = 0;

        // Get compulsory payments in date range (no session year fee filter)
        $compulsoryPayments = CompulsoryFee::where('school_id', $schoolId)
            ->where('status', 'Success')
            ->whereBetween('date', [$from, $to])
            ->with('fees_paid')
            ->get();

        // Collect fee_ids from actual payments to load category mappings
        $feeIdsFromPayments = $compulsoryPayments
            ->pluck('fees_paid.fees_id')
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        if (!empty($feeIdsFromPayments)) {
            // Load fees_class_types for fee_ids that appear in payments
            $allFcts = FeesClassType::whereIn('fees_id', $feeIdsFromPayments)
                ->with('finance_category')
                ->get();
            $fctByFeeId = $allFcts->groupBy('fees_id');

            $compPerFee = $compulsoryPayments->groupBy(fn($c) => $c->fees_paid->fees_id ?? 0);

            foreach ($compPerFee as $fid => $records) {
                $sum    = $records->sum('amount');
                $fcts   = $fctByFeeId->get($fid, collect());
                $totalW = $fcts->sum(fn($f) => ($f->fee_amount_mmk > 0 ? $f->fee_amount_mmk : $f->amount));
                if ($totalW <= 0) {
                    // No fee_class_type info → Uncategorized
                    $incomeByCat['Uncategorized'] = ($incomeByCat['Uncategorized'] ?? 0) + $sum;
                    $totalIncomeCat += $sum;
                    continue;
                }
                foreach ($fcts as $fct) {
                    $cat = $fct->finance_category->name ?? 'Uncategorized';
                    $weight = ($fct->fee_amount_mmk > 0 ? $fct->fee_amount_mmk : $fct->amount);
                    $incomeByCat[$cat] = ($incomeByCat[$cat] ?? 0) + ($sum * $weight / $totalW);
                    $totalIncomeCat += ($sum * $weight / $totalW);
                }
            }
        }

        // ── Expense by Category ──
        $expenseByCat = [];
        $totalExpenseCat = 0;
        $expenses = Expense::owner()
            ->whereBetween('date', [$from, $to])
            ->with('finance_category')
            ->get();

        foreach ($expenses as $e) {
            $cat = $e->finance_category->name ?? 'Uncategorized';
            $amt = ($e->amount_mmk > 0) ? $e->amount_mmk : $e->amount;
            $expenseByCat[$cat] = ($expenseByCat[$cat] ?? 0) + $amt;
            $totalExpenseCat += $amt;
        }

        // Format
        arsort($incomeByCat);
        arsort($expenseByCat);

        $incomeRows = array_map(fn($cat, $amt) => [
            'category' => $cat, 'amount' => $amt,
            'percentage' => $totalIncomeCat > 0 ? round($amt / $totalIncomeCat * 100, 1) : 0,
        ], array_keys($incomeByCat), $incomeByCat);

        $expenseRows = array_map(fn($cat, $amt) => [
            'category' => $cat, 'amount' => $amt,
            'percentage' => $totalExpenseCat > 0 ? round($amt / $totalExpenseCat * 100, 1) : 0,
        ], array_keys($expenseByCat), $expenseByCat);

        return ['income' => array_values($incomeRows), 'expense' => array_values($expenseRows)];
    }

    /**
     * Recent 10 payments (combined compulsory + optional).
     */
    private function getRecentPayments(int $schoolId, array $allFeeIds, string $from, string $to): array
    {
        $payments = [];
        if (empty($allFeeIds)) return $payments;

        $feesClassTypeIds = FeesClassType::whereIn('fees_id', $allFeeIds)->pluck('id')->toArray();

        $comp = CompulsoryFee::where('school_id', $schoolId)->where('status', 'Success')
            ->whereBetween('date', [$from, $to])
            ->whereHas('fees_paid', fn($q) => $q->whereIn('fees_id', $allFeeIds))
            ->with('student')
            ->orderBy('date', 'desc')->limit(10)->get();

        $opt = collect();
        if (!empty($feesClassTypeIds)) {
            $opt = OptionalFee::where('school_id', $schoolId)->where('status', 'Success')
                ->whereBetween('date', [$from, $to])
                ->whereIn('fees_class_id', $feesClassTypeIds)
                ->with('student')
                ->orderBy('date', 'desc')->limit(10)->get();
        }

        foreach ($comp as $c) {
            $payments[] = [
                'date' => $c->date, 'student' => $c->student->full_name ?? 'N/A',
                'type' => 'Compulsory', 'amount' => $c->amount,
                'method' => $c->mode_name, 'receipt_id' => $c->fees_paid_id,
            ];
        }
        foreach ($opt as $o) {
            $payments[] = [
                'date' => $o->date, 'student' => $o->student->full_name ?? 'N/A',
                'type' => 'Optional', 'amount' => $o->amount,
                'method' => $o->mode_name, 'receipt_id' => $o->fees_paid_id,
            ];
        }

        usort($payments, fn($a, $b) => strcmp($b['date'], $a['date']));
        return array_slice($payments, 0, 10);
    }
}
