<?php

namespace Tests\Feature;

use App\Models\FinanceGroup;
use App\Services\FinanceGroupScopeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceGroupScopeServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;

    /** @var array<string, mixed> */
    private array $schoolConnection;

    /** @var array<int, string> */
    private array $databaseFiles = [];

    private string $centralDatabase;
    private string $tenantOne;
    private string $tenantTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->centralDatabase = $this->temporaryDatabase();
        $this->tenantOne = $this->temporaryDatabase();
        $this->tenantTwo = $this->temporaryDatabase();

        $sqlite = static fn (string $database): array => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
        Config::set('database.connections.mysql', $sqlite($this->centralDatabase));
        Config::set('database.connections.school', $sqlite($this->tenantOne));
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('database_name')->unique();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Member One', 'code' => 'MEMBER_ONE', 'database_name' => $this->tenantOne],
            ['id' => 2, 'name' => 'Member Two', 'code' => 'MEMBER_TWO', 'database_name' => $this->tenantTwo],
            ['id' => 3, 'name' => 'Unrelated', 'code' => 'UNRELATED', 'database_name' => $this->temporaryDatabase()],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'school_id' => null, 'email' => 'group@example.test'],
            ['id' => 101, 'school_id' => null, 'email' => 'other@example.test'],
        ]);

        $migration = require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php');
        $migration->up();

        $this->createTenantSchema($this->tenantOne, 201, 1);
        $this->createTenantSchema($this->tenantTwo, 202, 2);
        Config::set('database.connections.school.database', $this->tenantOne);
        DB::purge('school');
        DB::setDefaultConnection('mysql');
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        Config::set('database.connections.school', $this->schoolConnection);
        DB::setDefaultConnection('mysql');

        foreach ($this->databaseFiles as $databaseFile) {
            @unlink($databaseFile);
        }

        parent::tearDown();
    }

    public function test_group_scope_schema_is_central_only_and_a_group_code_can_be_configured_later(): void
    {
        DB::setDefaultConnection('school');

        $group = app(FinanceGroupScopeService::class)->createGroup([
            'name' => 'Configurable Bowen Group',
            'code' => null,
            'reporting_currency' => 'MMK',
        ]);
        $group = app(FinanceGroupScopeService::class)->updateGroup($group, [
            'code' => 'BOWEN_GROUP',
            'name' => 'Configurable Bowen Group',
            'status' => 'active',
            'reporting_currency' => 'mmk',
            'fiscal_year_start_month' => 4,
        ]);

        $this->assertSame('mysql', $group->getConnectionName());
        $this->assertSame('BOWEN_GROUP', FinanceGroup::on('mysql')->findOrFail($group->id)->code);
        $this->assertSame('MMK', $group->reporting_currency);
        $this->assertSame(4, $group->fiscal_year_start_month);
        $this->assertTrue(Schema::connection('mysql')->hasTable('finance_groups'));
        $this->assertFalse(Schema::connection('school')->hasTable('finance_groups'));
        $this->assertSame(0, DB::connection('school')->table('bank_accounts')->count());

        $this->expectException(ValidationException::class);
        app(FinanceGroupScopeService::class)->createGroup(['name' => 'Duplicate', 'code' => 'BOWEN_GROUP']);
    }

    public function test_group_school_configuration_uses_only_the_central_registry_and_revokes_missing_memberships(): void
    {
        $service = app(FinanceGroupScopeService::class);
        $group = $service->createGroup(['name' => 'Configurable Group']);

        $service->syncSchools($group, [1, 2, 2]);
        $groupUser = $service->addUser($group, 100);
        $service->grantScope($groupUser, 'view_reports', 'GROUP');
        $this->assertSame([1, 2], $service->accessibleSchools($groupUser)->pluck('school_id')->all());

        $service->syncSchools($group, [2]);
        $this->assertSame('revoked', $group->schools()->where('school_id', 1)->value('status'));
        $this->assertSame('active', $group->schools()->where('school_id', 2)->value('status'));

        try {
            $service->syncSchools($group, [2, 999]);
            $this->fail('An unknown School was accepted from configuration input.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::connection('school')->table('bank_accounts')->count());
        }
    }

    public function test_explicit_membership_and_scope_never_infer_cross_school_access(): void
    {
        $service = app(FinanceGroupScopeService::class);
        $group = $service->createGroup(['name' => 'Bowen Draft']);
        $service->addSchool($group, 1);
        $service->addSchool($group, 2);
        $groupUser = $service->addUser($group, 100);

        $service->grantScope($groupUser, 'view_reports', 'SCHOOL', 1);
        $this->assertSame([1], $service->accessibleSchools($groupUser)->pluck('school_id')->all());

        $service->grantScope($groupUser, 'view_reports', 'GROUP');
        $this->assertSame([1, 2], $service->accessibleSchools($groupUser)->pluck('school_id')->all());

        $this->expectException(ValidationException::class);
        $service->grantScope($groupUser, 'view_reports', 'SCHOOL', 3);
    }

    public function test_duplicate_scope_is_updated_in_place_and_revoked_membership_is_removed_from_access(): void
    {
        $service = app(FinanceGroupScopeService::class);
        $group = $service->createGroup(['name' => 'Bowen Draft']);
        $service->addSchool($group, 1);
        $groupUser = $service->addUser($group, 100);

        $first = $service->grantScope($groupUser, 'view_reports', 'SCHOOL', 1);
        $second = $service->grantScope($groupUser, 'view_reports', 'SCHOOL', 1);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $groupUser->scopes()->count());

        $service->revokeSchool($group, 1);
        $this->assertSame([], $service->accessibleSchools($groupUser)->pluck('school_id')->all());
    }

    public function test_revoked_group_user_loses_access_without_deleting_historical_scope_records(): void
    {
        $service = app(FinanceGroupScopeService::class);
        $group = $service->createGroup(['name' => 'Bowen Draft']);
        $service->addSchool($group, 1);
        $groupUser = $service->addUser($group, 100);
        $service->grantScope($groupUser, 'view_reports', 'SCHOOL', 1);

        $groupUser->update(['status' => 'revoked']);

        $this->assertSame([], $service->accessibleSchools($groupUser)->pluck('school_id')->all());
        $this->assertSame(1, $groupUser->scopes()->count());
    }

    public function test_tenant_identity_is_explicit_validated_and_connection_is_restored(): void
    {
        $service = app(FinanceGroupScopeService::class);
        $group = $service->createGroup(['name' => 'Bowen Draft']);
        $service->addSchool($group, 1);
        $groupUser = $service->addUser($group, 100);

        $previousDefault = DB::getDefaultConnection();
        $previousTenant = config('database.connections.school.database');
        $identity = $service->bindTenantIdentity($groupUser, 1, 201);

        $this->assertSame(201, $identity->tenant_user_id);
        $this->assertSame($previousDefault, DB::getDefaultConnection());
        $this->assertSame($previousTenant, config('database.connections.school.database'));
        $this->assertSame(1, $groupUser->tenantIdentities()->count());
        $this->assertSame(0, DB::connection('school')->table('bank_accounts')->count());

        try {
            $service->bindTenantIdentity($groupUser, 1, 202);
            $this->fail('A user from another tenant was accepted.');
        } catch (ValidationException) {
            $this->assertSame($previousDefault, DB::getDefaultConnection());
            $this->assertSame($previousTenant, config('database.connections.school.database'));
            $this->assertSame(1, $groupUser->tenantIdentities()->count());
        }
    }

    public function test_unknown_central_user_and_school_are_rejected_without_tenant_writes(): void
    {
        $service = app(FinanceGroupScopeService::class);
        $group = $service->createGroup(['name' => 'Bowen Draft']);

        try {
            $service->addSchool($group, 999);
            $this->fail('Unknown School was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, DB::connection('school')->table('bank_accounts')->count());
        }

        $this->expectException(ModelNotFoundException::class);
        $service->addUser($group, 999);
    }

    public function test_migration_rollback_is_central_only(): void
    {
        $migration = require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php');
        $migration->down();

        $this->assertFalse(Schema::connection('mysql')->hasTable('finance_groups'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('finance_group_user_tenant_identities'));
        $this->assertTrue(Schema::connection('school')->hasTable('bank_accounts'));
        $this->assertSame(0, DB::connection('school')->table('bank_accounts')->count());
    }

    private function createTenantSchema(string $database, int $userId, int $schoolId): void
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        Schema::connection('school')->create('users', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('school')->create('bank_accounts', function ($table): void {
            $table->id();
            $table->string('account_name');
        });
        DB::connection('school')->table('users')->insert(['id' => $userId, 'school_id' => $schoolId]);
    }

    private function temporaryDatabase(): string
    {
        $database = tempnam(sys_get_temp_dir(), 'eschool_finance_group_');
        $this->databaseFiles[] = $database;

        return $database;
    }
}
