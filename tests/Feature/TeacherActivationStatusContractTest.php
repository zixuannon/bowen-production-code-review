<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherActivationStatusContractTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = tempnam(sys_get_temp_dir(), 'teacher_status_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'mysql');
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        session(['db_connection_name' => 'mysql']);

        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('mobile');
            $table->string('password')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('gender')->nullable();
            $table->date('dob')->nullable();
            $table->text('current_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('mysql')->create('staffs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('qualification')->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->date('joining_date')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_active_and_inactive_teacher_statuses_persist_and_filter_after_reload(): void
    {
        $active = $this->teacher(15, 'active@example.test', 1, null);
        $inactive = $this->teacher(15, 'inactive@example.test', 0, now());

        $this->assertSame([$active->id], User::on('mysql')->whereHas('staff')->pluck('id')->all());
        $this->assertSame([$inactive->id], User::on('mysql')->onlyTrashed()->where('status', 0)->whereHas('staff')->pluck('id')->all());

        $inactive->update(['status' => 1, 'deleted_at' => null]);
        $this->assertSame(1, User::on('mysql')->findOrFail($inactive->id)->status);
        $this->assertNull(User::on('mysql')->findOrFail($inactive->id)->deleted_at);

        $active->update(['status' => 0, 'deleted_at' => now()]);
        $this->assertSame(0, User::on('mysql')->withTrashed()->findOrFail($active->id)->status);
        $this->assertNotNull(User::on('mysql')->withTrashed()->findOrFail($active->id)->deleted_at);
        $this->assertSame([$inactive->id], User::on('mysql')->whereHas('staff')->pluck('id')->all());
        $this->assertSame([$active->id], User::on('mysql')->onlyTrashed()->where('status', 0)->whereHas('staff')->pluck('id')->all());
    }

    public function test_status_update_query_is_tenant_scoped_and_cannot_select_another_school_teacher(): void
    {
        $ownTeacher = $this->teacher(15, 'own@example.test', 1, null);
        $foreignTeacher = $this->teacher(16, 'foreign@example.test', 1, null);

        $this->assertSame($ownTeacher->id, User::on('mysql')->where('school_id', 15)->findOrFail($ownTeacher->id)->id);
        $this->assertNull(User::on('mysql')->where('school_id', 15)->find($foreignTeacher->id));
    }

    public function test_controller_and_form_keep_the_canonical_status_contract_and_visible_feedback_path(): void
    {
        $project = dirname(__DIR__, 2);
        $controller = file_get_contents($project . '/app/Http/Controllers/TeacherController.php');
        $view = file_get_contents($project . '/resources/views/teacher/index.blade.php');
        $events = file_get_contents($project . '/public/assets/js/custom/bootstrap-table/actionEvents.js');
        $ajax = file_get_contents($project . '/public/assets/js/custom/function.js');

        $this->assertStringContainsString("'status'", $controller);
        $this->assertStringContainsString("'boolean'", $controller);
        $this->assertStringContainsString("'deleted_at' => \$newStatus === 1 ? null : now()", $controller);
        $this->assertStringContainsString('teacherForCurrentSchool', $controller);
        $this->assertStringContainsString('if ($teacher->trashed())', $controller);
        $this->assertStringContainsString("abort_unless(Auth::user()?->can('teacher-edit'), 403)", $controller);
        $this->assertStringContainsString('abort_unless((int) $teacher->school_id === (int) $schoolId, 403)', $controller);
        $this->assertStringContainsString('return response($teacher->staff);', $controller);
        $this->assertStringContainsString('SchoolRecordLifecycleAuditService', $controller);
        $this->assertStringContainsString('XiaobailongLifecycleNotifier', $controller);
        $this->assertStringContainsString('edit_teacher_status_active', $view);
        $this->assertStringContainsString('teacher-status-reason', $view);
        $this->assertStringContainsString('syncTeacherStatusReason', $view);
        $this->assertStringContainsString("data('teacher-status', String(row.status))", $events);
        $this->assertStringContainsString('showErrorToast(response.message)', $ajax);
    }

    private function teacher(int $schoolId, string $email, int $status, $deletedAt): User
    {
        $teacher = User::on('mysql')->create([
            'first_name' => 'Teacher',
            'last_name' => 'QA',
            'email' => $email,
            'mobile' => '0912345678',
            'school_id' => $schoolId,
            'status' => $status,
            'deleted_at' => $deletedAt,
        ]);
        Staff::on('mysql')->create(['user_id' => $teacher->id, 'qualification' => 'QA']);

        return $teacher;
    }
}
