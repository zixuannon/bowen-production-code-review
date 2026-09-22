<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\SchoolDataService;
use App\Services\TenantConnectionScope;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SchoolProvisioningSafetyTest extends TestCase
{
    private array $mysql;
    private array $school;
    private string $centralDatabase;
    private string $tenantDatabase;
    private bool $databasesCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SCHOOL_PROVISIONING_MYSQL_REHEARSAL') !== '1') {
            $this->markTestSkipped('Set SCHOOL_PROVISIONING_MYSQL_REHEARSAL=1 for disposable local MySQL provisioning rehearsal.');
        }

        $this->mysql = config('database.connections.mysql');
        $this->school = config('database.connections.school');
        $host = (string) ($this->mysql['host'] ?? '');
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $this->fail("Disposable rehearsal refuses non-local MySQL host: {$host}");
        }

        $suffix = getmypid().'_'.bin2hex(random_bytes(4));
        $this->centralDatabase = 'eschool_step2_central_'.$suffix;
        $this->tenantDatabase = 'eschool_step2_tenant_'.$suffix;

        DB::connection('mysql')->statement("CREATE DATABASE `{$this->centralDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::connection('mysql')->statement("CREATE DATABASE `{$this->tenantDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->databasesCreated = true;

        Config::set('database.connections.mysql', $this->withDatabase($this->mysql, $this->centralDatabase));
        Config::set('database.connections.school', $this->withDatabase($this->school, $this->tenantDatabase));
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');

        $this->createSchema();
        $this->seedSchool();
    }

    protected function tearDown(): void
    {
        if (!isset($this->mysql)) {
            parent::tearDown();

            return;
        }

        DB::purge('mysql');
        DB::purge('school');
        Config::set('database.connections.mysql', $this->mysql);
        Config::set('database.connections.school', $this->school);
        DB::setDefaultConnection('mysql');
        if ($this->databasesCreated) {
            DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$this->centralDatabase}`");
            DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$this->tenantDatabase}`");
        }

        parent::tearDown();
    }

    public function test_first_provisioning_creates_exactly_one_target_school_admin_and_role(): void
    {
        $this->provisionWithRole();

        $this->assertSame(1, DB::connection('school')->table('users')->where('id', 10)->count());
        $this->assertSame('admin@example.test', DB::connection('school')->table('users')->where('id', 10)->value('email'));
        $this->assertSame(1, (int) DB::connection('school')->table('users')->where('id', 10)->value('school_id'));
        $this->assertSame(1, $this->schoolAdminAssignmentCount());
        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_repeat_provisioning_is_a_no_op_for_user_role_and_canonical_school_code(): void
    {
        $this->provisionWithRole();
        $this->provisionWithRole();

        $this->assertSame(1, DB::connection('school')->table('users')->where('id', 10)->count());
        $this->assertSame(1, $this->schoolAdminAssignmentCount());
        $this->assertSame('MMBOWEN05', School::on('mysql')->findOrFail(1)->code);
    }

    public function test_matching_existing_user_is_reused_and_missing_role_is_completed_once(): void
    {
        $now = now();
        DB::connection('school')->table('users')->insert([
            'id' => 10, 'school_id' => 1, 'first_name' => 'School', 'last_name' => 'Admin',
            'email' => 'admin@example.test', 'mobile' => '0999', 'password' => 'hash',
            'status' => 1, 'two_factor_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->provisionWithRole();
        $this->provisionWithRole();

        $this->assertSame(1, DB::connection('school')->table('users')->where('id', 10)->count());
        $this->assertSame(1, $this->schoolAdminAssignmentCount());
    }

    public function test_conflicting_existing_identity_fails_closed_without_overwrite_or_role_assignment(): void
    {
        $now = now();
        DB::connection('school')->table('users')->insert([
            'id' => 10, 'school_id' => 2, 'first_name' => 'Other', 'last_name' => 'Admin',
            'email' => 'other@example.test', 'mobile' => '0000', 'password' => 'other-hash',
            'status' => 1, 'two_factor_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $this->withinTenant(fn () => app(SchoolDataService::class)->provisionInitialSchoolAdmin($this->school()));
            $this->fail('Conflicting tenant identity must fail closed.');
        } catch (\LogicException) {
            // Expected: the existing tenant row must not be changed.
        }

        $this->assertSame('other@example.test', DB::connection('school')->table('users')->where('id', 10)->value('email'));
        $this->assertSame(0, $this->schoolAdminAssignmentCount());
    }

    public function test_email_bound_to_another_tenant_identity_fails_closed(): void
    {
        $now = now();
        DB::connection('school')->table('users')->insert([
            'id' => 11, 'school_id' => 1, 'first_name' => 'Other', 'last_name' => 'Identity',
            'email' => 'ADMIN@example.test', 'mobile' => '0000', 'password' => 'other-hash',
            'status' => 1, 'two_factor_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $this->withinTenant(fn () => app(SchoolDataService::class)->provisionInitialSchoolAdmin($this->school()));
            $this->fail('An email already bound to another tenant identity must fail closed.');
        } catch (\LogicException) {
            // Expected: identity ownership cannot be inferred from email alone.
        }

        $this->assertSame(0, DB::connection('school')->table('users')->where('id', 10)->count());
    }

    public function test_retry_after_exception_reuses_the_same_user_and_restores_connection_context(): void
    {
        try {
            $this->withinTenant(function (): void {
                app(SchoolDataService::class)->provisionInitialSchoolAdmin($this->school());
                throw new \RuntimeException('controlled tenant setup failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('controlled tenant setup failure', $exception->getMessage());
        }

        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->provisionWithRole();
        $this->assertSame(1, DB::connection('school')->table('users')->where('id', 10)->count());
        $this->assertSame(1, $this->schoolAdminAssignmentCount());
        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_central_super_admin_is_never_reused_as_target_school_admin(): void
    {
        DB::connection('mysql')->table('users')->insert([
            'id' => 99, 'school_id' => null, 'first_name' => 'Super', 'last_name' => 'Admin',
            'email' => 'super@example.test', 'mobile' => '0111', 'password' => 'hash',
            'status' => 1, 'two_factor_enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->provisionWithRole();

        $this->assertNull(DB::connection('mysql')->table('users')->where('id', 99)->value('school_id'));
        $this->assertFalse(DB::connection('school')->table('users')->where('id', 99)->exists());
        $this->assertSame(1, $this->schoolAdminAssignmentCount());
    }

    public function test_actual_tenant_setup_path_uses_the_single_idempotent_admin_provisioner(): void
    {
        $source = (string) file_get_contents(app_path('Services/SchoolDataService.php'));

        $this->assertStringContainsString('$this->provisionInitialSchoolAdmin($schoolData);', $source);
        $this->assertStringContainsString('$this->ensureInitialSchoolAdminRole($school);', $source);
        $this->assertStringContainsString("->insertOrIgnore([", $source);
        $this->assertStringNotContainsString('$userRow[]', $source);
        $this->assertStringNotContainsString("table('users')->insert(\$userRow)", $source);
    }

    private function provisionWithRole(): void
    {
        $this->withinTenant(function (): void {
            $school = $this->school();
            $service = app(SchoolDataService::class);
            $service->provisionInitialSchoolAdmin($school);
            $this->createSchoolAdminRole();
            $service->ensureInitialSchoolAdminRole($school);
        });
    }

    private function withinTenant(\Closure $callback): mixed
    {
        return app(TenantConnectionScope::class)->forSchool($this->school(), $callback);
    }

    private function school(): School
    {
        return School::on('mysql')->findOrFail(1);
    }

    private function createSchoolAdminRole(): void
    {
        DB::connection('school')->table('roles')->updateOrInsert(
            ['name' => 'School Admin', 'school_id' => 1],
            ['guard_name' => 'web', 'custom_role' => 0, 'editable' => 0, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function schoolAdminAssignmentCount(): int
    {
        return DB::connection('school')->table('model_has_roles as assignment')
            ->join('roles', 'roles.id', '=', 'assignment.role_id')
            ->where('assignment.model_id', 10)
            ->where('assignment.model_type', User::class)
            ->where('roles.name', 'School Admin')
            ->where('roles.school_id', 1)
            ->count();
    }

    private function createSchema(): void
    {
        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->id(); $table->string('name'); $table->string('code')->unique(); $table->string('database_name');
            $table->unsignedBigInteger('admin_id')->nullable(); $table->string('type')->default('custom');
            $table->boolean('status')->default(true); $table->boolean('installed')->default(false); $table->timestamps(); $table->softDeletes();
        });
        $this->createUsersTable('mysql');
        $this->createUsersTable('school');
        Schema::connection('school')->create('roles', function ($table): void {
            $table->id(); $table->string('name'); $table->string('guard_name')->default('web');
            $table->unsignedBigInteger('school_id')->nullable(); $table->boolean('custom_role')->default(false); $table->boolean('editable')->default(false); $table->timestamps();
            $table->unique(['name', 'school_id']);
        });
        Schema::connection('school')->create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id');
            $table->unique(['role_id', 'model_id', 'model_type']);
        });
    }

    private function createUsersTable(string $connection): void
    {
        Schema::connection($connection)->create('users', function ($table): void {
            $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id')->nullable();
            $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->string('mobile')->nullable();
            $table->string('email')->nullable(); $table->string('password')->nullable(); $table->boolean('status')->default(true);
            $table->boolean('two_factor_enabled')->default(false); $table->timestamp('email_verified_at')->nullable(); $table->timestamps(); $table->softDeletes();
        });
    }

    private function seedSchool(): void
    {
        $now = now();
        DB::connection('mysql')->table('schools')->insert([
            'id' => 1, 'name' => 'Provisioning QA', 'code' => 'MMBOWEN05', 'database_name' => $this->tenantDatabase,
            'admin_id' => 10, 'type' => 'custom', 'status' => 0, 'installed' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('mysql')->table('users')->insert([
            'id' => 10, 'school_id' => 1, 'first_name' => 'School', 'last_name' => 'Admin',
            'email' => 'admin@example.test', 'mobile' => '0999', 'password' => 'hash', 'status' => 1,
            'two_factor_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function withDatabase(array $connection, string $database): array
    {
        $connection['database'] = $database;

        return $connection;
    }
}
