<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\BankAccount;
use App\Models\BankAccountBalanceAdjustment;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesPaid;
use App\Services\FeesPaidImportService;
use App\Services\FeesPaymentService;
use App\Services\SchoolDataService;
use App\Support\LocalBowenQaGuard;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LocalBowenQa extends Command
{
    protected $signature = 'local:bowen-qa {action : reset, verify, assert-payment-deleted, or assert-opening-balance}';

    protected $description = 'Create or verify the fixed local-only synthetic BOWEN_QA tenant.';

    public function handle(): int
    {
        try {
            $this->guard();

            if ($this->argument('action') === 'reset') {
                $school = $this->ensureCentralFixture();
                $this->rebuildTenantSchema($school);
                $this->seedTenantFixture($school);
                $this->seedFeatureFixture($school);
                $this->seedCentralBranding();
            } elseif ($this->argument('action') === 'assert-payment-deleted') {
                return $this->assertPaymentDeleted();
            } elseif ($this->argument('action') === 'assert-opening-balance') {
                return $this->assertOpeningBalanceAudit();
            } elseif ($this->argument('action') !== 'verify') {
                throw new \LogicException('Action must be reset, verify, assert-payment-deleted, or assert-opening-balance.');
            }

            return $this->verify();
        } catch (\Throwable $exception) {
            $this->error('BOWEN_QA action refused: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }

    private function guard(): void
    {
        LocalBowenQaGuard::assertEnvironment(
            (string) app()->environment(),
            (string) config('app.url'),
            (string) config('database.connections.mysql.database'),
        );
    }

    private function ensureCentralFixture(): School
    {
        $now = now();
        $central = DB::connection('mysql');

        $central->statement('CREATE DATABASE IF NOT EXISTS `' . LocalBowenQaGuard::TENANT_DATABASE . '`');

        $school = School::on('mysql')->withTrashed()->firstOrNew(['code' => LocalBowenQaGuard::SCHOOL_CODE]);
        $school->fill([
            'name' => 'Bowen School — Local QA',
            'address' => 'Synthetic local test environment',
            'support_phone' => '0000000000',
            'support_email' => 'qa-admin@bowen-qa.test',
            'tagline' => 'Synthetic Bowen School configuration for local QA',
            'logo' => '',
            'status' => 1,
            'installed' => 1,
            'domain' => 'bowen-qa.local',
            'domain_type' => 'subdomain',
            'type' => 'custom',
            'database_name' => LocalBowenQaGuard::TENANT_DATABASE,
        ]);
        if ($school->trashed()) {
            $school->restore();
        }
        $school->save();

        $userId = $this->upsertCentralUser($school->id, $now);
        $central->table('schools')->where('id', $school->id)->update(['admin_id' => $userId, 'updated_at' => $now]);

        return School::on('mysql')->findOrFail($school->id);
    }

    private function rebuildTenantSchema(School $school): void
    {
        LocalBowenQaGuard::assertTenant((string) $school->code, (string) $school->database_name);
        Config::set('database.connections.school.database', LocalBowenQaGuard::TENANT_DATABASE);
        DB::purge('school');

        Artisan::call('migrate:fresh', [
            '--database' => 'school',
            '--path' => 'database/migrations/schools',
            '--force' => true,
        ]);
        if (Artisan::output() && str_contains(Artisan::output(), 'FAIL')) {
            throw new \RuntimeException('Local tenant migration failed: ' . trim(Artisan::output()));
        }
        DB::purge('school');
        DB::connection('school')->getPdo();
    }

    private function seedTenantFixture(School $centralSchool): void
    {
        LocalBowenQaGuard::assertTenant((string) $centralSchool->code, (string) $centralSchool->database_name);
        $now = now();
        $school = DB::connection('school');
        $adminId = (int) DB::connection('mysql')->table('users')->where('email', 'qa_admin@bowen-qa.test')->value('id');

        $school->table('schools')->updateOrInsert(['id' => $centralSchool->id], [
            'name' => $centralSchool->name,
            'address' => $centralSchool->address,
            'support_phone' => $centralSchool->support_phone,
            'support_email' => $centralSchool->support_email,
            'tagline' => $centralSchool->tagline,
            'logo' => '',
            // The tenant schema has a foreign key to users, so the admin is
            // linked after the deterministic tenant users exist.
            'admin_id' => null,
            'status' => 1,
            'installed' => 1,
            'domain' => $centralSchool->domain,
            'database_name' => LocalBowenQaGuard::TENANT_DATABASE,
            'code' => LocalBowenQaGuard::SCHOOL_CODE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            ['qa_admin@bowen-qa.test', 'QA', 'Admin'],
            ['qa_teacher@bowen-qa.test', 'QA', 'Teacher'],
            ['qa_guardian@bowen-qa.test', 'QA', 'Guardian'],
            ['qa_student@bowen-qa.test', 'QA', 'Student'],
            ['qa_head_finance@bowen-qa.test', 'QA', 'Head Finance'],
            ['qa_cashier_a@bowen-qa.test', 'QA', 'Cashier A'],
            ['qa_cashier_b@bowen-qa.test', 'QA', 'Cashier B'],
        ] as [$email, $first, $last]) {
            $centralUser = DB::connection('mysql')->table('users')->where('email', $email)->first();
            $school->table('users')->updateOrInsert(['id' => $centralUser->id], [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => $centralUser->password,
                'school_id' => $centralSchool->id,
                'two_factor_enabled' => 0,
                'status' => 1,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $school->table('schools')->where('id', $centralSchool->id)->update([
            'admin_id' => $adminId,
            'updated_at' => $now,
        ]);

        $this->seedSchoolSettings($centralSchool->id, $now);
        $this->seedAcademicAndFinanceFixture($centralSchool->id, $now);

        DB::setDefaultConnection('school');
        /** @var SchoolDataService $schoolService */
        $schoolService = app(SchoolDataService::class);
        $schoolService->createPermissions();
        $tenantSchool = School::on('school')->findOrFail($centralSchool->id);
        $schoolService->createSchoolAdminRole($tenantSchool);
        $schoolService->createTeacherRole($tenantSchool);
        $schoolService->defaultRoles($tenantSchool);
        $this->assignFixtureRoles($centralSchool->id);
        $this->seedFinanceRoleAssignments($centralSchool->id, $now);
        DB::setDefaultConnection('mysql');
    }

    private function upsertCentralUser(int $schoolId, $now): int
    {
        $users = [
            ['qa_admin@bowen-qa.test', 'QA', 'Admin'],
            ['qa_teacher@bowen-qa.test', 'QA', 'Teacher'],
            ['qa_guardian@bowen-qa.test', 'QA', 'Guardian'],
            ['qa_student@bowen-qa.test', 'QA', 'Student'],
            ['qa_head_finance@bowen-qa.test', 'QA', 'Head Finance'],
            ['qa_cashier_a@bowen-qa.test', 'QA', 'Cashier A'],
            ['qa_cashier_b@bowen-qa.test', 'QA', 'Cashier B'],
        ];
        foreach ($users as [$email, $first, $last]) {
            DB::connection('mysql')->table('users')->updateOrInsert(['email' => $email], [
                'first_name' => $first,
                'last_name' => $last,
                'password' => Hash::make('local-bowen-qa-only'),
                'school_id' => $schoolId,
                'two_factor_enabled' => 0,
                'status' => 1,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return (int) DB::connection('mysql')->table('users')->where('email', 'qa_admin@bowen-qa.test')->value('id');
    }

    private function seedSchoolSettings(int $schoolId, $now): void
    {
        $settings = [
            'school_name' => 'Bowen School — Local QA',
            'school_tagline' => 'Synthetic local Bowen configuration',
            'currency_code' => 'MMK',
            'currency_symbol' => 'K',
            'date_format' => 'd-m-Y',
            'time_format' => 'h:i A',
            'domain' => 'bowen-qa.local',
        ];
        foreach ($settings as $name => $data) {
            DB::connection('school')->table('school_settings')->updateOrInsert(
                ['school_id' => $schoolId, 'name' => $name],
                ['data' => $data, 'type' => 'string'],
            );
        }
    }

    private function seedAcademicAndFinanceFixture(int $schoolId, $now): void
    {
        $school = DB::connection('school');
        $school->table('session_years')->updateOrInsert(
            ['school_id' => $schoolId, 'name' => 'Bowen QA 2026'],
            ['default' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now],
        );
        $sessionId = (int) $school->table('session_years')->where('school_id', $schoolId)->where('name', 'Bowen QA 2026')->value('id');
        $school->table('school_settings')->updateOrInsert(['school_id' => $schoolId, 'name' => 'session_year'], ['data' => (string) $sessionId, 'type' => 'number']);

        $school->table('mediums')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA Medium'], ['created_at' => $now, 'updated_at' => $now]);
        $mediumId = (int) $school->table('mediums')->where('school_id', $schoolId)->where('name', 'Bowen QA Medium')->value('id');
        $school->table('sections')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA Section'], ['created_at' => $now, 'updated_at' => $now]);
        $sectionId = (int) $school->table('sections')->where('school_id', $schoolId)->where('name', 'Bowen QA Section')->value('id');
        $school->table('classes')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA Class'], ['medium_id' => $mediumId, 'include_semesters' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $classId = (int) $school->table('classes')->where('school_id', $schoolId)->where('name', 'Bowen QA Class')->value('id');
        $school->table('class_sections')->updateOrInsert(['school_id' => $schoolId, 'class_id' => $classId, 'section_id' => $sectionId], ['medium_id' => $mediumId, 'created_at' => $now, 'updated_at' => $now]);
        $classSectionId = (int) $school->table('class_sections')->where('school_id', $schoolId)->where('class_id', $classId)->where('section_id', $sectionId)->value('id');

        $studentId = (int) $school->table('users')->where('email', 'qa_student@bowen-qa.test')->value('id');
        $guardianId = (int) $school->table('users')->where('email', 'qa_guardian@bowen-qa.test')->value('id');
        $school->table('students')->updateOrInsert(['school_id' => $schoolId, 'admission_no' => 'BOWEN_QA_P1_STUDENT_001'], [
            'user_id' => $studentId, 'guardian_id' => $guardianId, 'class_id' => $classId, 'class_section_id' => $classSectionId,
            'session_year_id' => $sessionId, 'join_session_year_id' => $sessionId, 'admission_date' => '2026-01-01',
            'roll_number' => 1, 'application_type' => 0, 'application_status' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $school->table('fees_types')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA Tuition'], ['description' => 'Synthetic compulsory finance fixture', 'created_at' => $now, 'updated_at' => $now]);
        $compulsoryTypeId = (int) $school->table('fees_types')->where('school_id', $schoolId)->where('name', 'Bowen QA Tuition')->value('id');
        $school->table('fees_types')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA Optional Activity'], ['description' => 'Synthetic optional finance fixture', 'created_at' => $now, 'updated_at' => $now]);
        $optionalTypeId = (int) $school->table('fees_types')->where('school_id', $schoolId)->where('name', 'Bowen QA Optional Activity')->value('id');
        $school->table('fees')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA P1 Fee'], [
            'currency' => 'MMK', 'due_date' => '2026-02-01', 'due_charges' => 0, 'due_charges_amount' => 0,
            'class_id' => $classId, 'session_year_id' => $sessionId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $feeId = (int) $school->table('fees')->where('school_id', $schoolId)->where('name', 'Bowen QA P1 Fee')->value('id');
        $school->table('fees')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA P2 Access Fee'], [
            'currency' => 'MMK', 'due_date' => '2026-12-31', 'due_charges' => 0, 'due_charges_amount' => 0,
            'class_id' => $classId, 'session_year_id' => $sessionId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $p2FeeId = (int) $school->table('fees')->where('school_id', $schoolId)->where('name', 'Bowen QA P2 Access Fee')->value('id');
        $school->table('fees_class_types')->updateOrInsert(['school_id' => $schoolId, 'class_id' => $classId, 'fees_id' => $feeId, 'fees_type_id' => $compulsoryTypeId], ['amount' => 1000, 'optional' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $school->table('fees_class_types')->updateOrInsert(['school_id' => $schoolId, 'class_id' => $classId, 'fees_id' => $feeId, 'fees_type_id' => $optionalTypeId], ['amount' => 300, 'optional' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $school->table('fees_class_types')->updateOrInsert(['school_id' => $schoolId, 'class_id' => $classId, 'fees_id' => $p2FeeId, 'fees_type_id' => $compulsoryTypeId], ['amount' => 500, 'optional' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $school->table('fees_class_types')->updateOrInsert(['school_id' => $schoolId, 'class_id' => $classId, 'fees_id' => $p2FeeId, 'fees_type_id' => $optionalTypeId], ['amount' => 100, 'optional' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $adminId = (int) $school->table('users')->where('email', 'qa_admin@bowen-qa.test')->value('id');
        foreach ([
            ['BOWEN_QA_P1_BANK', 'Bowen QA P1 Bank', 'bank', 5000, 1],
            ['BOWEN_QA_P1_CASH', 'Bowen QA P1 Cash', 'cash', 1000, 0],
            ['QA_P2_CASH_A', 'QA P2 Cash A', 'cash', 100, 0],
            ['QA_P2_CASH_B', 'QA P2 Cash B', 'cash', 200, 0],
            ['QA_P2_BANK', 'QA P2 Bank', 'bank', 300, 0],
        ] as [$number, $name, $type, $opening, $default]) {
            $school->table('bank_accounts')->updateOrInsert(['school_id' => $schoolId, 'account_number' => $number], [
                'account_name' => $name, 'bank_name' => 'TEST ONLY', 'account_type' => $type, 'currency' => 'MMK',
                'opening_balance' => $opening, 'opening_balance_date' => '2026-01-01', 'is_active' => 1, 'is_default' => $default,
                'notes' => 'Synthetic BOWEN_QA fixture; local browser QA may mutate this record.', 'created_by' => $adminId, 'updated_by' => $adminId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $bankId = (int) $school->table('bank_accounts')->where('school_id', $schoolId)->where('account_number', 'BOWEN_QA_P1_BANK')->value('id');
        $school->table('fees_paids')->updateOrInsert(['school_id' => $schoolId, 'fees_id' => $feeId, 'student_id' => $studentId], [
            'is_fully_paid' => 1, 'is_used_installment' => 0, 'amount' => 1000,
            'transaction_currency' => 'MMK', 'original_amount' => 1000, 'exchange_rate_snapshot' => 1, 'amount_mmk' => 1000,
            'date' => '2026-02-01', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $feesPaidId = (int) $school->table('fees_paids')->where('school_id', $schoolId)->where('fees_id', $feeId)->where('student_id', $studentId)->value('id');
        $school->table('compulsory_fees')->updateOrInsert(['school_id' => $schoolId, 'reference_no' => 'BOWEN_QA_P1_PAYMENT_001'], [
            'student_id' => $studentId, 'type' => 'Full Payment', 'mode' => 'Cash', 'bank_account_id' => $bankId,
            'amount' => 1000, 'due_charges' => 0, 'fees_paid_id' => $feesPaidId, 'status' => 'Success', 'date' => '2026-02-01',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $school->table('expense_categories')->updateOrInsert(['school_id' => $schoolId, 'name' => 'Bowen QA Expense Category'], ['description' => 'Synthetic local finance fixture', 'created_at' => $now, 'updated_at' => $now]);
        $expenseCategoryId = (int) $school->table('expense_categories')->where('school_id', $schoolId)->where('name', 'Bowen QA Expense Category')->value('id');
        $school->table('expenses')->updateOrInsert(['school_id' => $schoolId, 'ref_no' => 'BOWEN_QA_P1_EXPENSE_001'], [
            'category_id' => $expenseCategoryId, 'bank_account_id' => $bankId, 'title' => 'Bowen QA synthetic expense',
            'description' => 'Local-only synthetic baseline', 'amount' => 50, 'date' => '2026-01-15', 'session_year_id' => $sessionId,
            'created_by' => $adminId, 'updated_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedCentralBranding(): void
    {
        $now = now();
        $settings = [
            'system_name' => 'Bowen School — Local QA',
            'tag_line' => 'Synthetic Bowen School local QA environment',
            'horizontal_logo' => 'bowen-qa/brand-horizontal.jpg',
            'vertical_logo' => 'bowen-qa/brand-vertical.jpg',
            'login_page_logo' => 'bowen-qa/brand-login.jpg',
            'school_code_prefix' => 'BOWENQA',
            'theme_primary_color' => '#56cc99',
            'theme_secondary_color' => '#215679',
            'theme_secondary_color_1' => '#38a3a5',
            'theme_primary_background_color' => '#f2f5f7',
            'theme_text_secondary_color' => '#5c788c',
        ];
        foreach ($settings as $name => $data) {
            DB::connection('mysql')->table('system_settings')->updateOrInsert(['name' => $name], ['data' => $data, 'type' => 'string']);
        }
    }

    private function assignFixtureRoles(int $schoolId): void
    {
        $school = DB::connection('school');
        $adminRole = $school->table('roles')->where('school_id', $schoolId)->where('name', 'School Admin')->value('id');
        $teacherRole = $school->table('roles')->where('school_id', $schoolId)->where('name', 'Teacher')->value('id');
        $guardianRole = $school->table('roles')->where('school_id', $schoolId)->where('name', 'Guardian')->value('id');
        $studentRole = $school->table('roles')->where('school_id', $schoolId)->where('name', 'Student')->value('id');
        foreach ([
            ['qa_admin@bowen-qa.test', $adminRole], ['qa_teacher@bowen-qa.test', $teacherRole],
            ['qa_guardian@bowen-qa.test', $guardianRole], ['qa_student@bowen-qa.test', $studentRole],
        ] as [$email, $roleId]) {
            $userId = $school->table('users')->where('email', $email)->value('id');
            $school->table('model_has_roles')->updateOrInsert(['role_id' => $roleId, 'model_id' => $userId, 'model_type' => 'App\\Models\\User'], []);
        }
    }

    private function seedFinanceRoleAssignments(int $schoolId, $now): void
    {
        $school = DB::connection('school');
        foreach (['Head Finance', 'Cashier'] as $roleName) {
            $school->table('roles')->updateOrInsert(['school_id' => $schoolId, 'name' => $roleName, 'guard_name' => 'web'], ['updated_at' => $now, 'created_at' => $now]);
        }
        $headRole = $school->table('roles')->where('school_id', $schoolId)->where('name', 'Head Finance')->value('id');
        $cashierRole = $school->table('roles')->where('school_id', $schoolId)->where('name', 'Cashier')->value('id');
        // Do not clone School Admin. Finance roles receive only the permissions
        // they need for the foundation; account scope then further restricts
        // Cashier visibility to explicit assignments.
        $permissionIds = $school->table('permissions')
            ->whereIn('name', ['expense-list', 'expense-create', 'fees-paid'])
            ->pluck('id', 'name');
        $school->table('role_has_permissions')->whereIn('role_id', [$headRole, $cashierRole])->delete();
        foreach ($permissionIds as $permissionId) {
            $school->table('role_has_permissions')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $headRole], []);
        }
        foreach (['expense-list', 'expense-create', 'fees-paid'] as $permissionName) {
            $school->table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permissionIds[$permissionName],
                'role_id' => $cashierRole,
            ], []);
        }
        foreach ([['qa_head_finance@bowen-qa.test', $headRole], ['qa_cashier_a@bowen-qa.test', $cashierRole], ['qa_cashier_b@bowen-qa.test', $cashierRole]] as [$email, $role]) {
            $id = $school->table('users')->where('email', $email)->value('id');
            $school->table('model_has_roles')->updateOrInsert(['role_id' => $role, 'model_id' => $id, 'model_type' => 'App\\Models\\User'], []);
        }
        foreach ([['qa_cashier_a@bowen-qa.test', 'QA_P2_CASH_A'], ['qa_cashier_b@bowen-qa.test', 'QA_P2_CASH_B']] as [$email, $account]) {
            $school->table('bank_account_user')->updateOrInsert([
                'user_id' => $school->table('users')->where('email', $email)->value('id'),
                'bank_account_id' => $school->table('bank_accounts')->where('school_id', $schoolId)->where('account_number', $account)->value('id'),
            ], ['updated_at' => $now, 'created_at' => $now]);
        }
    }

    private function seedFeatureFixture(School $school): void
    {
        $now = now();
        $features = [
            'Student Management', 'Academics Management', 'Slider Management', 'Teacher Management',
            'Session Year Management', 'Holiday Management', 'Timetable Management', 'Attendance Management',
            'Exam Management', 'Lesson Management', 'Assignment Management', 'Announcement Management',
            'Staff Management', 'Expense Management', 'Staff Leave Management', 'Fees Management',
            'School Gallery Management', 'ID Card - Certificate Generation', 'Website Management', 'Chat Module',
            'Transportation Module', 'Staff Attendance Management',
        ];
        foreach ($features as $name) {
            DB::connection('mysql')->table('features')->updateOrInsert(['name' => $name], ['status' => 1, 'is_default' => 0, 'updated_at' => $now, 'created_at' => $now]);
        }
        DB::connection('mysql')->table('packages')->updateOrInsert(
            ['name' => 'Bowen QA Local Feature Package'],
            ['description' => 'Synthetic local-only package for browser QA', 'tagline' => 'BOWEN_QA', 'student_charge' => 0, 'staff_charge' => 0, 'charges' => 0, 'no_of_students' => 0, 'no_of_staffs' => 0, 'days' => 365, 'type' => 1, 'status' => 1, 'is_trial' => 0, 'highlight' => 0, 'rank' => 999, 'updated_at' => $now, 'created_at' => $now],
        );
        $packageId = (int) DB::connection('mysql')->table('packages')->where('name', 'Bowen QA Local Feature Package')->value('id');
        DB::connection('mysql')->table('subscriptions')->updateOrInsert(
            ['school_id' => $school->id, 'name' => 'Bowen QA Full Feature Fixture'],
            ['package_id' => $packageId, 'package_type' => 1, 'student_charge' => 0, 'staff_charge' => 0, 'charges' => 0, 'no_of_students' => 0, 'no_of_staffs' => 0, 'billing_cycle' => 365, 'start_date' => '2026-01-01', 'end_date' => '2030-12-31', 'updated_at' => $now, 'created_at' => $now],
        );
        $subscriptionId = (int) DB::connection('mysql')->table('subscriptions')->where('school_id', $school->id)->where('name', 'Bowen QA Full Feature Fixture')->value('id');
        foreach (DB::connection('mysql')->table('features')->whereIn('name', $features)->pluck('id') as $featureId) {
            DB::connection('mysql')->table('subscription_features')->updateOrInsert(['subscription_id' => $subscriptionId, 'feature_id' => $featureId], ['updated_at' => $now, 'created_at' => $now]);
        }
    }

    private function verify(): int
    {
        $school = School::on('mysql')->where('code', LocalBowenQaGuard::SCHOOL_CODE)->first();
        if (!$school) {
            throw new \LogicException('BOWEN_QA central school record is missing.');
        }
        LocalBowenQaGuard::assertTenant((string) $school->code, (string) $school->database_name);
        Config::set('database.connections.school.database', $school->database_name);
        DB::purge('school');
        $tenant = DB::connection('school');
        $checks = [
            'admin' => $tenant->table('users')->where('email', 'qa_admin@bowen-qa.test')->exists(),
            'session' => $tenant->table('session_years')->where('school_id', $school->id)->where('name', 'Bowen QA 2026')->exists(),
            'roles' => $tenant->table('roles')->where('school_id', $school->id)->where('name', 'School Admin')->exists(),
            'features' => DB::connection('mysql')->table('subscriptions')->where('school_id', $school->id)->exists(),
        ];
        if (in_array(false, $checks, true)) {
            throw new \LogicException('BOWEN_QA verification failed: ' . json_encode($checks));
        }
        $this->info('BOWEN_QA verified: local tenant, synthetic admin, academic session, role bootstrap, and Bowen feature set.');
        return self::SUCCESS;
    }

    private function connectFixtureTenant(): array
    {
        $school = School::on('mysql')->where('code', LocalBowenQaGuard::SCHOOL_CODE)->firstOrFail();
        LocalBowenQaGuard::assertTenant((string) $school->code, (string) $school->database_name);
        Config::set('database.connections.school.database', $school->database_name);
        DB::purge('school');
        Config::set('database.default', 'school');
        DB::setDefaultConnection('school');
        session(['db_connection_name' => 'school']);

        return [$school, DB::connection('school')];
    }

    private function assertPaymentDeleted(): int
    {
        [$school, $tenant] = $this->connectFixtureTenant();
        try {
            $adminId = (int) $tenant->table('users')->where('email', 'qa_admin@bowen-qa.test')->value('id');
            $studentId = (int) $tenant->table('users')->where('email', 'qa_student@bowen-qa.test')->value('id');
            $payment = $tenant->table('compulsory_fees')
                ->where('school_id', $school->id)
                ->where('reference_no', 'BOWEN_QA_P1_PAYMENT_001')
                ->first();
            $this->assertLocal($payment !== null, 'Synthetic payment history is missing from BOWEN_QA.');

            $this->assertLocal($payment->deleted_at !== null, 'Payment is not soft deleted.');
            $this->assertLocal((int) $payment->deleted_by === $adminId, 'Payment deleted_by is not the BOWEN_QA admin.');
            $this->assertLocal($payment->delete_reason === 'BOWEN_QA P1 payment deletion acceptance', 'Payment delete reason is incorrect.');
            $this->assertLocal(!$tenant->table('compulsory_fees')->where('id', $payment->id)->whereNull('deleted_at')->exists(), 'Soft-deleted payment is visible to normal queries.');
            $this->assertLocal($tenant->table('compulsory_fees')->where('id', $payment->id)->exists(), 'Soft-deleted payment is missing from historical queries.');
            $this->assertLocal((float) $tenant->table('fees_paids')->where('id', $payment->fees_paid_id)->value('amount') === 0.0, 'FeesPaid aggregation was not reset after deletion.');

            $expected = (float) $tenant->table('fees_class_types')
                ->where('school_id', $school->id)->where('optional', 0)->sum('amount');
            $paid = (float) $tenant->table('compulsory_fees')->where('school_id', $school->id)
                ->where('student_id', $studentId)->where('status', 'Success')->whereNull('deleted_at')->sum('amount');
            $this->assertLocal($expected === 1000.0 && $paid === 0.0 && ($expected - $paid) === 1000.0, 'FeesPaid/outstanding calculation is incorrect after deletion.');

            $account = $tenant->table('bank_accounts')->where('school_id', $school->id)->where('account_number', 'BOWEN_QA_P1_BANK')->first();
            $this->assertLocal($account !== null, 'Synthetic Fund Account is missing from BOWEN_QA.');
            $income = (float) $tenant->table('compulsory_fees')->where('bank_account_id', $account->id)->whereNull('deleted_at')->sum('amount');
            $expenses = (float) $tenant->table('expenses')->where('bank_account_id', $account->id)->whereNull('deleted_at')->sum('amount');
            $balance = (float) $account->opening_balance + $income - $expenses;
            $this->assertLocal($balance === 5050.0, 'Fund Account balance is incorrect after the combined acceptance scenarios.');

            $ledgerRows = $tenant->table('compulsory_fees')->where('school_id', $school->id)
                ->where('student_id', $studentId)->where('status', 'Success')->whereNull('deleted_at')->count();
            $this->assertLocal($ledgerRows === 0, 'The normal student-ledger source still includes the deleted payment.');

            Auth::loginUsingId($adminId);
            $fee = Fee::on('school')->where('school_id', $school->id)->where('name', 'Bowen QA P1 Fee')->firstOrFail();
            $manualRejected = false;
            try {
                app(FeesPaymentService::class)->processPayment([
                    'fees_id' => $fee->id, 'student_id' => $studentId, 'installment_mode' => false,
                    'mode' => 'Cash', 'bank_account_id' => $account->id, 'date' => '2026-02-02',
                    'enter_amount' => 1000, 'total_amount' => 1000, 'due_charges_amount' => 0,
                    'advance' => 0, 'transaction_currency' => 'MMK', 'reference_no' => 'BOWEN_QA_P1_PAYMENT_001',
                ], $fee);
            } catch (\InvalidArgumentException $exception) {
                $manualRejected = str_contains($exception->getMessage(), 'already exists');
            }
            $this->assertLocal($manualRejected, 'Manual payment service did not reserve the soft-deleted reference number.');
            $this->assertExcelReferenceRejected($school->id, $adminId);

            $this->info('BOWEN_QA payment acceptance verified: soft delete/audit, FeesPaid/outstanding, Fund Account balance, ledger exclusion, and manual/Excel reference reservation.');
            return self::SUCCESS;
        } finally {
            Auth::logout();
            session()->forget('db_connection_name');
            Config::set('database.default', 'mysql');
            DB::setDefaultConnection('mysql');
        }
    }

    private function assertOpeningBalanceAudit(): int
    {
        [$school, $tenant] = $this->connectFixtureTenant();
        try {
            $adminId = (int) $tenant->table('users')->where('email', 'qa_admin@bowen-qa.test')->value('id');
            $account = $tenant->table('bank_accounts')->where('school_id', $school->id)->where('account_number', 'BOWEN_QA_P1_BANK')->first();
            $this->assertLocal($account !== null, 'Synthetic Fund Account is missing from BOWEN_QA.');
            $adjustments = $tenant->table('bank_account_balance_adjustments')->where('bank_account_id', $account->id)->orderBy('id')->get();
            $this->assertLocal($adjustments->count() === 1, 'Opening-balance audit count is not exactly one after unrelated account edit.');
            $adjustment = $adjustments->first();
            $this->assertLocal(
                (float) $adjustment->old_opening_balance === 5000.0 &&
                (float) $adjustment->new_opening_balance === 5100.0 &&
                substr((string) $adjustment->old_opening_balance_date, 0, 10) === '2026-01-01' &&
                substr((string) $adjustment->new_opening_balance_date, 0, 10) === '2026-01-01' &&
                (int) $adjustment->changed_by === $adminId &&
                $adjustment->reason === 'BOWEN_QA P1 opening balance acceptance' &&
                $adjustment->created_at !== null,
                'Opening-balance adjustment audit values are incorrect.',
            );
            $this->assertLocal($account->account_name === 'Bowen QA P1 Bank — unrelated edit', 'Unrelated Bank Account edit did not complete.');
            $income = (float) $tenant->table('compulsory_fees')->where('bank_account_id', $account->id)->whereNull('deleted_at')->sum('amount');
            $expenses = (float) $tenant->table('expenses')->where('bank_account_id', $account->id)->whereNull('deleted_at')->sum('amount');
            $this->assertLocal(((float) $account->opening_balance + $income - $expenses) === 5050.0, 'Fund Account balance is incorrect after opening-balance update.');
            $this->info('BOWEN_QA opening-balance acceptance verified: required audit record, actor/reason, resulting balance, and no audit row for unrelated edit.');
            return self::SUCCESS;
        } finally {
            session()->forget('db_connection_name');
            Config::set('database.default', 'mysql');
            DB::setDefaultConnection('mysql');
        }
    }

    private function assertExcelReferenceRejected(int $schoolId, int $adminId): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bowen_qa_reference_');
        if ($path === false) {
            throw new \RuntimeException('Unable to create local-only Excel-import assertion file.');
        }
        try {
            $handle = fopen($path, 'wb');
            fputcsv($handle, ['admission_no', 'academic_year', 'class_name', 'fee_structure_name', 'bank_account_name', 'installment_name', 'date', 'amount', 'payment_mode', 'cheque_no', 'reference_no']);
            fputcsv($handle, ['BOWEN_QA_P1_STUDENT_001', 'Bowen QA 2026', 'Bowen QA Class', 'Bowen QA P1 Fee', 'Bowen QA P1 Bank', '', '2026-02-02', '1000', 'Cash', '', 'BOWEN_QA_P1_PAYMENT_001']);
            fclose($handle);
            $result = app(FeesPaidImportService::class)->preview(new UploadedFile($path, 'bowen_qa_reference.csv', 'text/csv', null, true), $schoolId, $adminId);
            $this->assertLocal(($result['summary']['duplicate'] ?? 0) === 1, 'Excel paid-fee import did not reserve the soft-deleted reference number.');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function assertLocal(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \LogicException($message);
        }
    }
}
