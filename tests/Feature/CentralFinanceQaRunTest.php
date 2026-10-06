<?php

namespace Tests\Feature;

use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceQaRun;
use App\Models\CentralFinanceQaRunRecord;
use App\Models\CentralFinanceUser;
use App\Models\CentralFinanceReceivable;
use App\Models\School;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceQaRunService;
use App\Services\CentralFinanceQaSchoolIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CentralFinanceQaRunTest extends TestCase
{
    private string $database;
    private School $school;
    private CentralFinanceUser $actor;
    private CentralFinanceQaRunService $runs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_qa_run_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('schools', function (Blueprint $table): void { $table->id(); $table->string('name')->default('fixture'); $table->string('code')->default('MMBOWEN01'); $table->string('database_name')->default('qa_tenant'); $table->boolean('installed')->default(true); $table->string('status')->default('active'); $table->softDeletes(); $table->timestamps(); });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void { $table->id(); $table->string('name')->default('actor'); $table->softDeletes(); $table->timestamps(); });
        Schema::connection('mysql')->create('central_finance_document_audits', function (Blueprint $table): void {
            $table->id(); $table->uuid('audit_uuid')->nullable(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('group_id')->nullable();
            $table->string('document_type'); $table->unsignedBigInteger('document_id'); $table->string('action'); $table->unsignedBigInteger('actor_id');
            $table->string('reason'); $table->json('before_values')->nullable(); $table->json('after_values')->nullable(); $table->timestamps();
        });
        $classificationMigration = require database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php');
        $classificationMigration->up();
        $migration = require database_path('migrations/2026_10_05_000001_create_central_finance_qa_runs.php');
        $migration->up();
        $this->school = School::on('mysql')->create(['id' => 1, 'name' => 'Zixuan', 'code' => 'MMBOWEN01', 'database_name' => 'qa_tenant', 'installed' => true, 'status' => 'active']);
        $this->actor = CentralFinanceUser::on('mysql')->create(['id' => 1, 'name' => 'QA Owner']);
        CentralFinanceDataClassification::on('mysql')->create([
            'school_id' => 1, 'subject_scope' => 'central', 'subject_type' => 'school', 'subject_id' => 1,
            'classification' => CentralFinanceDataClassification::QA_TEST, 'reason' => 'QA Run fixture.', 'classified_by' => 1,
        ]);
        Config::set('finance_release.p31_p32_tenants.MMBOWEN01', 'qa_tenant');
        $this->runs = new CentralFinanceQaRunService(new CentralFinanceQaSchoolIdentity());
        $this->app->instance(CentralFinanceQaRunService::class, $this->runs);
        $this->app->instance(CentralFinanceDataIsolationService::class, new CentralFinanceDataIsolationService());
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_lifecycle_is_preparing_active_completed_archived_and_retains_membership(): void
    {
        $run = $this->runs->create($this->actor, 1, 'run 1');
        $this->assertSame(CentralFinanceQaRun::PREPARING, $run->status);
        CentralFinanceQaRunRecord::on('mysql')->create(['qa_run_id' => $run->id, 'school_id' => 1, 'subject_scope' => 'tenant:1', 'subject_type' => 'student', 'subject_id' => 101]);
        $run = $this->runs->activate($this->actor, $run->id);
        $this->assertSame(CentralFinanceQaRun::ACTIVE, $run->status);
        $run = $this->runs->complete($this->actor, $run->id);
        $this->assertSame(CentralFinanceQaRun::COMPLETED, $run->status);
        try {
            $this->runs->create($this->actor, 1, 'too early');
            $this->fail('A completed Run must be archived before a new Run starts.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame(CentralFinanceQaRun::COMPLETED, $run->fresh()->status);
        }
        $run = $this->runs->archive($this->actor, $run->id, 'E2E complete');
        $this->assertSame(CentralFinanceQaRun::ARCHIVED, $run->status);
        $this->assertDatabaseHas('central_finance_qa_run_records', ['qa_run_id' => $run->id, 'subject_id' => 101], 'mysql');
        $this->assertDatabaseHas('central_finance_document_audits', ['document_type' => 'central_finance_qa_run', 'action' => 'archived'], 'mysql');
        $next = $this->runs->create($this->actor, 1, 'run 2');
        $this->assertSame(2, (int) $next->run_number);
        $this->assertNotSame((int) $run->id, (int) $next->id);
        $this->assertDatabaseHas('central_finance_qa_run_records', ['qa_run_id' => $run->id, 'subject_id' => 101], 'mysql');
        $this->expectException(RuntimeException::class);
        $run->delete();
    }

    public function test_membership_cannot_be_updated_or_moved_and_runs_cannot_skip_states(): void
    {
        $run = $this->runs->create($this->actor, 1, 'run');
        $membership = CentralFinanceQaRunRecord::on('mysql')->create(['qa_run_id' => $run->id, 'school_id' => 1, 'subject_scope' => 'tenant:1', 'subject_type' => 'student', 'subject_id' => 102]);
        try {
            $membership->update(['qa_run_id' => 2]);
            $this->fail('Membership mutation should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $run->status = CentralFinanceQaRun::ARCHIVED;
        $this->expectException(RuntimeException::class);
        $run->save();
    }

    public function test_permanent_school_cannot_be_reclassified_as_official(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->runs->assertSchoolClassification(1, CentralFinanceDataClassification::PRODUCTION);
    }

    public function test_double_import_reservation_converges_and_mixed_run_payment_is_rejected(): void
    {
        $run1 = $this->runs->create($this->actor, 1, 'run 1');
        $firstReservation = $this->runs->reserveStudentImport(1, 'fixture-import-1');
        $secondReservation = $this->runs->reserveStudentImport(1, 'fixture-import-1');
        $this->assertSame($run1->id, $firstReservation?->id);
        $this->assertSame($firstReservation?->id, $secondReservation?->id);
        $this->assertSame(1, CentralFinanceQaRunRecord::on('mysql')->where('subject_type', 'student_import_reference')->count());

        $run2 = CentralFinanceQaRun::on('mysql')->create([
            'run_uuid' => (string) \Illuminate\Support\Str::uuid(), 'school_id' => 1, 'run_number' => 2,
            'label' => 'run 2', 'status' => CentralFinanceQaRun::ACTIVE, 'created_by' => $this->actor->id,
        ]);
        CentralFinanceQaRunRecord::on('mysql')->create(['qa_run_id' => $run1->id, 'school_id' => 1, 'subject_scope' => 'central', 'subject_type' => 'receivable', 'subject_id' => 501]);
        CentralFinanceQaRunRecord::on('mysql')->create(['qa_run_id' => $run2->id, 'school_id' => 1, 'subject_scope' => 'central', 'subject_type' => 'receivable', 'subject_id' => 502]);
        $this->expectException(AuthorizationException::class);
        DB::connection('mysql')->transaction(fn () => $this->runs->lockActiveRunForCentralRecords(1, 'receivable', [501, 502]));
    }

    public function test_linked_student_import_reservation_does_not_block_run_activation(): void
    {
        $run = $this->runs->create($this->actor, 1, 'run with imported Student');
        $reference = 'fixture-import-linked';
        $this->runs->reserveStudentImport(1, $reference);
        Schema::connection('mysql')->create('central_finance_student_profiles', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('tenant_student_id');
        });
        DB::connection('mysql')->table('central_finance_student_profiles')->insert(['id' => 91, 'school_id' => 1, 'tenant_student_id' => 901]);
        Schema::connection('mysql')->create('central_finance_receivables', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_profile_id');
        });
        DB::connection('mysql')->table('central_finance_receivables')->insert(['id' => 701, 'school_id' => 1, 'student_profile_id' => 91]);
        foreach ([
            ['school_id' => 1, 'subject_scope' => 'tenant:1', 'subject_type' => 'student', 'subject_id' => 901],
            ['school_id' => 1, 'subject_scope' => 'central', 'subject_type' => 'student_profile', 'subject_id' => 91],
        ] as $classification) {
            CentralFinanceDataClassification::on('mysql')->create($classification + [
                'classification' => CentralFinanceDataClassification::QA_TEST,
                'reason' => 'QA Run fixture.', 'classified_by' => 1,
            ]);
        }
        $this->assertDatabaseHas('central_finance_data_classifications', [
            'subject_scope' => 'central', 'subject_type' => 'student_profile', 'subject_id' => 91,
            'classification' => CentralFinanceDataClassification::QA_TEST,
        ], 'mysql');

        $this->runs->linkImportedStudents($this->actor, 1, (int) $run->id, [[
            'student_id' => 901, 'import_reference' => $reference,
        ]]);

        $activated = $this->runs->activate($this->actor, (int) $run->id);
        $this->assertSame(CentralFinanceQaRun::ACTIVE, $activated->status);
        $this->assertSame(1, CentralFinanceQaRunRecord::on('mysql')->where([
            'qa_run_id' => $run->id, 'subject_type' => 'student', 'subject_id' => 901,
        ])->count());
        $this->assertDatabaseHas('central_finance_qa_run_records', [
            'qa_run_id' => $run->id, 'subject_scope' => 'central', 'subject_type' => 'student_profile', 'subject_id' => 91,
        ], 'mysql');
        $this->assertDatabaseHas('central_finance_qa_run_records', [
            'qa_run_id' => $run->id, 'subject_scope' => 'central', 'subject_type' => 'receivable', 'subject_id' => 701,
        ], 'mysql');
        $this->assertDatabaseHas('central_finance_data_classifications', [
            'subject_scope' => 'central', 'subject_type' => 'receivable', 'subject_id' => 701,
            'classification' => CentralFinanceDataClassification::QA_TEST,
        ], 'mysql');
    }

    public function test_completed_run_rejects_new_tenant_finance_writes(): void
    {
        $run = $this->runs->create($this->actor, 1, 'run');
        CentralFinanceQaRunRecord::on('mysql')->create(['qa_run_id' => $run->id, 'school_id' => 1, 'subject_scope' => 'tenant:1', 'subject_type' => 'student', 'subject_id' => 601]);
        $this->runs->activate($this->actor, $run->id);
        $this->assertSame('held', $this->runs->withActiveTenantStudentRun(1, 601, fn () => 'held'));
        $this->runs->complete($this->actor, $run->id);
        $this->expectException(AuthorizationException::class);
        $this->runs->withActiveTenantStudentRun(1, 601, fn () => $this->fail('Closed Run callback must not execute.'));
    }

    public function test_official_view_excludes_run_records_even_with_legacy_qa_reveal(): void
    {
        Schema::connection('mysql')->create('central_finance_receivables', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); });
        DB::connection('mysql')->table('central_finance_receivables')->insert([
            ['id' => 701, 'school_id' => 1], ['id' => 702, 'school_id' => 2],
        ]);
        $run = $this->runs->create($this->actor, 1, 'run');
        CentralFinanceQaRunRecord::on('mysql')->create(['qa_run_id' => $run->id, 'school_id' => 1, 'subject_scope' => 'central', 'subject_type' => 'receivable', 'subject_id' => 701]);
        $query = CentralFinanceDataIsolationService::class;
        $rows = app($query)->apply(CentralFinanceReceivable::on('mysql'), 'receivable', true)->pluck('id')->all();
        $this->assertSame([702], $rows);
    }

    public function test_exact_central_migration_rehearses_on_disposable_sqlite_and_refuses_partial_state(): void
    {
        Config::set('app.url', 'http://localhost');
        Schema::connection('mysql')->dropIfExists('central_finance_qa_run_records');
        Schema::connection('mysql')->dropIfExists('central_finance_qa_runs');
        Schema::connection('mysql')->create('migrations', function (Blueprint $table): void { $table->id(); $table->string('migration'); $table->integer('batch'); });
        if (!Schema::connection('mysql')->hasTable('schools')) {
            Schema::connection('mysql')->create('schools', function (Blueprint $table): void { $table->id(); });
        }
        if (!Schema::connection('mysql')->hasTable('users')) {
            Schema::connection('mysql')->create('users', function (Blueprint $table): void { $table->id(); });
        }
        $this->artisan('finance:qa-runs-migrate')->assertExitCode(0);
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_qa_runs'));
        $this->artisan('finance:qa-runs-migrate', ['--execute' => true])->assertExitCode(0);
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_qa_run_records'));
        $this->artisan('finance:qa-runs-migrate')->assertExitCode(0);
        $this->artisan('finance:qa-runs-migrate', ['--execute' => true])->assertExitCode(0);
        $this->assertSame(1, DB::connection('mysql')->table('migrations')->where('migration', \App\Console\Commands\MigrateCentralFinanceQaRuns::MIGRATION)->count());
        $migrationPath = database_path('migrations/2026_10_05_000001_create_central_finance_qa_runs.php');
        $migrator = app('migrator');
        $migrator->usingConnection('mysql', function () use ($migrator, $migrationPath): void {
            $migrator->rollback([$migrationPath], ['pretend' => false, 'step' => false]);
        });
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_qa_runs'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_qa_run_records'));
        $this->artisan('finance:qa-runs-migrate')->assertExitCode(0);
        $this->artisan('finance:qa-runs-migrate')->expectsOutputToContain('central=eligible')->assertExitCode(0);
        $this->artisan('finance:qa-runs-migrate', ['--execute' => true])->assertExitCode(0);
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_qa_run_records'));
        Schema::connection('mysql')->drop('central_finance_qa_run_records');
        $this->artisan('finance:qa-runs-migrate', ['--execute' => true])->assertExitCode(1);
    }

    public function test_production_execute_confirms_before_database_preflight(): void
    {
        $previousEnvironment = $this->app['env'];
        $previousConnection = Config::get('database.connections.mysql');
        DB::purge('mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'mysql', 'host' => '192.0.2.1', 'port' => 3306,
            'database' => 'must_not_connect_before_confirmation', 'username' => 'root', 'password' => '',
        ]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'));

        try {
            $exitCode = \Illuminate\Support\Facades\Artisan::call('finance:qa-runs-migrate', ['--execute' => true]);
            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Command cancelled.', \Illuminate\Support\Facades\Artisan::output());
        } finally {
            $this->app['env'] = $previousEnvironment;
            Config::set('database.connections.mysql', $previousConnection);
            DB::purge('mysql');
        }
    }
}
