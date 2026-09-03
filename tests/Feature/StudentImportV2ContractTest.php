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
    public function test_template_requires_a_text_student_code_and_preserves_the_v2_admission_fields(): void
    {
        $export = new StudentImportV2TemplateExport();
        $this->assertSame([
            'Student Code', 'First Name', 'Last Name', 'Mobile', 'Gender', 'Date of Birth', 'Admission Date',
            'Current Address', 'Permanent Address', 'Guardian Email', 'Guardian First Name',
            'Guardian Last Name', 'Guardian Mobile', 'Guardian Gender',
        ], $export->headings());

        $path = tempnam(sys_get_temp_dir(), 'student-import-v2-');
        file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));
        $book = IOFactory::load($path);
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame('00125', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('@', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
            $this->assertSame('@', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
            $this->assertSame('@', $sheet->getStyle('M2')->getNumberFormat()->getFormatCode());
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
