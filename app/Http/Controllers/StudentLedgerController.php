<?php

namespace App\Http\Controllers;

use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\OptionalFee;
use App\Models\Students;
use App\Models\School;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Auth;

class StudentLedgerController extends Controller
{
    public function index()
    {
        ResponseService::noPermissionThenRedirect('fees-paid');

        $request = request();
        $students = collect();
        $search   = $request->get('search');

        if ($search) {
            // Normalize: remove all spaces for space-insensitive matching
            // e.g. "BowenSchool" → finds "Bowen School", "StudentOne" → finds "Student One"
            $normalizedSearch = preg_replace('/\s+/u', '', $search);

            $students = Students::with(['user', 'class_section.class', 'class_section.section', 'guardian', 'studentImportIdentity'])
                ->where('school_id', Auth::user()->school_id)
                ->where(function ($q) use ($search, $normalizedSearch) {
                    $q->where('admission_no', 'like', "%{$search}%")
                      ->orWhereRaw("REPLACE(admission_no, ' ', '') LIKE ?", ["%{$normalizedSearch}%"])
                      ->orWhereHas('studentImportIdentity', fn ($identity) => $identity->where('student_code', 'like', "%{$search}%"))
                      ->orWhereHas('user', function ($uq) use ($search, $normalizedSearch) {
                          $uq->where('first_name', 'like', "%{$search}%")
                             ->orWhere('last_name', 'like', "%{$search}%")
                             ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                             ->orWhereRaw(
                                 "REPLACE(CONCAT(COALESCE(first_name, ''), COALESCE(last_name, '')), ' ', '') LIKE ?",
                                 ["%{$normalizedSearch}%"]
                             );
                      });
                })
                ->limit(50)
                ->get();
        }

        return view('student-ledger.index', compact('students', 'search'));
    }

    /**
     * Show student ledger for a given student (by users.id).
     *
     * NOTE: In this project, compulsory_fees.student_id and optional_fees.student_id
     * both store users.id (not students.id). The {userId} route parameter is
     * therefore the users.id value.
     *
     * Class resolution priority:
     *   students.class_section_id -> class_sections.class_id   (primary)
     *   students.class_id                                     (fallback)
     */
    public function show($userId)
    {
        ResponseService::noPermissionThenRedirect('fees-paid');

        $data = $this->buildStudentLedgerData($userId);

        if ($data === null) {
            abort(404);
        }

        $data['centralFinance'] = app(\App\Services\CentralFinanceStudentReadBridge::class)->forStudent($data['student']);
        return view('student-ledger.show', $data);
    }

    /**
     * Print / Statement view for a single student ledger.
     */
    public function print($userId)
    {
        ResponseService::noPermissionThenRedirect('fees-paid');

        $data = $this->buildStudentLedgerData($userId);

        if ($data === null) {
            abort(404);
        }

        $school = School::findOrFail(Auth::user()->school_id);
        $data['school'] = $school;

        return view('student-ledger.print', $data);
    }

    /**
     * Build all student ledger data for a given userId.
     *
     * Returns an array with all view variables, or null if student not found.
     */
    private function buildStudentLedgerData($userId): ?array
    {
        // ---- Student Info ----
        $student = Students::with(['user', 'class_section.class', 'class_section.section', 'guardian'])
            ->where('user_id', $userId)
            ->where('school_id', Auth::user()->school_id)
            ->first();

        if (!$student) {
            return null;
        }

        // Resolve class_id via class_section (priority), fallback to direct class_id
        $classId = $student->class_section->class_id ?? $student->class_id;

        $cache       = app(CachingService::class);
        $sessionYear = $cache->getDefaultSessionYear();

        // Early return when missing session year or class_id
        if (!$sessionYear || !$classId) {
            return [
                'student'                   => $student,
                'fees'                      => collect(),
                'hasFeeStructure'           => false,
                'sessionYear'               => $sessionYear,
                'compulsoryExpected'        => collect(),
                'totalCompulsoryExpected'   => 0,
                'totalCompulsoryPaid'       => 0,
                'totalCompulsoryOutstanding'=> 0,
                'optionalPaidRecords'       => collect(),
                'totalOptionalPaid'         => 0,
                'totalPaid'                 => 0,
                'lastPaymentDate'           => '',
                'paymentHistory'            => collect(),
                'currencyTotals'            => collect(),
            ];
        }

        $sessionYearId = $sessionYear->id;

        // Confirmed student-level snapshots are the receivable source of truth.
        // Current Fee Setup is deliberately not read here.
        $compulsoryExpected = StudentFeeAssignmentItem::query()
            ->where('status', StudentFeeAssignmentItem::ACTIVE)
            ->where('optional_snapshot', false)
            ->whereHas('assignment', fn ($query) => $query
                ->where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $sessionYearId)
                ->where('status', StudentFeeAssignment::CONFIRMED))
            ->get();
        $allSnapshotItems = StudentFeeAssignmentItem::query()
            ->where('status', StudentFeeAssignmentItem::ACTIVE)
            ->whereHas('assignment', fn ($query) => $query
                ->where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $sessionYearId)
                ->where('status', StudentFeeAssignment::CONFIRMED))
            ->get();
        $feeIds = $allSnapshotItems->pluck('fee_id')->filter()->unique()->values()->all();
        $fees = Fee::withTrashed()->whereIn('id', $feeIds)->get();
        $hasFeeStructure = $allSnapshotItems->isNotEmpty();
        $expectedByCurrency = $compulsoryExpected
            ->groupBy(fn ($item) => strtoupper((string) $item->currency_snapshot))
            ->map(fn ($items) => (float) $items->sum('amount_snapshot'));

        // ===== Compulsory Paid =====
        $totalCompulsoryPaid   = 0;
        $compulsoryPaidRecords = collect();
        if ($hasFeeStructure) {
            $compulsoryPaidRecords = CompulsoryFee::where('student_id', $userId)
                ->where('status', 'Success')
                ->whereHas('fees_paid', function ($q) use ($feeIds) {
                    $q->whereIn('fees_id', $feeIds);
                })
                ->with(['fees_paid', 'installment_fee'])
                ->orderBy('date', 'desc')
                ->get();
        }

        // ===== Optional Paid =====
        $totalOptionalPaid   = 0;
        $optionalPaidRecords = collect();
        if ($hasFeeStructure) {
            $optionalPaidRecords = OptionalFee::where('student_id', $userId)
                ->where('status', 'Success')
                ->whereHas('fees_paid', function ($q) use ($feeIds) {
                    $q->whereIn('fees_id', $feeIds);
                })
                ->with(['fees_paid', 'fees_class_type.fees_type', 'fees_class_type.finance_category'])
                ->orderBy('date', 'desc')
                ->get();
        }

        $paidByCurrency = $compulsoryPaidRecords->groupBy(fn ($row) => strtoupper((string) ($row->transaction_currency ?: 'MMK')))
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => $row->original_amount !== null ? $row->original_amount : $row->amount));
        $optionalByCurrency = $optionalPaidRecords->groupBy(fn ($row) => strtoupper((string) ($row->transaction_currency ?: 'MMK')))
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => $row->original_amount !== null ? $row->original_amount : $row->amount));
        $currencies = $expectedByCurrency->keys()->merge($paidByCurrency->keys())->merge($optionalByCurrency->keys())->unique()->sort()->values();
        $currencyTotals = $currencies->mapWithKeys(function ($currency) use ($expectedByCurrency, $paidByCurrency, $optionalByCurrency): array {
            $expected = (float) $expectedByCurrency->get($currency, 0);
            $paid = (float) $paidByCurrency->get($currency, 0);
            return [$currency => [
                'expected' => $expected,
                'compulsory_paid' => $paid,
                'compulsory_outstanding' => max(0, $expected - $paid),
                'optional_paid' => (float) $optionalByCurrency->get($currency, 0),
            ]];
        });
        // Retain legacy scalar variables as MMK-only projections; never mix currencies.
        $totalCompulsoryExpected = (float) ($currencyTotals['MMK']['expected'] ?? 0);
        $totalCompulsoryPaid = (float) ($currencyTotals['MMK']['compulsory_paid'] ?? 0);
        $totalCompulsoryOutstanding = (float) ($currencyTotals['MMK']['compulsory_outstanding'] ?? 0);
        $totalOptionalPaid = (float) ($currencyTotals['MMK']['optional_paid'] ?? 0);
        $totalPaid = $totalCompulsoryPaid + $totalOptionalPaid;
        $lastPaymentDate = max(
            $compulsoryPaidRecords->max('date') ?? '',
            $optionalPaidRecords->max('date') ?? ''
        );

        // ===== Payment History =====
        $paymentHistory = collect();

        foreach ($compulsoryPaidRecords as $r) {
            $paymentHistory->push([
                'type'             => 'Compulsory',
                'date'             => $r->date,
                'fee_item'         => $fees->firstWhere('id', $r->fees_paid?->fees_id)?->name ?? 'Compulsory Fee',
                'mode'             => $r->mode,
                'mode_name'        => $r->mode_name,
                'currency'         => $r->transaction_currency ?? 'MMK',
                'original_amount'  => $r->original_amount ?? $r->amount,
                'exchange_rate'    => $r->exchange_rate_snapshot ?? 1,
                'mmk_amount'       => $r->amount_mmk ?? $r->amount,
                'due_charges'      => $r->due_charges ?? 0,
                'status'           => $r->status,
                'fees_paid_id'     => $r->fees_paid_id,
                'installment'      => $r->installment_fee->name ?? null,
            ]);
        }

        foreach ($optionalPaidRecords as $r) {
            $feeTypeName = $r->fees_class_type->fees_type->name ?? 'Optional Fee';
            $paymentHistory->push([
                'type'             => 'Optional',
                'date'             => $r->date,
                'fee_item'         => $feeTypeName,
                'mode'             => $r->mode,
                'mode_name'        => $r->mode_name,
                'currency'         => $r->transaction_currency ?? 'MMK',
                'original_amount'  => $r->original_amount ?? $r->amount,
                'exchange_rate'    => $r->exchange_rate_snapshot ?? 1,
                'mmk_amount'       => $r->amount_mmk ?? $r->amount,
                'due_charges'      => 0,
                'status'           => $r->status,
                'fees_paid_id'     => $r->fees_paid_id,
                'installment'      => null,
            ]);
        }

        $paymentHistory = $paymentHistory->sortByDesc('date');

        return compact(
            'student', 'fees', 'hasFeeStructure', 'sessionYear',
            'compulsoryExpected', 'totalCompulsoryExpected',
            'totalCompulsoryPaid', 'totalCompulsoryOutstanding',
            'optionalPaidRecords', 'totalOptionalPaid', 'totalPaid',
            'lastPaymentDate', 'paymentHistory', 'currencyTotals'
        );
    }
}
