<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateStudentFeeAssignmentSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentFeeAssignmentMigrationRunnerTest extends TestCase
{
    private array $mysql;
    private array $school;
    private array $trusted;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = config('database.connections.mysql');
        $this->school = config('database.connections.school');
        $central = $this->database();
        $this->trusted = ['MMBOWEN01'=>$this->database(),'SCH202616'=>$this->database(),'SCH202619'=>$this->database()];
        $sqlite = static fn (string $database): array => ['driver'=>'sqlite','database'=>$database,'prefix'=>'','foreign_key_constraints'=>true];
        Config::set('database.connections.mysql', $sqlite($central));
        Config::set('database.connections.school', $sqlite($this->trusted['MMBOWEN01']));
        Config::set('finance_release.student_fee_assignment_tenants', $this->trusted);
        Config::set('finance_release.student_fee_assignment_canary', 'MMBOWEN01');
        DB::purge('mysql'); DB::purge('school'); DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('schools', function ($table): void { $table->increments('id'); $table->string('code')->unique(); $table->string('database_name')->unique(); $table->timestamp('deleted_at')->nullable(); });
        foreach ($this->trusted as $code => $database) {
            DB::connection('mysql')->table('schools')->insert(['code'=>$code,'database_name'=>$database]);
            Config::set('database.connections.school.database', $database); DB::purge('school');
            Schema::connection('school')->create('migrations', function ($table): void { $table->increments('id'); $table->string('migration'); $table->integer('batch'); });
            Schema::connection('school')->create('students', fn ($table) => $table->increments('id'));
            Schema::connection('school')->create('fees_class_types', fn ($table) => $table->increments('id'));
        }
    }

    protected function tearDown(): void
    {
        DB::purge('school'); DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysql); Config::set('database.connections.school', $this->school);
        Config::set('finance_release.student_fee_assignment_tenants', null); Config::set('finance_release.student_fee_assignment_canary', null);
        DB::setDefaultConnection('mysql'); foreach ($this->files as $file) @unlink($file); parent::tearDown();
    }

    public function test_fixed_allowlist_is_exact_and_demo_is_excluded(): void
    {
        $this->assertSame(['MMBOWEN01','SCH202616','SCH202619','SCH202620','SCH202621','SCH202631','SCH202632'], array_keys(MigrateStudentFeeAssignmentSchema::PRODUCTION_TENANTS));
        $this->assertArrayNotHasKey('SCH20261', MigrateStudentFeeAssignmentSchema::PRODUCTION_TENANTS);
        $this->assertSame([
            '2026_08_27_000001_create_student_fee_assignment_tables',
            '2026_08_27_000003_add_type_to_student_fee_assignments',
            '2026_08_27_000004_create_student_fee_assignment_source_locks',
        ], MigrateStudentFeeAssignmentSchema::MIGRATIONS);
    }

    public function test_default_is_zero_write_and_demo_unknown_and_database_names_are_denied(): void
    {
        $this->artisan('finance:migrate-student-fee-assignments')->assertExitCode(0);
        foreach ($this->trusted as $database) $this->assertFalse($this->complete($database));
        $this->artisan('finance:migrate-student-fee-assignments', ['--tenant'=>[$this->trusted['MMBOWEN01']]])->assertExitCode(1);
        $this->artisan('finance:migrate-student-fee-assignments', ['--tenant'=>['SCH20261']])->assertExitCode(1);
        $this->artisan('finance:migrate-student-fee-assignments', ['--tenant'=>['UNKNOWN']])->assertExitCode(1);
    }

    public function test_remaining_schools_are_refused_until_canary_is_schema_and_history_verified(): void
    {
        $this->artisan('finance:migrate-student-fee-assignments', ['--tenant'=>['SCH202616','SCH202619'],'--execute'=>true])->assertExitCode(1);
        $this->assertFalse($this->complete($this->trusted['SCH202616']));
        $this->artisan('finance:migrate-student-fee-assignments', ['--tenant'=>['MMBOWEN01'],'--execute'=>true])->assertExitCode(0);
        $this->assertTrue($this->complete($this->trusted['MMBOWEN01']));
        $this->assertSame(MigrateStudentFeeAssignmentSchema::MIGRATIONS, $this->migrations($this->trusted['MMBOWEN01']));
        $this->artisan('finance:migrate-student-fee-assignments', ['--tenant'=>['SCH202616','SCH202619'],'--execute'=>true])->assertExitCode(0);
        foreach (['SCH202616','SCH202619'] as $code) $this->assertTrue($this->complete($this->trusted[$code]));
    }

    public function test_partial_state_fails_closed_without_writing(): void
    {
        $this->on($this->trusted['MMBOWEN01'], fn () => Schema::connection('school')->create('student_fee_assignments', fn ($table) => $table->increments('id')));
        $this->artisan('finance:migrate-student-fee-assignments', ['--tenant'=>['MMBOWEN01']])->assertExitCode(1);
        $this->assertSame([], $this->migrations($this->trusted['MMBOWEN01']));
    }

    private function database(): string { $file = tempnam(sys_get_temp_dir(), 'student-assignment-runner-'); $this->files[] = $file; return $file; }
    private function on(string $database, callable $callback): mixed { Config::set('database.connections.school.database', $database); DB::purge('school'); return $callback(); }
    private function complete(string $database): bool { return $this->on($database, fn (): bool => Schema::connection('school')->hasTable('student_fee_assignments') && Schema::connection('school')->hasTable('student_fee_assignment_items') && Schema::connection('school')->hasTable('student_fee_assignment_source_locks') && Schema::connection('school')->hasColumn('student_fee_assignments','assignment_type')); }
    private function migrations(string $database): array { return $this->on($database, fn (): array => DB::connection('school')->table('migrations')->whereIn('migration', MigrateStudentFeeAssignmentSchema::MIGRATIONS)->orderBy('migration')->pluck('migration')->all()); }
}
