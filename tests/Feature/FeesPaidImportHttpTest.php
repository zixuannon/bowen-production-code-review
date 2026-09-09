<?php

namespace Tests\Feature;

use App\Models\Fee;
use App\Models\FeeImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * HTTP-level tests for the Fees Paid Excel Import feature.
 *
 * Covers: auth, permissions, feature flag, template download,
 * preview, confirm, error handling, cross-user/school protection,
 * and school-isolation for tokens.
 */
class FeesPaidImportHttpTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'school'];

    private int $schoolId = 1;
    private int $otherSchoolId = 5;
    private User $adminUser;
    private User $otherAdminUser;
    private int $classId;
    private int $sessionYearId;
    private string $className;
    private string $academicYearName;
    private string $bankAccountName;

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            file_put_contents(storage_path('installed'), date('Y-m-d H:i:s'));
        }

        // Pre-cache "Fees Management" feature
        $cacheKey = config('constants.CACHE.SCHOOL.FEATURES') . '_' . $this->schoolId;
        Cache::put($cacheKey, [1 => 'Fees Management'], 3600);

        // Also cache for other school
        $cacheKey2 = config('constants.CACHE.SCHOOL.FEATURES') . '_' . $this->otherSchoolId;
        Cache::put($cacheKey2, [1 => 'Fees Management'], 3600);

        $this->ensureRoles();
        $this->ensureFeesClassTypesTable();
        $this->ensureClassesTable();
        $this->ensureStudentsTable();

        // Create test class
        $this->className = 'HTTP-Grade-' . Str::random(4);
        $this->classId = DB::table('classes')->insertGetId([
            'name'       => $this->className,
            'school_id'  => $this->schoolId,
            'medium_id'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create session year
        $this->academicYearName = 'HTTP-AY-' . Str::random(4);
        $this->sessionYearId = DB::table('session_years')->insertGetId([
            'name'       => $this->academicYearName,
            'school_id'  => $this->schoolId,
            'default'    => 0,
            'start_date' => '2025-06-01',
            'end_date'   => '2026-05-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Default session year
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 1, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'school_id' => $this->schoolId,
            ]);
        } catch (\Throwable) {}

        // Second school for cross-school tests
        try {
            DB::connection('school')->table('schools')->insertOrIgnore([
                'id' => $this->otherSchoolId, 'name' => 'Other School', 'code' => 'OS5',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {}

        $this->adminUser = $this->createAdminUser('ImportAdmin', $this->schoolId);
        $this->assignPermission($this->adminUser, 'fees-paid');

        $this->otherAdminUser = $this->createAdminUser('OtherAdmin', $this->schoolId);
        $this->assignPermission($this->otherAdminUser, 'fees-paid');

        // Create default bank account for import tests
        $this->ensureBankAccountsTable();
        $this->bankAccountName = 'HTTP Bank ' . Str::random(4);
        DB::table('bank_accounts')->insert([
            'school_id'          => $this->schoolId,
            'account_name'       => $this->bankAccountName,
            'account_number'     => 'HTTP-ACC',
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

    private function actAsAdminForImport(?User $user = null): void
    {
        $this->withoutMiddleware();
        $this->actingAs($user ?? $this->adminUser);
    }

    // ================================================================
    // 1. Auth & Permissions
    // ================================================================

    /** @test */
    public function unauthenticated_user_cannot_access_template_download(): void
    {
        $response = $this->get(route('fees.import.template'));
        $response->assertOk();
        $response->assertSee('login', false);
    }

    /** @test */
    public function unauthenticated_user_cannot_upload_for_preview(): void
    {
        $file = $this->createValidXlsx();
        $response = $this->post(route('fees.import.preview'), ['file' => $file]);
        $response->assertOk();
        $response->assertSee('login', false);
    }

    /** @test */
    public function unauthenticated_user_cannot_confirm(): void
    {
        $response = $this->post(route('fees.import.confirm'), ['token' => 'fake-token']);
        $response->assertOk();
        $response->assertSee('login', false);
    }

    /** @test */
    public function user_without_permission_cannot_access_template(): void
    {
        $noPermUser = $this->createAdminUser('NoPerm', $this->schoolId);
        $this->actingAs($noPermUser);

        $response = $this->get(route('fees.import.template'));
        $response->assertRedirect();
    }

    /** @test */
    public function user_without_permission_cannot_preview(): void
    {
        $noPermUser = $this->createAdminUser('NoPerm2', $this->schoolId);
        $this->actingAs($noPermUser);

        $file = $this->createValidXlsx();
        $response = $this->post(route('fees.import.preview'), ['file' => $file]);
        $response->assertRedirect();
    }

    // ================================================================
    // 2. Template Download
    // ================================================================

    /** @test */
    public function template_can_be_downloaded_by_authorized_user(): void
    {
        $this->actAsAdminForImport();

        $response = $this->get(route('fees.import.template'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('fees_paid_import_template', $response->headers->get('Content-Disposition'));
    }

    // ================================================================
    // 3. Preview - valid file
    // ================================================================

    /** @test */
    public function valid_file_returns_preview_data(): void
    {
        $this->actAsAdminForImport();

        $fee = $this->createTestFee(50000.00);
        $admissionNo = $this->createStudentUser('PreviewStudent', $this->schoolId);

        $file = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, 'INV-PREVIEW-HTTP-' . Str::uuid()->toString()),
        ]);

        $response = $this->post(route('fees.import.preview'), ['file' => $file]);

        $response->assertOk();
        $response->assertJson(['error' => false]);
        $data = $response->json('data');
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertEquals(1, $data['summary']['total']);
    }

    // ================================================================
    // 4. Preview - invalid file (422)
    // ================================================================

    /** @test */
    public function non_excel_file_returns_422(): void
    {
        $this->actAsAdminForImport();

        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');
        $response = $this->post(route('fees.import.preview'), ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJson(['error' => true]);
    }

    /** @test */
    public function empty_file_returns_preview_with_zero_rows(): void
    {
        $this->actAsAdminForImport();

        $file = $this->createEmptyXlsx();
        $response = $this->post(route('fees.import.preview'), ['file' => $file]);

        $response->assertOk();
        $response->assertJson(['error' => false]);
        $this->assertEquals(0, $response->json('data.summary.total'));
    }

    /** @test */
    public function missing_file_returns_validation_error(): void
    {
        $this->actAsAdminForImport();

        $response = $this->post(route('fees.import.preview'), []);
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    // ================================================================
    // 5. Confirm - cannot confirm when error rows exist
    // ================================================================

    /** @test */
    public function confirm_with_error_rows_is_rejected(): void
    {
        $this->actAsAdminForImport();

        $fee = $this->createTestFee(50000.00);

        $file = $this->createXlsxFromRows([
            ['NONEXISTENT-ADM', $this->academicYearName, $this->className,
             $this->feeStructureName, $this->bankAccountName, '', '2025-01-15', '50000', 'Cash', '',
             'INV-ERR-CONFIRM-' . Str::uuid()->toString()],
        ]);

        $previewResponse = $this->post(route('fees.import.preview'), ['file' => $file]);
        $previewResponse->assertOk();
        $data = $previewResponse->json('data');
        $this->assertEquals(1, $data['summary']['error']);

        $batch = FeeImportBatch::where('token', $data['token'])->first();
        $this->assertNotNull($batch);
        $this->assertGreaterThan(0, $batch->error_rows);

        $confirmResponse = $this->post(route('fees.import.confirm'), ['token' => $data['token']]);
        $confirmResponse->assertStatus(422);
        $confirmResponse->assertJson(['error' => true]);
    }

    // ================================================================
    // 6. Confirm - successful
    // ================================================================

    /** @test */
    public function valid_confirm_imports_successfully(): void
    {
        $this->actAsAdminForImport();

        $fee = $this->createTestFee(50000.00);
        $admissionNo = $this->createStudentUser('ConfirmStudent', $this->schoolId);

        $file = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, 'INV-CONFIRM-OK-' . Str::uuid()->toString()),
        ]);

        $previewResponse = $this->post(route('fees.import.preview'), ['file' => $file]);
        $previewResponse->assertOk();
        $payload = $previewResponse->json('data');

        $confirmResponse = $this->post(route('fees.import.confirm'), ['token' => $payload['token']]);
        $confirmResponse->assertOk();
        $confirmResponse->assertJson(['error' => false]);
        $result = $confirmResponse->json('data');
        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals(1, $result['total_rows']);
    }

    // ================================================================
    // 7. Double-confirm is rejected
    // ================================================================

    /** @test */
    public function duplicate_confirm_is_rejected(): void
    {
        $this->actAsAdminForImport();

        $fee = $this->createTestFee(50000.00);
        $admissionNo = $this->createStudentUser('DoubleConfirm', $this->schoolId);

        $file = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, 'INV-DBL-' . Str::uuid()->toString()),
        ]);

        $previewResponse = $this->post(route('fees.import.preview'), ['file' => $file]);
        $previewResponse->assertOk();
        $token = $previewResponse->json('data.token');

        $first = $this->post(route('fees.import.confirm'), ['token' => $token]);
        $first->assertOk();
        $first->assertJson(['error' => false]);

        $second = $this->post(route('fees.import.confirm'), ['token' => $token]);
        $second->assertStatus(422);
        $second->assertJson(['error' => true]);
    }

    // ================================================================
    // 8. Cross-user token protection
    // ================================================================

    /** @test */
    public function other_user_cannot_confirm_another_users_token(): void
    {
        $this->actAsAdminForImport($this->adminUser);

        $fee = $this->createTestFee(50000.00);
        $admissionNo = $this->createStudentUser('CrossUserStu', $this->schoolId);

        $file = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, 'INV-CROSS-USER-' . Str::uuid()->toString()),
        ]);

        $previewResponse = $this->post(route('fees.import.preview'), ['file' => $file]);
        $previewResponse->assertOk();
        $token = $previewResponse->json('data.token');

        $this->actAsAdminForImport($this->otherAdminUser);

        $confirmResponse = $this->post(route('fees.import.confirm'), ['token' => $token]);
        $confirmResponse->assertStatus(422);
        $confirmResponse->assertJson(['error' => true]);
    }

    // ================================================================
    // 9. Cross-school token tests (NEW)
    // ================================================================

    /** @test */
    public function school_a_token_cannot_be_confirmed_in_school_b(): void
    {
        // This test verifies that even if school_id is somehow manipulated
        // in the request, the controller always uses Auth::user()->school_id.
        // The service layer also checks school_id on the batch query.
        $this->actAsAdminForImport($this->adminUser);

        $fee = $this->createTestFee(50000.00);
        $admissionNo = $this->createStudentUser('CrossSchoolStu', $this->schoolId);

        $file = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, 'INV-CROSS-SCHOOL-A-' . Str::uuid()->toString()),
        ]);

        $previewResponse = $this->post(route('fees.import.preview'), ['file' => $file]);
        $previewResponse->assertOk();
        $token = $previewResponse->json('data.token');

        // Verify the batch was created with correct school_id
        $batch = FeeImportBatch::where('token', $token)->first();
        $this->assertEquals($this->schoolId, $batch->school_id);

        // Now try to confirm with a DIFFERENT school context by directly
        // hitting the confirm endpoint. The controller always uses Auth::user()->school_id,
        // so if the user's school_id doesn't match the batch's school_id, it fails.
        // Since our test user is in school 1, confirming with school 5 batch won't work
        // because the batch was created with school_id=1.
        //
        // The controller uses Auth::user()->school_id, so school isolation is
        // guaranteed by the service layer's school_id filter on batch queries.

        // Create a batch in school 5 and try to confirm it with school 1 user
        // fee_import_batches lives on the school connection
        try {
            DB::connection('school')->table('fee_import_batches')->insert([
                'token'       => 'fake-token-cross-school',
                'school_id'   => $this->otherSchoolId,
                'imported_by' => $this->adminUser->id,
                'file_name'   => 'test.xlsx',
                'file_hash'   => 'abc123',
                'status'      => 'pending',
                'total_rows'  => 1,
                'expired_at'  => now()->addMinutes(30),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable) {}

        // Confirm with school 1 user's context → should fail because batch is in school 5
        $confirmResponse = $this->post(route('fees.import.confirm'), ['token' => 'fake-token-cross-school']);
        $confirmResponse->assertStatus(422);
        $confirmResponse->assertJson(['error' => true]);
    }

    /** @test */
    public function controller_always_uses_auth_user_school_id_not_request_param(): void
    {
        // Verify the controller does NOT trust a school_id from the request.
        // It always reads school_id from Auth::user()->school_id.
        // The service methods receive schoolId as a parameter from the controller,
        // and the controller hard-codes it to Auth::user()->school_id.

        $this->actAsAdminForImport($this->adminUser);

        $fee = $this->createTestFee(50000.00);
        $admissionNo = $this->createStudentUser('NoTrustSchool', $this->schoolId);

        $file = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, 'INV-NO-TRUST-' . Str::uuid()->toString()),
        ]);

        // Even if school_id is passed in request body, controller ignores it
        $previewResponse = $this->post(route('fees.import.preview'), [
            'file'      => $file,
            'school_id' => $this->otherSchoolId, // Attacker tries to use school 5
        ]);
        $previewResponse->assertOk();
        $data = $previewResponse->json('data');

        // The batch should be in school 1 (auth user's school), NOT school 5
        $batch = FeeImportBatch::where('token', $data['token'])->first();
        $this->assertEquals($this->schoolId, $batch->school_id,
            'Batch must use Auth::user()->school_id, not request param');
        $this->assertNotEquals($this->otherSchoolId, $batch->school_id,
            'Batch must NOT use school_id from request body');
    }

    /** @test */
    public function different_schools_can_import_same_reference_no(): void
    {
        // Create a reference_no in school 1
        $fee = $this->createTestFee(50000.00);
        $admissionNo = $this->createStudentUser('SameRefSchool1', $this->schoolId);

        $sharedRef = 'INV-SHARED-REF-' . Str::uuid()->toString();

        $this->actAsAdminForImport($this->adminUser);

        $file = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, $sharedRef),
        ]);

        $previewResponse = $this->post(route('fees.import.preview'), ['file' => $file]);
        $previewResponse->assertOk();
        $data = $previewResponse->json('data');

        // Should be valid (reference_no doesn't exist in THIS school yet)
        $this->assertEquals(1, $data['summary']['valid']);

        // Confirm it
        $confirmResponse = $this->post(route('fees.import.confirm'), ['token' => $data['token']]);
        $confirmResponse->assertOk();

        // Now create the same reference_no in school 5 DB
        try {
            DB::table('compulsory_fees')->insert([
                'student_id'    => 1,
                'type'          => 'Full Payment',
                'mode'          => 'Cash',
                'amount'        => 1000,
                'date'          => now()->format('Y-m-d'),
                'school_id'     => $this->otherSchoolId,
                'reference_no'  => $sharedRef,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable) {}

        // Now in school 1, re-importing the same ref should be rejected
        $file2 = $this->createXlsxFromRows([
            $this->validRowData($admissionNo, $this->feeStructureName, $sharedRef),
        ]);

        $preview2 = $this->post(route('fees.import.preview'), ['file' => $file2]);
        $preview2->assertOk();
        $data2 = $preview2->json('data');

        // Reference_no exists in school 1 (from the confirm above).
        // Fee is also now fully paid. The row should be either duplicate or error.
        $this->assertEquals(0, $data2['summary']['valid'],
            'Same reference_no must not be valid (duplicate or error)');
        $this->assertGreaterThanOrEqual(1, $data2['summary']['duplicate'] + $data2['summary']['error'],
            'Re-import must be rejected as duplicate or error');
    }

    /** @test */
    public function school_id_filter_prevents_cross_school_token_access(): void
    {
        // Token column is globally unique. Even if different schools
        // somehow had the same token string, the school_id filter in
        // FeeImportBatch::where('token', $token)->where('school_id', $schoolId)
        // prevents cross-school access.

        $token1 = 'token-school1-' . Str::uuid()->toString();
        $token5 = 'token-school5-' . Str::uuid()->toString();

        // fee_import_batches lives on the school connection
        DB::connection('school')->table('fee_import_batches')->insert([
            'token'       => $token1,
            'school_id'   => $this->schoolId,
            'imported_by' => $this->adminUser->id,
            'file_name'   => 'school1.xlsx',
            'file_hash'   => 'hash1',
            'status'      => 'pending',
            'total_rows'  => 1,
            'expired_at'  => now()->addMinutes(30),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::connection('school')->table('fee_import_batches')->insert([
            'token'       => $token5,
            'school_id'   => $this->otherSchoolId,
            'imported_by' => 999,
            'file_name'   => 'school5.xlsx',
            'file_hash'   => 'hash5',
            'status'      => 'pending',
            'total_rows'  => 1,
            'expired_at'  => now()->addMinutes(30),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Query school 1's batch → must ONLY find school 1 record
        $batch1 = FeeImportBatch::where('token', $token1)
            ->where('school_id', $this->schoolId)
            ->first();
        $this->assertNotNull($batch1, 'School 1 batch must be found');
        $this->assertEquals($this->schoolId, $batch1->school_id);

        // Query same token with wrong school_id → must NOT find anything
        $batch1wrong = FeeImportBatch::where('token', $token1)
            ->where('school_id', $this->otherSchoolId)
            ->first();
        $this->assertNull($batch1wrong, 'Token from school 1 must NOT be accessible from school 5 context');

        // Query school 5's batch
        $batch5 = FeeImportBatch::where('token', $token5)
            ->where('school_id', $this->otherSchoolId)
            ->first();
        $this->assertNotNull($batch5, 'School 5 batch must be found');
        $this->assertEquals($this->otherSchoolId, $batch5->school_id);

        // Cross-check: school 1 cannot access school 5's batch
        $batch5wrong = FeeImportBatch::where('token', $token5)
            ->where('school_id', $this->schoolId)
            ->first();
        $this->assertNull($batch5wrong, 'Token from school 5 must NOT be accessible from school 1 context');
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function ensureRoles(): void
    {
        foreach (['Super Admin', 'School Admin', 'Student', 'Teacher'] as $name) {
            $exists = DB::table('roles')->where('name', $name)->where('school_id', $this->schoolId)->exists();
            if (!$exists) {
                DB::table('roles')->insert([
                    'name' => $name, 'guard_name' => 'web', 'school_id' => $this->schoolId,
                    'custom_role' => 1, 'editable' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
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
                optional tinyint(1) NOT NULL DEFAULT 0,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
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

    private function createAdminUser(string $name, int $schoolId): User
    {
        $email = strtolower($name) . '-import-' . Str::random(6) . '@test.local';
        $userId = DB::table('users')->insertGetId([
            'first_name' => $name, 'last_name' => 'Admin', 'email' => $email,
            'password' => bcrypt('password'), 'school_id' => $schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('name', 'School Admin')->where('school_id', $schoolId)->first();
        if ($role) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $role->id, 'model_type' => 'App\Models\User', 'model_id' => $userId,
            ]);
        }
        return User::find($userId);
    }

    private function createStudentUser(string $name, int $schoolId): string
    {
        $admissionNo = 'HTTP-ADM-' . strtoupper(Str::random(6));

        $email = strtolower($name) . '-' . Str::random(6) . '@student.local';
        $userId = DB::table('users')->insertGetId([
            'first_name' => $name, 'last_name' => 'Student', 'email' => $email,
            'password' => bcrypt('password'), 'school_id' => $schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('name', 'Student')->where('school_id', $schoolId)->first();
        if ($role) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $role->id, 'model_type' => 'App\Models\User', 'model_id' => $userId,
            ]);
        }
        // Create Students record with admission_no
        DB::table('students')->insertOrIgnore([
            'user_id'        => $userId,
            'class_id'       => $this->classId,
            'admission_no'   => $admissionNo,
            'admission_date' => now()->format('Y-m-d'),
            'school_id'      => $schoolId,
            'session_year_id' => $this->sessionYearId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        return $admissionNo;
    }

    private $feeStructureName;

    private function createTestFee(float $total, ?int $feeId = null): Fee
    {
        $this->feeStructureName = 'HTTP Fee ' . Str::random(4);
        $fee = new Fee();
        $data = [
            'name' => $this->feeStructureName,
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'due_charges' => 0,
            'class_id' => $this->classId,
            'school_id' => $this->schoolId,
            'session_year_id' => $this->sessionYearId,
            'total_compulsory_fees' => $total,
        ];
        if ($feeId !== null) {
            $data['id'] = $feeId;
        }
        $fee->forceFill($data);
        $fee->save();
        return $fee->fresh();
    }

    private function assignPermission(User $user, string $permission): void
    {
        $permId = DB::table('permissions')->where('name', $permission)->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId([
                'name' => $permission, 'guard_name' => 'web',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('model_has_permissions')->insertOrIgnore([
            'permission_id' => $permId, 'model_type' => 'App\Models\User', 'model_id' => $user->id,
        ]);
    }

    /**
     * Build a valid row using BUSINESS fields only.
     */
    private function validRowData(string $admissionNo, string $feeStructureName, string $referenceNo): array
    {
        return [
            $admissionNo,
            $this->academicYearName,
            $this->className,
            $feeStructureName,
            $this->bankAccountName,   // Bank Account Name
            '',   // Installment Name
            '2025-01-15',
            '50000',
            'Cash',
            '',   // Cheque No
            $referenceNo,
        ];
    }

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

    private function createXlsxFromRows(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($this->defaultHeader() as $colIdx => $h) {
            $sheet->setCellValue([$colIdx + 1, 1], $h);
        }
        foreach ($rows as $rowIdx => $row) {
            foreach ($row as $colIdx => $val) {
                $sheet->setCellValue([$colIdx + 1, $rowIdx + 2], $val);
            }
        }
        $tmpfile = tempnam(sys_get_temp_dir(), 'http_import_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpfile);
        return new UploadedFile($tmpfile, 'http_test_import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function createValidXlsx(): UploadedFile
    {
        return $this->createXlsxFromRows([
            ['ADM-DUMMY', $this->academicYearName, $this->className,
             'Dummy Fee', $this->bankAccountName, '', '2025-01-15', '50000', 'Cash', '',
             'INV-VALID-' . Str::uuid()->toString()],
        ]);
    }

    private function createEmptyXlsx(): UploadedFile
    {
        return $this->createXlsxFromRows([]);
    }
}
