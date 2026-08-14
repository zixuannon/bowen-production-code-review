<?php

namespace Tests\Feature;

use App\Console\Commands\BootstrapExistingTenantFinancePermissions;
use App\Models\Role;
use App\Services\ExistingTenantFinancePermissionBootstrap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExistingTenantFinancePermissionBootstrapTest extends TestCase
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

    public function test_existing_tenant_bootstrap_applies_only_the_exact_finance_permission_matrix_idempotently(): void
    {
        $schoolId = $this->createSchool();
        $roles = $this->roles($schoolId);
        $teacher = $this->createRole('Teacher', $schoolId);
        Permission::findOrCreate('unrelated-permission', 'web');
        $teacher->givePermissionTo('unrelated-permission');
        $userId = $this->createCashierAssignment($schoolId, $roles['Cashier']->id);
        $beforeAssignments = DB::connection('school')->table('model_has_roles')->get()->map(fn ($row) => (array) $row)->all();
        $beforeTransactions = $this->transactionalCounts();

        $bootstrap = app(ExistingTenantFinancePermissionBootstrap::class);
        $this->assertSame([
            'School Admin' => [],
            'Head Finance' => [],
            'Cashier' => [],
        ], $bootstrap->status($schoolId));

        $bootstrap->apply($schoolId);
        $first = $bootstrap->status($schoolId);
        $bootstrap->apply($schoolId);
        $second = $bootstrap->status($schoolId);

        foreach (ExistingTenantFinancePermissionBootstrap::ROLE_PERMISSIONS as $role => $permissions) {
            $expected = $permissions;
            sort($expected);
            $this->assertSame($expected, $second[$role]);
        }
        $this->assertSame($first, $second);
        $this->assertNotContains('finance-staff-manage', $second['Cashier']);
        $this->assertSame(['finance-handover-view'], array_values(array_intersect($second['School Admin'], [
            'finance-handover-view', 'finance-handover-create', 'finance-handover-confirm', 'finance-handover-reject', 'finance-handover-cancel',
        ])));
        $this->assertSame($beforeAssignments, DB::connection('school')->table('model_has_roles')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertTrue($teacher->fresh()->hasPermissionTo('unrelated-permission'));
        $this->assertSame($beforeTransactions, $this->transactionalCounts());
        $this->assertSame(1, DB::connection('school')->table('model_has_roles')->where('model_id', $userId)->count());
    }

    public function test_command_accepts_only_fixed_active_tenant_names_and_refuses_demo(): void
    {
        $this->assertTrue(BootstrapExistingTenantFinancePermissions::validTenantSelection(['eschool_saas_15_zixuan']));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection([]));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection(['eschool_saas_1_demo']));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection(['mysql']));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection(['eschool_saas_15_zixuan', 'eschool_saas_15_zixuan']));
    }

    /** @return array<string, Role> */
    private function roles(int $schoolId): array
    {
        return collect(['School Admin', 'Head Finance', 'Cashier'])
            ->mapWithKeys(fn (string $role) => [$role => $this->createRole($role, $schoolId)])
            ->all();
    }

    private function createRole(string $name, int $schoolId): Role
    {
        return Role::withoutGlobalScope('school')->create([
            'name' => $name,
            'guard_name' => 'web',
            'school_id' => $schoolId,
            'custom_role' => 1,
            'editable' => 1,
        ]);
    }

    private function createSchool(): int
    {
        return DB::connection('school')->table('schools')->insertGetId([
            'name' => 'Existing Finance Permission Bootstrap',
            'address' => 'Local only',
            'support_phone' => '0',
            'support_email' => uniqid('finance-bootstrap-', true) . '@test.local',
            'tagline' => 'Local only',
            'logo' => '',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCashierAssignment(int $schoolId, int $roleId): int
    {
        $userId = DB::connection('school')->table('users')->insertGetId([
            'first_name' => 'Existing',
            'last_name' => 'Cashier',
            'email' => uniqid('existing-cashier-', true) . '@test.local',
            'password' => bcrypt('local-only'),
            'school_id' => $schoolId,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('school')->table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => \App\Models\User::class,
            'model_id' => $userId,
        ]);

        return $userId;
    }

    /** @return array<string, int> */
    private function transactionalCounts(): array
    {
        return collect(['bank_accounts', 'bank_account_user', 'fees_paids', 'expenses', 'fund_handovers', 'bank_transfers'])
            ->filter(fn (string $table) => Schema::connection('school')->hasTable($table))
            ->mapWithKeys(fn (string $table) => [$table => DB::connection('school')->table($table)->count()])
            ->all();
    }
}
