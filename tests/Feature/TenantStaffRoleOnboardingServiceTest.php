<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantStaffRoleOnboardingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TenantStaffRoleOnboardingServiceTest extends TestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = tempnam(sys_get_temp_dir(), 'tenant_staff_role_onboarding_');
        Config::set('database.connections.school', [
            'driver' => 'sqlite',
            'database' => $this->tenant,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('school');
        DB::setDefaultConnection('school');

        Schema::connection('school')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('central_finance_source_uuid')->nullable();
            $table->unsignedBigInteger('school_id');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('school')->create('staffs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
        });
        Schema::connection('school')->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->boolean('custom_role')->default(false);
            $table->boolean('editable')->default(true);
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::connection('school')->create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::connection('school')->create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::connection('school')->create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::connection('school')->create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        (require database_path('migrations/schools/2026_09_01_000001_create_school_record_lifecycle_audits_table.php'))->up();

        DB::connection('school')->table('users')->insert([
            ['id' => 1, 'school_id' => 19, 'first_name' => 'Timecity', 'last_name' => 'Admin', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'school_id' => 19, 'first_name' => 'May', 'last_name' => 'Staff', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'school_id' => 17, 'first_name' => 'Bahan', 'last_name' => 'Staff', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('school')->table('staffs')->insert([
            ['id' => 1, 'user_id' => 1],
            ['id' => 2, 'user_id' => 2],
            ['id' => 3, 'user_id' => 3],
        ]);
        DB::connection('school')->table('roles')->insert([
            ['id' => 1, 'name' => 'School Admin', 'guard_name' => 'web', 'school_id' => 19, 'custom_role' => false, 'editable' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Finance Department', 'guard_name' => 'web', 'school_id' => 19, 'custom_role' => true, 'editable' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('school')->table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 1],
            ['role_id' => 2, 'model_type' => User::class, 'model_id' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetUser();
        DB::purge('school');
        @unlink($this->tenant);
        parent::tearDown();
    }

    public function test_school_admin_assigns_multiple_canonical_roles_without_replacing_existing_roles_and_writes_audit(): void
    {
        $actor = User::on('school')->findOrFail(1);
        $staff = User::on('school')->findOrFail(2);
        Auth::setUser($actor);

        $result = app(TenantStaffRoleOnboardingService::class)->assign($actor, $staff, [
            TenantStaffRoleOnboardingService::SCHOOL_ACCOUNTANT,
            TenantStaffRoleOnboardingService::PRINCIPAL,
        ], 'Approved Timecity onboarding');

        $this->assertSame(
            ['Finance Department', 'Principal', 'School Accountant'],
            $result->roles->pluck('name')->sort()->values()->all(),
        );
        $this->assertSame(0, DB::connection('school')->table('role_has_permissions')->count());
        $this->assertDatabaseHas('roles', [
            'name' => 'School Accountant', 'school_id' => 19, 'custom_role' => 1, 'editable' => 0,
        ], 'school');
        $audit = DB::connection('school')->table('school_record_lifecycle_audits')->sole();
        $this->assertSame('staff', $audit->subject_type);
        $this->assertSame('assign_role', $audit->action);
        $this->assertSame('Approved Timecity onboarding', $audit->reason);
        $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['Finance Department'], $metadata['before_roles']);
        $this->assertSame(['Finance Department', 'Principal', 'School Accountant'], $metadata['after_roles']);
    }

    public function test_repeat_assignment_is_idempotent_and_does_not_duplicate_roles(): void
    {
        $actor = User::on('school')->findOrFail(1);
        $staff = User::on('school')->findOrFail(2);
        Auth::setUser($actor);
        $service = app(TenantStaffRoleOnboardingService::class);

        $service->assign($actor, $staff, [TenantStaffRoleOnboardingService::FRONT_DESK], 'First approval');
        $service->assign($actor, $staff->fresh(), [TenantStaffRoleOnboardingService::FRONT_DESK], 'Repeat approval');

        $frontDeskRole = DB::connection('school')->table('roles')->where('name', TenantStaffRoleOnboardingService::FRONT_DESK)->sole();
        $this->assertSame(1, DB::connection('school')->table('model_has_roles')->where(['model_id' => 2, 'role_id' => $frontDeskRole->id])->count());
        $this->assertSame(2, DB::connection('school')->table('school_record_lifecycle_audits')->count());
    }

    public function test_cross_school_assignment_is_rejected_without_role_or_audit_write(): void
    {
        $actor = User::on('school')->findOrFail(1);
        $staff = User::on('school')->findOrFail(3);
        Auth::setUser($actor);
        $rolesBefore = DB::connection('school')->table('roles')->count();

        try {
            app(TenantStaffRoleOnboardingService::class)->assign(
                $actor,
                $staff,
                [TenantStaffRoleOnboardingService::SCHOOL_ACCOUNTANT],
                'Invalid cross-school attempt',
            );
            $this->fail('Cross-school assignment must be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame($rolesBefore, DB::connection('school')->table('roles')->count());
            $this->assertSame(0, DB::connection('school')->table('school_record_lifecycle_audits')->count());
        }
    }
}
