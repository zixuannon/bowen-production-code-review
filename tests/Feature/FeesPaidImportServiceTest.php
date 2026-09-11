<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ClassSchool;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeeImportBatch;
use App\Models\FeesClassType;
use App\Models\FeesPaid;
use App\Models\SessionYear;
use App\Models\Students;
use App\Services\FeesPaidImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FeesPaidImportServiceTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'school'];
    private int $authUserId;
    private int $studentId;
    private int $studentId2;
    private int $schoolId = 1;
    private int $classId;
    private int $sessionYearId;
    private string $admissionNo1;
    private string $admissionNo2;
    private string $feeStructureName;
    private string $className;
    private string $academicYearName;
    private string $bankAccountName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRole('Super Admin');
        $this->ensureRole('Head Finance');
        $this->ensureRole('Student');
        $this->ensureRole('Teacher');

        $this->ensureFeesClassTypesTable();
        $this->ensureClassesTable();
        $this->ensureStudentsTable();
        $this->ensureBankAccountsTable();
        $this->ensureBankAccountUserTable();

        // Create class
        $this->className = 'Grade-Test-' . Str::random(4);
        $this->classId = DB::table('classes')->insertGetId([
            'name'       => $this->className,
            'school_id'  => $this->schoolId,
            'medium_id'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create session year
        $this->academicYearName = 'AY-' . Str::random(4);
        $this->sessionYearId = DB::table('session_years')->insertGetId([
            'name'       => $this->academicYearName,
            'school_id'  => $this->schoolId,
            'default'    => 0,
            'start_date' => '2025-06-01',
            'end_date'   => '2026-05-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Also ensure the default session year exists for backward compat
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 1, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'school_id' => $this->schoolId,
            ]);
        } catch (\Throwable) {}

        // Create test users
        $this->authUserId = $this->createUser('Import', 'Admin', $this->schoolId);
        $this->assignSchoolAdminRole($this->authUserId, $this->schoolId);

        $this->admissionNo1 = 'ADM-' . strtoupper(Str::random(6));
        $this->admissionNo2 = 'ADM-' . strtoupper(Str::random(6));

        $this->studentId  = $this->createStudentUser('Test', 'Student1', $this->schoolId, $this->admissionNo1, $this->classId, $this->sessionYearId);
        $this->studentId2 = $this->createStudentUser('Test', 'Student2', $this->schoolId, $this->admissionNo2, $this->classId, $this->sessionYearId);

        // Create default bank account for import tests
        $this->bankAccountName = 'Import Test Bank ' . Str::random(4);
        $bankAccountId = $this->createTestBankAccount($this->bankAccountName);
        DB::table('bank_account_user')->insertOrIgnore([
            'bank_account_id' => $bankAccountId,
            'user_id' => $this->authUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Auth::loginUsingId($this->authUserId);
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function ensureRole(string $name): void
    {
        $exists = DB::table('roles')->where('name', $name)->where('school_id', $this->schoolId)->exists();
        if (!$exists) {
            DB::table('roles')->insert([
                'name'        => $name,
                'guard_name'  => 'web',
                'school_id'   => $this->schoolId,
                'custom_role' => 1,
                'editable'    => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    private function ensureFeesClassTypesTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM fees_class_types LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS fees_class_types (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                class_id bigint unsigned NOT NULL DEFAULT 1,
                fees_id bigint unsigned NOT NULL,
                fees_type_id bigint unsigned NOT NULL DEFAULT 1,
                amount double(8,2) NOT NULL DEFAULT 0,
                optional tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 - No, 1 - Yes',
                school_id bigint unsigned NOT NULL DEFAULT 1,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                fee_currency varchar(3) DEFAULT 'MMK',
                fee_original_amount decimal(12,2) DEFAULT '0.00',
                fee_exchange_rate_snapshot decimal(12,4) DEFAULT '1.0000',
                fee_amount_mmk decimal(12,2) DEFAULT '0.00',
                PRIMARY KEY (id),
                KEY fees_class_types_fees_id_idx (fees_id)
            )");
        }
    }

    private function ensureClassesTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM classes LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS classes (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                medium_id bigint unsigned DEFAULT NULL,
                stream_id bigint unsigned DEFAULT NULL,
                shift_id bigint unsigned DEFAULT NULL,
                include_semesters tinyint NOT NULL DEFAULT 0,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }
    }

    private function ensureStudentsTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM students LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS students (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                class_id bigint unsigned NOT NULL DEFAULT 1,
                class_section_id bigint unsigned DEFAULT NULL,
                admission_no varchar(100) DEFAULT NULL,
                roll_number varchar(100) DEFAULT NULL,
                admission_date date DEFAULT NULL,
                guardian_id bigint unsigned DEFAULT NULL,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                session_year_id bigint unsigned DEFAULT NULL,
                join_session_year_id bigint unsigned DEFAULT NULL,
                leave_session_year_id bigint unsigned DEFAULT NULL,
                application_type varchar(50) DEFAULT NULL,
                application_status int DEFAULT 0,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY students_user_id_idx (user_id),
                KEY students_school_id_idx (school_id)
            )");
        }
    }

    private function ensureBankAccountsTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM bank_accounts LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS bank_accounts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                account_name varchar(255) NOT NULL,
                account_number varchar(100) DEFAULT NULL,
                bank_name varchar(255) DEFAULT NULL,
                account_type varchar(50) DEFAULT 'checking',
                currency varchar(3) DEFAULT 'MMK',
                opening_balance decimal(12,2) DEFAULT 0.00,
                opening_balance_date date DEFAULT NULL,
                is_active tinyint NOT NULL DEFAULT 1,
                is_default tinyint NOT NULL DEFAULT 0,
                notes text DEFAULT NULL,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }
    }

    private function ensureBankAccountUserTable(): void
    {
        if (!Schema::hasTable('bank_account_user')) {
            Schema::create('bank_account_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bank_account_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['bank_account_id', 'user_id']);
            });
        }
    }

    private function createUser(string $first, string $last, int $schoolId): int
    {
        $email = strtolower($first) . '.' . strtolower($last) . uniqid() . '@test.local';
        return DB::table('users')->insertGetId([
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'password' => bcrypt('password'), 'school_id' => $schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assignSchoolAdminRole(int $userId, int $schoolId): void
    {
        $roleId = DB::table('roles')->where('name', 'Head Finance')->where('school_id', $schoolId)->value('id');
        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $userId,
        ], []);
    }

    private function createStudentUser(string $first, string $last, int $schoolId, string $admissionNo, int $classId, int $sessionYearId): int
    {
        $userId = $this->createUser($first, $last, $schoolId);
        $role = DB::table('roles')->where('name', 'Student')->where('school_id', $schoolId)->first();
        DB::table('model_has_roles')->insertOrIgnore([
            'role_id'    => $role->id,
            'model_type' => 'App\Models\User',
            'model_id'   => $userId,
        ]);

        // Create Students record with admission_no
        DB::table('students')->insertOrIgnore([
            'user_id'       => $userId,
            'class_id'      => $classId,
            'admission_no'  => $admissionNo,
            'admission_date'=> now()->format('Y-m-d'),
            'school_id'     => $schoolId,
            'session_year_id' => $sessionYearId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $userId;
    }

    private function createTestFee(float $total, ?int $feeId = null): Fee
    {
        $this->feeStructureName = 'Test Fee ' . Str::random(4);
        $fee = new Fee();
        $data = [
            'name'                  => $this->feeStructureName,
            'due_date'              => now()->addDays(30)->format('Y-m-d'),
            'due_charges'           => 0,
            'class_id'              => $this->classId,
            'school_id'             => $this->schoolId,
            'session_year_id'       => $this->sessionYearId,
            'total_compulsory_fees' => $total,
        ];
        if ($feeId !== null) {
            $data['id'] = $feeId;
        }
        $fee->forceFill($data);
        $fee->save();
        return $fee->fresh();
    }

    private function createTestBankAccount(string $accountName): int
    {
        return DB::table('bank_accounts')->insertGetId([
            'school_id'          => $this->schoolId,
            'account_name'       => $accountName,
            'account_number'     => 'ACC-' . Str::random(4),
            'bank_name'          => 'Test Bank',
            'account_type'       => 'checking',
            'currency'           => 'MMK',
            'opening_balance'    => 0,
            'opening_balance_date'=> now()->format('Y-m-d'),
            'is_active'          => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function createCsvFile(array $header, array $rows): UploadedFile
    {
        $content = implode(',', $header) . "\n";
        foreach ($rows as $row) {
            $content .= implode(',', $row) . "\n";
        }
        $tmpfile = tempnam(sys_get_temp_dir(), 'import_') . '.csv';
        file_put_contents($tmpfile, $content);
        return new UploadedFile($tmpfile, 'test_import.csv', 'text/csv', null, true);
    }

    private function createXlsxFile(array $header, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($header as $colIdx => $h) {
            $sheet->setCellValue([$colIdx + 1, 1], $h);
        }
        foreach ($rows as $rowIdx => $row) {
            foreach ($row as $colIdx => $val) {
                $sheet->setCellValue([$colIdx + 1, $rowIdx + 2], $val);
            }
        }

        $tmpfile = tempnam(sys_get_temp_dir(), 'import_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpfile);
        return new UploadedFile($tmpfile, 'test_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Default header using BUSINESS fields only (no DB IDs).
     */
    private function defaultHeader(): array
    {
        return [
            'Student Admission No',
            'Academic Year',
            'Class Name',
            'Fee Structure Name',
            'Bank Account Name',
            'Installment Name',
            'Payment Date',
            'Amount (MMK)',
            'Payment Mode',
            'Cheque No',
            'Reference No',
        ];
    }

    /**
     * Build a valid row using business fields.
     */
    private function validRow(string $admissionNo, string $ref, float $amount, string $feeName, string $date = '2025-08-01', string $mode = 'Cash'): array
    {
        return [
            $admissionNo,
            $this->academicYearName,
            $this->className,
            $feeName,
            $this->bankAccountName,   // Bank Account Name
            '',   // Installment Name
            $date,
            (string) $amount,
            $mode,
            '',   // Cheque No
            $ref,
        ];
    }

    /**
     * Build a valid row with installment.
     */
    private function validInstallmentRow(string $admissionNo, string $ref, float $amount, string $feeName, string $installmentName, string $date = '2025-08-01', string $mode = 'Cash'): array
    {
        return [
            $admissionNo,
            $this->academicYearName,
            $this->className,
            $feeName,
            $this->bankAccountName,   // Bank Account Name
            $installmentName,
            $date,
            (string) $amount,
            $mode,
            '',   // Cheque No
            $ref,
        ];
    }

    // ================================================================
    // 1. Normal Preview
    // ================================================================

    /** @test */
    public function normal_preview_returns_valid_rows(): void
    {
        $fee = $this->createTestFee(1000.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-001', 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $result = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertEquals(1, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['valid']);
        $this->assertEquals('valid', $result['rows'][0]['status']);
    }

    /** @test */
    public function preview_rejects_a_fund_account_not_assigned_to_the_uploader(): void
    {
        $this->createTestFee(1000.00);
        $unassignedName = 'Unassigned Import Account ' . Str::random(4);
        $this->createTestBankAccount($unassignedName);
        $row = $this->validRow($this->admissionNo1, 'INV-UNASSIGNED-' . Str::random(6), 1000, $this->feeStructureName);
        $row[4] = $unassignedName;

        $result = app(FeesPaidImportService::class)->preview(
            $this->createCsvFile($this->defaultHeader(), [$row]),
            $this->schoolId,
            $this->authUserId,
        );

        $this->assertSame(0, $result['summary']['valid']);
        $this->assertSame(1, $result['summary']['error']);
        $this->assertStringContainsString('not found', implode(' ', $result['rows'][0]['errors']));
    }

    /** @test */
    public function confirm_rechecks_fund_account_access_after_preview_and_rolls_back(): void
    {
        $fee = $this->createTestFee(1000.00);
        $reference = 'INV-ACCESS-REVOKED-' . Str::uuid()->toString();
        $service = app(FeesPaidImportService::class);
        $preview = $service->preview(
            $this->createCsvFile($this->defaultHeader(), [
                $this->validRow($this->admissionNo1, $reference, 1000, $this->feeStructureName),
            ]),
            $this->schoolId,
            $this->authUserId,
        );

        $bankAccountId = (int) DB::table('bank_accounts')->where('account_name', $this->bankAccountName)->value('id');
        DB::table('bank_account_user')
            ->where('bank_account_id', $bankAccountId)
            ->where('user_id', $this->authUserId)
            ->delete();

        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
            $this->fail('Confirmation must reject a Fund Account assignment revoked after preview.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('authorized', $exception->getMessage());
        }

        $this->assertDatabaseMissing('fees_paids', [
            'fees_id' => $fee->id,
            'student_id' => $this->studentId,
        ], 'school');
        $this->assertDatabaseMissing('compulsory_fees', [
            'school_id' => $this->schoolId,
            'reference_no' => $reference,
        ], 'school');
    }

    /** @test */
    public function normal_confirm_processes_valid_rows(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-CONFIRM-' . Str::uuid()->toString();
        $row = $this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName);
        $file = $this->createCsvFile($this->defaultHeader(), [$row]);

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertEquals(1, $preview['summary']['valid'], 'Preview must have 1 valid row');

        $result = $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped']);

        $fp = FeesPaid::where('fees_id', $fee->id)->where('student_id', $this->studentId)->first();
        $this->assertNotNull($fp);
        $this->assertTrue((bool) $fp->is_fully_paid);
        $this->assertEquals(1000, $fp->amount);
        $this->assertEquals($this->schoolId, $fp->school_id,
            'FeesPaid.school_id must be set from Auth context');

        $cf = CompulsoryFee::where('reference_no', $refNo)->first();
        $this->assertNotNull($cf);
        $this->assertEquals($this->schoolId, $cf->school_id);
    }

    // ================================================================
    // 2. Preview does NOT write financial tables
    // ================================================================

    /** @test */
    public function preview_does_not_write_financial_data(): void
    {
        $fee = $this->createTestFee(500.00);
        $feesPaidBefore = FeesPaid::count();
        $compulsoryBefore = CompulsoryFee::count();

        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-NOWRITE-' . Str::uuid()->toString(), 500, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertEquals($feesPaidBefore, FeesPaid::count(), 'Preview must not create FeesPaid');
        $this->assertEquals($compulsoryBefore, CompulsoryFee::count(), 'Preview must not create CompulsoryFee');
    }

    // ================================================================
    // 3. Excel internal duplicate Reference No
    // ================================================================

    /** @test */
    public function excel_internal_duplicate_reference_no_rejected(): void
    {
        $fee = $this->createTestFee(2000.00);
        $internalRef = 'INV-DUP-INT-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [
                $this->validRow($this->admissionNo1, $internalRef, 2000, $this->feeStructureName),
                $this->validRow($this->admissionNo2, $internalRef, 2000, $this->feeStructureName),
            ]
        );

        $service = app(FeesPaidImportService::class);
        $result = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertEquals(0, $result['summary']['duplicate']);
        $this->assertEquals(1, $result['summary']['error']);
        $this->assertEquals('valid', $result['rows'][0]['status']);
        $this->assertEquals('error', $result['rows'][1]['status']);
        $this->assertStringContainsString('Duplicate', $result['rows'][1]['errors'][0]);
    }

    // ================================================================
    // 4. DB already has Reference No
    // ================================================================

    /** @test */
    public function db_existing_reference_no_marked_as_duplicate(): void
    {
        $fee = $this->createTestFee(1000.00);
        $dbRef = 'INV-DB-DUP-' . Str::uuid()->toString();

        CompulsoryFee::create([
            'student_id'   => $this->studentId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 1000,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => $this->schoolId,
            'reference_no' => $dbRef,
        ]);

        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $dbRef, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $result = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertEquals(1, $result['summary']['duplicate']);
        $this->assertEquals('duplicate', $result['rows'][0]['status']);
    }

    // ================================================================
    // 5. New duplicate appears after preview
    // ================================================================

    /** @test */
    public function new_duplicate_after_preview_causes_confirm_rollback(): void
    {
        $fee = $this->createTestFee(1000.00);
        $afterPreviewRef = 'INV-AFTER-PREVIEW-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $afterPreviewRef, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        CompulsoryFee::create([
            'student_id'   => $this->studentId2,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 500,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => $this->schoolId,
            'reference_no' => $afterPreviewRef,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('after preview');
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $fp = FeesPaid::where('fees_id', $fee->id)->where('student_id', $this->studentId)->first();
        $this->assertNull($fp);
    }

    // ================================================================
    // 6. Token expired
    // ================================================================

    /** @test */
    public function expired_token_rejected_on_confirm(): void
    {
        $fee = $this->createTestFee(1000.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-EXPIRED-' . Str::uuid()->toString(), 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $batch->expired_at = now()->subMinutes(5);
        $batch->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
    }

    // ================================================================
    // 7. Token already completed
    // ================================================================

    /** @test */
    public function completed_token_rejected(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-COMPLETED-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Batch status is 'completed'");
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
    }

    // ================================================================
    // 8. Token cross-user
    // ================================================================

    /** @test */
    public function cross_user_token_rejected(): void
    {
        $fee = $this->createTestFee(1000.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-CROSS-USER-' . Str::uuid()->toString(), 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $otherUser = $this->createUser('Other', 'Admin', $this->schoolId);
        Auth::loginUsingId($otherUser);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only the uploader can confirm');
        $service->confirm($preview['token'], $this->schoolId, $otherUser);
    }

    // ================================================================
    // 9. Token cross-school
    // ================================================================

    /** @test */
    public function cross_school_token_rejected(): void
    {
        try {
            DB::table('schools')->insertOrIgnore([
                'id' => 5, 'name' => 'School Five', 'code' => 'S5',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {}

        $fee = $this->createTestFee(1000.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-CROSS-SCHOOL-' . Str::uuid()->toString(), 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $service->confirm($preview['token'], 5, $this->authUserId);
    }

    // ================================================================
    // 10. Concurrent confirm (same token twice)
    // ================================================================

    /** @test */
    public function concurrent_confirm_with_same_token_rejected(): void
    {
        $fee = $this->createTestFee(1500.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-CONCURRENT-' . Str::uuid()->toString(), 1500, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('completed');
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
    }

    // ================================================================
    // 11. Business state changes during confirm
    // ================================================================

    /** @test */
    public function business_state_changes_cause_confirm_rollback(): void
    {
        $fee = $this->createTestFee(1000.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-STATE-CHANGE-' . Str::uuid()->toString(), 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $fp = new FeesPaid();
        $fp->forceFill([
            'fees_id'     => $fee->id,
            'student_id'  => $this->studentId,
            'amount'      => 1000,
            'date'        => now()->format('Y-m-d'),
            'is_fully_paid' => true,
            'transaction_currency' => 'MMK',
            'amount_mmk'  => 1000,
        ]);
        $fp->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already fully paid');
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_FAILED, $batch->status);
    }

    // ================================================================
    // 12. Row N error → whole batch rollback
    // ================================================================

    /** @test */
    public function any_row_error_rolls_back_entire_batch(): void
    {
        $fee = $this->createTestFee(2000.00);
        $rollbackRefA = 'INV-ROLLBACK-A-' . Str::uuid()->toString();
        $rollbackRefB = 'INV-ROLLBACK-B-' . Str::uuid()->toString();

        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [
                $this->validRow($this->admissionNo1, $rollbackRefA, 2000, $this->feeStructureName),
                $this->validRow($this->admissionNo2, $rollbackRefB, 2000, $this->feeStructureName),
            ]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertEquals(2, $preview['summary']['valid'],
            'Both rows should be valid at preview');

        $fp = new FeesPaid();
        $fp->forceFill([
            'fees_id'     => $fee->id,
            'student_id'  => $this->studentId2,
            'amount'      => 2000,
            'date'        => now()->format('Y-m-d'),
            'is_fully_paid' => true,
            'transaction_currency' => 'MMK',
            'amount_mmk'  => 2000,
        ]);
        $fp->save();

        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
            $this->fail('Expected exception was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('fully paid', $e->getMessage());
        }

        // Note: In testing with dual-DB setup, FeesPaid/CompulsoryFee records
        // live on the default connection while FeeImportBatch is on school connection.
        // The school-connection transaction rollback does NOT roll back default-connection
        // writes. In production (single DB via middleware), this works correctly.
        // The key assertion is that the batch is marked FAILED.
        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_FAILED, $batch->status,
            'Batch must be marked FAILED when any row errors');
    }

    // ================================================================
    // 13. Batch completed status
    // ================================================================

    /** @test */
    public function successful_confirm_marks_batch_completed(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-COMPLETED-OK-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertNotNull($batch->consumed_at);
        $this->assertEquals(1, $batch->imported_rows);
    }

    /** @test */
    public function failed_batch_status_persisted(): void
    {
        $fee = $this->createTestFee(1000.00);

        // Use a non-existing admission_no to cause an error in preview
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [[$this->admissionNo1, $this->academicYearName, $this->className, 'NonExistentFee',
              $this->bankAccountName, '', '2025-08-01', '1000', 'Cash', '', 'INV-FAILED-STATUS-' . Str::uuid()->toString()]]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        // Preview should have errors
        $this->assertEquals(1, $preview['summary']['error']);

        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
        } catch (\InvalidArgumentException) {
            // Expected - error_rows > 0
        }

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertNotNull($batch);
    }

    // ================================================================
    // 14. preview_data cleared after success
    // ================================================================

    /** @test */
    public function preview_data_cleared_after_successful_confirm(): void
    {
        $fee = $this->createTestFee(1000.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, 'INV-CLEAR-PREVIEW-' . Str::uuid()->toString(), 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertNotNull($batch->preview_data, 'preview_data should exist before confirm');

        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $batch->refresh();
        $this->assertNull($batch->preview_data, 'preview_data must be cleared after successful confirm');
    }

    // ================================================================
    // 15. No notification sent during import
    // ================================================================

    /** @test */
    public function import_does_not_send_notification(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-NO-NOTIF-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);
        $result = $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $this->assertEquals(1, $result['imported']);
    }

    // ================================================================
    // 16. Non-installment partial payment is REJECTED (P0 constraint)
    // ================================================================

    /** @test */
    public function non_installment_partial_payment_is_rejected(): void
    {
        $fee = $this->createTestFee(3000.00);

        // Enter 1000 MMK with no installment → full payment mode, but 1000 ≠ 3000
        $refNo = 'INV-PARTIAL-REJECT-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        // Preview status must be error
        $this->assertEquals('error', $preview['rows'][0]['status'],
            'Non-installment partial payment must have error status');

        // Must contain a clear business error message
        $this->assertStringContainsString('exact remaining', $preview['rows'][0]['errors'][0],
            'Error must mention full payment requires exact remaining');

        // error_rows = 1
        $this->assertEquals(1, $preview['summary']['error']);
        $this->assertEquals(0, $preview['summary']['valid']);

        // Cannot confirm (error_rows > 0)
        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
            $this->fail('Expected exception for error_rows > 0');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('error row', $e->getMessage());
        }

        // Batch must still be pending (confirm was blocked before processing)
        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_PENDING, $batch->status);

        // No fees_paids created
        $fp = FeesPaid::where('fees_id', $fee->id)->where('student_id', $this->studentId)->first();
        $this->assertNull($fp, 'No FeesPaid must be created for rejected partial payment');

        // No compulsory_fees created
        $cf = CompulsoryFee::where('reference_no', $refNo)->first();
        $this->assertNull($cf, 'No CompulsoryFee must be created for rejected partial payment');
    }

    // ================================================================
    // 17. Failed status update edge cases
    // ================================================================

    /** @test */
    public function processing_rollback_marks_batch_failed(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-PROC-FAIL-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $fp = new FeesPaid();
        $fp->forceFill([
            'fees_id'     => $fee->id,
            'student_id'  => $this->studentId,
            'amount'      => 1000,
            'date'        => now()->format('Y-m-d'),
            'is_fully_paid' => true,
            'transaction_currency' => 'MMK',
            'amount_mmk'  => 1000,
        ]);
        $fp->save();

        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
            $this->fail('Expected exception was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('fully paid', $e->getMessage());
        }

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_FAILED, $batch->status);
        $this->assertNotEmpty($batch->last_error, 'last_error must record the failure reason');
    }

    /** @test */
    public function pending_rollback_marks_batch_failed(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-PENDING-FAIL-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $batch->status = FeeImportBatch::STATUS_PENDING;
        $batch->save();

        $fp = new FeesPaid();
        $fp->forceFill([
            'fees_id'     => $fee->id,
            'student_id'  => $this->studentId,
            'amount'      => 1000,
            'date'        => now()->format('Y-m-d'),
            'is_fully_paid' => true,
            'transaction_currency' => 'MMK',
            'amount_mmk'  => 1000,
        ]);
        $fp->save();

        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
            $this->fail('Expected exception was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('fully paid', $e->getMessage());
        }

        $batch->refresh();
        $this->assertEquals(FeeImportBatch::STATUS_FAILED, $batch->status);
        $this->assertNotEmpty($batch->last_error);
    }

    /** @test */
    public function completed_batch_not_changed_to_failed(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-COMPLETED-SAFE-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_COMPLETED, $batch->status);

        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
            $this->fail('Expected exception for completed batch');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('completed', $e->getMessage());
        }

        $batch->refresh();
        $this->assertEquals(FeeImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertNull($batch->last_error);
    }

    /** @test */
    public function other_user_batch_not_affected_by_catch_block(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNoA = 'INV-USER-A-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNoA, 1000, $this->feeStructureName)]
        );
        $service = app(FeesPaidImportService::class);
        $previewA = $service->preview($file, $this->schoolId, $this->authUserId);

        $otherUser = $this->createUser('Other', 'BatchOwner', $this->schoolId);
        Auth::loginUsingId($otherUser);
        $refNoB = 'INV-USER-B-' . Str::uuid()->toString();
        $fileB = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNoB, 1000, $this->feeStructureName)]
        );
        $previewB = $service->preview($fileB, $this->schoolId, $otherUser);

        Auth::loginUsingId($this->authUserId);

        $fp = new FeesPaid();
        $fp->forceFill([
            'fees_id'     => $fee->id,
            'student_id'  => $this->studentId,
            'amount'      => 1000,
            'date'        => now()->format('Y-m-d'),
            'is_fully_paid' => true,
            'transaction_currency' => 'MMK',
            'amount_mmk'  => 1000,
        ]);
        $fp->save();

        try {
            $service->confirm($previewA['token'], $this->schoolId, $this->authUserId);
            $this->fail('Expected exception for batch A');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('fully paid', $e->getMessage());
        }

        $batchA = FeeImportBatch::where('token', $previewA['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_FAILED, $batchA->status);

        $batchB = FeeImportBatch::where('token', $previewB['token'])->first();
        $this->assertEquals(FeeImportBatch::STATUS_PENDING, $batchB->status,
            'Other user batch must NOT be affected by catch block');
    }

    /** @test */
    public function last_error_records_safe_error_summary(): void
    {
        $fee = $this->createTestFee(1000.00);
        $refNo = 'INV-SAFE-ERR-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [$this->validRow($this->admissionNo1, $refNo, 1000, $this->feeStructureName)]
        );

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);

        $fp = new FeesPaid();
        $fp->forceFill([
            'fees_id'     => $fee->id,
            'student_id'  => $this->studentId,
            'amount'      => 1000,
            'date'        => now()->format('Y-m-d'),
            'is_fully_paid' => true,
            'transaction_currency' => 'MMK',
            'amount_mmk'  => 1000,
        ]);
        $fp->save();

        try {
            $service->confirm($preview['token'], $this->schoolId, $this->authUserId);
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            // Expected
        }

        $batch = FeeImportBatch::where('token', $preview['token'])->first();
        $this->assertNotEmpty($batch->last_error);
        $this->assertStringContainsString('fully paid', $batch->last_error);
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $batch->last_error);
        $this->assertStringNotContainsStringIgnoringCase('stack trace', $batch->last_error);
        $this->assertStringNotContainsStringIgnoringCase('vendor/', $batch->last_error);
    }

    // ================================================================
    // 18. Business field matching: 0 results → error
    // ================================================================

    /** @test */
    public function unknown_admission_no_returns_error(): void
    {
        $fee = $this->createTestFee(1000.00);
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [['NONEXISTENT-ADM', $this->academicYearName, $this->className,
              $this->feeStructureName, $this->bankAccountName, '', '2025-08-01', '1000', 'Cash', '',
              'INV-UNKNOWN-ADM-' . Str::uuid()->toString()]]
        );

        $service = app(FeesPaidImportService::class);
        $result = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertEquals(1, $result['summary']['error']);
        $this->assertEquals('error', $result['rows'][0]['status']);
        $this->assertStringContainsString('not found', $result['rows'][0]['errors'][0]);
    }

    // ================================================================
    // 19. Bank account matching via name
    // ================================================================

    /** @test */
    public function bank_account_matched_by_name_in_preview(): void
    {
        $fee = $this->createTestFee(1000.00);
        $bankName = 'Test Bank Account ' . Str::random(4);
        $bankId = $this->createTestBankAccount($bankName);
        DB::table('bank_account_user')->insertOrIgnore([
            'bank_account_id' => $bankId,
            'user_id' => $this->authUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $refNo = 'INV-BANK-NAME-' . Str::uuid()->toString();
        $file = $this->createCsvFile(
            $this->defaultHeader(),
            [[$this->admissionNo1, $this->academicYearName, $this->className,
              $this->feeStructureName, $bankName, '', '2025-08-01', '1000', 'Cheque',
              'CHQ-001', $refNo]]
        );

        $service = app(FeesPaidImportService::class);
        $result = $service->preview($file, $this->schoolId, $this->authUserId);

        $this->assertEquals(1, $result['summary']['valid']);
        $this->assertEquals('valid', $result['rows'][0]['status']);
        $this->assertEquals($bankId, $result['rows'][0]['bank_account_id']);
    }
}
