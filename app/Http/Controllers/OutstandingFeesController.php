<?php

namespace App\Http\Controllers;

use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\Students;
use App\Models\SessionYear;
use App\Models\ClassSection;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Auth;

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

        // If no session year, show empty
        if (!$filterSessionYearId) {
            return view('outstanding-fees.index', [
                'students'      => collect(),
                'summary'       => collect(),
                'allSessionYears' => $allSessionYears,
                'filterSessionYearId' => null,
                'classSections'  => collect(),
                'search'        => $request->get('search'),
                'classSectionFilter' => $request->get('class_section_id'),
                'statusFilter'    => $request->get('status'),
                'outstandingOnly' => $request->get('outstanding_only'),
            ]);
        }

        // ---- Step 1: Load students ----
        $search = $request->get('search');
        $classSectionFilter = $request->get('class_section_id');
        $statusFilter       = $request->get('status');
        $outstandingOnly    = $request->get('outstanding_only');

        $studentsQuery = Students::with(['user', 'guardian', 'class_section.class', 'class_section.section'])
            ->where('school_id', $schoolId);

        // Search filter
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

        // Class Section filter
        if ($classSectionFilter) {
            $studentsQuery->where('class_section_id', $classSectionFilter);
        }

        $students = $studentsQuery->get();

        if ($students->isEmpty()) {
            return view('outstanding-fees.index', [
                'students'          => collect(),
                'summary'           => collect(),
                'allSessionYears'   => $allSessionYears,
                'filterSessionYearId' => $filterSessionYearId,
                'classSections'     => ClassSection::owner()->with('class', 'section')->get(),
                'search'            => $search,
                'classSectionFilter'=> $classSectionFilter,
                'statusFilter'      => $statusFilter,
                'outstandingOnly'   => $outstandingOnly,
            ]);
        }

        // ---- Step 2: Resolve class_id per student, group by class_id ----
        $studentUserIds = [];
        $classIdGroups = []; // class_id => [student objects]
        $studentData   = []; // user_id => resolved class_id for this student

        foreach ($students as $stu) {
            $userId  = $stu->user_id;
            $classId = $stu->class_section->class_id ?? $stu->class_id;
            $studentUserIds[] = $userId;
            $studentData[$userId] = [
                'student' => $stu,
                'class_id' => $classId,
            ];

            if ($classId) {
                $classIdGroups[$classId][] = $stu;
            }
        }

        // ---- Step 3: Batch query ALL fees for relevant class_ids ----
        $allClassIds     = array_keys($classIdGroups);
        $allFees         = Fee::whereIn('class_id', $allClassIds)
            ->where('session_year_id', $filterSessionYearId)
            ->with(['fees_class_type.fees_type', 'fees_class_type.finance_category'])
            ->get();

        // Map: class_id => [fee records]
        $feesByClass = [];
        foreach ($allFees as $fee) {
            $feesByClass[$fee->class_id][] = $fee;
        }

        // Collect all fee IDs for batch compulsory_fees query
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

        // Group paid by student_id (users.id)
        $paidByStudent = [];
        foreach ($allCompulsoryPaid as $paid) {
            $sid = $paid->student_id;
            $paidByStudent[$sid]['total'][] = $paid->amount;
            $paidByStudent[$sid]['dates'][] = $paid->date;
        }

        // ---- Step 5: Aggregate in PHP per student ----
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
                // Last compulsory payment date
                $dates = $paidByStudent[$userId]['dates'];
                $lastPaymentDate = !empty($dates) ? max($dates) : '';
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
                'student'            => $stu,
                'user_id'            => $userId,
                'full_name'          => $stu->user->full_name ?? '',
                'admission_no'       => $stu->admission_no,
                'class_name'         => $stu->class_section->class->name ?? ($stu->class->name ?? ''),
                'section_name'       => $stu->class_section->section->name ?? '',
                'guardian_name'      => $stu->guardian->full_name ?? '',
                'contact'            => $stu->guardian->mobile ?? $stu->user->mobile ?? '',
                'compulsory_expected' => $compulsoryExpected,
                'compulsory_paid'    => $compulsoryPaid,
                'outstanding'        => $outstanding,
                'last_payment_date'  => $lastPaymentDate,
                'status'             => $status,
                'status_label'       => $statusLabel,
            ];
        }

        // ---- Apply post-aggregation filters ----
        $resultRows = collect($resultRows);

        // Status filter
        if ($statusFilter && in_array($statusFilter, ['unpaid', 'partial', 'fully_paid', 'no_fee_structure'])) {
            $resultRows = $resultRows->where('status', $statusFilter);
        }

        // Outstanding only filter
        if ($outstandingOnly) {
            $resultRows = $resultRows->where('outstanding', '>', 0);
        }

        // ---- Summary ----
        $summary = [
            'total_students'   => $resultRows->count(),
            'total_expected'   => $resultRows->sum('compulsory_expected'),
            'total_paid'       => $resultRows->sum('compulsory_paid'),
            'total_outstanding' => $resultRows->sum('outstanding'),
        ];

        // Class Sections for filter dropdown
        $classSections = ClassSection::owner()->with('class', 'section')->get();

        return view('outstanding-fees.index', compact(
            'resultRows', 'summary', 'allSessionYears', 'filterSessionYearId',
            'classSections', 'search', 'classSectionFilter', 'statusFilter', 'outstandingOnly'
        ));
    }
}
