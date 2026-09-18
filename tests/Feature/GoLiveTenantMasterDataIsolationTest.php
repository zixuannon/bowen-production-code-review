<?php

namespace Tests\Feature;

use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceDataIsolationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class GoLiveTenantMasterDataIsolationTest extends TestCase
{
    private string $centralDatabase;
    private string $bahanDatabase;
    private string $timecityDatabase;
    private array $originalMysql;
    private array $originalSchool;
    private CentralFinanceDataIsolationService $isolation;
    private CentralFinanceUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centralDatabase = tempnam(sys_get_temp_dir(), 'go_live_central_');
        $this->bahanDatabase = tempnam(sys_get_temp_dir(), 'go_live_bahan_');
        $this->timecityDatabase = tempnam(sys_get_temp_dir(), 'go_live_timecity_');
        $this->originalMysql = config('database.connections.mysql');
        $this->originalSchool = config('database.connections.school');

        Config::set('database.connections.mysql', $this->sqlite($this->centralDatabase));
        DB::purge('mysql');
        Schema::connection('mysql')->create('schools', fn (Blueprint $table) => [
            $table->id(), $table->string('name'), $table->string('code'), $table->string('database_name'),
            $table->softDeletes(), $table->timestamps(),
        ]);
        Schema::connection('mysql')->create('users', fn (Blueprint $table) => [
            $table->id(), $table->string('first_name')->nullable(), $table->string('last_name')->nullable(),
            $table->string('email')->nullable(), $table->unsignedBigInteger('school_id')->nullable(),
            $table->softDeletes(), $table->timestamps(),
        ]);
        DB::connection('mysql')->table('schools')->insert([
            ['id'=>17, 'name'=>'Bahan', 'code'=>'MMBOWEN02', 'database_name'=>$this->bahanDatabase, 'created_at'=>now(), 'updated_at'=>now()],
            ['id'=>19, 'name'=>'Timecity', 'code'=>'MMBOWEN03', 'database_name'=>$this->timecityDatabase, 'created_at'=>now(), 'updated_at'=>now()],
        ]);
        DB::connection('mysql')->table('users')->insert([
            'id'=>100, 'first_name'=>'Head', 'last_name'=>'Finance', 'email'=>'head@example.test',
            'school_id'=>null, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        (require database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php'))->up();
        Schema::connection('mysql')->create('central_finance_school_staff_identities', fn (Blueprint $table) => [
            $table->id(), $table->uuid('identity_uuid'), $table->unsignedBigInteger('school_id'),
            $table->uuid('tenant_user_uuid'), $table->unsignedBigInteger('central_user_id'),
            $table->string('status'), $table->timestamps(),
        ]);
        $this->createTenant($this->bahanDatabase);
        $this->createTenant($this->timecityDatabase);

        $this->isolation = app(CentralFinanceDataIsolationService::class);
        $this->actor = CentralFinanceUser::on('mysql')->findOrFail(100);
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        DB::purge('mysql');
        Config::set('database.connections.school', $this->originalSchool);
        Config::set('database.connections.mysql', $this->originalMysql);
        foreach ([$this->centralDatabase, $this->bahanDatabase, $this->timecityDatabase] as $database) {
            @unlink($database);
        }
        parent::tearDown();
    }

    public function test_tenant_master_classification_is_school_scoped_audited_and_non_destructive(): void
    {
        foreach (['student', 'staff', 'fee', 'fee_type', 'fee_item'] as $subjectType) {
            $this->isolation->classify(
                $this->actor, 17, $subjectType, 2,
                CentralFinanceDataClassification::QA_TEST,
                'QA master retained for historical traceability.',
            );
        }

        $this->assertSame(5, DB::connection('mysql')->table('central_finance_data_classifications')
            ->where('subject_scope', 'tenant:17')->where('classification', 'qa_test')->count());
        $this->assertSame(5, DB::connection('mysql')->table('central_finance_data_classification_audits')
            ->where('subject_scope', 'tenant:17')->where('after_classification', 'qa_test')->count());
        $this->assertSame('production', $this->isolation->classification('student', 2, 'tenant:19'));

        $this->useTenant($this->bahanDatabase);
        foreach (['student'=>'students', 'staff'=>'users', 'fee'=>'fees', 'fee_type'=>'fees_types', 'fee_item'=>'fees_class_types'] as $type => $table) {
            $query = DB::connection('school')->table($table);
            $this->isolation->applyTenant($query, $type, 17);
            $this->assertSame([1], $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(), $type);

            $history = DB::connection('school')->table($table);
            $this->isolation->applyTenant($history, $type, 17, true);
            $this->assertSame([1, 2], $history->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(), $type);
            $this->assertSame(2, DB::connection('school')->table($table)->count(), "{$type} history changed");
        }

        $this->useTenant($this->timecityDatabase);
        $timecityStudents = DB::connection('school')->table('students');
        $this->isolation->applyTenant($timecityStudents, 'student', 19);
        $this->assertSame([1, 2], $timecityStudents->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_classification_update_is_append_only_and_registry_mismatch_fails_closed(): void
    {
        $this->isolation->classify($this->actor, 17, 'student', 2, 'qa_test', 'QA student.');
        $this->isolation->classify($this->actor, 17, 'student', 2, 'archived', 'Archive after QA completion.');
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_data_classification_audits')
            ->where(['subject_scope'=>'tenant:17', 'subject_type'=>'student', 'subject_id'=>2])->count());
        $this->assertDatabaseHas('central_finance_data_classification_audits', [
            'subject_scope'=>'tenant:17', 'subject_type'=>'student', 'subject_id'=>2,
            'before_classification'=>'qa_test', 'after_classification'=>'archived',
        ], 'mysql');

        $this->useTenant($this->timecityDatabase);
        $before = DB::connection('mysql')->table('central_finance_data_classifications')->count();
        try {
            $this->isolation->applyTenant(DB::connection('school')->table('students'), 'student', 17);
            $this->fail('Wrong tenant database mapping must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('registry mismatch', $exception->getMessage());
        }
        $this->assertSame($before, DB::connection('mysql')->table('central_finance_data_classifications')->count());
    }

    public function test_central_staff_identity_is_hidden_but_remains_queryable_in_history_mode(): void
    {
        DB::connection('mysql')->table('users')->insert([
            'id'=>101, 'first_name'=>'QA', 'last_name'=>'Front Desk', 'email'=>'qa-frontdesk@example.test',
            'school_id'=>17, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        DB::connection('mysql')->table('central_finance_school_staff_identities')->insert([
            'id'=>50, 'identity_uuid'=>'00000000-0000-4000-8000-000000000050', 'school_id'=>17,
            'tenant_user_uuid'=>'00000000-0000-4000-8000-000000000002', 'central_user_id'=>101,
            'status'=>'active', 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $this->isolation->classify($this->actor, 17, 'central_staff_identity', 50, 'qa_test', 'QA Front Desk identity.');

        $default = CentralFinanceUser::on('mysql')->whereIn('id', [100, 101]);
        $this->isolation->applyCentralStaffUsers($default, 17);
        $this->assertSame([100], $default->pluck('id')->map(fn ($id) => (int) $id)->all());

        $history = CentralFinanceUser::on('mysql')->whereIn('id', [100, 101]);
        $this->isolation->applyCentralStaffUsers($history, 17, true);
        $this->assertEqualsCanonicalizing([100, 101], $history->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, DB::connection('mysql')->table('users')->whereIn('id', [100, 101])->count());
    }

    public function test_active_qa_school_metadata_is_workflow_writable_but_archived_fixture_is_not(): void
    {
        $this->isolation->classify($this->actor, 17, 'school', 17, 'qa_test', 'Permanent QA School.');
        $this->assertTrue($this->isolation->isTenantMetadataWorkflowWritable('student', 17, 1));
        $this->assertFalse($this->isolation->isTenantMetadataProduction('student', 17, 1));

        $this->isolation->classify($this->actor, 17, 'student', 1, 'archived', 'Expired QA fixture.');
        $this->assertFalse($this->isolation->isTenantMetadataWorkflowWritable('student', 17, 1));
    }

    private function createTenant(string $database): void
    {
        $this->useTenant($database);
        foreach (['students', 'fees', 'fees_types', 'fees_class_types'] as $table) {
            Schema::connection('school')->create($table, fn (Blueprint $schema) => [
                $schema->id(), $schema->string('name')->nullable(), $schema->timestamps(),
            ]);
            DB::connection('school')->table($table)->insert([
                ['id'=>1, 'name'=>'Production', 'created_at'=>now(), 'updated_at'=>now()],
                ['id'=>2, 'name'=>'QA Test', 'created_at'=>now(), 'updated_at'=>now()],
            ]);
        }
        Schema::connection('school')->create('users', fn (Blueprint $table) => [
            $table->id(), $table->string('name')->nullable(), $table->uuid('central_finance_source_uuid')->nullable(), $table->timestamps(),
        ]);
        DB::connection('school')->table('users')->insert([
            ['id'=>1, 'name'=>'Production', 'central_finance_source_uuid'=>'00000000-0000-4000-8000-000000000001', 'created_at'=>now(), 'updated_at'=>now()],
            ['id'=>2, 'name'=>'QA Test', 'central_finance_source_uuid'=>'00000000-0000-4000-8000-000000000002', 'created_at'=>now(), 'updated_at'=>now()],
        ]);
        Schema::connection('school')->create('staffs', fn (Blueprint $table) => [
            $table->id(), $table->unsignedBigInteger('user_id'), $table->timestamps(),
        ]);
        DB::connection('school')->table('staffs')->insert([
            ['id'=>1, 'user_id'=>1, 'created_at'=>now(), 'updated_at'=>now()],
            ['id'=>2, 'user_id'=>2, 'created_at'=>now(), 'updated_at'=>now()],
        ]);
    }

    private function useTenant(string $database): void
    {
        Config::set('database.connections.school', $this->sqlite($database));
        DB::purge('school');
    }

    private function sqlite(string $database): array
    {
        return ['driver'=>'sqlite', 'database'=>$database, 'prefix'=>'', 'foreign_key_constraints'=>true];
    }
}
