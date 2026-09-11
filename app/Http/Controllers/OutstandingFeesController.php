<?php

namespace App\Http\Controllers;

use App\Exports\OutstandingFeesExport;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\OptionalFee;
use App\Models\Students;
use App\Models\SessionYear;
use App\Models\ClassSection;
use App\Models\School;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class OutstandingFeesController extends Controller
{
    /**
     * List all students with outstanding fees summary.
     *
     * Batch query strategy (avoids N+1):
     *   1. Load students with eager load (user, guardian, class_section)
     *   2. Resolve class_id per student (class_section.class_id ?? class_id)
     *   3. Group students by class_id, batch query fees
     *   4. Batch query compulsory_fees paid via whereIn student_id
     *   5. Aggregate in PHP per student
     *
     * IMPORTANT: compulsory_fees.student_id = users.id, NOT students.id
     */
    public function index()
    {
        ResponseService::noPermissionThenRedirect('fees-paid');

        $request   = request();
        $cache     = app(CachingService::class);
        $sessionYear = $cache->getDefaultSessionYear();
        $schoolId  = Auth::user()->school_id;

        // Session Year filter (default = current session)
        $filterSessionYearId = $request->get('session_year_id', $sessionYear->id ?? null);
        $allSessionYears = SessionYear::owner()->orderBy('start_date', 'desc')->get();

        // Filters
        $search            = $request->get('search');
        $classSectionFilter = $request->get('class_section_id');
        $statusFilter       = $request->get('status');
        $outstandingOnly    = $request->get('outstanding_only');

        // Class Sections for filter dropdown
        $classSections = ClassSection::owner()->with('class', 'section')->get();

        // If no session year, show empty
        if (!$filterSessionYearId) {
            return view('outstanding-fees.index', [
                'resultRows'      => collect(),
                'summary'         => collect(),
                'allSessionYears'  => $allSessionYears,
                'filterSessionYearId' => null,
                'classSections'    => $classSections,
                'search'           => $search,
                'classSectionFilter' => $classSectionFilter,
                'statusFilter'     => $statusFilter,
                'outstandingOnly'  => $outstandingOnly,
            ]);
        }

        $filters = compact(
            'search', 'classSectionFilter', 'statusFilter',
            'outstandingOnly', 'filterSessionYearId'
        );

        [$resultRows, $summary] = $this->buildOutstandingFeesData($schoolId, $filters);

        return view('outstanding-fees.index', compact(
            'resultRows', 'summary', 'allSessionYears', 'filterSessionYearId',
            'classSections', 'search', 'classSectionFilter', 'statusFilter', 'outstandingOnly'
        ));
    }

    /**
     * Export outstanding fees data as Excel (.xlsx).
     */
    public function export()
    {
        ResponseService::noPermissionThenRedirect('fees-paid');

        $request   = request();
        $cache     = app(CachingService::class);
        $sessionYear = $cache->getDefaultSessionYear();
        $schoolId  = Auth::user()->school_id;

        $filterSessionYearId = $request->get('session_year_id', $sessionYear->id ?? null);

        $search            = $request->get('search');
        $classSectionFilter = $request->get('class_section_id');
        $statusFilter       = $request->get('status');
        $outstandingOnly    = $request->get('outstanding_only');

        $school = School::findOrFail($schoolId);

        // Resolve session year name for Excel display (P2-1)
        $filterSessionYearName = $filterSessionYearId
            ? SessionYear::owner()->where('id', $filterSessionYearId)->value('name')
            : null;

        $filters = compact(
            'search', 'classSectionFilter', 'statusFilter',
            'outstandingOnly', 'filterSessionYearId', 'filterSessionYearName'
        );

        [$resultRows, $summary] = $this->buildOutstandingFeesData($schoolId, $filters);

        $filename = 'outstanding_fees_' . now()->format('Ymd') . '.xlsx';

        return Excel::download(
            new OutstandingFeesExport($resultRows, $summary, $school->name, $filters),
            $filename
        );
    }

    /**
     * Build the outstanding fees data: resultRows and summary.
     *
     * Returns: [resultRows (Collection), summary (array)]
     *
     * Each result row contains:
     *   full_name, admission_no, user_id, class_name, section_name,
     *   guardian_name, contact, compulsory_expected, compulsory_paid,
     *   optional_paid, outstanding, last_payment_date,
     *   status, status_label
     */
    private function buildOutstandingFeesData(int $schoolId, array $filters): array
    {
        $search              = $filters['search'] ?? null;
        $classSectionFilter   = $filters['classSectionFilter'] ?? null;
        $statusFilter         = $filters['statusFilter'] ?? null;
        $outstandingOnly      = $filters['outstandingOnly'] ?? false;
        $filterSessionYearId  = $filters['filterSessionYearId'] ?? null;

        if (!$filterSessionYearId) {
            return [collect(), collect()];
        }

        // ---- Step 1: Load students ----
        $studentsQuery = Students::with(['user', 'guardian', 'class_section.class', 'class_section.section'])
            ->where('school_id', $schoolId);

        if ($search) {
            $normalizedSearch = preg_replace('/\s+/u', '', $search);
            $studentsQuery->where(function ($q) use ($search, $normalizedSearch) {
                $q->where('admission_no', 'like', "%{$search}%")
                  ->orWhereRaw("REPLACE(admission_no, ' ', '') LIKE ?", ["%{$normalizedSearch}%"])
                  ->orWhereHas('user', function ($uq) use ($search, $normalizedSearch) {
                      $uq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                         ->orWhereRaw(
                             "REPLACE(CONCAT(COALESCE(first_name, ''), COALESCE(last_name, '')), ' ', '') LIKE ?",
                             ["%{$normalizedSearch}%"]
                         );
                  });
            });
        }

        if ($classSectionFilter) {
            $studentsQuery->where('class_section_id', $classSectionFilter);
        }

        $students = $studentsQuery->get();

        if ($students->isEmpty()) {
            return [collect(), collect()];
        }

        // ---- Step 2: Resolve students and immutable receivable snapshots ----
        $studentUserIds = [];
        $studentData   = [];

        foreach ($students as $stu) {
            $userId  = $stu->user_id;
            $studentUserIds[] = $userId;
            $studentData[$userId] = $stu;
        }

        $assignmentItems = StudentFeeAssignmentItem::query()
            ->where('status', StudentFeeAssignmentItem::ACTIVE)
            ->whereHas('assignment', fn ($query) => $query
                ->where('school_id', $schoolId)
                ->whereIn('student_id', $students->pluck('id'))
                ->where('academic_year_id', $filterSessionYearId)
                ->where('status', StudentFeeAssignment::CONFIRMED))
            ->with('assignment:id,student_id')
            ->get();
        $itemsByStudent = $assignmentItems->groupBy('assignment.student_id');
        $allFeeIds = $assignmentItems->pluck('fee_id')->filter()->unique()->values()->all();

        // ---- Step 4: Batch query compulsory paid ----
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
            $sid = $paid->student_id;
            $currency = strtoupper((string) ($paid->transaction_currency ?: 'MMK'));
            $paidByStudent[$sid]['records'][] = [
                'currency' => $currency,
                'amount' => $paid->original_amount !== null ? (float) $paid->original_amount : (float) $paid->amount,
            ];
            $paidByStudent[$sid]['dates'][] = $paid->date;
        }

        // ---- Step 5: Batch query optional paid (for export reference only) ----
        // optional_fees has no session_year_id column; filter via fees_class_type → fees_id → fee.session_year_id
        $allOptionalPaid = collect();
        if (!empty($allFeeIds) && !empty($studentUserIds)) {
            $allOptionalPaid = OptionalFee::where('school_id', $schoolId)
                ->whereIn('student_id', $studentUserIds)
                ->where('status', 'Success')
                ->whereHas('fees_class_type', function ($q) use ($allFeeIds) {
                    $q->whereIn('fees_id', $allFeeIds);
                })
                ->get();
        }

        $optionalPaidByStudent = [];
        foreach ($allOptionalPaid as $opaid) {
            $sid = $opaid->student_id;
            $optionalPaidByStudent[$sid][] = [
                'currency' => strtoupper((string) ($opaid->transaction_currency ?: 'MMK')),
                'amount' => $opaid->original_amount !== null ? (float) $opaid->original_amount : (float) $opaid->amount,
            ];
        }

        // ---- Step 6: Aggregate by currency; currencies are never added together ----
        $resultRows = [];

        foreach ($students as $stu) {
            $userId  = $stu->user_id;
            $snapshots = $itemsByStudent->get($stu->id, collect());
            $expectedByCurrency = $snapshots->where('optional_snapshot', false)
                ->groupBy(fn ($item) => strtoupper((string) $item->currency_snapshot))
                ->map(fn ($items) => (float) $items->sum('amount_snapshot'));
            $lastPaymentDate = '';
            $paidByCurrency = collect();
            if (isset($paidByStudent[$userId])) {
                $paidByCurrency = collect($paidByStudent[$userId]['records'] ?? [])->groupBy('currency')
                    ->map(fn ($records) => (float) collect($records)->sum('amount'));
                $dates = $paidByStudent[$userId]['dates'];
                $lastPaymentDate = !empty($dates) ? max($dates) : '';
            }
            $optionalByCurrency = collect();
            if (isset($optionalPaidByStudent[$userId])) {
                $optionalByCurrency = collect($optionalPaidByStudent[$userId])->groupBy('currency')
                    ->map(fn ($records) => (float) collect($records)->sum('amount'));
            }

            $currencies = $expectedByCurrency->keys()->merge($paidByCurrency->keys())->merge($optionalByCurrency->keys())->unique()->sort()->values();
            $currencyTotals = $currencies->mapWithKeys(function ($currency) use ($expectedByCurrency, $paidByCurrency, $optionalByCurrency): array {
                $expected = (float) $expectedByCurrency->get($currency, 0);
                $paid = (float) $paidByCurrency->get($currency, 0);
                return [$currency => [
                    'expected' => $expected,
                    'paid' => $paid,
                    'optional_paid' => (float) $optionalByCurrency->get($currency, 0),
                    'outstanding' => max(0, $expected - $paid),
                ]];
            });

            // Status
            if ($expectedByCurrency->isEmpty()) {
                $status = 'no_fee_structure';
                $statusLabel = __('No confirmed receivable snapshot');
            } elseif ($paidByCurrency->sum() == 0) {
                $status = 'unpaid';
                $statusLabel = __('Unpaid');
            } elseif ($currencyTotals->contains(fn ($totals) => $totals['outstanding'] > 0)) {
                $status = 'partial';
                $statusLabel = __('Partial');
            } else {
                $status = 'fully_paid';
                $statusLabel = __('Fully Paid');
            }

            $resultRows[] = [
                'student'             => $stu,
                'user_id'             => $userId,
                'full_name'           => $stu->user->full_name ?? '',
                'admission_no'        => $stu->admission_no,
                'class_name'          => $stu->class_section->class->name ?? ($stu->class->name ?? ''),
                'section_name'        => $stu->class_section->section->name ?? '',
                'guardian_name'       => $stu->guardian->full_name ?? '',
                'contact'             => $stu->guardian->mobile ?? $stu->user->mobile ?? '',
                'currency_totals'      => $currencyTotals,
                'has_outstanding'      => $currencyTotals->contains(fn ($totals) => $totals['outstanding'] > 0),
                'last_payment_date'   => $lastPaymentDate,
                'status'              => $status,
                'status_label'        => $statusLabel,
            ];
        }

        $resultRows = collect($resultRows);

        // ---- Apply post-aggregation filters ----
        if ($statusFilter && in_array($statusFilter, ['unpaid', 'partial', 'fully_paid', 'no_fee_structure'])) {
            $resultRows = $resultRows->where('status', $statusFilter);
        }

        if ($outstandingOnly) {
            $resultRows = $resultRows->where('has_outstanding', true);
        }

        // ---- Summary: a separate subtotal for every currency ----
        $currencySummary = collect();
        foreach ($resultRows as $row) {
            foreach ($row['currency_totals'] as $currency => $totals) {
                $current = $currencySummary->get($currency, ['expected' => 0, 'paid' => 0, 'outstanding' => 0]);
                foreach (array_keys($current) as $field) $current[$field] += $totals[$field];
                $currencySummary->put($currency, $current);
            }
        }
        $summary = [
            'total_students'    => $resultRows->count(),
            'currency_totals'   => $currencySummary,
        ];

        return [$resultRows, $summary];
    }
}
