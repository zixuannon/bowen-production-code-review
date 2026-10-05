<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use App\Services\QaStaffClassificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Real InnoDB regression: Central metadata and tenant identity use one PDO. */
final class QaStaffClassificationTest extends TestCase
{
    private const TABLE = 'central_finance_data_classifications';
    private const AUDIT = 'central_finance_data_classification_audits';
    private const BASE_MIGRATION = '2026_09_14_000003_create_central_finance_data_classifications.php';
    private const ACTOR_MIGRATION = '2026_10_05_000001_add_tenant_actor_to_finance_data_classifications.php';
    private bool $createdBase = false;
    private bool $createdActor = false;
    private int $schoolId;
    private int $centralActorId;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('eschool_testing', config('database.connections.mysql.database'));
        $this->assertSame('school_testing', config('database.connections.school.database'));
        foreach (['mysql', 'school'] as $connection) {
            $this->assertContains(config("database.connections.$connection.host"), ['127.0.0.1', 'localhost']);
            $this->assertSame('mysql', DB::connection($connection)->getDriverName());
        }
        if (!Schema::connection('mysql')->hasTable(self::TABLE)) {
            (require database_path('migrations/'.self::BASE_MIGRATION))->up();
            $this->createdBase = true;
        }
        if (!Schema::connection('mysql')->hasColumn(self::TABLE, 'actor_scope')) {
            (require database_path('migrations/'.self::ACTOR_MIGRATION))->up();
            $this->createdActor = true;
        }
        DB::setDefaultConnection('school');
        DB::connection('school')->beginTransaction();
        // All fixture rows intentionally share the PDO used by the production
        // transaction. A second Central transaction would hide rows or block FKs.
        $this->schoolId = $this->central('schools')->insertGetId([
            'name' => 'Staff classification QA', 'address' => 'Local only',
            'support_phone' => '0', 'support_email' => 'school@qa.test',
            'database_name' => 'school_testing', 'code' => 'QA'.Str::random(12),
            'installed' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('school')->table('schools')->insert([
            'id' => $this->schoolId, 'name' => 'Stale tenant replica', 'code' => 'STALE',
        ]);
        $this->actor = $this->newUser('Actor');
        $this->centralActorId = $this->central('users')->insertGetId([
            'first_name' => 'Central', 'last_name' => 'Fixture',
            'email' => Str::uuid().'@qa.test', 'password' => 'disposable',
            'school_id' => null, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->classify('school', $this->schoolId, 'qa_test', 'central');
        Auth::setUser($this->actor);
        request()->attributes->set('_trusted_tenant_context_active', true);
        request()->attributes->set('trusted_tenant_school_id', $this->schoolId);
    }

    protected function tearDown(): void
    {
        while (DB::connection('school')->transactionLevel() > 0) {
            DB::connection('school')->rollBack();
        }
        if ($this->createdActor) {
            (require database_path('migrations/'.self::ACTOR_MIGRATION))->down();
        }
        if ($this->createdBase) {
            (require database_path('migrations/'.self::BASE_MIGRATION))->down();
        }
        Auth::forgetUser();
        DB::setDefaultConnection('mysql');
        parent::tearDown();
    }

    public static function roles(): array
    {
        return [['Principal'], ['Front Desk / Admissions & Collection'], ['School Accountant'], ['School Admin']];
    }

    /** @dataProvider roles */
    public function test_qa_staff_inherits_explicit_classification_and_actual_tenant_actor(string $role): void
    {
        $target = $this->staff($role);
        // A numerically matching Central user must never be the audit actor.
        $this->central('users')->insertOrIgnore([
            'id' => $this->actor->id, 'first_name' => 'Unrelated Central', 'last_name' => 'Collision',
            'email' => Str::uuid().'@qa.test', 'password' => 'disposable', 'school_id' => null, 'status' => 1,
        ]);
        app(QaStaffClassificationService::class)->inherit($target, $this->actor);
        $row = $this->subject(self::TABLE, $target)->sole();
        $this->assertSame('qa_test', $row->classification);
        $this->assertNull($row->classified_by);
        $this->assertSame('tenant', $row->actor_scope);
        $this->assertSame($this->schoolId, (int) $row->actor_school_id);
        $this->assertSame((int) $this->actor->id, (int) $row->actor_tenant_user_id);
        $audit = $this->subject(self::AUDIT, $target)->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame('tenant', $audit->actor_scope);
        $this->assertSame($this->schoolId, (int) $audit->actor_school_id);
        $this->assertSame((int) $this->actor->id, (int) $audit->actor_tenant_user_id);
        $this->assertSame('qa_test', $audit->after_classification);
        $this->assertSame('staff_created', $audit->action);
        $this->assertNotEmpty($audit->reason);
        $this->assertSame((int) $row->id, (int) $audit->classification_id);
        $this->assertSame(1, Staff::where('user_id', $target->id)->count());
    }

    public function test_official_school_creation_does_not_write_classification_or_audit(): void
    {
        $this->central(self::TABLE)->where('subject_type', 'school')->where('subject_id', $this->schoolId)->update(['classification' => 'production']);
        $target = $this->staff('Principal');
        app(QaStaffClassificationService::class)->inherit($target, $this->actor);
        $this->assertSame(0, $this->subject(self::TABLE, $target)->count());
        $this->assertSame(0, $this->subject(self::AUDIT, $target)->count());
    }

    public function test_retry_is_idempotent_and_preserves_initial_actor_and_audit(): void
    {
        $target = $this->staff('Principal');
        $service = app(QaStaffClassificationService::class);
        $service->inherit($target, $this->actor);
        $first = (array) $this->subject(self::TABLE, $target)->sole();
        $audit = (array) $this->subject(self::AUDIT, $target)->sole();
        $service->inherit($target, $this->actor);
        $this->assertSame($first, (array) $this->subject(self::TABLE, $target)->sole());
        $this->assertSame($audit, (array) $this->subject(self::AUDIT, $target)->sole());
    }

    public static function conflicts(): array { return [['production'], ['archived']]; }

    /** @dataProvider conflicts */
    public function test_existing_conflicting_staff_classification_is_never_overwritten(string $classification): void
    {
        $target = $this->staff('Principal');
        $this->classify('staff', $target->id, $classification, 'tenant:'.$this->schoolId);
        $this->assertRejected($target);
        $this->assertSame($classification, $this->subject(self::TABLE, $target)->sole()->classification);
        $this->assertSame(0, $this->subject(self::AUDIT, $target)->count());
    }

    public static function invalidContexts(): array
    {
        return array_map(fn ($case) => [$case], [
            'missing-trust', 'non-boolean-trust', 'cross-school-request', 'cross-school-target',
            'cross-school-actor', 'wrong-auth-user', 'inactive-actor', 'deleted-actor',
            'inactive-school', 'uninstalled-school', 'deleted-school', 'wrong-registry-database',
            'wrong-config-database', 'central-default', 'missing-staff', 'missing-role',
        ]);
    }

    /** @dataProvider invalidContexts */
    public function test_tampered_or_incomplete_context_fails_without_metadata(string $case): void
    {
        $target = $this->staff('Principal');
        switch ($case) {
            case 'missing-trust': request()->attributes->remove('_trusted_tenant_context_active'); break;
            case 'non-boolean-trust': request()->attributes->set('_trusted_tenant_context_active', 'true'); break;
            case 'cross-school-request': request()->attributes->set('trusted_tenant_school_id', $this->schoolId + 1); break;
            case 'cross-school-target': $target->setRawAttributes(array_merge($target->getAttributes(), ['school_id' => $this->schoolId + 1]), true); break;
            case 'cross-school-actor': $this->actor->setRawAttributes(array_merge($this->actor->getAttributes(), ['school_id' => $this->schoolId + 1]), true); break;
            case 'wrong-auth-user': Auth::setUser($target); break;
            case 'inactive-actor': DB::connection('school')->table('users')->where('id', $this->actor->id)->update(['status' => 0]); break;
            case 'deleted-actor': $this->actor->delete(); break;
            case 'inactive-school': $this->central('schools')->where('id', $this->schoolId)->update(['status' => 0]); break;
            case 'uninstalled-school': $this->central('schools')->where('id', $this->schoolId)->update(['installed' => 0]); break;
            case 'deleted-school': $this->central('schools')->where('id', $this->schoolId)->update(['deleted_at' => now()]); break;
            case 'wrong-registry-database': $this->central('schools')->where('id', $this->schoolId)->update(['database_name' => 'other_school_testing']); break;
            case 'wrong-config-database': config(['database.connections.school.database' => 'other_school_testing']); break;
            case 'central-default': DB::setDefaultConnection('mysql'); break;
            case 'missing-staff': Staff::where('user_id', $target->id)->delete(); break;
            case 'missing-role': DB::connection('school')->table('model_has_roles')->where('model_id', $target->id)->delete(); break;
        }
        try {
            $this->assertRejected($target);
            $this->assertSame(0, $this->subject(self::TABLE, $target)->count());
            $this->assertSame(0, $this->subject(self::AUDIT, $target)->count());
        } finally {
            config(['database.connections.school.database' => 'school_testing']);
        }
    }

    public static function failingWrites(): array { return [[self::TABLE], [self::AUDIT]]; }

    /** @dataProvider failingWrites */
    public function test_metadata_failure_rolls_back_user_staff_role_and_both_metadata_writes(string $table): void
    {
        $armed = true;
        DB::connection('school')->beforeExecuting(function ($sql) use (&$armed, $table): void {
            if ($armed && str_starts_with(strtolower(ltrim($sql)), 'insert ') && str_contains($sql, '`'.$table.'`')) {
                throw new RuntimeException('Synthetic metadata write rejection');
            }
        });
        $target = null;
        try {
            DB::connection('school')->transaction(function () use (&$target): void {
                $target = $this->staff('Principal');
                app(QaStaffClassificationService::class)->inherit($target, $this->actor);
            });
            $this->fail('Injected write failure must abort Staff creation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Synthetic metadata write rejection', $exception->getPrevious()?->getMessage() ?? $exception->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertNotNull($target);
        $this->assertSame(0, User::where('id', $target->id)->count());
        $this->assertSame(0, Staff::where('user_id', $target->id)->count());
        $this->assertSame(0, DB::connection('school')->table('model_has_roles')->where('model_id', $target->id)->count());
        $this->assertSame(0, $this->subject(self::TABLE, $target)->count());
        $this->assertSame(0, $this->subject(self::AUDIT, $target)->count());
    }

    public function test_caller_rollback_removes_successful_classification_and_audit_with_staff(): void
    {
        DB::connection('school')->beginTransaction();
        $target = $this->staff('Principal');
        app(QaStaffClassificationService::class)->inherit($target, $this->actor);
        $this->assertSame(1, $this->subject(self::AUDIT, $target)->count());
        DB::connection('school')->rollBack();
        $this->assertSame(0, User::where('id', $target->id)->count());
        $this->assertSame(0, $this->subject(self::TABLE, $target)->count());
        $this->assertSame(0, $this->subject(self::AUDIT, $target)->count());
    }

    public function test_real_central_reconciliation_preserves_inactive_staff_and_is_idempotent(): void
    {
        $roleId = $this->central('roles')->insertGetId([
            'name' => 'Head Finance', 'guard_name' => 'web', 'school_id' => null,
        ]);
        $this->central('model_has_roles')->insert([
            'role_id' => $roleId, 'model_id' => $this->centralActorId, 'model_type' => User::class,
        ]);
        $actor = new \App\Models\CentralFinanceUser;
        $actor->setRawAttributes((array) $this->central('users')->where('id', $this->centralActorId)->sole(), true);
        $actor->exists = true;
        $target = $this->staff('Principal');
        $target->update(['status' => 0]);
        $target->delete();
        $before = (array) DB::connection('school')->table('users')->where('id', $target->id)->sole();
        $service = app(QaStaffClassificationService::class);
        $service->reconcileMissing($target, $actor, 'Verified missing QA classification');
        $service->reconcileMissing($target, $actor, 'Retry must preserve first audit');
        $this->assertSame($before, (array) DB::connection('school')->table('users')->where('id', $target->id)->sole());
        $row = $this->subject(self::TABLE, $target)->sole();
        $audit = $this->subject(self::AUDIT, $target)->sole();
        $this->assertSame('central', $row->actor_scope);
        $this->assertSame($this->centralActorId, (int) $row->classified_by);
        $this->assertNull($row->actor_tenant_user_id);
        $this->assertSame('staff_classification_reconciled', $audit->action);
        $this->assertSame($this->centralActorId, (int) $audit->actor_id);
        $this->assertSame('Verified missing QA classification', $audit->reason);
    }

    public function test_central_reconciliation_rejects_actor_without_central_authority(): void
    {
        $actor = new \App\Models\CentralFinanceUser;
        $actor->setRawAttributes((array) $this->central('users')->where('id', $this->centralActorId)->sole(), true);
        $actor->exists = true;
        $target = $this->staff('Principal');
        try {
            app(QaStaffClassificationService::class)->reconcileMissing($target, $actor, 'Not authorized');
            $this->fail('An unprivileged Central user must not reconcile Staff.');
        } catch (\App\Exceptions\StaffClassificationException $exception) {
            $this->assertSame('Authorized Central reconciliation actor is required.', $exception->getMessage());
        }
        $this->assertSame(0, $this->subject(self::TABLE, $target)->count());
        $this->assertSame(0, $this->subject(self::AUDIT, $target)->count());
    }

    private function newUser(string $name): User
    {
        return User::on('school')->create([
            'first_name' => $name, 'last_name' => 'QA', 'email' => Str::uuid().'@qa.test',
            'password' => 'disposable', 'school_id' => $this->schoolId, 'status' => 1,
        ]);
    }

    private function staff(string $role): User
    {
        $user = $this->newUser('Target');
        Staff::on('school')->create(['user_id' => $user->id, 'salary' => 1]);
        $roleId = DB::connection('school')->table('roles')->insertGetId([
            'name' => $role, 'guard_name' => 'web', 'school_id' => $this->schoolId,
        ]);
        DB::connection('school')->table('model_has_roles')->insert([
            'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $user->id,
        ]);
        return $user;
    }

    private function central(string $table): \Illuminate\Database\Query\Builder
    {
        return DB::connection('school')->table('eschool_testing.'.$table);
    }

    private function subject(string $table, User $target): \Illuminate\Database\Query\Builder
    {
        return $this->central($table)->where([
            'subject_scope' => 'tenant:'.$this->schoolId, 'subject_type' => 'staff', 'subject_id' => $target->id,
        ]);
    }

    private function classify(string $type, int $id, string $classification, string $scope): void
    {
        $this->central(self::TABLE)->insert([
            'classification_uuid' => Str::uuid(), 'school_id' => $this->schoolId,
            'subject_scope' => $scope, 'subject_type' => $type, 'subject_id' => $id,
            'classification' => $classification, 'reason' => 'Disposable classification fixture',
            'classified_by' => $this->centralActorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertRejected(User $target): void
    {
        try {
            app(QaStaffClassificationService::class)->inherit($target, $this->actor);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
            return;
        }
        $this->fail('Untrusted Staff classification accepted.');
    }
}
