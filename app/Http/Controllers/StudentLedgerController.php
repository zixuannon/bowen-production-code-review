<?php

namespace App\Http\Controllers;

use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\OptionalFee;
use App\Models\Students;
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
            $students = Students::with(['user', 'class_section.class', 'class_section.section', 'guardian'])
                ->where('school_id', Auth::user()->school_id)
                ->where(function ($q) use ($search) {
                    $q->where('admission_no', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($uq) use ($search) {
                          $uq->where('first_name', 'like', "%{$search}%")
                             ->orWhere('last_name', 'like', "%{$search}%")
                             ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
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

        // ---- Student Info ----
        $student = Students::with(['user', 'class_section.class', 'class_section.section', 'guardian'])
            ->where('user_id', $userId)
            ->where('school_id', Auth::user()->school_id)
            ->first();

        if (!$student) {
            abort(404);
        }

        // Resolve class_id via class_section (priority), fallback to direct class_id
        $classId = $student->class_section->class_id ?? $student->class_id;

        $cache       = app(CachingService::class);
        $sessionYear = $cache->getDefaultSessionYear();

        // Early return when missing session year or class_id
        if (!$sessionYear || !$classId) {
            return view('student-ledger.show', [
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
            ]);
        }

        $sessionYearId = $sessionYear->id;

        // ---- ALL Fee structures for this class + session year ----
        // A class may have multiple fees (e.g. separate fee records for different
        // academic programs), so we aggregate across all matching Fee records.
        $fees = Fee::where('class_id', $classId)
            ->where('session_year_id', $sessionYearId)
            ->with(['fees_class_type.fees_type', 'fees_class_type.finance_category'])
            ->get();

        $feeIds          = $fees->pluck('id')->toArray();
        $hasFeeStructure = $fees->isNotEmpty();

        // ===== Compulsory Expected (aggregate across ALL fees) =====
        $compulsoryExpected      = collect();
        $totalCompulsoryExpected = 0;
        if ($hasFeeStructure) {
            foreach ($fees as $fee) {
                $compulsoryExpected = $compulsoryExpected->merge(
                    $fee->fees_class_type->where('optional', 0)
                );
            }
            $totalCompulsoryExpected = $compulsoryExpected->sum(function ($item) {
                return ($item->fee_amount_mmk > 0) ? $item->fee_amount_mmk : $item->amount;
            });
        }

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
            $totalCompulsoryPaid = $compulsoryPaidRecords->sum('amount');
        }

        // ===== Compulsory Outstanding =====
        $totalCompulsoryOutstanding = max(0, $totalCompulsoryExpected - $totalCompulsoryPaid);

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
            $totalOptionalPaid = $optionalPaidRecords->sum('amount');
        }

        // ===== Totals =====
        $totalPaid       = $totalCompulsoryPaid + $totalOptionalPaid;
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
                'fee_item'         => $fees->first()->name ?? 'Compulsory Fee',
                'mode'             => $r->mode,
                'mode_name'        => $r->mode_name,
                'currency'         => $r->fees_paid->transaction_currency ?? 'MMK',
                'original_amount'  => $r->fees_paid->original_amount ?? $r->amount,
                'exchange_rate'    => $r->fees_paid->exchange_rate_snapshot ?? 1,
                'mmk_amount'       => $r->amount,
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
                'currency'         => $r->fees_paid->transaction_currency ?? 'MMK',
                'original_amount'  => $r->fees_paid->original_amount ?? $r->amount,
                'exchange_rate'    => $r->fees_paid->exchange_rate_snapshot ?? 1,
                'mmk_amount'       => $r->amount,
                'due_charges'      => 0,
                'status'           => $r->status,
                'fees_paid_id'     => $r->fees_paid_id,
                'installment'      => null,
            ]);
        }

        $paymentHistory = $paymentHistory->sortByDesc('date');

        return view('student-ledger.show', compact(
            'student', 'fees', 'hasFeeStructure', 'sessionYear',
            'compulsoryExpected', 'totalCompulsoryExpected',
            'totalCompulsoryPaid', 'totalCompulsoryOutstanding',
            'optionalPaidRecords', 'totalOptionalPaid', 'totalPaid',
            'lastPaymentDate', 'paymentHistory'
        ));
    }
}
