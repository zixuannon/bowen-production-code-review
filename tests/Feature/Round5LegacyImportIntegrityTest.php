<?php

namespace Tests\Feature;

use App\Exports\StudentDataExport;
use App\Services\StudentCodeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class Round5LegacyImportIntegrityTest extends TestCase
{
    private array $schoolConnection;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolConnection = config('database.connections.school');
        $this->database = tempnam(sys_get_temp_dir(), 'round5-import-');
        Config::set('database.connections.school', ['driver'=>'sqlite','database'=>$this->database,'prefix'=>'','foreign_key_constraints'=>true]);
        DB::purge('school');
        Schema::connection('school')->create('students', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('school_id'); $table->timestamps();
        });
        (require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('school'); Config::set('database.connections.school', $this->schoolConnection); @unlink($this->database);
        parent::tearDown();
    }

    public function test_legacy_template_and_import_use_string_student_code_without_latest_id_identity(): void
    {
        $reflection = new \ReflectionClass(StudentDataExport::class);
        $export = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('formFields');
        $property->setAccessible(true);
        $property->setValue($export, new \Illuminate\Database\Eloquent\Collection());
        $this->assertSame('student_code', $export->headings()[0]);
        $this->assertSame('00125', $export->collection()->first()[0]);
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT, $export->columnFormats()['A']);

        $source = file_get_contents(app_path('Imports/StudentsImport.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString("'*.student_code'  => 'required|string|max:100'", $source);
        $this->assertStringContainsString("DB::connection('school')->transaction", $source);
        $this->assertStringContainsString('$studentCodeService->assign(', $source);
        $this->assertStringContainsString('Str::orderedUuid()', $source);
        $this->assertStringNotContainsString("latest('id')", $source);
    }

    public function test_database_identity_preserves_leading_zero_and_rejects_duplicate_race(): void
    {
        $service = app(StudentCodeService::class);
        $this->assertSame('00125', $service->normalize('00125'));
        DB::connection('school')->table('student_import_identities')->insert([
            'school_id'=>9, 'student_code'=>'00125', 'student_id'=>1, 'user_id'=>11, 'created_at'=>now(), 'updated_at'=>now(),
        ]);

        try {
            DB::connection('school')->table('student_import_identities')->insert([
                'school_id'=>9, 'student_code'=>'00125', 'student_id'=>2, 'user_id'=>12, 'created_at'=>now(), 'updated_at'=>now(),
            ]);
            $this->fail('A concurrent duplicate must lose at the database unique gate.');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, DB::connection('school')->table('student_import_identities')->where('school_id', 9)->where('student_code', '00125')->count());
        DB::connection('school')->table('student_import_identities')->insert([
            'school_id'=>10, 'student_code'=>'00125', 'student_id'=>3, 'user_id'=>13, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $this->assertSame(2, DB::connection('school')->table('student_import_identities')->where('student_code', '00125')->count());
    }

    public function test_invalid_student_codes_fail_closed(): void
    {
        $this->expectException(ValidationException::class);
        app(StudentCodeService::class)->normalize("bad\0code");
    }
}
