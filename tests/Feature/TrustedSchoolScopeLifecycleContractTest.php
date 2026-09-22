<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TrustedSchoolScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TrustedSchoolScopeLifecycleContractTest extends TestCase
{
    private array $mysql;
    private string $database;
    private Request $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysql = config('database.connections.mysql');
        $this->database = tempnam(sys_get_temp_dir(), 'trusted_lifecycle_scope_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('database_name');
            $table->softDeletes();
        });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 15, 'database_name' => 'tenant_school_15'],
            ['id' => 16, 'database_name' => 'tenant_school_16'],
        ]);

        $this->previousRequest = request();
        $request = Request::create('/lifecycle-scope');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $request->session()->flush();
    }

    protected function tearDown(): void
    {
        app()->instance('request', $this->previousRequest);
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysql);
        DB::setDefaultConnection('mysql');
        @unlink($this->database);

        parent::tearDown();
    }

    public function test_lifecycle_scope_accepts_only_the_registered_actor_school_context_and_does_not_leak_between_requests(): void
    {
        $actor = new User(['school_id' => 15]);
        $actor->id = 501;
        $scope = app(TrustedSchoolScopeService::class);

        request()->session()->put('school_database_name', 'tenant_school_15');
        $this->assertSame(15, $scope->trustedSchoolIdFor($actor));

        request()->session()->put('school_database_name', 'tenant_school_16');
        try {
            $scope->trustedSchoolIdFor($actor);
            $this->fail('A School A actor must not mutate a School B lifecycle record.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_missing_or_untrusted_context_fails_closed_and_super_admin_is_not_turned_into_a_school_actor(): void
    {
        $scope = app(TrustedSchoolScopeService::class);
        $actor = new User(['school_id' => 15]);
        $actor->id = 501;

        try {
            $scope->trustedSchoolIdFor($actor);
            $this->fail('A lifecycle mutation must not proceed without a trusted School context.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $superAdmin = new User(['school_id' => null]);
        $superAdmin->id = 1;
        try {
            $scope->trustedSchoolIdFor($superAdmin);
            $this->fail('A Super Admin must not be implicitly converted into a School actor.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_trusted_scope_rejects_a_mismatched_api_context_and_allows_a_second_valid_school_without_stale_state(): void
    {
        $scope = app(TrustedSchoolScopeService::class);
        $schoolAActor = new User(['school_id' => 15]);
        $schoolBActor = new User(['school_id' => 16]);

        request()->attributes->set('trusted_tenant_school_id', 15);
        $this->assertSame(15, $scope->trustedSchoolIdFor($schoolAActor));

        request()->attributes->set('trusted_tenant_school_id', 16);
        $this->assertSame(16, $scope->trustedSchoolIdFor($schoolBActor));

        request()->attributes->set('trusted_tenant_school_id', 15);
        try {
            $scope->trustedSchoolIdFor($schoolBActor);
            $this->fail('A School B actor must not inherit a preceding School A API context.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_explicit_lifecycle_record_lookup_accepts_own_school_and_rejects_a_cross_school_direct_id(): void
    {
        User::on('mysql')->insert([
            ['id' => 100, 'first_name' => 'Own', 'school_id' => 15, 'status' => 1],
            ['id' => 101, 'first_name' => 'Foreign', 'school_id' => 16, 'status' => 1],
        ]);
        $actor = new User(['school_id' => 15]);
        request()->session()->put('school_database_name', 'tenant_school_15');

        $schoolId = app(TrustedSchoolScopeService::class)->trustedSchoolIdFor($actor);
        $lookup = User::on('mysql')->withTrashed()->where('school_id', $schoolId);

        $this->assertSame(100, $lookup->findOrFail(100)->id);
        $this->assertNull(User::on('mysql')->withTrashed()->where('school_id', $schoolId)->find(101));
    }

    public function test_student_and_teacher_lifecycle_paths_use_the_strict_scope_before_lookup_mutation_and_audit(): void
    {
        $student = file_get_contents(app_path('Http/Controllers/StudentController.php'));
        $teacher = file_get_contents(app_path('Http/Controllers/TeacherController.php'));

        foreach ([$student, $teacher] as $controller) {
            $this->assertStringContainsString('TrustedSchoolScopeService', $controller);
            $this->assertStringContainsString('trustedSchoolIdFor(Auth::user())', $controller);
            $this->assertStringContainsString('SchoolRecordLifecycleAuditService', $controller);
        }

        $this->assertStringContainsString('studentUserForTrustedSchool', $student);
        $this->assertStringContainsString('studentForTrustedSchool', $student);
        $this->assertStringContainsString("->where('school_id', \$schoolId)", $student);
        $this->assertStringContainsString("['reason' => ['required', 'string', 'max:2000']]", $student);
        $this->assertStringContainsString("Auth::user(),\n                \$user,", $student);
        $this->assertStringContainsString("Auth::user(),\n                    \$studentUser,", $student);
        $this->assertStringContainsString('Permanent deletion is not available for student records.', $student);

        $this->assertStringContainsString('teacherForCurrentSchool', $teacher);
        $this->assertStringContainsString("->where('school_id', \$this->trustedSchoolId())", $teacher);
        $this->assertStringContainsString("->where('school_id', \$schoolId)", $teacher);
        $this->assertStringContainsString("['reason' => ['required', 'string', 'max:2000']]", $teacher);
        $this->assertStringContainsString("Auth::user(),\n                \$teacher,", $teacher);
        $this->assertStringContainsString('Permanent deletion is not available for teacher records.', $teacher);
    }
}
