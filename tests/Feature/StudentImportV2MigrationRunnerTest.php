<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateStudentImportV2Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StudentImportV2MigrationRunnerTest extends TestCase
{
    private array $mysql; private array $school; private string $central; private string $tenant;
    protected function setUp(): void
    {
        parent::setUp(); $this->mysql = config('database.connections.mysql'); $this->school = config('database.connections.school');
        $this->central = tempnam(sys_get_temp_dir(), 'student-v2-central-'); $this->tenant = tempnam(sys_get_temp_dir(), 'student-v2-tenant-');
        $sqlite = static fn (string $database): array => ['driver'=>'sqlite','database'=>$database,'prefix'=>'','foreign_key_constraints'=>true];
        Config::set('database.connections.mysql', $sqlite($this->central)); Config::set('database.connections.school', $sqlite($this->tenant)); DB::purge('mysql'); DB::purge('school'); DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('schools', function (Blueprint $table): void { $table->increments('id'); $table->string('code')->unique(); $table->string('database_name')->unique(); $table->timestamp('deleted_at')->nullable(); });
        Schema::connection('mysql')->create('migrations', function (Blueprint $table): void { $table->increments('id'); $table->string('migration'); $table->integer('batch'); });
        Schema::connection('mysql')->create('central_finance_student_profiles', fn (Blueprint $table) => $table->increments('id')->string('admission_no')->nullable());
        DB::connection('mysql')->table('schools')->insert(['code'=>MigrateStudentImportV2Schema::SCHOOL_CODE, 'database_name'=>$this->tenant]);
        Schema::connection('school')->create('migrations', function (Blueprint $table): void { $table->increments('id'); $table->string('migration'); $table->integer('batch'); });
        Schema::connection('school')->create('students', fn (Blueprint $table) => $table->increments('id'));
        Schema::connection('school')->create('users', fn (Blueprint $table) => $table->increments('id'));
    }
    protected function tearDown(): void { DB::purge('mysql'); DB::purge('school'); Config::set('database.connections.mysql',$this->mysql); Config::set('database.connections.school',$this->school); @unlink($this->central); @unlink($this->tenant); parent::tearDown(); }

    public function test_default_is_read_only_then_exact_additive_migrations_complete(): void
    {
        $this->artisan('student-import-v2:migrate')->assertExitCode(0);
        $this->assertFalse(Schema::connection('school')->hasTable('student_import_identities'));
        $this->artisan('student-import-v2:migrate', ['--execute'=>true])->assertExitCode(0);
        $this->assertTrue(Schema::connection('mysql')->hasColumn('central_finance_student_profiles','student_code'));
        $this->assertTrue(Schema::connection('school')->hasTable('student_import_identities'));
        $this->artisan('student-import-v2:migrate')->assertExitCode(0);
    }
    public function test_partial_tenant_state_fails_closed_without_running_migrations(): void
    {
        Schema::connection('school')->create('student_import_identities', fn (Blueprint $table) => $table->increments('id'));
        $this->artisan('student-import-v2:migrate')->assertExitCode(1);
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_student_profiles','student_code'));
    }
}
