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
    protected bool $tenantDbAsDefault = true;

    use DatabaseTransactions;

    protected $connectionsToTransact = ['school'];
    private string $previousConnection;
    private ?string $previousPermissionConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        $this->previousPermissionConnection = config('permission.connection');
        DB::setDefaultConnection('school');
        config(['permission.connection' => 'school']);
        \Illuminate\Database\Eloquent\Model::setConnectionResolver(app('db'));
        $roleIds = DB::connection('school')->table('roles')
            ->whereIn('name', array_keys(ExistingTenantFinancePermissionBootstrap::ROLE_PERMISSIONS))
            ->pluck('id');
        if ($roleIds->isNotEmpty()) {
            DB::connection('school')->table('model_has_roles')->whereIn('role_id', $roleIds)->delete();
            DB::connection('school')->table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();
            DB::connection('school')->table('roles')->whereIn('id', $roleIds)->delete();
        }
        // Permission models may retain the central resolver from earlier
        // suites; isolate the disposable role catalog before each case.
        DB::connection('mysql')->table('roles')
            ->whereIn('name', array_keys(ExistingTenantFinancePermissionBootstrap::ROLE_PERMISSIONS))
            ->delete();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousConnection);
        config(['permission.connection' => $this->previousPermissionConnection]);
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
        $this->assertSame([], array_values(array_intersect($second['School Admin'], [
            'finance-handover-view', 'finance-handover-create', 'finance-handover-confirm', 'finance-handover-reject', 'finance-handover-cancel',
        ])));
        $this->assertSame($beforeAssignments, DB::connection('school')->table('model_has_roles')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertTrue($teacher->fresh()->hasPermissionTo('unrelated-permission'));
        $this->assertSame($beforeTransactions, $this->transactionalCounts());
        $this->assertSame(1, DB::connection('school')->table('model_has_roles')->where('model_id', $userId)->count());
    }

    public function test_unique_legacy_null_scoped_finance_roles_are_accepted_without_being_changed(): void
    {
        $schoolId = $this->createSchool();
        $this->roles($schoolId, ['Head Finance', 'Cashier']);

        $bootstrap = app(ExistingTenantFinancePermissionBootstrap::class);
        $bootstrap->apply($schoolId);

        foreach (ExistingTenantFinancePermissionBootstrap::ROLE_PERMISSIONS as $role => $permissions) {
            sort($permissions);
            $this->assertSame($permissions, $bootstrap->status($schoolId)[$role]);
        }
        $this->assertNull(Role::withoutGlobalScope('school')->where('name', 'Head Finance')->sole()->school_id);
        $this->assertNull(Role::withoutGlobalScope('school')->where('name', 'Cashier')->sole()->school_id);
    }

    public function test_duplicate_legacy_or_mixed_tenant_role_definitions_are_rejected(): void
    {
        $schoolId = $this->createSchool();
        $this->roles($schoolId, ['Head Finance']);
        $this->createRole('Head Finance', null);

        $this->expectException(\LogicException::class);
        app(ExistingTenantFinancePermissionBootstrap::class)->preflight($schoolId);
    }

    public function test_duplicate_null_legacy_role_definitions_are_rejected(): void
    {
        $schoolId = $this->createSchool();
        $this->roles($schoolId, ['Cashier']);
        $this->createRole('Cashier', null);

        $this->expectException(\LogicException::class);
        app(ExistingTenantFinancePermissionBootstrap::class)->preflight($schoolId);
    }

    public function test_role_belonging_to_another_tenant_is_rejected(): void
    {
        $schoolId = $this->createSchool();
        $otherSchoolId = $this->createSchool();
        $this->roles($schoolId);
        Role::withoutGlobalScope('school')->where('name', 'Cashier')->where('school_id', $schoolId)->delete();
        $this->createRole('Cashier', $otherSchoolId);

        $this->expectException(\LogicException::class);
        app(ExistingTenantFinancePermissionBootstrap::class)->preflight($schoolId);
    }

    public function test_command_accepts_only_fixed_active_school_codes_and_refuses_demo_or_database_names(): void
    {
        $this->assertTrue(BootstrapExistingTenantFinancePermissions::validTenantSelection(['SCH202615']));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection([]));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection(['SCH20261']));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection(['mysql']));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection(['eschool_saas_15_zixuan']));
        $this->assertFalse(BootstrapExistingTenantFinancePermissions::validTenantSelection(['SCH202615', 'SCH202615']));
    }

    /** @param array<int, string> $legacyNames
     *  @return array<string, Role>
     */
    private function roles(int $schoolId, array $legacyNames = []): array
    {
        return collect(['School Admin', 'Head Finance', 'Cashier'])
            ->mapWithKeys(fn (string $role) => [$role => $this->createRole($role, in_array($role, $legacyNames, true) ? null : $schoolId)])
            ->all();
    }

    private function createRole(string $name, ?int $schoolId): Role
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
