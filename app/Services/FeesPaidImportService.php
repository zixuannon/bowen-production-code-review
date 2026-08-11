<?php

namespace App\Services;

use App\Helpers\MoneyDecimal;
use App\Models\BankAccount;
use App\Models\ClassSchool;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeeImportBatch;
use App\Models\FeesPaid;
use App\Models\SessionYear;
use App\Models\Students;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

/**
 * FeesPaidImportService
 *
 * Handles Excel preview and batch confirm for compulsory fee imports.
 *
 * TRANSACTION RULES:
 *   - Preview: No transaction (reads only, creates pending batch)
 *   - Confirm: School DB transaction wraps ALL financial writes + pending→processing
 *     On failure: transaction rolls back financial writes, THEN batch saved as failed
 *
 * P0 CONSTRAINTS (applied in payment_data construction, not in FeesPaymentService):
 *   - Currency fixed to MMK
 *   - Advance fixed to 0
 *   - One row = one payment, at most one installment
 *   - No duplicate Reference No (within Excel + DB)
 *   - No overpayment (amount <= remaining)
 *   - No notification
 *   - Full success or full rollback
 *
 * MATCHING RULES (all business fields → exactly 1 DB record, 0 or >1 = error):
 *   admission_no → Students → user_id
 *   academic year name → SessionYear → session_year_id
 *   class name → ClassSchool → class_id
 *   fee structure name + class_id + session_year_id → Fee → fees_id
 *   bank account name → BankAccount → bank_account_id
 *   installment name + Fee → FeesInstallment → installment_id
 */
class FeesPaidImportService
{
    const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    const MAX_ROWS = 500;
    const TOKEN_EXPIRY_MINUTES = 30;

    // Column map: internal key → zero-based Excel column index
    const COLUMN_MAP = [
        'admission_no'       => 0,  // A: Student Admission No
        'academic_year'      => 1,  // B: Academic Year
        'class_name'         => 2,  // C: Class Name
        'fee_structure_name' => 3,  // D: Fee Structure Name
        'bank_account_name'  => 4,  // E: Bank Account Name
        'installment_name'   => 5,  // F: Installment Name
        'date'               => 6,  // G: Payment Date
        'amount'             => 7,  // H: Amount (MMK)
        'payment_mode'       => 8,  // I: Payment Mode
        'cheque_no'          => 9,  // J: Cheque No
        'reference_no'       => 10, // K: Reference No
    ];

    // Allowed payment modes (same as frontend)
    const ALLOWED_MODES = [
        'Cash', 'Cheque', 'Online',
        'KBZ Pay', 'Quick Pay', 'KBZ Bank',
        'AYA Bank', 'YOMA BANK', 'CB Bank',
        'Wechat Pay', 'Ali Pay',
    ];

    private FeesPaymentService $paymentService;

    public function __construct(FeesPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Preview: Upload + validate Excel, create pending batch.
     */
    public function preview(UploadedFile $file, int $schoolId, int $userId): array
    {
        // ---- 1. File validation ----
        $this->validateFile($file);

        // ---- 2. Parse Excel ----
        $rows = $this->parseExcel($file);

        if (count($rows) > self::MAX_ROWS) {
            throw new \InvalidArgumentException("Excel has " . count($rows) . " data rows. Maximum is " . self::MAX_ROWS);
        }

        // ---- 3. Build batch record ----
        $token = \Illuminate\Support\Str::uuid()->toString();
        $fileHash = hash_file('sha256', $file->getRealPath());

        // ---- 4. Validate all rows ----
        $previewRows = [];
        $duplicateCount = 0;
        $errorCount = 0;
        $validCount = 0;

        $excelRefNos = [];

        foreach ($rows as $idx => $row) {
            $rowNum = $idx + 2; // +2 for header row + 1-indexed
            $result = $this->validatePreviewRow($row, $rowNum, $schoolId, $excelRefNos);

            $previewRows[] = $result;

            switch ($result['status']) {
                case 'duplicate': $duplicateCount++; break;
                case 'error':     $errorCount++; break;
                case 'valid':     $validCount++; break;
            }
        }

        // ---- 5. Create pending batch ----
        $batch = FeeImportBatch::create([
            'token'          => $token,
            'school_id'      => $schoolId,
            'imported_by'    => $userId,
            'file_name'      => $file->getClientOriginalName(),
            'file_hash'      => $fileHash,
            'preview_data'   => $previewRows,
            'status'         => FeeImportBatch::STATUS_PENDING,
            'total_rows'     => count($rows),
            'success_rows'   => $validCount,
            'duplicate_rows' => $duplicateCount,
            'error_rows'     => $errorCount,
            'expired_at'     => now()->addMinutes(self::TOKEN_EXPIRY_MINUTES),
        ]);

        return [
            'batch_id' => $batch->id,
            'token'    => $token,
            'rows'     => $previewRows,
            'summary'  => [
                'total'     => count($rows),
                'valid'     => $validCount,
                'duplicate' => $duplicateCount,
                'error'     => $errorCount,
            ],
        ];
    }

    /**
     * Confirm: Process all valid rows from a batch.
     */
    public function confirm(string $token, int $schoolId, int $userId): array
    {
        $errors = [];

        try {
            return DB::connection('school')->transaction(function () use ($token, $schoolId, $userId, &$errors) {
                // ---- 1. Lock batch INSIDE transaction on school connection ----
                $batch = FeeImportBatch::where('token', $token)
                    ->where('school_id', $schoolId)
                    ->lockForUpdate()
                    ->first();

                if (!$batch) {
                    throw new \InvalidArgumentException('Import batch not found');
                }

                if ($batch->imported_by !== $userId) {
                    throw new \InvalidArgumentException('Only the uploader can confirm this batch');
                }

                if ($batch->status !== FeeImportBatch::STATUS_PENDING) {
                    throw new \InvalidArgumentException("Batch status is '{$batch->status}', expected 'pending'");
                }

                if ($batch->expired_at && now()->gt($batch->expired_at)) {
                    throw new \InvalidArgumentException('Preview session has expired. Please re-upload.');
                }

                if ($batch->error_rows > 0) {
                    throw new \InvalidArgumentException(
                        "Batch has {$batch->error_rows} error row(s). Fix errors and re-upload before confirming."
                    );
                }

                // ---- 2. Mark as processing (inside transaction) ----
                $batch->status = FeeImportBatch::STATUS_PROCESSING;
                $batch->save();

                // ---- 3. Process each valid row ----
                $previewRows = $batch->preview_data ?? [];
                $imported = 0;
                $skipped = 0;

                foreach ($previewRows as $idx => $pRow) {
                    try {
                        if ($pRow['status'] === 'duplicate') {
                            $skipped++;
                            continue;
                        }
                        if ($pRow['status'] === 'error') {
                            $skipped++;
                            continue;
                        }

                        // Re-validate ALL business conditions against CURRENT DB state
                        $fee = $this->revalidateRow($pRow, $schoolId);

                        // Inject import_batch_id so compulsory_fees records link back to the batch
                        $pRow['payment_data']['import_batch_id'] = $batch->id;

                        // Process payment via FeesPaymentService
                        $this->paymentService->processPayment($pRow['payment_data'], $fee);

                        $imported++;
                    } catch (\Throwable $e) {
                        Log::warning('Import confirm row error', [
                            'row'     => $pRow['row_number'] ?? ($idx + 1),
                            'message' => $e->getMessage(),
                        ]);
                        $errors[] = "Row " . ($pRow['row_number'] ?? ($idx + 1)) . ": " . $e->getMessage();

                        throw new \InvalidArgumentException(
                            "Confirmation failed. All changes rolled back. Errors:\n" . implode("\n", $errors)
                        );
                    }
                }

                // ---- 4. Mark completed (inside transaction) ----
                $batch->status        = FeeImportBatch::STATUS_COMPLETED;
                $batch->imported_rows = $imported;
                $batch->skipped_rows  = $skipped;
                $batch->consumed_at   = now();
                $batch->preview_data  = null;
                $batch->save();

                return [
                    'batch_id'   => $batch->id,
                    'imported'   => $imported,
                    'skipped'    => $skipped,
                    'total_rows' => $batch->total_rows,
                ];
            });
        } catch (\Throwable $e) {
            if (!empty($errors)) {
                try {
                    $batch = FeeImportBatch::where('token', $token)
                        ->where('school_id', $schoolId)
                        ->where('imported_by', $userId)
                        ->whereIn('status', [
                            FeeImportBatch::STATUS_PROCESSING,
                            FeeImportBatch::STATUS_PENDING,
                        ])
                        ->first();

                    if ($batch) {
                        $errorMsg = implode("\n", $errors);
                        if (mb_strlen($errorMsg) > 5000) {
                            $errorMsg = mb_substr($errorMsg, 0, 5000) . "\n... [truncated]";
                        }
                        $batch->status     = FeeImportBatch::STATUS_FAILED;
                        $batch->last_error = $errorMsg;
                        $batch->save();
                    }
                } catch (\Throwable $saveError) {
                    Log::error('Failed to save batch error status', [
                        'message' => $saveError->getMessage(),
                        'token'   => $token,
                    ]);
                }
            }
            throw $e;
        }
    }

    // ================================================================
    // Private helpers
    // ================================================================

    private function validateFile(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds 5MB limit');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            throw new \InvalidArgumentException('Only .xlsx, .xls, and .csv files are allowed');
        }
    }

    private function parseExcel(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'csv') {
            return $this->parseCsv($file);
        }

        $collection = Excel::toCollection(null, $file);
        $sheet = $collection->first(); // FIRST SHEET ONLY

        $rows = [];
        $isFirst = true;

        foreach ($sheet as $spreadsheetRow) {
            if ($isFirst) {
                $isFirst = false;
                continue;
            }

            $rowArray = [];
            foreach ($spreadsheetRow as $value) {
                $rowArray[] = $value;
            }

            if ($this->isBlankRow($rowArray)) {
                continue;
            }

            foreach ($rowArray as $cell) {
                if (is_string($cell) && str_starts_with(trim($cell), '=')) {
                    throw new \InvalidArgumentException(
                        'Excel contains formulas. Please paste values only.'
                    );
                }
            }

            $maxCols = count(self::COLUMN_MAP);
            while (count($rowArray) < $maxCols) {
                $rowArray[] = null;
            }

            $rows[] = $rowArray;
        }

        return $rows;
    }

    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $rows = [];
        $isFirst = true;
        while (($data = fgetcsv($handle)) !== false) {
            if ($isFirst) {
                $isFirst = false;
                continue;
            }
            if ($this->isBlankRow($data)) {
                continue;
            }
            $maxCols = count(self::COLUMN_MAP);
            while (count($data) < $maxCols) {
                $data[] = null;
            }
            $rows[] = $data;
        }
        fclose($handle);
        return $rows;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '' && trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Match exactly one result from a query. 0 or >1 → error.
     */
    private function matchOne(string $fieldLabel, $query, string $searchValue): array
    {
        $results = $query->get();

        if ($results->count() === 0) {
            return ['error' => "{$fieldLabel} '{$searchValue}' not found"];
        }

        if ($results->count() > 1) {
            $matchedNames = $results->pluck($results->first()->getKeyName() === 'id' ? 'id' : 'id')->implode(', ');
            return ['error' => "{$fieldLabel} '{$searchValue}' matched multiple records. Please be more specific."];
        }

        return ['result' => $results->first()];
    }

    /**
     * Validate a single row during preview.
     *
     * Business-field matching: each field maps to exactly 1 DB record.
     * 0 or >1 results → error.
     */
    private function validatePreviewRow(array $row, int $rowNum, int $schoolId, array &$excelRefNos): array
    {
        $base = [
            'row_number' => $rowNum,
            'status'     => 'valid',
            'errors'     => [],
            'warnings'   => [],
        ];

        $raw = [];
        foreach (self::COLUMN_MAP as $key => $colIdx) {
            $raw[$key] = isset($row[$colIdx]) ? trim((string) $row[$colIdx]) : '';
        }

        // Resolved IDs (set by matching, NOT from Excel)
        $studentId   = null;
        $student     = null;
        $feesId      = null;
        $fee         = null;
        $classId     = null;
        $sessionYearId = null;
        $bankAccountId = null;
        $installmentId  = null;

        // ---- 1. Match Student by Admission No ----
        $admissionNo = trim($raw['admission_no'] ?? '');
        if (empty($admissionNo)) {
            $base['errors'][] = 'Student Admission No is required';
            $base['status'] = 'error';
        } else {
            $studentRecordQuery = Students::where('admission_no', $admissionNo)
                ->where('school_id', $schoolId);
            $match = $this->matchOne('Student Admission No', $studentRecordQuery, $admissionNo);

            if (isset($match['error'])) {
                $base['errors'][] = $match['error'];
                $base['status'] = 'error';
            } else {
                /** @var Students $studentRecord */
                $studentRecord = $match['result'];
                $studentId = $studentRecord->user_id;

                $student = User::where('id', $studentId)
                    ->where('school_id', $schoolId)
                    ->role('Student')
                    ->first();
                if (!$student) {
                    $base['errors'][] = "User for admission '{$admissionNo}' is not a valid student";
                    $base['status'] = 'error';
                    $studentId = null;
                } else {
                    $base['student_name'] = $student->first_name . ' ' . $student->last_name;
                }
            }
        }

        // ---- 2. Match Academic Year by name ----
        $academicYear = trim($raw['academic_year'] ?? '');
        if (empty($academicYear)) {
            $base['errors'][] = 'Academic Year is required';
            $base['status'] = 'error';
        } else {
            $sessionQuery = SessionYear::where('name', $academicYear)
                ->where('school_id', $schoolId);
            $match = $this->matchOne('Academic Year', $sessionQuery, $academicYear);

            if (isset($match['error'])) {
                $base['errors'][] = $match['error'];
                $base['status'] = 'error';
            } else {
                $sessionYearId = $match['result']->id;
            }
        }

        // ---- 3. Match Class by name ----
        $className = trim($raw['class_name'] ?? '');
        if (empty($className)) {
            $base['errors'][] = 'Class Name is required';
            $base['status'] = 'error';
        } else {
            $classQuery = ClassSchool::where('name', $className)
                ->where('school_id', $schoolId);
            $match = $this->matchOne('Class Name', $classQuery, $className);

            if (isset($match['error'])) {
                $base['errors'][] = $match['error'];
                $base['status'] = 'error';
            } else {
                $classId = $match['result']->id;
            }
        }

        // ---- 4. Match Fee by name + class + academic year ----
        $feeStructureName = trim($raw['fee_structure_name'] ?? '');
        if (empty($feeStructureName)) {
            $base['errors'][] = 'Fee Structure Name is required';
            $base['status'] = 'error';
        } elseif ($classId && $sessionYearId) {
            $feeQuery = Fee::with(['installments', 'fees_class_type'])
                ->where('name', $feeStructureName)
                ->where('class_id', $classId)
                ->where('session_year_id', $sessionYearId)
                ->where('school_id', $schoolId);
            $match = $this->matchOne('Fee Structure Name', $feeQuery, $feeStructureName);

            if (isset($match['error'])) {
                $base['errors'][] = $match['error'];
                $base['status'] = 'error';
            } else {
                $fee = $match['result'];
                $feesId = $fee->id;
                $base['fee_name'] = $fee->name;
            }
        }

        // ---- 5. Match Bank Account by name (required) ----
        $bankAccountName = trim($raw['bank_account_name'] ?? '');
        if (empty($bankAccountName)) {
            $base['errors'][] = 'Bank Account Name is required';
            $base['status'] = 'error';
        } else {
            $bankQuery = BankAccount::where('account_name', $bankAccountName)
                ->where('school_id', $schoolId)
                ->active();
            $match = $this->matchOne('Bank Account Name', $bankQuery, $bankAccountName);

            if (isset($match['error'])) {
                $base['errors'][] = $match['error'];
                $base['status'] = 'error';
            } else {
                $bankAccountId = (int) $match['result']->id;
            }
        }

        // ---- Check already fully paid ----
        $feesPaid = null;
        if ($studentId && $feesId) {
            $feesPaid = FeesPaid::where('fees_id', $feesId)
                ->where('student_id', $studentId)
                ->first();
            if ($feesPaid && $feesPaid->is_fully_paid) {
                $base['errors'][] = 'Fee already fully paid';
                $base['status'] = 'error';
            }
        }

        // ---- Amount ----
        $amount = MoneyDecimal::normalize($raw['amount'] ?? '0');
        if (MoneyDecimal::lessThanOrEqual($amount, '0.00')) {
            $base['errors'][] = 'Amount must be positive';
            $base['status'] = 'error';
        }

        // ---- Reference No ----
        $referenceNo = strtoupper(trim($raw['reference_no'] ?? ''));
        if (empty($referenceNo)) {
            $base['errors'][] = 'Reference No is required';
            $base['status'] = 'error';
        } else {
            $base['reference_no'] = $referenceNo;

            if (in_array($referenceNo, $excelRefNos, true)) {
                $base['errors'][] = "Duplicate Reference No '{$referenceNo}' within this Excel file";
                $base['status'] = 'error';
            } else {
                $excelRefNos[] = $referenceNo;
            }

            $dbExists = CompulsoryFee::withTrashed()->where('school_id', $schoolId)
                ->where('reference_no', $referenceNo)
                ->exists();
            if ($dbExists) {
                $base['errors'][] = "Reference No '{$referenceNo}' already exists in database";
                $base['status'] = 'duplicate';
            }
        }

        // ---- Payment Mode ----
        $mode = trim($raw['payment_mode'] ?? 'Cash');
        if (!empty($mode) && !in_array($mode, self::ALLOWED_MODES)) {
            $base['errors'][] = "Invalid payment mode '{$mode}'";
            $base['status'] = 'error';
        } else {
            $base['payment_mode'] = $mode ?: 'Cash';
        }

        // ---- Cheque No ----
        if (in_array($mode, ['Cheque']) && empty(trim($raw['cheque_no'] ?? ''))) {
            $base['errors'][] = 'Cheque number required for Cheque payment mode';
            $base['status'] = 'error';
        }

        // ---- Date ----
        $dateStr = trim($raw['date'] ?? '');
        if (empty($dateStr)) {
            $base['errors'][] = 'Payment date is required';
            $base['status'] = 'error';
        } else {
            $timestamp = $this->parseDate($dateStr);
            if ($timestamp === false) {
                $base['errors'][] = "Invalid date format: '{$dateStr}'";
                $base['status'] = 'error';
            } else {
                $base['payment_date'] = $dateStr;
            }
        }

        // ---- Installment Name (match within fee) ----
        $installmentName = trim($raw['installment_name'] ?? '');
        $base['installment_name'] = $installmentName;

        if (!empty($installmentName) && $fee && $studentId && $base['status'] !== 'error') {
            $matchedInstallments = $fee->installments->where('name', $installmentName);

            if ($matchedInstallments->count() === 0) {
                $base['errors'][] = "Installment '{$installmentName}' not found for fee '{$fee->name}'";
                $base['status'] = 'error';
            } elseif ($matchedInstallments->count() > 1) {
                $base['errors'][] = "Installment '{$installmentName}' matched multiple records in fee '{$fee->name}'";
                $base['status'] = 'error';
            } else {
                $installment = $matchedInstallments->first();
                $alreadyPaidInst = CompulsoryFee::where('student_id', $studentId)
                    ->where('installment_id', $installment->id)
                    ->exists();
                if ($alreadyPaidInst) {
                    $base['errors'][] = "Installment '{$installmentName}' already paid";
                    $base['status'] = 'error';
                }
                $installmentId = $installment->id;
                $base['installment_id'] = $installmentId;
            }
        }

        // ---- Amount validation vs remaining ----
        if ($fee && $studentId && $base['status'] !== 'error') {
            $totalCompulsory = $fee->total_compulsory_fees;
            $alreadyPaidAmount = $feesPaid ? $feesPaid->amount : 0;
            $remaining = (float) ($totalCompulsory - $alreadyPaidAmount);

            if ($remaining <= 0) {
                $base['errors'][] = 'No remaining balance (already fully paid?)';
                $base['status'] = 'error';
            } elseif (!empty($installmentName)) {
                // Installment: amount must not exceed remaining
                if ((float) $amount > $remaining) {
                    $base['errors'][] = "Amount exceeds remaining balance ({$remaining})";
                    $base['status'] = 'error';
                }
            } else {
                // Full Payment: amount must equal remaining
                $remainingNormalized = MoneyDecimal::normalize((string) $remaining);
                if (!MoneyDecimal::equals($amount, $remainingNormalized)) {
                    $base['errors'][] = "Full payment requires exact remaining: {$remainingNormalized}";
                    $base['status'] = 'error';
                }
            }
        }

        // ---- Build payment_data for confirm ----
        // IMPORTANT: internal IDs come from backend matching, NOT from Excel
        $base['payment_data'] = [
            'fees_id'                => $feesId,
            'student_id'             => $studentId,
            'installment_mode'       => !empty($installmentName),
            'installment_fees'       => !empty($installmentName) ? [
                [
                    'id'          => $installmentId ?? 0,
                    'amount'      => $amount,
                    'due_charges' => 0,
                ]
            ] : [],
            'mode'                   => $mode ?: 'Cash',
            'cheque_no'              => in_array($mode, ['Cheque']) ? (trim($raw['cheque_no'] ?? '')) : null,
            'bank_account_id'        => $bankAccountId,
            'date'                   => $dateStr,
            'total_amount'           => $fee ? $fee->total_compulsory_fees : 0,
            'enter_amount'           => empty($installmentName) ? $amount : null,
            'reference_no'           => $referenceNo,
            'due_charges_amount'     => 0,
            'advance'                => 0,
            'parent_id'              => null,
            'transaction_currency'   => 'MMK',
            'original_amount'        => null,
            'exchange_rate_snapshot' => null,
        ];

        // Store resolved IDs in preview row for display (read-only)
        $base['student_id']       = $studentId;
        $base['fees_id']          = $feesId;
        $base['class_id']         = $classId;
        $base['session_year_id']  = $sessionYearId;
        $base['bank_account_id']  = $bankAccountId;

        // Store original business values for display
        $base['admission_no']       = $admissionNo;
        $base['academic_year']      = $academicYear;
        $base['class_name']         = $className;
        $base['fee_structure_name'] = $feeStructureName;
        $base['bank_account_name']  = $bankAccountName;

        return $base;
    }

    /**
     * Re-validate a row during confirm.
     */
    private function revalidateRow(array $pRow, int $schoolId): Fee
    {
        $pd        = $pRow['payment_data'];
        $studentId = $pd['student_id'];
        $feesId    = $pd['fees_id'];

        // 1. Student still exists in school
        $student = User::where('id', $studentId)
            ->where('school_id', $schoolId)
            ->role('Student')
            ->first();
        if (!$student) {
            throw new \InvalidArgumentException("Student {$studentId} no longer available");
        }

        // 2. Fee still exists
        $fee = Fee::with(['installments', 'fees_class_type'])
            ->where('school_id', $schoolId)
            ->find($feesId);
        if (!$fee) {
            throw new \InvalidArgumentException("Fee {$feesId} no longer available");
        }

        // 3. Not already fully paid
        $feesPaid = FeesPaid::where('fees_id', $feesId)
            ->where('student_id', $studentId)
            ->first();
        if ($feesPaid && $feesPaid->is_fully_paid) {
            throw new \InvalidArgumentException("Fee {$feesId} for student {$studentId} is already fully paid");
        }

        // 4. Reference No not newly created since preview
        $referenceNo = $pd['reference_no'] ?? null;
        if ($referenceNo) {
            $existing = CompulsoryFee::where('school_id', $schoolId)
                ->where('reference_no', $referenceNo)
                ->exists();
            if ($existing) {
                throw new \InvalidArgumentException(
                    "Reference No '{$referenceNo}' was created after preview. Please re-preview."
                );
            }
        }

        // 5. Installment still valid
        $installmentFees = $pd['installment_fees'] ?? [];
        if (!empty($installmentFees)) {
            foreach ($installmentFees as $inst) {
                $installmentId = $inst['id'] ?? 0;
                $installment = $fee->installments->firstWhere('id', $installmentId);
                if (!$installment) {
                    throw new \InvalidArgumentException("Installment {$installmentId} no longer exists");
                }
                $alreadyPaid = CompulsoryFee::where('student_id', $studentId)
                    ->where('installment_id', $installmentId)
                    ->exists();
                if ($alreadyPaid) {
                    throw new \InvalidArgumentException("Installment {$installmentId} already paid");
                }
            }
        }

        // 6. Amount vs remaining
        $totalCompulsory = $fee->total_compulsory_fees;
        $alreadyPaidAmount = $feesPaid ? $feesPaid->amount : 0;
        $remaining = (float) ($totalCompulsory - $alreadyPaidAmount);

        if ($remaining <= 0) {
            throw new \InvalidArgumentException("No remaining balance");
        }

        if (empty($installmentFees)) {
            $enterAmount = $pd['enter_amount'];
            if (abs((float) $enterAmount - $remaining) > 0.01) {
                throw new \InvalidArgumentException("Full payment amount changed. Remaining is now {$remaining}");
            }
        } else {
            $installAmount = (float) ($installmentFees[0]['amount'] ?? 0);
            if ($installAmount > $remaining) {
                throw new \InvalidArgumentException("Amount exceeds remaining balance ({$remaining})");
            }
        }

        // 7. Bank Account still exists
        $bankId = $pd['bank_account_id'] ?? null;
        if ($bankId) {
            $bankAccount = BankAccount::where('id', $bankId)
                ->where('school_id', $schoolId)
                ->active()
                ->first();
            if (!$bankAccount) {
                throw new \InvalidArgumentException("Bank account {$bankId} no longer available");
            }
        }

        // 8. Payment mode still valid
        $mode = $pd['mode'] ?? 'Cash';
        if (!in_array($mode, self::ALLOWED_MODES)) {
            throw new \InvalidArgumentException("Invalid payment mode '{$mode}'");
        }

        return $fee;
    }

    /**
     * Parse date string or Excel serial number.
     */
    private function parseDate(string $value): int|false
    {
        $value = trim($value);
        if (is_numeric($value)) {
            $serial = (int) $value;
            if ($serial > 0) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($serial);
            }
        }
        $timestamp = strtotime($value);
        return $timestamp ?: false;
    }
}
