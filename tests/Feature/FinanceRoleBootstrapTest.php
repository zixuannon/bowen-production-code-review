<?php

namespace Tests\Feature;

use App\Console\Commands\BootstrapFinanceRoles;
use App\Models\Role;
use App\Services\SchoolDataService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceRoleBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['school'];
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('school');
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousConnection);
        parent::tearDown();
    }

    public function test_tenant_initialization_ensures_the_two_finance_roles_idempotently_without_assigning_users(): void
    {
        $schoolId = $this->createSchool();
        $this->ensureSpatiePivots();
        $userId = DB::connection('school')->table('users')->insertGetId([
            'first_name' => 'Bootstrap', 'last_name' => 'User', 'email' => uniqid('finance-bootstrap-', true) . '@test.local',
            'password' => bcrypt('local-only'), 'school_id' => $schoolId, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Role::withoutGlobalScope('school')->create(['name' => 'Teacher', 'guard_name' => 'web', 'school_id' => $schoolId, 'custom_role' => 0, 'editable' => 1]);
        $beforeAssignments = DB::connection('school')->table('model_has_roles')->count();
        $beforeFinance = $this->transactionalCounts();

        $service = app(SchoolDataService::class);
        $school = (object) ['id' => $schoolId];
        $service->createPermissions();
        $service->ensureFinanceRoleDefaultPermissions($school);
        $service->ensureFinanceRoleDefaultPermissions($school);

        $roles = Role::withoutGlobalScope('school')->where('school_id', $schoolId)->where('guard_name', 'web')->pluck('name')->sort()->values()->all();
        $this->assertSame(['Cashier', 'Head Finance', 'Teacher'], $roles);
        $this->assertSame(['Head Finance', 'Cashier'], SchoolDataService::FINANCE_ROLE_NAMES);
        $this->assertSame(1, Role::withoutGlobalScope('school')->where('school_id', $schoolId)->where('name', 'Head Finance')->where('guard_name', 'web')->count());
        $this->assertSame(1, Role::withoutGlobalScope('school')->where('school_id', $schoolId)->where('name', 'Cashier')->where('guard_name', 'web')->count());
        $this->assertTrue(Role::withoutGlobalScope('school')->where('school_id', $schoolId)->where('name', 'Head Finance')->firstOrFail()->hasPermissionTo('finance-handover-create'));
        $this->assertTrue(Role::withoutGlobalScope('school')->where('school_id', $schoolId)->where('name', 'Cashier')->firstOrFail()->hasPermissionTo('finance-handover-confirm'));
        $this->assertSame($beforeAssignments, DB::connection('school')->table('model_has_roles')->count());
        $this->assertSame([], DB::connection('school')->table('model_has_roles')->where('model_id', $userId)->get()->all());
        $this->assertSame($beforeFinance, $this->transactionalCounts());
    }

    public function test_bootstrap_command_accepts_only_the_existing_fixed_tenant_allowlist(): void
    {
        $this->assertTrue(BootstrapFinanceRoles::validTenantSelection(['eschool_saas_15_zixuan']));
        $this->assertFalse(BootstrapFinanceRoles::validTenantSelection([]));
        $this->assertFalse(BootstrapFinanceRoles::validTenantSelection(['mysql']));
        $this->assertFalse(BootstrapFinanceRoles::validTenantSelection(['eschool_saas_15_zixuan', 'eschool_saas_15_zixuan']));
    }

    private function createSchool(): int
    {
        return DB::connection('school')->table('schools')->insertGetId([
            'name' => 'Finance Bootstrap Test', 'address' => 'Local only', 'support_phone' => '0', 'support_email' => 'bootstrap@test.local',
            'tagline' => 'Local only', 'logo' => '', 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ensureSpatiePivots(): void
    {
        if (!Schema::connection('school')->hasTable('model_has_roles')) {
            Schema::connection('school')->create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
            });
        }
    }

    /** @return array<string, int> */
    private function transactionalCounts(): array
    {
        return collect(['bank_accounts', 'fees_paids', 'expenses', 'fund_handovers', 'bank_transfers'])
            ->filter(fn (string $table) => Schema::connection('school')->hasTable($table))
            ->mapWithKeys(fn (string $table) => [$table => DB::connection('school')->table($table)->count()])
            ->all();
    }
}
