<?php

namespace Tests\Feature;

use App\Services\TenantFinanceOnboardingRoleProvisioner;
use App\Services\TenantStaffRoleOnboardingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class TenantFinanceOnboardingRoleProvisionerTest extends TestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = tempnam(sys_get_temp_dir(), 'finance_onboarding_roles_');
        Config::set('database.connections.school', [
            'driver' => 'sqlite',
            'database' => $this->tenant,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('school');

        Schema::connection('school')->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->unsignedBigInteger('school_id');
            $table->boolean('custom_role')->default(true);
            $table->boolean('editable')->default(true);
            $table->timestamps();
            $table->unique(['name', 'guard_name', 'school_id']);
        });
        Schema::connection('school')->create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        @unlink($this->tenant);
        parent::tearDown();
    }

    public function test_it_provisions_all_three_permission_free_roles_without_any_scope_grant(): void
    {
        $result = app(TenantFinanceOnboardingRoleProvisioner::class)->provision(
            41,
            'MMBOWEN41',
            $this->tenant,
            'new_school_setup',
        );

        $this->assertSame([
            TenantStaffRoleOnboardingService::SCHOOL_ACCOUNTANT => 'created',
            TenantStaffRoleOnboardingService::FRONT_DESK => 'created',
            TenantStaffRoleOnboardingService::PRINCIPAL => 'created',
        ], $result);
        $this->assertSame(
            TenantStaffRoleOnboardingService::roleNames(),
            DB::connection('school')->table('roles')->orderBy('id')->pluck('name')->all(),
        );
        $this->assertSame(0, DB::connection('school')->table('role_has_permissions')->count());
    }

    public function test_repeat_provisioning_is_idempotent_and_preserves_existing_role_metadata(): void
    {
        DB::connection('school')->table('roles')->insert([
            'name' => TenantStaffRoleOnboardingService::FRONT_DESK,
            'guard_name' => 'web',
            'school_id' => 41,
            'custom_role' => 0,
            'editable' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = app(TenantFinanceOnboardingRoleProvisioner::class);

        $first = $service->provision(41, 'MMBOWEN41', $this->tenant, 'existing_tenant');
        $second = $service->provision(41, 'MMBOWEN41', $this->tenant, 'repeat_existing_tenant');

        $this->assertSame('reused', $first[TenantStaffRoleOnboardingService::FRONT_DESK]);
        $this->assertSame([
            TenantStaffRoleOnboardingService::SCHOOL_ACCOUNTANT => 'reused',
            TenantStaffRoleOnboardingService::FRONT_DESK => 'reused',
            TenantStaffRoleOnboardingService::PRINCIPAL => 'reused',
        ], $second);
        $this->assertSame(3, DB::connection('school')->table('roles')->count());
        $this->assertDatabaseHas('roles', [
            'name' => TenantStaffRoleOnboardingService::FRONT_DESK,
            'school_id' => 41,
            'custom_role' => 0,
            'editable' => 1,
        ], 'school');
    }

    public function test_permission_conflict_fails_closed_without_partial_role_creation(): void
    {
        $roleId = DB::connection('school')->table('roles')->insertGetId([
            'name' => TenantStaffRoleOnboardingService::PRINCIPAL,
            'guard_name' => 'web',
            'school_id' => 41,
            'custom_role' => 1,
            'editable' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('school')->table('role_has_permissions')->insert([
            'permission_id' => 99,
            'role_id' => $roleId,
        ]);

        try {
            app(TenantFinanceOnboardingRoleProvisioner::class)->provision(
                41,
                'MMBOWEN41',
                $this->tenant,
                'conflict_test',
            );
            $this->fail('A canonical-name role with permissions must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires manual review', $exception->getMessage());
        }

        $this->assertSame(1, DB::connection('school')->table('roles')->count());
    }

    public function test_wrong_tenant_database_context_is_rejected_without_write(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant connection mismatch');

        try {
            app(TenantFinanceOnboardingRoleProvisioner::class)->provision(
                41,
                'MMBOWEN41',
                $this->tenant.'_wrong',
                'wrong_context',
            );
        } finally {
            $this->assertSame(0, DB::connection('school')->table('roles')->count());
        }
    }
}
