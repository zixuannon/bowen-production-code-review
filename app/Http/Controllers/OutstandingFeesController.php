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

        $filters = compact(
            'search', 'classSectionFilter', 'statusFilter',
            'outstandingOnly', 'filterSessionYearId'
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

        // ---- Step 2: Resolve class_id per student, group by class_id ----
        $studentUserIds = [];
        $classIdGroups = [];
        $studentData   = [];

        foreach ($students as $stu) {
            $userId  = $stu->user_id;
            $classId = $stu->class_section->class_id ?? $stu->class_id;
            $studentUserIds[] = $userId;
            $studentData[$userId] = [
                'student'  => $stu,
                'class_id' => $classId,
            ];

            if ($classId) {
                $classIdGroups[$classId][] = $stu;
            }
        }

        // ---- Step 3: Batch query ALL fees for relevant class_ids ----
        $allClassIds = array_keys($classIdGroups);
        $allFees     = Fee::whereIn('class_id', $allClassIds)
            ->where('session_year_id', $filterSessionYearId)
            ->with(['fees_class_type.fees_type', 'fees_class_type.finance_category'])
            ->get();

        $feesByClass = [];
        foreach ($allFees as $fee) {
            $feesByClass[$fee->class_id][] = $fee;
        }

        $allFeeIds = $allFees->pluck('id')->toArray();

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
            $paidByStudent[$sid]['total'][] = $paid->amount;
            $paidByStudent[$sid]['dates'][] = $paid->date;
        }

        // ---- Step 5: Batch query optional paid (for export reference only) ----
        $allOptionalPaid = collect();
        if (!empty($studentUserIds)) {
            $allOptionalPaid = OptionalFee::where('school_id', $schoolId)
                ->where('session_year_id', $filterSessionYearId)
                ->whereIn('student_id', $studentUserIds)
                ->where('status', 'Success')
                ->get();
        }

        $optionalPaidByStudent = [];
        foreach ($allOptionalPaid as $opaid) {
            $sid = $opaid->student_id;
            $optionalPaidByStudent[$sid][] = $opaid->amount;
        }

        // ---- Step 6: Aggregate in PHP per student ----
        $resultRows = [];

        foreach ($students as $stu) {
            $userId  = $stu->user_id;
            $classId = $studentData[$userId]['class_id'];

            // Fee structure for this student's class
            $classFees = $feesByClass[$classId] ?? [];

            // Compulsory Expected
            $compulsoryExpected = 0;
            $hasFeeStructure = !empty($classFees);

            if ($hasFeeStructure) {
                foreach ($classFees as $fee) {
                    $compulsoryItems = $fee->fees_class_type->where('optional', 0);
                    $compulsoryExpected += $compulsoryItems->sum(function ($item) {
                        return ($item->fee_amount_mmk > 0) ? $item->fee_amount_mmk : $item->amount;
                    });
                }
            }

            // Compulsory Paid
            $compulsoryPaid = 0;
            $lastPaymentDate = '';
            if (isset($paidByStudent[$userId])) {
                $compulsoryPaid = array_sum($paidByStudent[$userId]['total']);
                $dates = $paidByStudent[$userId]['dates'];
                $lastPaymentDate = !empty($dates) ? max($dates) : '';
            }

            // Optional Paid (reference only, not included in outstanding)
            $optionalPaid = 0;
            if (isset($optionalPaidByStudent[$userId])) {
                $optionalPaid = array_sum($optionalPaidByStudent[$userId]);
            }

            // Outstanding
            $outstanding = max(0, $compulsoryExpected - $compulsoryPaid);

            // Status
            if ($compulsoryExpected == 0) {
                $status = 'no_fee_structure';
                $statusLabel = __('No Fee Structure');
            } elseif ($compulsoryPaid == 0 && $compulsoryExpected > 0) {
                $status = 'unpaid';
                $statusLabel = __('Unpaid');
            } elseif ($compulsoryPaid > 0 && $outstanding > 0) {
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
                'compulsory_expected'  => $compulsoryExpected,
                'compulsory_paid'     => $compulsoryPaid,
                'optional_paid'       => $optionalPaid,
                'outstanding'         => $outstanding,
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
            $resultRows = $resultRows->where('outstanding', '>', 0);
        }

        // ---- Summary ----
        $summary = [
            'total_students'    => $resultRows->count(),
            'total_expected'    => $resultRows->sum('compulsory_expected'),
            'total_paid'        => $resultRows->sum('compulsory_paid'),
            'total_outstanding' => $resultRows->sum('outstanding'),
        ];

        return [$resultRows, $summary];
    }
}
