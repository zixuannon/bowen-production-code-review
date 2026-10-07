<?php

namespace Tests\Feature;

use App\Models\CentralFinanceStudentProfile;
use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\StudentFeeAssignment;
use App\Models\Students;
use App\Models\User;
use App\Services\CentralFinanceDataIsolationService as Isolation;
use App\Services\CentralFinanceQaRunService as Runs;
use App\Services\CentralFinanceTenantFeeAssignmentSource;
use App\Services\StudentFeeAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Real MySQL/PDO visibility and atomic rollback, scoped to one disposable School. */
final class QaFeeAssignmentLifecycleTest extends TestCase
{
    private int $schoolId;
    private int $classId;
    private int $yearId;
    private int $sectionId;
    private int $centralActorId;
    private int $runId;
    private User $actor;
    private Students $student;
    private FeesClassType $template;
    private array $createdMigrations = [];
    private bool $failItemMembership = false;
    private ?int $groupId = null;
    private ?int $groupUserId = null;
    private ?int $tenantRoleId = null;
    private ?int $createdPermissionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['mysql' => 'eschool_testing', 'school' => 'school_testing'] as $connection => $database) {
            $this->assertSame($database, config('database.connections.'.$connection.'.database'));
            $this->assertContains(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost']);
        }
        foreach ([
            ['2026_09_14_000003_create_central_finance_data_classifications.php', Isolation::TABLE, null],
            ['2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php', Isolation::TABLE, 'actor_scope'],
            ['2026_10_05_000001_create_central_finance_qa_runs.php', Runs::TABLE, null],
        ] as [$file, $table, $column]) {
            if ($column ? !Schema::connection('mysql')->hasColumn($table, $column) : !Schema::connection('mysql')->hasTable($table)) {
                (require database_path('migrations/'.$file))->up();
                $this->createdMigrations[] = $file;
            }
        }
        $this->assertFalse(DB::connection('mysql')->table('schools')->where('code', 'MMBOWEN01')->exists(), 'A pre-existing QA identity must not be changed by this fixture.');
        $this->schoolId = DB::connection('mysql')->table('schools')->insertGetId([
            'name' => 'Disposable Assignment QA', 'code' => 'MMBOWEN01', 'database_name' => 'school_testing',
            'address' => 'Local only', 'support_phone' => '0', 'support_email' => 'fixture@local.test',
            'installed' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['finance_release.p31_p32_tenants.MMBOWEN01' => 'school_testing']);
        DB::setDefaultConnection('school');
        DB::table('schools')->insert(['id' => $this->schoolId, 'name' => 'Assignment QA', 'code' => 'MMBOWEN01']);
        $this->actor = $this->newUser('Actor');
        $medium = DB::table('mediums')->insertGetId(['name' => 'Assignment QA Medium', 'school_id' => $this->schoolId]);
        $this->classId = DB::table('classes')->insertGetId(['name' => 'Assignment QA Class', 'medium_id' => $medium, 'school_id' => $this->schoolId]);
        $section = DB::table('sections')->insertGetId(['name' => 'QA', 'school_id' => $this->schoolId]);
        $this->sectionId = DB::table('class_sections')->insertGetId(['class_id' => $this->classId, 'section_id' => $section, 'medium_id' => $medium, 'school_id' => $this->schoolId]);
        $this->yearId = DB::table('session_years')->insertGetId(['name' => 'QA Year', 'school_id' => $this->schoolId, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->centralActorId = DB::connection('mysql')->table('users')->insertGetId([
            'first_name' => 'Central', 'last_name' => 'Fixture', 'email' => Str::uuid().'@local.test',
            'password' => 'disposable', 'school_id' => null, 'status' => 1,
        ]);
        $this->classify('school', $this->schoolId, 'qa_test', 'central');
        $this->runId = $this->newRun(1);
        $this->student = $this->newStudent($this->runId);
        $type = DB::table('fees_types')->insertGetId(['name' => 'Reusable type', 'school_id' => $this->schoolId]);
        $fee = new Fee();
        $fee->forceFill(['name' => 'Reusable tuition', 'due_date' => null, 'due_charges' => 0, 'due_charges_amount' => 0,
            'class_id' => $this->classId, 'school_id' => $this->schoolId, 'session_year_id' => $this->yearId]);
        $fee->save();
        $this->template = FeesClassType::create(['class_id' => $this->classId, 'fees_id' => $fee->id, 'fees_type_id' => $type,
            'amount' => 400000, 'optional' => true, 'school_id' => $this->schoolId, 'fee_currency' => 'MMK',
            'fee_original_amount' => 400000, 'fee_exchange_rate_snapshot' => 1, 'fee_amount_mmk' => 400000]);
        foreach (['fee' => $fee->id, 'fee_type' => $type, 'fee_item' => $this->template->id] as $subject => $id) $this->classify($subject, $id);
        Auth::setUser($this->actor);
        request()->attributes->set('_trusted_tenant_context_active', true);
        request()->attributes->set('trusted_tenant_school_id', $this->schoolId);
        DB::connection('school')->beforeExecuting(function (string $query, array $bindings): void {
            if ($this->failItemMembership && str_starts_with(strtolower($query), 'insert into')
                && str_contains($query, Runs::RECORDS_TABLE) && in_array('student_fee_assignment_item', $bindings, true)) {
                throw new \RuntimeException('Injected assignment item membership failure.');
            }
        });
    }

    protected function tearDown(): void
    {
        $this->failItemMembership = false;
        while (DB::connection('school')->transactionLevel() > 0) DB::connection('school')->rollBack();
        if (isset($this->schoolId)) {
            if (isset($this->centralActorId) && Schema::connection('mysql')->hasTable('central_finance_school_staff_identities')) {
                DB::connection('mysql')->table('central_finance_school_staff_identities')->where('central_user_id', $this->centralActorId)->delete();
            }
            if ($this->tenantRoleId !== null) {
                DB::connection('school')->table('model_has_roles')->where('role_id', $this->tenantRoleId)->delete();
                DB::connection('school')->table('role_has_permissions')->where('role_id', $this->tenantRoleId)->delete();
                DB::connection('school')->table('roles')->where('id', $this->tenantRoleId)->delete();
            }
            if ($this->createdPermissionId !== null) DB::connection('school')->table('permissions')->where('id', $this->createdPermissionId)->delete();
            foreach ([Runs::RECORDS_TABLE, Runs::TABLE, Isolation::AUDIT_TABLE, Isolation::TABLE, 'central_finance_receivable_sync_events', 'central_finance_receivables', 'central_finance_sync_events', 'central_finance_student_profiles', 'central_finance_school_cutovers', 'central_finance_user_school_scopes', 'finance_group_user_tenant_identities', 'finance_group_user_scopes', 'finance_group_schools'] as $table) {
                if (Schema::connection('mysql')->hasTable($table)) DB::connection('mysql')->table($table)->where('school_id', $this->schoolId)->delete();
            }
            $assignmentIds = DB::connection('school')->table('student_fee_assignments')->where('school_id', $this->schoolId)->pluck('id');
            DB::connection('school')->table('student_fee_assignment_items')->whereIn('student_fee_assignment_id', $assignmentIds)->delete();
            if (isset($this->actor)) DB::connection('school')->table('staffs')->where('user_id', $this->actor->id)->delete();
            foreach (['student_fee_assignment_source_locks', 'student_fee_assignments', 'students', 'fees_class_types', 'fees', 'fees_types', 'class_sections', 'sections', 'classes', 'mediums', 'session_years', 'users'] as $table) {
                DB::connection('school')->table($table)->where('school_id', $this->schoolId)->delete();
            }
            DB::connection('school')->table('schools')->where('id', $this->schoolId)->delete();
            if ($this->groupUserId !== null) DB::connection('mysql')->table('finance_group_users')->where('id', $this->groupUserId)->delete();
            if ($this->groupId !== null) DB::connection('mysql')->table('finance_groups')->where('id', $this->groupId)->delete();
            if (isset($this->centralActorId)) {
                DB::connection('mysql')->table('model_has_roles')->where('model_id', $this->centralActorId)->where('model_type', User::class)->delete();
                DB::connection('mysql')->table('users')->where('id', $this->centralActorId)->delete();
            }
            DB::connection('mysql')->table('schools')->where('id', $this->schoolId)->delete();
        }
        foreach (array_reverse($this->createdMigrations) as $file) (require database_path('migrations/'.$file))->down();
        Auth::forgetUser();
        session()->forget(\App\Services\CentralFinanceSchoolStaffIdentityService::SESSION_KEY);
        DB::setDefaultConnection('mysql');
        parent::tearDown();
    }

    public function test_creation_classifies_assignment_and_items_with_actual_actor_and_same_run_atomically(): void
    {
        $draft = $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id]);
        foreach (['student_fee_assignment' => $draft->id, 'student_fee_assignment_item' => $draft->items->sole()->id] as $type => $id) {
            $metadata = $this->metadata(Isolation::TABLE)->where('subject_type', $type)->where('subject_id', $id)->sole();
            $audit = $this->metadata(Isolation::AUDIT_TABLE)->where('subject_type', $type)->where('subject_id', $id)->sole();
            $this->assertSame('qa_test', $metadata->classification);
            $this->assertSame('tenant', $audit->actor_scope);
            $this->assertSame((int) $this->actor->id, (int) $audit->actor_tenant_user_id);
            $this->assertSame($this->runId, (int) DB::connection('mysql')->table(Runs::RECORDS_TABLE)->where('subject_type', $type)->where('subject_scope', 'tenant:'.$this->schoolId)->where('subject_id', $id)->sole()->qa_run_id);
        }
        $this->assertSame(0, DB::connection('mysql')->table(Runs::RECORDS_TABLE)->where('school_id', $this->schoolId)->whereIn('subject_type', ['fee', 'fee_type', 'fee_item'])->count());
        $this->assertSame(0, DB::connection('school')->transactionLevel());
    }

    public function test_reusable_template_new_run_sees_new_price_while_old_snapshots_never_reprice(): void
    {
        $draft = $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id]);
        DB::table('fees_class_types')->where('id', $this->template->id)->update(['amount' => 450000, 'fee_original_amount' => 450000, 'fee_amount_mmk' => 450000]);
        $confirmed = $this->service()->confirm($this->student, $this->actor, $draft->uuid);
        $this->assertSame('400000.0000', $confirmed->items->sole()->amount_snapshot);
        DB::connection('mysql')->table(Runs::TABLE)->where('id', $this->runId)->update(['status' => 'archived']);
        $secondRun = $this->newRun(2);
        $newStudent = $this->newStudent($secondRun);
        $newDraft = $this->service()->saveAdditionalDraft($newStudent, $this->actor, [$this->template->id]);
        $this->assertSame('450000.0000', $newDraft->items->sole()->amount_snapshot);
        $this->assertSame('400000.0000', $confirmed->fresh('items')->items->sole()->amount_snapshot);
        $this->expectException(AuthorizationException::class);
        $this->service()->confirm($this->student, $this->actor, $draft->uuid);
    }

    public function test_partial_membership_failure_rolls_back_snapshot_classification_audit_and_membership(): void
    {
        $before = $this->metadata(Isolation::TABLE)->count();
        $this->failItemMembership = true;
        try {
            $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id]);
            $this->fail('Injected failure did not abort.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected assignment item membership failure.', $exception->getMessage());
        }
        $this->failItemMembership = false;
        $this->assertSame(0, StudentFeeAssignment::where('school_id', $this->schoolId)->count());
        $this->assertSame($before, $this->metadata(Isolation::TABLE)->count());
        $this->assertSame(0, $this->metadata(Isolation::AUDIT_TABLE)->count());
        $this->assertSame(1, DB::connection('mysql')->table(Runs::RECORDS_TABLE)->where('school_id', $this->schoolId)->count());
    }

    public function test_archived_unclassified_and_official_templates_are_not_selectable_in_qa_school(): void
    {
        foreach (['fee', 'fee_type', 'fee_item'] as $type) {
            $id = ['fee' => $this->template->fees_id, 'fee_type' => $this->template->fees_type_id, 'fee_item' => $this->template->id][$type];
            foreach (['archived', 'production', null] as $classification) {
                if ($classification === null) $this->metadata(Isolation::TABLE)->where('subject_type', $type)->where('subject_id', $id)->delete();
                else $this->classify($type, $id, $classification);
                $this->assertCount(0, $this->service()->availableAdditionalItems($this->student));
                $this->assertCount(0, $this->service()->configuredAdditionalItems($this->student));
            }
            $this->classify($type, $id);
        }
    }

    public function test_confirmation_rechecks_template_eligibility_without_backfilling_old_assignments(): void
    {
        $draft = $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id]);
        $this->classify('fee_item', $this->template->id, 'archived');
        try {
            $this->service()->confirm($this->student, $this->actor, $draft->uuid);
            $this->fail('Archived template accepted.');
        } catch (ValidationException) {
            $this->assertSame('draft', $draft->fresh()->status);
        }
        $this->classify('fee_item', $this->template->id);
        $this->metadata(Isolation::AUDIT_TABLE)->where('subject_type', 'student_fee_assignment')->where('subject_id', $draft->id)->delete();
        $this->metadata(Isolation::TABLE)->where('subject_type', 'student_fee_assignment')->where('subject_id', $draft->id)->delete();
        try {
            $this->service()->confirm($this->student, $this->actor, $draft->uuid);
            $this->fail('Unclassified historical assignment accepted.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('classification', $exception->getMessage());
        }
        $this->assertSame(0, $this->metadata(Isolation::TABLE)->where('subject_type', 'student_fee_assignment')->count());
    }

    public function test_projection_uses_confirmed_snapshots_only_and_missing_classification_fails_distinctly(): void
    {
        $profile = $this->profile();
        $source = app(CentralFinanceTenantFeeAssignmentSource::class);
        $this->assertSame([], $source->allForProfile($profile));
        $draft = $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id]);
        $this->service()->confirm($this->student, $this->actor, $draft->uuid);
        $rows = $source->allForProfile($profile);
        $this->assertCount(1, $rows);
        $this->assertSame(400000.0, $rows[0]['amount']);
        $this->metadata(Isolation::AUDIT_TABLE)->where('subject_type', 'student_fee_assignment_item')->where('subject_id', $draft->items->sole()->id)->delete();
        $this->metadata(Isolation::TABLE)->where('subject_type', 'student_fee_assignment_item')->where('subject_id', $draft->items->sole()->id)->delete();
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Fee projection classification is missing or inconsistent');
        $source->allForProfile($profile);
    }

    public function test_saving_qa_draft_again_retains_old_item_and_metadata_history_and_rejects_stale_uuid(): void
    {
        $first = $this->service()->saveDraft($this->student, $this->actor, [$this->template->id]);
        $oldItem = $first->items->sole();
        $second = $this->service()->saveDraft($this->student, $this->actor, [$this->template->id]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('400000.0000', $oldItem->fresh()->amount_snapshot);
        $this->assertSame(1, $this->metadata(Isolation::TABLE)->where('subject_type', 'student_fee_assignment_item')->where('subject_id', $oldItem->id)->count());
        $this->assertSame(1, $this->metadata(Isolation::AUDIT_TABLE)->where('subject_type', 'student_fee_assignment_item')->where('subject_id', $oldItem->id)->count());
        $this->expectException(ValidationException::class);
        $this->service()->confirm($this->student, $this->actor, $first->uuid);
    }

    public function test_central_optional_adapter_preserves_operator_identity_and_existing_receivable_when_source_metadata_is_missing(): void
    {
        $principal = $this->centralOperator();
        $profile = $this->profile();
        DB::setDefaultConnection('mysql');
        $adapter = app(\App\Services\CentralFinanceOptionalFeeAssignmentService::class);
        $receivables = $adapter->add($principal, $profile, [$this->template->id]);
        $receivable = $receivables->sole();
        $this->assertSame('400000.0000', $receivable->amount_due);
        $assignment = StudentFeeAssignment::on('school')->where('school_id', $this->schoolId)->sole();
        $this->assertSame((int) $this->actor->id, (int) $assignment->confirmed_by);
        foreach (['student_fee_assignment', 'student_fee_assignment_item'] as $type) {
            $audit = $this->metadata(Isolation::AUDIT_TABLE)->where('subject_type', $type)->sole();
            $this->assertSame('central', $audit->actor_scope);
            $this->assertSame((int) $principal->id, (int) $audit->actor_id);
            $this->assertNull($audit->actor_tenant_user_id);
        }
        $this->assertSame($receivable->id, $adapter->add($principal, $profile, [$this->template->id])->sole()->id);
        $this->assertSame(1, StudentFeeAssignment::on('school')->where('school_id', $this->schoolId)->count());
        $before = $receivable->fresh()->getAttributes();
        $this->metadata(Isolation::AUDIT_TABLE)->where('subject_type', 'student_fee_assignment_item')->delete();
        $this->metadata(Isolation::TABLE)->where('subject_type', 'student_fee_assignment_item')->delete();
        try {
            app(\App\Services\CentralFinanceReceivableSyncService::class)->syncProfile($profile);
            $this->fail('Missing classification must fail before cancellation.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('classification is missing', $exception->getMessage());
        }
        $this->assertSame($before, $receivable->fresh()->getAttributes());
    }

    public function test_central_assignment_rejects_wrong_principal_and_wrong_mapped_actor_without_writes(): void
    {
        $principal = $this->centralOperator();
        $wrong = $this->newUser('Wrong mapping');
        try {
            $this->service()->saveAdditionalDraft($this->student, $wrong, [$this->template->id], [], [], [], $principal);
            $this->fail('Mismatched tenant mapping accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('matching active tenant identity', $exception->getMessage());
        }
        Auth::setUser($this->actor);
        try {
            $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id], [], [], [], $principal);
            $this->fail('Unauthenticated principal accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Central Fee assignment actor', $exception->getMessage());
        }
        $this->assertSame(0, StudentFeeAssignment::where('school_id', $this->schoolId)->count());
        $this->assertSame(0, $this->metadata(Isolation::AUDIT_TABLE)->count());
    }

    public function test_cross_run_assignment_membership_is_rejected_without_reassignment(): void
    {
        $draft = $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id]);
        $differentRun = $this->newRun(2);
        DB::connection('mysql')->table(Runs::RECORDS_TABLE)->where('subject_scope', 'tenant:'.$this->schoolId)
            ->where('subject_type', 'student_fee_assignment_item')->where('subject_id', $draft->items->sole()->id)->update(['qa_run_id' => $differentRun]);
        try {
            $this->service()->confirm($this->student, $this->actor, $draft->uuid);
            $this->fail('Cross Run item accepted.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('different Run membership', $exception->getMessage());
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame($differentRun, (int) DB::connection('mysql')->table(Runs::RECORDS_TABLE)->where('subject_scope', 'tenant:'.$this->schoolId)
            ->where('subject_type', 'student_fee_assignment_item')->where('subject_id', $draft->items->sole()->id)->value('qa_run_id'));
    }

    public function test_non_login_front_desk_principal_can_use_real_optional_adapter_with_narrow_submit_scope(): void
    {
        $principal = $this->schoolStaffOperator();
        $profile = $this->profile();
        DB::setDefaultConnection('mysql');
        $receivable = app(\App\Services\CentralFinanceOptionalFeeAssignmentService::class)
            ->add($principal, $profile, [$this->template->id])->sole();
        $assignment = StudentFeeAssignment::on('school')->where('school_id', $this->schoolId)->sole();
        $this->assertSame('400000.0000', $receivable->amount_due);
        $this->assertSame('confirmed', $assignment->status);
        $this->assertSame((int) $this->actor->id, (int) $assignment->confirmed_by);
        $this->assertSchoolStaffAudits($principal);
        $this->assertSame(0, (int) DB::connection('mysql')->table('users')->where('id', $principal->id)->value('status'));
        $scope = DB::connection('mysql')->table('central_finance_user_school_scopes')->where('user_id', $principal->id)->sole();
        $this->assertSame(0, (int) $scope->can_operate);
        $this->assertSame(1, (int) $scope->can_submit_collections);
        $this->assertSame((int) $principal->id, (int) Auth::id());
    }

    public static function tenantControllerActions(): array { return [['saveDraft'], ['addFee']]; }

    /** @dataProvider tenantControllerActions */
    public function test_tenant_front_desk_controller_forwards_exact_non_login_principal(string $action): void
    {
        $principal = $this->schoolStaffOperator();
        $request = $this->trustedFrontDeskRequest();
        $this->assertTrue($this->actor->can('fees-create'));
        $response = app(\App\Http\Controllers\StudentFeeAssignmentController::class)->$action($request, $this->student->id);
        $this->assertSame(302, $response->getStatusCode());
        $assignment = StudentFeeAssignment::on('school')->where('school_id', $this->schoolId)->sole();
        $this->assertSame('draft', $assignment->status);
        $this->assertSame('400000.0000', $assignment->items->sole()->amount_snapshot);
        $this->assertSchoolStaffAudits($principal);
        $this->assertSame((int) $this->actor->id, (int) Auth::id());
        $this->assertNotInstanceOf(\App\Models\CentralFinanceUser::class, Auth::user());
        $this->assertSame(0, (int) DB::connection('mysql')->table('users')->where('id', $principal->id)->value('status'));
    }

    public static function invalidSchoolStaffGrants(): array
    {
        return array_map(fn (string $case): array => [$case], [
            'missing_role', 'missing_permission', 'wrong_role', 'missing_uuid', 'stale_uuid',
            'other_school', 'inactive_tenant', 'withdrawn_scope', 'withdrawn_mapping',
        ]);
    }

    /** @dataProvider invalidSchoolStaffGrants */
    public function test_non_login_front_desk_fails_closed_for_invalid_authority_without_metadata_writes(string $case): void
    {
        $principal = $this->schoolStaffOperator();
        $before = $this->metadata(Isolation::TABLE)->get()->toJson();
        switch ($case) {
            case 'missing_role':
                DB::table('model_has_roles')->where('role_id', $this->tenantRoleId)->delete();
                break;
            case 'missing_permission':
                DB::table('role_has_permissions')->where('role_id', $this->tenantRoleId)->delete();
                break;
            case 'wrong_role':
                DB::table('roles')->where('id', $this->tenantRoleId)->update(['name' => 'Library Clerk']);
                break;
            case 'missing_uuid':
                $this->actor->forceFill(['central_finance_source_uuid' => null])->save();
                break;
            case 'stale_uuid':
                DB::table('users')->where('id', $this->actor->id)->update(['central_finance_source_uuid' => (string) Str::uuid()]);
                break;
            case 'other_school':
                DB::connection('mysql')->table('central_finance_school_staff_identities')->where('central_user_id', $principal->id)->update(['school_id' => 1]);
                break;
            case 'inactive_tenant':
                DB::table('users')->where('id', $this->actor->id)->update(['status' => 0]);
                break;
            case 'withdrawn_scope':
                DB::connection('mysql')->table('central_finance_user_school_scopes')->where('user_id', $principal->id)->update(['can_submit_collections' => 0]);
                break;
            case 'withdrawn_mapping':
                DB::connection('mysql')->table('central_finance_school_staff_identities')->where('central_user_id', $principal->id)->update(['status' => 'revoked']);
                break;
        }
        try {
            $this->service()->saveAdditionalDraft($this->student, $this->actor, [$this->template->id], [], [], [], $principal);
            $this->fail('Invalid School Staff authority was accepted: '.$case);
        } catch (AuthorizationException|\RuntimeException $exception) {
            $this->assertContains($exception::class, [AuthorizationException::class, \RuntimeException::class]);
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame(0, StudentFeeAssignment::on('school')->where('school_id', $this->schoolId)->count());
        $this->assertSame($before, $this->metadata(Isolation::TABLE)->get()->toJson());
        $this->assertSame(0, $this->metadata(Isolation::AUDIT_TABLE)->count());
        $this->assertSame(0, DB::connection('mysql')->table(Runs::RECORDS_TABLE)->where('school_id', $this->schoolId)
            ->whereIn('subject_type', ['student_fee_assignment', 'student_fee_assignment_item'])->count());
    }

    public function test_tenant_controller_rejects_mismatched_staff_session_before_assignment_creation(): void
    {
        $this->schoolStaffOperator();
        $request = $this->trustedFrontDeskRequest();
        session()->put(\App\Services\CentralFinanceSchoolStaffIdentityService::SESSION_KEY, [
            'school_id' => $this->schoolId, 'user_uuid' => (string) Str::uuid(),
        ]);
        try {
            app(\App\Http\Controllers\StudentFeeAssignmentController::class)->saveDraft($request, $this->student->id);
            $this->fail('Mismatched Staff UUID was accepted.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('matching trusted School Staff session', $exception->getMessage());
        }
        $this->assertSame(0, StudentFeeAssignment::on('school')->where('school_id', $this->schoolId)->count());
        $this->assertSame(0, $this->metadata(Isolation::AUDIT_TABLE)->count());
    }

    private function schoolStaffOperator(): \App\Models\CentralFinanceUser
    {
        $this->centralOperator();
        $central = DB::connection('mysql');
        $central->table('model_has_roles')->where('model_id', $this->centralActorId)->where('model_type', User::class)->delete();
        $central->table('users')->where('id', $this->centralActorId)->update([
            'status' => 0, 'school_id' => $this->schoolId,
            'central_finance_principal_type' => \App\Services\CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE,
        ]);
        $central->table('central_finance_user_school_scopes')->where('user_id', $this->centralActorId)->update(['can_operate' => 0, 'can_submit_collections' => 1]);
        $central->table('finance_group_user_scopes')->where('group_user_id', $this->groupUserId)->where('capability', 'operate_finance')->delete();
        $this->actor->forceFill(['central_finance_source_uuid' => (string) Str::uuid()])->save();
        DB::table('staffs')->insert(['user_id' => $this->actor->id, 'salary' => 1]);
        $identityId = $central->table('central_finance_school_staff_identities')->insertGetId([
            'identity_uuid' => (string) Str::uuid(), 'school_id' => $this->schoolId, 'tenant_user_uuid' => $this->actor->getRawOriginal('central_finance_source_uuid'),
            'central_user_id' => $this->centralActorId, 'status' => 'active',
        ]);
        $this->tenantRoleId = DB::table('roles')->insertGetId(['name' => 'Front Desk', 'guard_name' => 'web', 'school_id' => $this->schoolId]);
        $permissionId = DB::table('permissions')->where('name', 'fees-create')->where('guard_name', 'web')->value('id');
        if (!$permissionId) {
            $permissionId = DB::table('permissions')->insertGetId(['name' => 'fees-create', 'guard_name' => 'web']);
            $this->createdPermissionId = $permissionId;
        }
        DB::table('model_has_roles')->insert(['role_id' => $this->tenantRoleId, 'model_type' => User::class, 'model_id' => $this->actor->id]);
        DB::table('role_has_permissions')->insert(['role_id' => $this->tenantRoleId, 'permission_id' => $permissionId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->classify('staff', $this->actor->id);
        $this->classify('central_user', $this->centralActorId, 'qa_test', 'central');
        $this->classify('central_staff_identity', $identityId, 'qa_test', 'central');
        $principal = \App\Models\CentralFinanceUser::on('mysql')->findOrFail($this->centralActorId);
        Auth::setUser($principal);
        return $principal;
    }

    private function trustedFrontDeskRequest(): \Illuminate\Http\Request
    {
        DB::setDefaultConnection('school');
        Auth::setUser($this->actor);
        session()->put('db_connection_name', 'school');
        session()->put(\App\Services\CentralFinanceSchoolStaffIdentityService::SESSION_KEY, [
            'school_id' => $this->schoolId, 'user_uuid' => $this->actor->getRawOriginal('central_finance_source_uuid'),
        ]);
        $request = \Illuminate\Http\Request::create('/students/'.$this->student->id.'/fee-assignment', 'POST', ['optional_fee_ids' => [$this->template->id]]);
        $request->setLaravelSession(app('session.store'));
        $request->attributes->set('_trusted_tenant_context_active', true);
        $request->attributes->set('trusted_tenant_school_id', $this->schoolId);
        $this->app->instance('request', $request);
        return $request;
    }

    private function assertSchoolStaffAudits(\App\Models\CentralFinanceUser $principal): void
    {
        foreach (['student_fee_assignment', 'student_fee_assignment_item'] as $type) {
            $audit = $this->metadata(Isolation::AUDIT_TABLE)->where('subject_type', $type)->sole();
            $this->assertSame('central', $audit->actor_scope);
            $this->assertSame((int) $principal->id, (int) $audit->actor_id);
            $this->assertNull($audit->actor_tenant_user_id);
            $this->assertSame('qa_test', $audit->after_classification);
            $this->assertSame($this->runId, (int) DB::connection('mysql')->table(Runs::RECORDS_TABLE)
                ->where('school_id', $this->schoolId)->where('subject_type', $type)->sole()->qa_run_id);
        }
    }

    private function centralOperator(): \App\Models\CentralFinanceUser
    {
        $db = DB::connection('mysql');
        $role = $db->table('roles')->where('name', 'Head Finance')->whereNull('school_id')->value('id');
        if (!$role) $role = $db->table('roles')->insertGetId(['name' => 'Head Finance', 'guard_name' => 'web', 'school_id' => null]);
        $db->table('model_has_roles')->insert(['role_id' => $role, 'model_type' => User::class, 'model_id' => $this->centralActorId]);
        $this->groupId = $db->table('finance_groups')->insertGetId(['name' => 'Disposable Fee QA', 'status' => 'active', 'reporting_currency' => 'MMK', 'fiscal_year_start_month' => 1]);
        $db->table('finance_group_schools')->insert(['group_id' => $this->groupId, 'school_id' => $this->schoolId, 'status' => 'active']);
        $this->groupUserId = $db->table('finance_group_users')->insertGetId(['group_id' => $this->groupId, 'central_user_id' => $this->centralActorId, 'status' => 'active']);
        foreach (['view_reports', 'operate_finance'] as $capability) $db->table('finance_group_user_scopes')->insert([
            'group_user_id' => $this->groupUserId, 'school_id' => $this->schoolId, 'scope_type' => 'SCHOOL', 'scope_key' => 'school:'.$this->schoolId,
            'capability' => $capability, 'status' => 'active',
        ]);
        $db->table('finance_group_user_tenant_identities')->insert(['group_user_id' => $this->groupUserId, 'school_id' => $this->schoolId, 'tenant_user_id' => $this->actor->id, 'status' => 'active']);
        $db->table('central_finance_user_school_scopes')->insert(['user_id' => $this->centralActorId, 'school_id' => $this->schoolId, 'can_view' => 1, 'can_operate' => 1]);
        $db->table('central_finance_school_cutovers')->insert(['school_id' => $this->schoolId, 'status' => 'central', 'receivable_sync_effective_at' => '2026-01-01 00:00:00']);
        $principal = \App\Models\CentralFinanceUser::on('mysql')->findOrFail($this->centralActorId);
        Auth::setUser($principal);
        \Illuminate\Support\Facades\Session::put(\App\Services\CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, $this->schoolId);
        return $principal;
    }

    private function service(): StudentFeeAssignmentService { return app(StudentFeeAssignmentService::class); }
    private function metadata(string $table) { return DB::connection('mysql')->table($table)->where('school_id', $this->schoolId)->where('subject_scope', 'tenant:'.$this->schoolId); }
    private function newUser(string $name): User { return User::create(['first_name' => $name, 'last_name' => 'QA', 'email' => Str::uuid().'@local.test', 'password' => 'disposable', 'school_id' => $this->schoolId, 'status' => 1]); }
    private function classify(string $type, int $id, string $classification = 'qa_test', ?string $scope = null): void
    {
        DB::connection('mysql')->table(Isolation::TABLE)->updateOrInsert(
            ['school_id' => $this->schoolId, 'subject_scope' => $scope ?? 'tenant:'.$this->schoolId, 'subject_type' => $type, 'subject_id' => $id],
            ['classification_uuid' => (string) Str::uuid(), 'classification' => $classification, 'reason' => 'Disposable QA fixture', 'classified_by' => null, 'created_at' => now(), 'updated_at' => now()]
        );
    }
    private function newRun(int $number): int
    {
        return DB::connection('mysql')->table(Runs::TABLE)->insertGetId(['run_uuid' => (string) Str::uuid(), 'school_id' => $this->schoolId,
            'run_number' => $number, 'label' => 'Disposable Run '.$number, 'status' => 'active', 'created_by' => $this->centralActorId, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function newStudent(int $runId): Students
    {
        $user = $this->newUser('Student');
        $student = Students::create(['user_id' => $user->id, 'class_section_id' => $this->sectionId, 'guardian_id' => $this->actor->id,
            'admission_no' => 'QAF-'.Str::random(12), 'admission_date' => now()->toDateString(), 'school_id' => $this->schoolId, 'session_year_id' => $this->yearId]);
        DB::connection('mysql')->table(Runs::RECORDS_TABLE)->insert(['qa_run_id' => $runId, 'school_id' => $this->schoolId,
            'subject_scope' => 'tenant:'.$this->schoolId, 'subject_type' => 'student', 'subject_id' => $student->id]);
        $this->classify('student', $student->id);
        return $student;
    }
    private function profile(): CentralFinanceStudentProfile
    {
        $profile = CentralFinanceStudentProfile::on('mysql')->updateOrCreate(['school_id' => $this->schoolId, 'tenant_student_id' => $this->student->id],
            ['class_id' => $this->classId]);
        DB::connection('mysql')->table(Runs::RECORDS_TABLE)->insertOrIgnore(['qa_run_id' => $this->runId, 'school_id' => $this->schoolId,
            'subject_scope' => 'central', 'subject_type' => 'student_profile', 'subject_id' => $profile->id]);
        $this->classify('student_profile', $profile->id, 'qa_test', 'central');
        return $profile;
    }
}
