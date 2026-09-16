<?php

namespace Tests\Feature;

use App\Services\TenantStaffRoleOnboardingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProvisionFinanceOnboardingRolesCommandTest extends TestCase
{
    private string $central;
    /** @var array<int, string> */
    private array $tenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->central = tempnam(sys_get_temp_dir(), 'finance_onboarding_central_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => $this->central,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('database_name');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->boolean('installed')->default(true);
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('central_finance_user_id');
            $table->unsignedBigInteger('school_id');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        DB::purge('mysql');
        foreach ($this->tenants as $tenant) @unlink($tenant);
        @unlink($this->central);
        parent::tearDown();
    }

    public function test_command_provisions_every_registry_tenant_and_repeat_is_idempotent(): void
    {
        $first = $this->tenant(51, 'MMBOWEN51');
        $second = $this->tenant(52, 'MMBOWEN52');

        $this->assertSame(0, Artisan::call('finance:provision-onboarding-roles', ['--execute' => true]), Artisan::output());
        $this->assertTenantRoleState($first, 51);
        $this->assertTenantRoleState($second, 52);

        $this->assertSame(0, Artisan::call('finance:provision-onboarding-roles', ['--execute' => true]), Artisan::output());
        $this->assertTenantRoleState($first, 51);
        $this->assertTenantRoleState($second, 52);
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_user_school_scopes')->count());
    }

    public function test_later_registry_mismatch_preflight_prevents_all_tenant_writes(): void
    {
        $first = $this->tenant(51, 'MMBOWEN51');
        $second = $this->tenant(52, 'MMBOWEN52');
        $this->useTenant($second);
        DB::connection('school')->table('schools')->where('id', 52)->update(['database_name' => $second.'_wrong']);

        $this->artisan('finance:provision-onboarding-roles', ['--execute' => true])->assertFailed();

        $this->useTenant($first);
        $this->assertSame(0, DB::connection('school')->table('roles')->count());
        $this->useTenant($second);
        $this->assertSame(0, DB::connection('school')->table('roles')->count());
    }

    private function tenant(int $id, string $code): string
    {
        $database = tempnam(sys_get_temp_dir(), 'finance_onboarding_tenant_');
        $this->tenants[] = $database;
        $this->useTenant($database);
        Schema::connection('school')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('database_name');
        });
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
        DB::connection('school')->table('schools')->insert(['id' => $id, 'code' => $code, 'database_name' => $database]);
        DB::connection('mysql')->table('schools')->insert([
            'id' => $id,
            'name' => "School {$id}",
            'code' => $code,
            'database_name' => $database,
            'installed' => true,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $database;
    }

    private function useTenant(string $database): void
    {
        Config::set('database.connections.school', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('school');
    }

    private function assertTenantRoleState(string $database, int $schoolId): void
    {
        $this->useTenant($database);
        $this->assertSame(
            TenantStaffRoleOnboardingService::roleNames(),
            DB::connection('school')->table('roles')->where('school_id', $schoolId)->orderBy('id')->pluck('name')->all(),
        );
        $this->assertSame(3, DB::connection('school')->table('roles')->where('school_id', $schoolId)->count());
        $this->assertSame(0, DB::connection('school')->table('role_has_permissions')->count());
    }
}
