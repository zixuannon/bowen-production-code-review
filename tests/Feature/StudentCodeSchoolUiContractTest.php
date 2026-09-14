<?php

namespace Tests\Feature;

use App\Models\Students;
use App\Models\User;
use App\Services\StudentCodeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StudentCodeSchoolUiContractTest extends TestCase
{
    private array $schoolConnection;
    private string $schoolDatabase;
    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolConnection = config('database.connections.school');
        $this->defaultConnection = DB::getDefaultConnection();
        $this->schoolDatabase = tempnam(sys_get_temp_dir(), 'student-code-ui-');
        Config::set('database.connections.school', ['driver' => 'sqlite', 'database' => $this->schoolDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('school');
        DB::setDefaultConnection('school');

        Schema::connection('school')->create('students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('school_id');
            $table->string('admission_no')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        (require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'))->up();
        (require database_path('migrations/schools/2026_09_14_000001_create_student_code_sequences.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->defaultConnection);
        DB::purge('school');
        Config::set('database.connections.school', $this->schoolConnection);
        @unlink($this->schoolDatabase);
        parent::tearDown();
    }

    public function test_student_codes_are_generated_as_six_digit_per_school_sequences(): void
    {
        $actor = new User();
        $actor->id = 99;
        $service = app(StudentCodeService::class);
        DB::connection('school')->table('students')->insert([
            ['user_id' => 1, 'school_id' => 10, 'admission_no' => '2026010001', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'school_id' => 10, 'admission_no' => '2026010002', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 3, 'school_id' => 11, 'admission_no' => '2026011001', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $first = Students::query()->findOrFail(1);
        $second = Students::query()->findOrFail(2);
        $otherSchool = Students::query()->findOrFail(3);

        $identity = $service->assignGenerated($first, $actor);
        $this->assertSame('000001', $identity->student_code);
        $this->assertSame('2026010001', $first->fresh()->admission_no);
        $this->assertSame($identity->id, $service->assignGenerated($first, $actor)->id);
        $this->assertSame('000002', $service->assignGenerated($second, $actor)->student_code);
        $this->assertSame('000001', $service->assignGenerated($otherSchool, $actor)->student_code);
        $this->assertSame('000001', DB::connection('school')->table('student_import_identities')->where('student_id', 1)->value('student_code'));

        try {
            $service->assign($first, $actor, '000003');
            $this->fail('An assigned Student Code must not be silently changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_code', $exception->errors());
        }
    }

    public function test_deleted_student_code_and_import_reference_are_never_reused(): void
    {
        $actor = new User();
        $actor->id = 99;
        DB::connection('school')->table('students')->insert([
            ['user_id' => 1, 'school_id' => 10, 'admission_no' => 'GR-1', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'school_id' => 10, 'admission_no' => 'GR-2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $service = app(StudentCodeService::class);
        $first = Students::query()->findOrFail(1);
        $second = Students::query()->findOrFail(2);
        $this->assertSame('000001', $service->assignGenerated($first, $actor, 'SRC-001')->student_code);
        DB::connection('school')->table('students')->where('id', $first->id)->update(['deleted_at' => now()]);
        $this->assertSame('000002', $service->assignGenerated($second, $actor, 'SRC-002')->student_code);
        $this->assertTrue($service->existsByImportReference(10, 'SRC-001'));

        try {
            $service->assignGenerated($second, $actor, 'SRC-003');
            $this->fail('Import Reference and Student Code must remain immutable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('import_reference', $exception->errors());
        }
    }

    public function test_import_reference_is_an_exactly_once_school_identity(): void
    {
        $actor = new User(); $actor->id = 99;
        DB::connection('school')->table('students')->insert([
            ['user_id' => 1, 'school_id' => 10, 'admission_no' => 'GR-1', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'school_id' => 10, 'admission_no' => 'GR-2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $service = app(StudentCodeService::class);
        $this->assertSame('000001', $service->assignGenerated(Students::query()->findOrFail(1), $actor, 'ROW-42')->student_code);

        try {
            $service->assignGenerated(Students::query()->findOrFail(2), $actor, 'ROW-42');
            $this->fail('A repeated School import reference must not create another identity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('import_reference', $exception->errors());
        }
        $this->assertSame(1, DB::connection('school')->table('student_import_identities')->where('school_id', 10)->count());
        $this->assertSame(2, (int) DB::connection('school')->table('student_code_sequences')->where('school_id', 10)->value('next_number'));
    }

    public function test_student_ui_and_central_projection_keep_student_code_separate_from_gr_number(): void
    {
        $create = file_get_contents(resource_path('views/students/create.blade.php'));
        $edit = file_get_contents(resource_path('views/students/details.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/StudentController.php'));
        $this->assertStringNotContainsString('name="student_code"', $create);
        $this->assertStringContainsString('id="student_code"', $create);
        $this->assertStringContainsString('readonly', $create);
        $this->assertStringNotContainsString('name="student_code"', $edit);
        $this->assertStringContainsString('assignGenerated($student, Auth::user())', $controller);
        $this->assertStringContainsString('data-field="student_code"', file_get_contents(resource_path('views/students/details.blade.php')));
        $this->assertStringContainsString("student_code', 'like'", file_get_contents(app_path('Http/Controllers/CentralFinanceStudentCollectionController.php')));
        $this->assertStringContainsString("student_code', 100", file_get_contents(database_path('migrations/2026_09_03_000002_add_student_code_to_central_finance_student_profiles.php')));
    }
}
