<?php

namespace Tests\Feature;

use App\Http\Controllers\FeesController;
use App\Http\Controllers\FeesTypeController;
use App\Models\Fee;
use App\Models\FeesType;
use App\Models\User;
use App\Services\CachingService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\FeaturesService;
use App\Services\QaFeeClassificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Real HTTP controller + InnoDB transactions; each fixture is a unique local School. */
final class QaFeeLifecycleTest extends TestCase
{
    private int $schoolId;
    private int $classId;
    private int $yearId;
    private User $actor;
    private array $createdMigrations = [];
    private bool $failAudit = false;
    private ?string $failAuditSubject = null;
    private ?array $allowedPermissions = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['mysql' => 'eschool_testing', 'school' => 'school_testing'] as $connection => $database) {
            $this->assertSame($database, config('database.connections.'.$connection.'.database'));
            $this->assertContains(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost']);
        }
        foreach ([
            ['2026_09_14_000003_create_central_finance_data_classifications.php', CentralFinanceDataIsolationService::TABLE, null],
            ['2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php', CentralFinanceDataIsolationService::TABLE, 'actor_scope'],
        ] as [$file, $table, $column]) {
            if ($column ? !Schema::connection('mysql')->hasColumn($table, $column) : !Schema::connection('mysql')->hasTable($table)) {
                (require database_path('migrations/'.$file))->up();
                $this->createdMigrations[] = $file;
            }
        }
        $this->schoolId = DB::connection('mysql')->table('schools')->insertGetId([
            'name' => 'Disposable Fee Lifecycle', 'code' => 'QAF'.Str::random(12), 'database_name' => 'school_testing',
            'address' => 'Local only', 'support_phone' => '0', 'support_email' => 'fixture@local.test',
            'installed' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::setDefaultConnection('school');
        DB::table('schools')->insert(['id' => $this->schoolId, 'name' => 'Disposable Fee Lifecycle', 'code' => 'QAF'.$this->schoolId]);
        $this->actor = User::create(['first_name' => 'Fee', 'last_name' => 'Fixture', 'email' => Str::uuid().'@local.test',
            'password' => 'disposable', 'school_id' => $this->schoolId, 'status' => 1]);
        $medium = DB::table('mediums')->insertGetId(['name' => 'Fee QA Medium', 'school_id' => $this->schoolId]);
        $this->classId = DB::table('classes')->insertGetId(['name' => 'Fee QA Class', 'medium_id' => $medium, 'school_id' => $this->schoolId]);
        $this->yearId = DB::table('session_years')->insertGetId(['name' => 'Fee QA Year', 'school_id' => $this->schoolId,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->classify('school', $this->schoolId, 'qa_test', 'central');
        $cache = Mockery::mock(CachingService::class);
        $cache->shouldReceive('getDefaultSessionYear')->andReturn((object) ['id' => $this->yearId]);
        $cache->shouldReceive('getDefaultSemesterData')->andReturn(null);
        $cache->shouldReceive('getSchoolSettings', 'getSystemSettings')->andReturn(collect());
        $this->app->instance(CachingService::class, $cache);
        $features = Mockery::mock(FeaturesService::class);
        $features->shouldReceive('hasFeature')->andReturn(true);
        $this->app->instance(FeaturesService::class, $features);
        Gate::before(fn ($user, string $permission) => $this->allowedPermissions === null || in_array($permission, $this->allowedPermissions, true));
        $this->withoutMiddleware();
        Route::any('/qa-fee-lifecycle/{controller}/{action}/{id?}', function (Request $request, string $controller, string $action, ?int $id = null) {
            Auth::setUser($this->actor);
            $request->attributes->set('_trusted_tenant_context_active', true);
            $request->attributes->set('trusted_tenant_school_id', $this->schoolId);
            $instance = app($controller === 'fee' ? FeesController::class : FeesTypeController::class);
            $result = match ($action) {
                'store', 'search' => $instance->$action($request),
                'show' => $instance->show(),
                'edit', 'deleteInstallment' => $instance->$action($id),
                default => $instance->$action($request, $id),
            };
            // Exercise the real edit lookup without unrelated global layout composers.
            return $action === 'edit' ? response()->json($result->getData()['fees']->toArray()) : $result;
        });
        DB::connection('school')->beforeExecuting(function (string $query, array $bindings): void {
            if ($this->failAudit && ($this->failAuditSubject === null || in_array($this->failAuditSubject, $bindings, true))
                && str_starts_with(strtolower($query), 'insert into') && str_contains($query, CentralFinanceDataIsolationService::AUDIT_TABLE)) {
                throw new \RuntimeException('Audit destination does not exist: injected rollback test.');
            }
        });
    }

    protected function tearDown(): void
    {
        $this->failAudit = false;
        while (DB::connection('school')->transactionLevel() > 0) DB::connection('school')->rollBack();
        if (isset($this->schoolId)) {
            // Only this test's unique disposable School is removed; no shared fixture rows.
            foreach ([CentralFinanceDataIsolationService::AUDIT_TABLE, CentralFinanceDataIsolationService::TABLE] as $table) {
                DB::connection('mysql')->table($table)->where('school_id', $this->schoolId)->delete();
            }
            if (isset($this->actor)) DB::connection('school')->table('school_record_lifecycle_audits')->where('actor_user_id', $this->actor->id)->delete();
            foreach (['session_years_trackings', 'fees_installments', 'fees_class_types', 'fees', 'fees_types', 'classes', 'mediums', 'session_years', 'users'] as $table) {
                DB::connection('school')->table($table)->where('school_id', $this->schoolId)->delete();
            }
            DB::connection('school')->table('schools')->where('id', $this->schoolId)->delete();
            DB::connection('mysql')->table('schools')->where('id', $this->schoolId)->delete();
        }
        foreach (array_reverse($this->createdMigrations) as $file) (require database_path('migrations/'.$file))->down();
        Auth::forgetUser();
        DB::setDefaultConnection('mysql');
        parent::tearDown();
    }

    public function test_http_qa_create_list_edit_and_same_fee_null_date_null_transition(): void
    {
        $type = $this->createType();
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertOk()->assertJsonPath('error', false);
        $fee = Fee::where('school_id', $this->schoolId)->sole();
        $item = DB::table('fees_class_types')->where('fees_id', $fee->id)->sole();
        foreach (['fee' => $fee->id, 'fee_type' => $type, 'fee_item' => $item->id] as $subject => $id) $this->assertClassification($subject, $id, 'qa_test');
        $this->assertNull($fee->getRawOriginal('due_date'));
        $this->assertSame(1, (int) $item->quantity_enabled);
        $this->getJson('/qa-fee-lifecycle/fee/show?search=Lifecycle')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('rows.0.id', $fee->id);
        $this->getJson('/qa-fee-lifecycle/fee/edit/'.$fee->id)->assertOk()->assertJsonPath('id', $fee->id)->assertJsonPath('due_date', null);
        foreach (['2026-12-31', null] as $date) {
            $payload = $this->payload($type);
            $payload['due_date'] = $date;
            $payload['compulsory_fees_type'][0]['quantity_enabled'] = $date === null ? 1 : 0;
            $this->put('/qa-fee-lifecycle/fee/update/'.$fee->id, $payload)->assertRedirect(route('fees.index'));
            $this->assertSame($date, DB::table('fees')->where('id', $fee->id)->value('due_date'));
            $this->assertSame(1, DB::table('fees')->where('school_id', $this->schoolId)->count());
            $this->assertSame(1, DB::table('fees_class_types')->where('fees_id', $fee->id)->count());
            $this->assertSame($date === null ? 1 : 0, (int) DB::table('fees_class_types')->where('id', $item->id)->value('quantity_enabled'));
        }
        $this->getJson('/qa-fee-lifecycle/fee/search?session_year_id='.$this->yearId)->assertOk()->assertJsonPath('data.0.id', $fee->id);
        $this->assertSame(3, $this->metadata(CentralFinanceDataIsolationService::AUDIT_TABLE)->count());
    }

    public function test_payment_history_reader_can_search_classified_fees_without_fee_management_permission(): void
    {
        $type = $this->createType();
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', false);
        $fee = Fee::where('school_id', $this->schoolId)->sole();
        $this->allowedPermissions = ['finance-payment-view'];
        $this->getJson('/qa-fee-lifecycle/fee/search?session_year_id='.$this->yearId)->assertOk()->assertJsonPath('data.0.id', $fee->id);
        $this->classify('fee', $fee->id, 'production');
        $this->getJson('/qa-fee-lifecycle/fee/search?session_year_id='.$this->yearId)->assertOk()->assertJsonPath('data', []);
        $this->allowedPermissions = [];
        $this->getJson('/qa-fee-lifecycle/fee/search?session_year_id='.$this->yearId)->assertForbidden();
    }

    public function test_official_school_new_fee_uses_production_classification_and_rejects_qa_type(): void
    {
        $qaType = $this->createType();
        $this->classify('school', $this->schoolId, 'production', 'central');
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($qaType))->assertJsonPath('error', true);
        $type = $this->createType();
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', false);
        $fee = Fee::where('school_id', $this->schoolId)->sole();
        $this->assertClassification('fee', $fee->id, 'production');
        $this->assertClassification('fee_type', $type, 'production');
        $this->getJson('/qa-fee-lifecycle/type/show')->assertJsonPath('total', 1)->assertJsonPath('rows.0.id', $type);
    }

    public function test_qa_type_selection_rejects_unclassified_archived_and_official_and_preserves_existing_fee(): void
    {
        $type = $this->createType();
        foreach (['production', 'archived', null] as $classification) {
            if ($classification === null) $type = (int) FeesType::create(['name' => 'Historical unclassified', 'school_id' => $this->schoolId])->id;
            else $this->classify('fee_type', $type, $classification);
            $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', true);
            $this->assertSame(0, DB::table('fees')->where('school_id', $this->schoolId)->count());
        }
    }

    public function test_audit_failure_rolls_back_fee_item_and_classification_even_for_legacy_commit_error_text(): void
    {
        $type = $this->createType();
        $before = $this->metadata(CentralFinanceDataIsolationService::TABLE)->count();
        $this->failAudit = true;
        $this->failAuditSubject = 'fee_item';
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', true);
        $this->failAudit = false;
        $this->assertSame(0, DB::table('fees')->where('school_id', $this->schoolId)->count());
        $this->assertSame(0, DB::table('fees_class_types')->where('school_id', $this->schoolId)->count());
        $this->assertSame($before, $this->metadata(CentralFinanceDataIsolationService::TABLE)->count());
        $this->assertSame(1, $this->metadata(CentralFinanceDataIsolationService::AUDIT_TABLE)->count());
    }

    public function test_direct_id_lifecycle_denies_archived_and_unclassified_records_without_backfill(): void
    {
        $type = $this->createType();
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', false);
        $fee = Fee::where('school_id', $this->schoolId)->sole();
        $this->classify('fee', $fee->id, 'archived');
        $this->getJson('/qa-fee-lifecycle/fee/edit/'.$fee->id)->assertNotFound();
        $this->postJson('/qa-fee-lifecycle/fee/deactivate/'.$fee->id, ['reason' => 'Fixture'])->assertJsonPath('error', true);
        $this->put('/qa-fee-lifecycle/fee/update/'.$fee->id, $this->payload($type))->assertRedirect(route('fees.edit', $fee->id));
        $this->assertNull(DB::table('fees')->where('id', $fee->id)->value('deleted_at'));
        $fee = Fee::create(['name' => 'Historical unclassified', 'school_id' => $this->schoolId, 'class_id' => $this->classId,
            'session_year_id' => $this->yearId, 'due_date' => null, 'due_charges' => 0, 'due_charges_amount' => 0]);
        $this->getJson('/qa-fee-lifecycle/fee/show')->assertJsonPath('total', 0);
        $this->postJson('/qa-fee-lifecycle/fee/reactivate/'.$fee->id, ['reason' => 'Fixture'])->assertJsonPath('error', true);
        $this->assertSame(0, $this->metadata(CentralFinanceDataIsolationService::TABLE)->where('subject_type', 'fee')->where('subject_id', $fee->id)->count());
    }

    public function test_active_qa_lifecycle_preserves_metadata_and_audits_both_transitions(): void
    {
        $type = $this->createType();
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', false);
        $fee = Fee::where('school_id', $this->schoolId)->sole();
        $before = $this->metadata(CentralFinanceDataIsolationService::TABLE)->get()->toJson();
        $this->postJson('/qa-fee-lifecycle/fee/deactivate/'.$fee->id, ['reason' => 'Stop fixture'])->assertJsonPath('error', false);
        $this->getJson('/qa-fee-lifecycle/fee/show?show_deleted=1')->assertJsonPath('total', 1);
        $this->postJson('/qa-fee-lifecycle/fee/reactivate/'.$fee->id, ['reason' => 'Resume fixture'])->assertJsonPath('error', false);
        $this->postJson('/qa-fee-lifecycle/type/deactivate/'.$type, ['reason' => 'Stop fixture'])->assertJsonPath('error', false);
        $this->postJson('/qa-fee-lifecycle/type/reactivate/'.$type, ['reason' => 'Resume fixture'])->assertJsonPath('error', false);
        $this->assertSame($before, $this->metadata(CentralFinanceDataIsolationService::TABLE)->get()->toJson());
        $this->assertSame(4, DB::table('school_record_lifecycle_audits')->where('actor_user_id', $this->actor->id)->count());
    }

    public function test_creation_only_api_refuses_historical_record_and_untrusted_actor(): void
    {
        $type = $this->createType();
        DB::beginTransaction();
        try {
            app(QaFeeClassificationService::class)->inheritCreated('fee_type', FeesType::findOrFail($type), $this->actor);
            $this->fail('Historical record accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('new Fee creation', $exception->getMessage());
        } finally { DB::rollBack(); }
        $this->assertSame(1, $this->metadata(CentralFinanceDataIsolationService::AUDIT_TABLE)->count());
        request()->attributes->set('_trusted_tenant_context_active', false);
        try {
            DB::transaction(function (): void {
                $target = FeesType::create(['name' => 'Untrusted fixture', 'school_id' => $this->schoolId]);
                app(QaFeeClassificationService::class)->inheritCreated('fee_type', $target, $this->actor);
            });
            $this->fail('Untrusted actor accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Trusted tenant Fee classification actor', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('fees_types')->where('school_id', $this->schoolId)->count());
    }

    public function test_foreign_school_type_and_archived_type_direct_updates_are_rejected(): void
    {
        $type = $this->createType();
        $originalName = DB::table('fees_types')->where('id', $type)->value('name');
        DB::table('fees_types')->where('id', $type)->update(['school_id' => 1]);
        try {
            $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', true);
            $this->putJson('/qa-fee-lifecycle/type/update/'.$type, ['edit_name' => 'Tampered'])->assertJsonPath('error', true);
            $this->assertSame($originalName, DB::table('fees_types')->where('id', $type)->value('name'));
        } finally {
            DB::table('fees_types')->where('id', $type)->update(['school_id' => $this->schoolId]);
        }
        $this->classify('fee_type', $type, 'archived');
        $this->putJson('/qa-fee-lifecycle/type/update/'.$type, ['edit_name' => 'Tampered'])->assertJsonPath('error', true);
        $this->postJson('/qa-fee-lifecycle/type/deactivate/'.$type, ['reason' => 'Fixture'])->assertJsonPath('error', true);
        $this->assertSame($originalName, DB::table('fees_types')->where('id', $type)->value('name'));
    }

    public function test_archived_item_blocks_configuration_update_and_item_lifecycle(): void
    {
        $type = $this->createType();
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', false);
        $fee = Fee::where('school_id', $this->schoolId)->sole();
        $item = DB::table('fees_class_types')->where('fees_id', $fee->id)->sole();
        $this->classify('fee_item', $item->id, 'archived');
        $payload = $this->payload($type);
        $payload['due_date'] = '2026-12-31';
        $payload['compulsory_fees_type'][0]['amount'] = 9999;
        $this->put('/qa-fee-lifecycle/fee/update/'.$fee->id, $payload)->assertRedirect(route('fees.edit', $fee->id));
        $this->postJson('/qa-fee-lifecycle/fee/deactivateClassType/'.$item->id, ['reason' => 'Fixture'])->assertJsonPath('error', true);
        $this->assertNull(DB::table('fees')->where('id', $fee->id)->value('due_date'));
        $this->assertSame(1000.0, (float) DB::table('fees_class_types')->where('id', $item->id)->value('amount'));
        $this->assertNull(DB::table('fees_class_types')->where('id', $item->id)->value('deleted_at'));
    }

    public function test_installment_delete_requires_own_active_workflow_fee_and_preserves_rejected_row(): void
    {
        $type = $this->createType();
        $this->postJson('/qa-fee-lifecycle/fee/store', $this->payload($type))->assertJsonPath('error', false);
        $fee = Fee::where('school_id', $this->schoolId)->sole();
        $installmentId = DB::table('fees_installments')->insertGetId(['name' => 'Local installment', 'fees_id' => $fee->id,
            'session_year_id' => $this->yearId, 'school_id' => $this->schoolId, 'due_date' => '2026-12-31',
            'due_charges' => 0, 'due_charges_type' => 'fixed', 'installment_amount' => 1000]);
        $this->classify('fee', $fee->id, 'archived');
        $this->deleteJson('/qa-fee-lifecycle/fee/deleteInstallment/'.$installmentId)->assertJsonPath('error', true);
        $this->assertSame(1, DB::table('fees_installments')->where('id', $installmentId)->count());
        $this->classify('fee', $fee->id, 'qa_test');
        DB::table('fees_installments')->where('id', $installmentId)->update(['school_id' => 1]);
        try {
            $this->deleteJson('/qa-fee-lifecycle/fee/deleteInstallment/'.$installmentId)->assertJsonPath('error', true);
            $this->assertSame(1, DB::table('fees_installments')->where('id', $installmentId)->count());
        } finally {
            DB::table('fees_installments')->where('id', $installmentId)->update(['school_id' => $this->schoolId]);
        }
        $this->deleteJson('/qa-fee-lifecycle/fee/deleteInstallment/'.$installmentId)->assertJsonPath('error', false);
        $this->assertSame(0, DB::table('fees_installments')->where('id', $installmentId)->count());
    }

    private function createType(): int
    {
        $this->postJson('/qa-fee-lifecycle/type/store', ['name' => 'Lifecycle Type '.Str::random(5), 'description' => 'Local fixture',
            'school_id' => 999999, 'classification' => 'production'])->assertOk()->assertJsonPath('error', false);
        return (int) DB::table('fees_types')->where('school_id', $this->schoolId)->max('id');
    }

    private function payload(int $type): array
    {
        return ['name' => 'Lifecycle Fee', 'class_id' => [$this->classId], 'due_date' => null, 'include_fee_installments' => 0,
            'due_charges_percentage' => 0, 'due_charges_amount' => 0,
            'compulsory_fees_type' => [['fees_type_id' => $type, 'amount' => 1000, 'quantity_enabled' => 1]],
            'classification' => 'production', 'include_qa_test' => true];
    }

    private function metadata(string $table)
    {
        return DB::connection('mysql')->table($table)->where('school_id', $this->schoolId)->where('subject_scope', 'tenant:'.$this->schoolId);
    }

    private function classify(string $type, int $id, string $classification, ?string $scope = null): void
    {
        DB::connection('mysql')->table(CentralFinanceDataIsolationService::TABLE)->updateOrInsert(
            ['school_id' => $this->schoolId, 'subject_scope' => $scope ?? 'tenant:'.$this->schoolId, 'subject_type' => $type, 'subject_id' => $id],
            ['classification_uuid' => (string) Str::uuid(), 'classification' => $classification, 'reason' => 'Disposable fixture',
                'classified_by' => null, 'actor_scope' => 'tenant', 'actor_school_id' => $this->schoolId, 'actor_tenant_user_id' => $this->actor->id,
                'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function assertClassification(string $type, int $id, string $classification): void
    {
        $record = $this->metadata(CentralFinanceDataIsolationService::TABLE)->where('subject_type', $type)->where('subject_id', $id)->sole();
        $audit = $this->metadata(CentralFinanceDataIsolationService::AUDIT_TABLE)->where('subject_type', $type)->where('subject_id', $id)->sole();
        $this->assertSame($classification, $record->classification);
        $this->assertSame($classification, $audit->after_classification);
        $this->assertSame('tenant', $audit->actor_scope);
        $this->assertSame((int) $this->actor->id, (int) $audit->actor_tenant_user_id);
        $this->assertNull($audit->actor_id);
        $this->assertNotEmpty($audit->reason);
    }
}
