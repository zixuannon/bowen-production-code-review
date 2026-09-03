<?php

namespace Tests\Feature;

use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceSyncEvent;
use App\Models\School;
use App\Models\Students;
use App\Services\CentralFinanceStudentProfileBackfillService;
use App\Services\CentralFinanceStudentProfileSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CentralFinanceStudentProfileSyncTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;

    /** @var array<string, mixed> */
    private array $schoolConnection;

    private string $centralDatabase;

    private string $schoolADatabase;

    private string $schoolBDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->centralDatabase = tempnam(sys_get_temp_dir(), 'central_finance_sync_central_');
        $this->schoolADatabase = tempnam(sys_get_temp_dir(), 'central_finance_sync_a_');
        $this->schoolBDatabase = tempnam(sys_get_temp_dir(), 'central_finance_sync_b_');

        Config::set('database.connections.mysql', $this->sqlite($this->centralDatabase));
        Config::set('database.connections.school', $this->sqlite($this->schoolADatabase));
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');

        $this->centralSchema();
        $this->tenantSchema($this->schoolADatabase);
        $this->tenantSchema($this->schoolBDatabase);
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Central QA School A', 'code' => 'SCH202615', 'database_name' => $this->schoolADatabase, 'installed' => true, 'status' => 'active'],
            ['id' => 2, 'name' => 'Central QA School B', 'code' => 'SCH202616', 'database_name' => $this->schoolBDatabase, 'installed' => true, 'status' => 'active'],
        ]);
        $this->seedTenant($this->schoolADatabase, 11, '11111111-1111-4111-8111-111111111111', 'A-001', 'Ada', 'School A', '2026-08-20 09:00:00');
        $this->seedTenant($this->schoolBDatabase, 11, '22222222-2222-4222-8222-222222222222', 'B-001', 'Ben', 'School B', '2026-08-20 09:00:00');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        DB::purge('school');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        Config::set('database.connections.school', $this->schoolConnection);
        DB::setDefaultConnection('mysql');
        @unlink($this->centralDatabase);
        @unlink($this->schoolADatabase);
        @unlink($this->schoolBDatabase);
        parent::tearDown();
    }

    public function test_two_tenants_sync_into_distinct_central_profiles_and_repeat_is_idempotent(): void
    {
        $sync = app(CentralFinanceStudentProfileSyncService::class);
        $first = $sync->syncSchool(School::on('mysql')->findOrFail(1));
        $second = $sync->syncSchool(School::on('mysql')->findOrFail(2));
        $again = $sync->syncSchool(School::on('mysql')->findOrFail(1));

        $this->assertSame('created', $first[0]['result']);
        $this->assertSame('created', $second[0]['result']);
        $this->assertSame('duplicate', $again[0]['result']);
        $this->assertSame(2, CentralFinanceStudentProfile::on('mysql')->count());
        $this->assertSame(2, CentralFinanceSyncEvent::on('mysql')->count());
        $this->assertSame(2, CentralFinanceSyncEvent::on('mysql')->where('school_id', 1)->firstOrFail()->attempts);
        $this->assertSame('Ada School A', CentralFinanceStudentProfile::on('mysql')->where('school_id', 1)->value('student_name'));
        $this->assertSame('Ben School B', CentralFinanceStudentProfile::on('mysql')->where('school_id', 2)->value('student_name'));
    }

    public function test_student_code_projects_from_the_tenant_identity_without_replacing_gr_admission_number(): void
    {
        $this->onTenant($this->schoolADatabase, function (): void {
            DB::connection('school')->table('student_import_identities')->insert([
                'school_id' => 1, 'student_code' => '00125', 'student_id' => 11, 'user_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        app(CentralFinanceStudentProfileSyncService::class)->syncSchool(School::on('mysql')->findOrFail(1));
        $profile = CentralFinanceStudentProfile::on('mysql')->where('school_id', 1)->firstOrFail();
        $this->assertSame('00125', $profile->student_code);
        $this->assertSame('A-001', $profile->admission_no);
    }

    public function test_newer_source_version_updates_only_its_school_and_stale_delivery_is_neutral(): void
    {
        $sync = app(CentralFinanceStudentProfileSyncService::class);
        $schoolA = School::on('mysql')->findOrFail(1);
        $schoolB = School::on('mysql')->findOrFail(2);
        $sync->syncSchool($schoolA);
        $sync->syncSchool($schoolB);

        $this->onTenant($this->schoolADatabase, function (): void {
            DB::connection('school')->table('users')->where('id', 1)->update(['first_name' => 'Ada Updated']);
            DB::connection('school')->table('students')->where('id', 11)->update(['application_status' => 'inactive', 'updated_at' => '2026-08-20 10:00:00']);
        });
        $updated = $sync->syncSchool($schoolA);
        $this->assertSame('updated', $updated[0]['result']);
        $this->assertSame('Ada Updated School A', CentralFinanceStudentProfile::on('mysql')->where('school_id', 1)->value('student_name'));
        $this->assertSame('active', CentralFinanceStudentProfile::on('mysql')->where('school_id', 2)->value('enrollment_status'));

        $stale = new \App\Services\CentralFinanceStudentProfilePayload(
            schoolId: 1, tenantStudentId: 11, sourceUuid: '11111111-1111-4111-8111-111111111111',
            admissionNo: 'A-001', studentName: 'Old Name', enrollmentStatus: 'active',
            sourceUpdatedAt: CarbonImmutable::parse('2026-08-20 08:00:00'),
        );
        $this->assertSame('ignored_stale', $sync->synchronize($stale)['result']);
        $this->assertSame('Ada Updated School A', CentralFinanceStudentProfile::on('mysql')->where('school_id', 1)->value('student_name'));
    }

    public function test_reconciliation_is_read_only_and_reports_missing_source_projection(): void
    {
        $sync = app(CentralFinanceStudentProfileSyncService::class);
        $schoolA = School::on('mysql')->findOrFail(1);
        $sync->syncSchool($schoolA);
        $before = $this->centralHash();

        $report = $sync->reconcileSchool($schoolA);
        $this->assertSame(1, $report['central_count']);
        $this->assertSame(1, $report['source_count']);
        $this->assertSame([], $report['missing_in_central']);
        $this->assertSame([], $report['missing_in_source']);
        $this->assertSame([], $report['stale_in_central']);
        $this->assertSame([], $report['mismatched']);
        $this->assertSame($before, $this->centralHash());

        $this->onTenant($this->schoolADatabase, function (): void {
            DB::connection('school')->table('students')->insert([
                'id' => 12, 'user_id' => 4, 'admission_no' => 'A-002',
                'central_finance_source_uuid' => '33333333-3333-4333-8333-333333333333',
                'application_status' => 'active', 'updated_at' => '2026-08-20 11:00:00',
            ]);
            DB::connection('school')->table('users')->insert(['id' => 4, 'first_name' => 'Amy', 'last_name' => 'New']);
        });
        $missing = $sync->reconcileSchool($schoolA);
        $this->assertSame(['33333333-3333-4333-8333-333333333333'], $missing['missing_in_central']);
        $this->assertSame($before, $this->centralHash());
    }

    public function test_gate_a_student_runner_defaults_to_read_only_reconciliation(): void
    {
        $before = $this->centralHash();
        $this->artisan('central-finance:student-profile-sync', ['--school-code' => ['SCH202615', 'SCH202616']])
            ->assertSuccessful();
        $this->assertSame($before, $this->centralHash());
        $this->artisan('central-finance:student-profile-sync', ['--school-code' => ['SCH202615'], '--backfill' => true])
            ->assertFailed();
        $this->assertSame($before, $this->centralHash());
    }

    public function test_reconciliation_reports_stale_and_mismatched_profiles_without_writing(): void
    {
        $sync = app(CentralFinanceStudentProfileSyncService::class);
        $school = School::on('mysql')->findOrFail(1);
        $sync->syncSchool($school);
        $profile = CentralFinanceStudentProfile::on('mysql')->firstOrFail();
        $profile->update(['student_name' => 'Incorrect Projection', 'source_updated_at' => '2026-08-20 08:00:00']);
        $before = $this->centralHash();

        $report = $sync->reconcileSchool($school);
        $this->assertSame(['11111111-1111-4111-8111-111111111111'], $report['stale_in_central']);
        $this->assertSame(['11111111-1111-4111-8111-111111111111'], $report['mismatched']);
        $this->assertSame($before, $this->centralHash());
    }

    public function test_missing_source_uuid_fails_closed_without_central_write(): void
    {
        $this->onTenant($this->schoolADatabase, function (): void {
            DB::connection('school')->table('students')->update(['central_finance_source_uuid' => null]);
        });
        $before = $this->centralHash();

        try {
            app(CentralFinanceStudentProfileSyncService::class)->syncSchool(School::on('mysql')->findOrFail(1));
            $this->fail('A tenant source UUID is mandatory for Central Finance sync.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no Central Finance source UUID', $exception->getMessage());
        }
        $this->assertSame($before, $this->centralHash());
    }

    public function test_failed_delivery_retries_the_same_event_without_creating_a_duplicate_profile(): void
    {
        $payload = new \App\Services\CentralFinanceStudentProfilePayload(
            schoolId: 1, tenantStudentId: 11, sourceUuid: '11111111-1111-4111-8111-111111111111',
            admissionNo: 'A-001', studentName: 'Ada School A', enrollmentStatus: 'active',
            sourceUpdatedAt: CarbonImmutable::parse('2026-08-20 09:00:00'),
        );
        $sync = app(CentralFinanceStudentProfileSyncService::class);

        $sync->recordDeliveryFailure($payload, 'delivery_timeout');
        $this->assertSame(0, CentralFinanceStudentProfile::on('mysql')->count());
        $this->assertSame('failed', CentralFinanceSyncEvent::on('mysql')->value('status'));

        $result = $sync->synchronize($payload);
        $event = CentralFinanceSyncEvent::on('mysql')->firstOrFail();
        $this->assertSame('created', $result['result']);
        $this->assertSame(1, CentralFinanceStudentProfile::on('mysql')->count());
        $this->assertSame(1, CentralFinanceSyncEvent::on('mysql')->count());
        $this->assertSame('processed', $event->status);
        $this->assertSame(2, $event->attempts);
        $this->assertNull($event->error_code);
    }

    public function test_requested_school_database_is_ignored_and_registry_connection_is_restored(): void
    {
        $sync = app(CentralFinanceStudentProfileSyncService::class);
        $school = School::on('mysql')->findOrFail(1);
        $school->database_name = $this->schoolBDatabase;
        $beforeDatabase = config('database.connections.school.database');
        $beforeDefault = DB::getDefaultConnection();

        $sync->syncSchool($school);

        $this->assertSame('A-001', CentralFinanceStudentProfile::on('mysql')->where('school_id', 1)->value('admission_no'));
        $this->assertSame($beforeDatabase, config('database.connections.school.database'));
        $this->assertSame($beforeDefault, DB::getDefaultConnection());
    }

    public function test_tenant_create_update_and_status_change_publish_minimum_profile_fields(): void
    {
        DB::setDefaultConnection('school');
        Config::set('database.connections.school.database', $this->schoolADatabase);
        DB::purge('school');
        $student = DB::connection('school')->transaction(function (): Students {
            return Students::on('school')->create([
                'user_id' => 3, 'school_id' => 1, 'guardian_id' => 2, 'class_section_id' => 1,
                'admission_no' => 'A-003', 'application_status' => '1',
            ]);
        });
        $this->assertNotNull($student->central_finance_source_uuid);
        $profile = CentralFinanceStudentProfile::on('mysql')->where('tenant_student_id', $student->id)->firstOrFail();
        $this->assertSame('A-003', $profile->admission_no);
        $this->assertSame('Grade 1', $profile->class_name);
        $this->assertSame('A', $profile->section_name);
        $this->assertSame('Guardian One', $profile->guardian_name);
        $this->assertSame('guardian@example.test', $profile->guardian_email);
        $this->assertSame('1', $profile->enrollment_status);

        $student->application_status = '0';
        $student->save();
        $this->assertSame('0', CentralFinanceStudentProfile::on('mysql')->whereKey($profile->id)->value('enrollment_status'));
        $this->assertSame(1, CentralFinanceStudentProfile::on('mysql')->where('tenant_student_id', $student->id)->count());
        DB::setDefaultConnection('mysql');
    }

    public function test_backfill_is_idempotent_and_one_failed_tenant_does_not_block_another(): void
    {
        $this->onTenant($this->schoolADatabase, function (): void {
            DB::connection('school')->table('students')->where('id', 11)->update(['central_finance_source_uuid' => null]);
        });
        $schoolA = School::on('mysql')->findOrFail(1);
        $schoolB = School::on('mysql')->findOrFail(2);
        $batch = app(CentralFinanceStudentProfileBackfillService::class);

        $first = $batch->synchronizeSchools([$schoolA, $schoolB], true);
        $this->assertSame('complete', $first[1]['status']);
        $this->assertSame(1, $first[1]['backfilled']);
        $this->assertSame('complete', $first[2]['status']);
        $second = $batch->synchronizeSchools([$schoolA, $schoolB], true);
        $this->assertSame(0, $second[1]['backfilled']);
        $this->assertSame(2, CentralFinanceStudentProfile::on('mysql')->count());

        DB::connection('mysql')->table('schools')->where('id', 1)->update(['database_name' => "\0invalid"]);
        $isolated = $batch->synchronizeSchools([School::on('mysql')->findOrFail(1), $schoolB], true);
        $this->assertSame('failed', $isolated[1]['status']);
        $this->assertSame('complete', $isolated[2]['status']);
    }

    /** @return array<string, mixed> */
    private function sqlite(string $database): array
    {
        return ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true];
    }

    private function centralSchema(): void
    {
        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->id(); $table->string('name'); $table->string('code')->unique(); $table->string('database_name')->unique(); $table->boolean('installed')->default(true); $table->string('status')->nullable(); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        (require database_path('migrations/2026_08_20_000003_create_central_finance_student_sync_tables.php'))->up();
        (require database_path('migrations/2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php'))->up();
        (require database_path('migrations/2026_09_03_000002_add_student_code_to_central_finance_student_profiles.php'))->up();
    }

    private function tenantSchema(string $database): void
    {
        $this->onTenant($database, function (): void {
            Schema::connection('school')->create('users', function ($table): void {
                $table->id(); $table->string('first_name')->nullable(); $table->string('last_name')->nullable();
                $table->string('email')->nullable(); $table->string('mobile')->nullable(); $table->string('status')->nullable();
            });
            Schema::connection('school')->create('classes', function ($table): void { $table->id(); $table->string('name'); });
            Schema::connection('school')->create('sections', function ($table): void { $table->id(); $table->string('name'); });
            Schema::connection('school')->create('class_sections', function ($table): void { $table->id(); $table->unsignedBigInteger('class_id'); $table->unsignedBigInteger('section_id'); });
            Schema::connection('school')->create('students', function ($table): void {
                $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('school_id')->nullable(); $table->string('admission_no')->nullable();
                $table->unsignedBigInteger('guardian_id')->nullable(); $table->unsignedBigInteger('class_section_id')->nullable();
                $table->string('application_status')->nullable(); $table->timestamp('created_at')->nullable(); $table->timestamp('updated_at')->nullable(); $table->timestamp('deleted_at')->nullable();
            });
            (require database_path('migrations/schools/2026_08_20_000002_add_central_finance_source_uuid_to_students_table.php'))->up();
            (require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'))->up();
            DB::connection('school')->table('classes')->insert(['id' => 1, 'name' => 'Grade 1']);
            DB::connection('school')->table('sections')->insert(['id' => 1, 'name' => 'A']);
            DB::connection('school')->table('class_sections')->insert(['id' => 1, 'class_id' => 1, 'section_id' => 1]);
        });
    }

    private function seedTenant(string $database, int $studentId, string $uuid, string $admissionNo, string $firstName, string $lastName, string $updatedAt): void
    {
        $this->onTenant($database, function () use ($studentId, $uuid, $admissionNo, $firstName, $lastName, $updatedAt): void {
            DB::connection('school')->table('users')->insert([
                ['id' => 1, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => null, 'mobile' => null, 'status' => '1'],
                ['id' => 2, 'first_name' => 'Guardian', 'last_name' => 'One', 'email' => 'guardian@example.test', 'mobile' => '09123456', 'status' => '1'],
                ['id' => 3, 'first_name' => 'Created', 'last_name' => 'Student', 'email' => null, 'mobile' => null, 'status' => '1'],
            ]);
            DB::connection('school')->table('students')->insert([
                'id' => $studentId, 'user_id' => 1, 'admission_no' => $admissionNo,
                'guardian_id' => 2, 'class_section_id' => 1, 'central_finance_source_uuid' => $uuid, 'application_status' => 'active', 'updated_at' => $updatedAt,
            ]);
        });
    }

    private function onTenant(string $database, callable $callback): mixed
    {
        $old = config('database.connections.school.database');
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            return $callback();
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $old);
        }
    }

    private function centralHash(): string
    {
        $parts = [];
        foreach (['central_finance_student_profiles', 'central_finance_sync_events'] as $table) {
            $parts[] = $table . ':' . json_encode(DB::connection('mysql')->table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(), JSON_THROW_ON_ERROR);
        }

        return hash('sha256', implode('|', $parts));
    }
}
