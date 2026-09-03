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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->defaultConnection);
        DB::purge('school');
        Config::set('database.connections.school', $this->schoolConnection);
        @unlink($this->schoolDatabase);
        parent::tearDown();
    }

    public function test_school_student_code_is_text_unique_and_stable_without_replacing_admission_number(): void
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

        $identity = $service->assign($first, $actor, '00125');
        $this->assertSame('00125', $identity->student_code);
        $this->assertSame('2026010001', $first->fresh()->admission_no);
        $this->assertSame($identity->id, $service->assign($first, $actor, '00125')->id);

        try {
            $service->assign($second, $actor, '00125');
            $this->fail('Same-School Student Code must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_code', $exception->errors());
        }

        $this->assertSame('00125', $service->assign($otherSchool, $actor, '00125')->student_code);

        try {
            $service->assign($first, $actor, '00126');
            $this->fail('An assigned Student Code must not be silently changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_code', $exception->errors());
        }
    }

    public function test_student_ui_and_central_projection_keep_student_code_separate_from_gr_number(): void
    {
        $this->assertStringContainsString("name=\"student_code\"", file_get_contents(resource_path('views/students/create.blade.php')));
        $this->assertStringContainsString('data-field="student_code"', file_get_contents(resource_path('views/students/details.blade.php')));
        $this->assertStringContainsString("student_code', 'like'", file_get_contents(app_path('Http/Controllers/CentralFinanceStudentCollectionController.php')));
        $this->assertStringContainsString("student_code', 100", file_get_contents(database_path('migrations/2026_09_03_000002_add_student_code_to_central_finance_student_profiles.php')));
    }
}
