<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\NotificationRecipientAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class LegacyStudentImportRetirementAndNotificationSecurityTest extends TestCase
{
    private array $mysqlConnection;
    private array $schoolConnection;
    private string $centralDatabase;
    private string $tenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->centralDatabase = tempnam(sys_get_temp_dir(), 'notification-central-');
        $this->tenantDatabase = tempnam(sys_get_temp_dir(), 'notification-tenant-');

        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->centralDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        Config::set('database.connections.school', ['driver' => 'sqlite', 'database' => $this->tenantDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('school');
        session(['db_connection_name' => 'school', 'school_database_name' => 'tenant_notification_qa']);

        $this->createCentralSchema();
        $this->createTenantSchema();
    }

    protected function tearDown(): void
    {
        session()->forget(['db_connection_name', 'school_database_name']);
        DB::purge('mysql');
        DB::purge('school');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        Config::set('database.connections.school', $this->schoolConnection);
        DB::setDefaultConnection(config('database.default'));
        @unlink($this->centralDatabase);
        @unlink($this->tenantDatabase);
        parent::tearDown();
    }

    public function test_school_admin_can_select_only_active_same_school_recipients_with_selected_roles(): void
    {
        $actor = $this->user(1, 1, 'School Admin');
        $frontDesk = $this->user(2, 1, 'Front Desk');
        $role = $this->role('Front Desk');

        $selection = app(NotificationRecipientAuthorizationService::class)
            ->authorize($actor, (string) $frontDesk->id, [(string) $role->id]);

        $this->assertSame([$frontDesk->id], $selection['recipients']->pluck('id')->all());
        $this->assertSame(['Front Desk'], $selection['roles']->pluck('name')->all());
    }

    public function test_cross_school_forged_mixed_inactive_and_tenant_mismatch_recipients_fail_closed(): void
    {
        $actor = $this->user(1, 1, 'School Admin');
        $sameSchool = $this->user(2, 1, 'Front Desk');
        $otherSchool = $this->user(3, 2, 'Front Desk');
        $inactive = $this->user(4, 1, 'Front Desk', 0);
        $deleted = $this->user(5, 1, 'Front Desk');
        $deleted->delete();
        $role = $this->role('Front Desk');
        $wrongRole = $this->role('Principal');
        $service = app(NotificationRecipientAuthorizationService::class);

        foreach ([
            (string) $otherSchool->id,
            $sameSchool->id.','.$otherSchool->id,
            '999999',
            (string) $inactive->id,
            (string) $deleted->id,
        ] as $recipients) {
            try {
                $service->authorize($actor, $recipients, [$role->id]);
                $this->fail('An invalid recipient set must be rejected before notification persistence or delivery.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('notifications', 0, 'school');
                $this->assertDatabaseCount('user_notifications', 0, 'school');
            }
        }

        $this->expectException(AuthorizationException::class);
        $service->authorize($actor, (string) $sameSchool->id, [$wrongRole->id]);

        // The assertion above exits through the expected exception; use a
        // fresh request below to exercise the trusted tenant mismatch path.
    }

    public function test_actor_tenant_mismatch_is_denied_before_any_notification_write_or_delivery(): void
    {
        $actor = $this->user(1, 1, 'School Admin');
        $sameSchool = $this->user(2, 1, 'Front Desk');
        $role = $this->role('Front Desk');
        $service = app(NotificationRecipientAuthorizationService::class);

        session(['school_database_name' => 'wrong_tenant']);
        $this->expectException(AuthorizationException::class);
        $service->authorize($actor, (string) $sameSchool->id, [$role->id]);
    }

    public function test_system_actor_retains_only_system_recipient_boundary(): void
    {
        $actor = $this->user(1, null, 'Super Admin');
        $systemRecipient = $this->user(2, null, 'Front Desk');
        $tenantRecipient = $this->user(3, 1, 'Front Desk');
        $role = $this->role('Front Desk');
        $service = app(NotificationRecipientAuthorizationService::class);

        $selection = $service->authorize($actor, (string) $systemRecipient->id, [$role->id]);
        $this->assertSame([$systemRecipient->id], $selection['recipients']->pluck('id')->all());

        $this->expectException(AuthorizationException::class);
        $service->authorize($actor, (string) $tenantRecipient->id, [$role->id]);
    }

    public function test_legacy_routes_are_retirement_guards_and_notification_authorization_precedes_every_write_or_delivery_path(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->keyBy('uri');
        foreach (['students/create-bulk', 'students/store-bulk', 'students/download-file'] as $legacyRoute) {
            $this->assertTrue($routes->has($legacyRoute));
            $this->assertSame('Closure', $routes->get($legacyRoute)->getActionName());
            try {
                ($routes->get($legacyRoute)->getAction('uses'))();
                $this->fail("{$legacyRoute} must be retired.");
            } catch (HttpException $exception) {
                $this->assertSame(410, $exception->getStatusCode());
            }
        }
        $uris = $routes->keys()->all();
        $this->assertContains('students/import-v2', $uris);
        $this->assertContains('students/import-v2/template', $uris);
        $this->assertContains('students/import-v2/preview', $uris);
        $this->assertContains('students/import-v2/confirm', $uris);

        $controller = file_get_contents(app_path('Http/Controllers/NotificationController.php'));
        $authorization = strpos($controller, 'recipientAuthorization->authorize');
        $transaction = strpos($controller, 'DB::beginTransaction');
        $notification = strpos($controller, '$this->notification->create');
        $userNotification = strpos($controller, 'UserNotification::insert');
        $delivery = strpos($controller, 'send_notification');

        $this->assertNotFalse($authorization);
        $this->assertNotFalse($transaction);
        $this->assertNotFalse($notification);
        $this->assertNotFalse($userNotification);
        $this->assertNotFalse($delivery);
        $this->assertLessThan($transaction, $authorization);
        $this->assertLessThan($notification, $authorization);
        $this->assertLessThan($userNotification, $authorization);
        $this->assertLessThan($delivery, $authorization);
    }

    private function createCentralSchema(): void
    {
        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('database_name');
            $table->timestamps();
            $table->softDeletes();
        });
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Tenant A', 'database_name' => 'tenant_notification_qa', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Tenant B', 'database_name' => 'tenant_notification_b', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function createTenantSchema(): void
    {
        Schema::connection('school')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('school')->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::connection('school')->create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::connection('school')->create('students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('guardian_id')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('school')->create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        Schema::connection('school')->create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
    }

    private function role(string $name): Role
    {
        $existing = Role::withoutGlobalScopes()->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        return Role::withoutGlobalScopes()->create(['name' => $name, 'guard_name' => 'web']);
    }

    private function user(int $id, ?int $schoolId, string $roleName, int $status = 1): User
    {
        $user = User::on('school')->create([
            'id' => $id,
            'school_id' => $schoolId,
            'first_name' => 'QA',
            'last_name' => (string) $id,
            'email' => "qa-{$id}-{$schoolId}@example.test",
            'status' => $status,
        ]);
        $user->roles()->attach($this->role($roleName));

        return $user->fresh(['roles']);
    }
}
