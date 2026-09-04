<?php

namespace Tests\Feature;

use App\Exports\StudentImportV2TemplateExport;
use App\Services\StudentImportV2Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

final class StudentImportV2ContractTest extends TestCase
{
    private array $schoolConnection;
    private string $schoolDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolConnection = config('database.connections.school');
        $this->schoolDatabase = tempnam(sys_get_temp_dir(), 'student-import-v2-schema-');
        Config::set('database.connections.school', ['driver' => 'sqlite', 'database' => $this->schoolDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('school');
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        Config::set('database.connections.school', $this->schoolConnection);
        @unlink($this->schoolDatabase);
        parent::tearDown();
    }
    public function test_v21_template_keeps_only_school_admin_fields_and_preserves_text_safe_identity_values(): void
    {
        $export = new StudentImportV2TemplateExport(
            [['id' => 31, 'name' => 'Grade 1 - A']],
            [['id' => 2026, 'name' => '2026']],
            [['name' => 'Nationality', 'type' => 'dropdown', 'required' => true, 'values' => ['Myanmar', 'China']]],
        );
        $this->assertSame([
            'Student Code *', '学生姓名 *', '班级 *', '学年 *', '性别', '出生日期', '入学日期', '学生电话',
            '家长/监护人姓名 *', '家长/监护人电话 *', '家长 Email', '备注', 'Nationality',
        ], $export->headings());

        $path = tempnam(sys_get_temp_dir(), 'student-import-v2-');
        file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));
        $book = IOFactory::load($path);
        try {
            $this->assertSame('Import', $book->getSheet(0)->getTitle());
            $this->assertSame(['Import', 'Class Sections', 'Academic Years', 'Custom Fields', 'Validation Lists'], $book->getSheetNames());
            $sheet = $book->getSheet(0);
            $this->assertSame('Student Code *', (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('@', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
            $this->assertSame('@', $sheet->getStyle('H2')->getNumberFormat()->getFormatCode());
            $this->assertSame('@', $sheet->getStyle('J2')->getNumberFormat()->getFormatCode());
            $this->assertSame('=StudentImportClassSections', $sheet->getCell('C2')->getDataValidation()->getFormula1());
            $this->assertSame('=StudentImportAcademicYears', $sheet->getCell('D2')->getDataValidation()->getFormula1());
            $this->assertSame('=StudentImportGenders', $sheet->getCell('E2')->getDataValidation()->getFormula1());
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN, $book->getSheetByName('Validation Lists')->getSheetState());
            $sheet->setCellValueExplicit('A2', '00125', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H2', '0912345678', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Import');
            $this->assertSame('00125', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('0912345678', (string) $sheet->getCell('H2')->getValue());
            $this->assertSame('=StudentImportClassSections', $sheet->getCell('C2')->getDataValidation()->getFormula1());
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_v2_routes_exist_beside_the_legacy_bulk_import_routes(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->pluck('uri')->all();
        $this->assertContains('students/create-bulk', $routes);
        $this->assertContains('students/store-bulk', $routes);
        $this->assertContains('students/import-v2', $routes);
        $this->assertContains('students/import-v2/template', $routes);
        $this->assertContains('students/import-v2/preview', $routes);
        $this->assertContains('students/import-v2/confirm', $routes);
    }

    public function test_preview_contract_is_no_write_and_confirm_reuses_the_canonical_student_fee_assignment_path(): void
    {
        $source = file_get_contents(app_path('Services/StudentImportV2Service.php'));
        $preview = substr($source, strpos($source, 'public function preview'), strpos($source, 'public function confirm') - strpos($source, 'public function preview'));
        $confirm = substr($source, strpos($source, 'public function confirm'), strpos($source, 'private function context') - strpos($source, 'public function confirm'));

        $this->assertStringContainsString('Cache::put', $preview);
        $this->assertStringNotContainsString('createStudentUser(', $preview);
        $this->assertStringNotContainsString('StudentImportIdentity::create', $preview);
        $this->assertStringContainsString('createStudentUser(', $confirm);
        $this->assertStringContainsString('saveDraft($student, $actor, [])', $confirm);
        $this->assertStringContainsString('confirm($student, $actor, $draft->uuid)', $confirm);
        $this->assertStringNotContainsString('CentralFinancePaymentService', $source);
    }

    public function test_parser_keeps_text_codes_and_rejects_numeric_identity_cells_that_have_lost_leading_zeroes(): void
    {
        $service = app(StudentImportV2Service::class);
        $text = new \ReflectionMethod($service, 'text');
        $text->setAccessible(true);
        $errors = [];
        $this->assertSame('00125', $text->invokeArgs($service, ['00125', 'Student Code', &$errors, true]));
        $this->assertSame([], $errors);

        $errors = [];
        $this->assertSame('', $text->invokeArgs($service, [125, 'Student Code', &$errors, true]));
        $this->assertSame(['Student Code must be stored as Text to preserve leading zeroes.'], $errors);
    }

    public function test_phase_two_contract_keeps_school_routing_server_side_and_finance_effects_receivable_only(): void
    {
        $source = file_get_contents(app_path('Services/StudentImportV2Service.php'));
        $controller = file_get_contents(app_path('Http/Controllers/StudentController.php'));
        $view = file_get_contents(resource_path('views/students/import_v2.blade.php'));

        $this->assertStringContainsString("Student Import V2 accepts XLSX workbooks only.", $source);
        $this->assertStringContainsString('placementByName', $source);
        $this->assertStringContainsString("where('school_id', \$actor->school_id)", $source);
        $this->assertStringContainsString("session('school_database_name'", $source);
        $this->assertStringContainsString("DB::connection('school')->getDatabaseName()", $source);
        $this->assertStringContainsString('(int) $school->id !== (int) $actor->school_id', $source);
        $this->assertStringContainsString('assertCentralWritesAllowed', $source);
        $this->assertStringContainsString('assertCompulsorySetup', $source);
        $this->assertStringContainsString('saveDraft($student, $actor, [])', $source);
        $this->assertStringNotContainsString('CentralFinancePaymentService', $source);
        $this->assertStringNotContainsString('CentralFinanceReceipt', $source);
        $this->assertStringNotContainsString("'session_year_id' => ['required'", $controller);
        $this->assertStringContainsString("'file' => ['required', 'file', 'mimes:xlsx'", $controller);
        $this->assertStringContainsString('accept=".xlsx"', $view);
        $this->assertStringNotContainsString('name="school_code"', $view);
    }

    public function test_v21_contract_uses_single_names_and_never_fakes_or_fuzzy_merges_email_less_guardians(): void
    {
        $source = file_get_contents(app_path('Services/StudentImportV2Service.php'));
        $users = file_get_contents(app_path('Services/UserService.php'));
        $migration = file_get_contents(database_path('migrations/schools/2026_09_03_000002_make_student_import_v21_identity_fields_nullable.php'));

        $this->assertStringContainsString('SIMPLIFIED_REQUIRED_HEADERS', $source);
        $this->assertStringContainsString('Possible existing Guardian match', $source);
        $this->assertStringContainsString('createGuardianForStudentImport', $source);
        $this->assertStringContainsString("'last_name' => null", $users);
        $this->assertStringContainsString('\'email\' => $email', $users);
        $this->assertStringContainsString("string('email')->nullable()->change()", $migration);
        $this->assertStringContainsString("string('last_name', 128)->nullable()->change()", $migration);
        $this->assertStringNotContainsString('no-email@', $source.$users);
    }

    public function test_staff_and_login_email_contracts_remain_required_after_nullable_student_guardian_schema_change(): void
    {
        $staffImport = file_get_contents(app_path('Imports/StaffImport.php'));
        $teacherImport = file_get_contents(app_path('Imports/TeacherImport.php'));
        $financeStaff = file_get_contents(app_path('Http/Controllers/FinanceStaffController.php'));
        $login = file_get_contents(app_path('Http/Controllers/Auth/LoginController.php'));

        $this->assertStringContainsString("'*.email'      => 'required|email'", $staffImport);
        $this->assertStringContainsString("'*.email'             => 'required|email'", $teacherImport);
        $this->assertStringContainsString("'email'=>['required','email'", $financeStaff);
        $this->assertStringContainsString("'email' => 'required|string'", $login);
    }

    public function test_simplified_workbook_parser_accepts_single_names_and_optional_contact_fields_without_formula_or_number_coercion(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(StudentImportV2TemplateExport::HEADINGS, null, 'A1');
        $sheet->setCellValueExplicit('A2', '00125', DataType::TYPE_STRING);
        $sheet->setCellValue('B2', '张 三');
        $sheet->setCellValue('C2', 'Grade 1 - A');
        $sheet->setCellValue('D2', '2026');
        $sheet->setCellValue('G2', '2026-09-03');
        $sheet->setCellValueExplicit('H2', '0912345678', DataType::TYPE_STRING);
        $sheet->setCellValue('I2', '王 母亲');
        $sheet->setCellValueExplicit('J2', '0998765432', DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'student-import-v21-parser-');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        try {
            $uploaded = new UploadedFile($path, 'Student_Import_V2_1.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $service = app(StudentImportV2Service::class);
            $readRows = new \ReflectionMethod($service, 'readRows');
            $readRows->setAccessible(true);
            $rows = $readRows->invoke($service, $uploaded);
            $this->assertSame('00125', $rows[0]['student_code']);
            $this->assertSame('张 三', $rows[0]['student_name']);
            $this->assertSame('0912345678', $rows[0]['mobile']);
            $this->assertSame('0998765432', $rows[0]['guardian_mobile']);
            $this->assertSame('', $rows[0]['guardian_email'] ?? '');
            $this->assertSame('', $rows[0]['gender'] ?? '');
            $this->assertSame('', $rows[0]['date_of_birth'] ?? '');
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_identity_migration_keeps_school_plus_text_code_unique_without_replacing_legacy_identifiers(): void
    {
        $migration = file_get_contents(database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'));
        $this->assertStringContainsString("string('student_code', 100)", $migration);
        $this->assertStringContainsString("['school_id', 'student_code']", $migration);
        $this->assertStringContainsString("'student_import_identity_school_code_unique'", $migration);
        $this->assertStringNotContainsString("table('students'", $migration);
        $this->assertStringNotContainsString("table('users'", $migration);
    }

    public function test_identity_migration_enforces_school_plus_text_code_and_allows_the_same_code_in_another_school(): void
    {
        $migration = require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php');
        $migration->up();
        DB::connection('school')->table('student_import_identities')->insert([
            'school_id' => 1, 'student_code' => '00125', 'student_id' => 1, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('school')->table('student_import_identities')->insert([
            'school_id' => 2, 'student_code' => '00125', 'student_id' => 2, 'user_id' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(QueryException::class);
        DB::connection('school')->table('student_import_identities')->insert([
            'school_id' => 1, 'student_code' => '00125', 'student_id' => 3, 'user_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
