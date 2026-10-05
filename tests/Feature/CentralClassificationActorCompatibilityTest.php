<?php

namespace Tests\Feature;

use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceDataClassificationAudit;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceDataIsolationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CentralClassificationActorCompatibilityTest extends TestCase
{
    private array $originalMysql;
    private array $originalSchool;
    private string $tenantDatabase;
    private CentralFinanceUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMysql = config('database.connections.mysql');
        $this->originalSchool = config('database.connections.school');
        $this->tenantDatabase = tempnam(sys_get_temp_dir(), 'classification_actor_');
        $sqlite = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        Config::set('database.connections.mysql', $sqlite);
        Config::set('database.connections.school', array_replace($sqlite, ['database' => $this->tenantDatabase]));
        DB::purge('mysql');
        DB::purge('school');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('database_name');
            $table->softDeletes();
        });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->softDeletes();
        });
        DB::connection('mysql')->table('schools')->insert(['id' => 17, 'database_name' => $this->tenantDatabase]);
        DB::connection('mysql')->table('users')->insert(['id' => 100, 'school_id' => null]);
        (require database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php'))->up();
        Schema::connection('school')->create('users', fn (Blueprint $table) => $table->id());
        Schema::connection('school')->create('staffs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        DB::connection('school')->table('users')->insert(['id' => 2]);
        DB::connection('school')->table('staffs')->insert(['id' => 1, 'user_id' => 2]);
        $this->actor = CentralFinanceUser::on('mysql')->findOrFail(100);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        DB::purge('school');
        Config::set('database.connections.mysql', $this->originalMysql);
        Config::set('database.connections.school', $this->originalSchool);
        @unlink($this->tenantDatabase);
        parent::tearDown();
    }

    public function test_central_reclassification_replaces_tenant_attribution_without_rewriting_history(): void
    {
        (require database_path('migrations/2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php'))->up();
        $identity = ['school_id' => 17, 'subject_scope' => 'tenant:17', 'subject_type' => 'staff', 'subject_id' => 2];
        $tenantActor = ['actor_scope' => 'tenant', 'actor_school_id' => 17, 'actor_tenant_user_id' => 25];
        $record = new CentralFinanceDataClassification();
        $record->forceFill($identity + $tenantActor + [
            'classification' => 'qa_test', 'reason' => 'Inherited QA Staff classification.', 'classified_by' => null,
        ])->save();
        $audit = new CentralFinanceDataClassificationAudit();
        $audit->forceFill($identity + $tenantActor + [
            'classification_id' => $record->id, 'before_classification' => null,
            'after_classification' => 'qa_test', 'reason' => $record->reason,
            'actor_id' => null, 'action' => 'staff_created',
        ])->save();
        $historical = (array) DB::connection('mysql')->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->find($audit->id);

        $updated = $this->classify('archived');

        $this->assertSame($record->id, $updated->id);
        $this->assertSame('central', $updated->actor_scope);
        $this->assertSame(100, (int) $updated->classified_by);
        $this->assertNull($updated->actor_school_id);
        $this->assertNull($updated->actor_tenant_user_id);
        $this->assertSame('archived', $updated->classification);
        $this->assertSame($historical, (array) DB::connection('mysql')
            ->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->find($audit->id));
        $this->assertDatabaseHas(CentralFinanceDataIsolationService::AUDIT_TABLE, [
            'classification_id' => $record->id, 'before_classification' => 'qa_test',
            'after_classification' => 'archived', 'actor_id' => 100,
            'actor_scope' => 'central', 'actor_school_id' => null, 'actor_tenant_user_id' => null,
            'action' => 'classification_changed',
        ], 'mysql');
        $this->assertSame(2, DB::connection('mysql')->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->count());
        $this->assertSame(1, DB::connection('school')->table('users')->count());
        $this->assertSame(1, DB::connection('school')->table('staffs')->count());
    }

    public function test_new_central_classification_records_explicit_actor_metadata(): void
    {
        (require database_path('migrations/2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php'))->up();

        $record = $this->classify('qa_test');

        $this->assertSame('central', $record->actor_scope);
        $this->assertNull($record->actor_school_id);
        $this->assertNull($record->actor_tenant_user_id);
        $this->assertDatabaseHas(CentralFinanceDataIsolationService::AUDIT_TABLE, [
            'classification_id' => $record->id, 'before_classification' => null,
            'actor_id' => 100, 'actor_scope' => 'central', 'action' => 'classification_changed',
        ], 'mysql');
    }

    public function test_old_schema_still_creates_and_reclassifies_with_legacy_central_actor_fields(): void
    {
        $record = $this->classify('qa_test');
        $historical = DB::connection('mysql')->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->first();

        $updated = $this->classify('archived');

        $this->assertSame($record->id, $updated->id);
        $this->assertSame(100, (int) $updated->classified_by);
        $this->assertSame('archived', $updated->classification);
        $this->assertSame((array) $historical, (array) DB::connection('mysql')
            ->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->find($historical->id));
        $this->assertDatabaseHas(CentralFinanceDataIsolationService::AUDIT_TABLE, [
            'classification_id' => $record->id, 'before_classification' => 'qa_test',
            'after_classification' => 'archived', 'actor_id' => 100,
        ], 'mysql');
        $this->assertSame(2, DB::connection('mysql')->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->count());
    }

    public function test_actor_columns_are_detected_independently_during_additive_rollout(): void
    {
        Schema::connection('mysql')->table(CentralFinanceDataIsolationService::TABLE, function (Blueprint $table): void {
            $table->string('actor_scope')->nullable();
            $table->unsignedBigInteger('actor_school_id')->nullable();
        });
        Schema::connection('mysql')->table(CentralFinanceDataIsolationService::AUDIT_TABLE, function (Blueprint $table): void {
            $table->string('action')->nullable();
        });
        $record = $this->classify('qa_test');
        DB::connection('mysql')->table(CentralFinanceDataIsolationService::TABLE)->where('id', $record->id)
            ->update(['actor_scope' => 'tenant', 'actor_school_id' => 17]);

        $updated = $this->classify('archived');

        $this->assertSame('central', $updated->actor_scope);
        $this->assertNull($updated->actor_school_id);
        $this->assertDatabaseHas(CentralFinanceDataIsolationService::AUDIT_TABLE, [
            'classification_id' => $record->id, 'after_classification' => 'archived',
            'actor_id' => 100, 'action' => 'classification_changed',
        ], 'mysql');
    }

    private function classify(string $classification): CentralFinanceDataClassification
    {
        return app(CentralFinanceDataIsolationService::class)->classify(
            $this->actor, 17, 'staff', 2, $classification, 'Central classification decision.',
        );
    }
}
