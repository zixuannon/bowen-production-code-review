<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Notifications\TenantStaffInvitation;
use App\Services\CachingService;
use App\Services\StaffInvitationService;
use App\Services\TenantPasswordBroker;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class StaffInvitationCanonicalIdentityTest extends TestCase
{
    private School $school;
    private User $user;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = config('database.connections.school.database');
        DB::connection('mysql')->beginTransaction();
        DB::connection('school')->beginTransaction();
        $this->school = School::on('mysql')->create([
            'name' => 'Invitation QA', 'address' => 'Local only',
            'support_phone' => '0', 'support_email' => 'school@qa.test',
            'database_name' => $this->database, 'code' => 'MMBOWEN'.random_int(10000, 99999999),
            'installed' => 1, 'status' => 1,
        ]);
        DB::connection('school')->table('schools')->insert([
            'id' => $this->school->id, 'name' => 'Tenant replica', 'code' => 'LEGACY-REPLICA',
        ]);
        DB::setDefaultConnection('school');
        $this->user = User::create([
            'first_name' => 'Invitation', 'last_name' => 'QA',
            'email' => 'invitation-'.bin2hex(random_bytes(5)).'@qa.test',
            'password' => Hash::make('DisposableOnly9!'), 'school_id' => $this->school->id, 'status' => 1,
        ]);
        request()->attributes->set('_trusted_tenant_context_active', true);
        request()->attributes->set('trusted_tenant_school_id', $this->school->id);
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@qa.test']);
        Mail::purge('array');
    }

    protected function tearDown(): void
    {
        config(['database.connections.school.database' => $this->database]);
        // Scoped connection restoration is covered in TenantPasswordResetTest;
        // this class retains both connections for transaction-only cleanup.
        DB::connection('school')->rollBack();
        DB::connection('mysql')->rollBack();
        DB::setDefaultConnection('mysql');
        parent::tearDown();
    }

    public static function staffRoles(): array
    {
        return [['Principal'], ['Front Desk / Admissions & Collection'], ['School Accountant'], ['Cashier'], ['School Admin']];
    }

    /** @dataProvider staffRoles */
    public function test_saved_school_id_wins_over_stale_replica_and_mail_is_generated_once(string $role): void
    {
        $roleId = DB::connection('school')->table('roles')->insertGetId([
            'name' => $role, 'guard_name' => 'web', 'school_id' => $this->school->id,
        ]);
        DB::connection('school')->table('model_has_roles')->insert([
            'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $this->user->id,
        ]);
        $beforeUsers = DB::connection('school')->table('users')->count();
        $originalHash = $this->user->password;
        $cache = Mockery::mock(CachingService::class);
        $cache->shouldReceive('getSchoolSettings')->once()->andReturn([
            'school_name' => 'Invitation QA', 'email-template-staff' => '{full_name} {code} {reset_link}',
        ]);
        $cache->shouldReceive('getSystemSettings')->once()->andReturn([]);
        $this->app->instance(CachingService::class, $cache);

        $this->assertSame('LEGACY-REPLICA', $this->user->school->code);
        $this->assertTrue(app(UserService::class)->sendStaffRegistrationEmail($this->user));
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertSame($this->user->email, $message->getTo()[0]->getAddress());
        $this->assertSame('Welcome to Invitation QA', $message->getSubject());
        $body = html_entity_decode($message->getHtmlBody());
        $this->assertStringContainsString($this->school->code, $body);
        $this->assertStringNotContainsString('LEGACY-REPLICA', $body);
        preg_match('~https?://[^\s"<>]+/password/reset/[^\s"<>]+~', $body, $links);
        $this->assertNotEmpty($links);
        $this->assertInvitationUrl($links[0]);
        $this->assertSame($beforeUsers, DB::connection('school')->table('users')->count());
        $this->assertSame($originalHash, DB::connection('school')->table('users')->where('id', $this->user->id)->value('password'));
        $this->assertSame(1, DB::connection('school')->table('model_has_roles')->where('model_id', $this->user->id)->count());
    }

    public function test_notification_send_uses_the_same_validated_canonical_context(): void
    {
        Notification::fake();
        app(StaffInvitationService::class)->send($this->user);
        Notification::assertSentTo($this->user, TenantStaffInvitation::class, function ($notification): bool {
            $this->assertInvitationUrl($notification->toMail($this->user)->actionUrl);
            return true;
        });
        Notification::assertCount(1);
    }

    public static function invalidContexts(): array
    {
        return array_map(fn ($case) => [$case], [
            'cross-school', 'missing-request-context', 'central-default', 'wrong-config-database',
            'wrong-live-database', 'missing-school', 'inactive-school', 'uninstalled-school',
            'deleted-school', 'blank-code', 'blank-database', 'changed-email', 'wrong-tenant-user',
        ]);
    }

    /** @dataProvider invalidContexts */
    public function test_invalid_identity_is_rejected_before_token_or_notification(string $case): void
    {
        Notification::fake();
        switch ($case) {
            case 'cross-school': request()->attributes->set('trusted_tenant_school_id', $this->school->id + 1); break;
            case 'missing-request-context': request()->attributes->remove('_trusted_tenant_context_active'); break;
            case 'central-default': DB::setDefaultConnection('mysql'); break;
            case 'wrong-config-database': config(['database.connections.school.database' => 'other_disposable_tenant']); break;
            case 'wrong-live-database':
                $this->school->database_name = 'other_disposable_tenant';
                $this->school->save();
                config(['database.connections.school.database' => 'other_disposable_tenant']);
                break;
            case 'missing-school': $this->user->setRawAttributes(array_merge($this->user->getAttributes(), ['school_id' => 2147483647]), true); break;
            case 'inactive-school': $this->school->update(['status' => 0]); break;
            case 'uninstalled-school': $this->school->update(['installed' => 0]); break;
            case 'deleted-school': $this->school->delete(); break;
            case 'blank-code': $this->school->update(['code' => '']); break;
            case 'blank-database': $this->school->update(['database_name' => '']); break;
            case 'changed-email': $this->user->email = 'other@qa.test'; break;
            case 'wrong-tenant-user': $this->user->id = 2147483647; break;
        }
        foreach (['createUrl', 'send'] as $method) {
            try {
                app(StaffInvitationService::class)->$method($this->user);
                $this->fail('Invalid invitation context accepted: '.$case);
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('invitation', $exception->getMessage());
            }
        }
        $this->assertSame(0, DB::connection('school')->table('staff_invitation_tokens')->where('email', $this->user->email)->count());
        Notification::assertNothingSent();
    }

    public function test_resend_replaces_the_old_token_without_creating_a_second_user(): void
    {
        $service = app(StaffInvitationService::class);
        $before = DB::connection('school')->table('users')->count();
        $old = basename(parse_url($service->createUrl($this->user), PHP_URL_PATH));
        $new = basename(parse_url($service->createUrl($this->user), PHP_URL_PATH));
        $broker = app(TenantPasswordBroker::class)->invitationBroker();
        $this->assertFalse($broker->tokenExists($this->user, $old));
        $this->assertTrue($broker->tokenExists($this->user, $new));
        $this->assertSame(1, DB::connection('school')->table('staff_invitation_tokens')->where('email', $this->user->email)->count());
        $this->assertSame($before, DB::connection('school')->table('users')->count());
    }

    private function assertInvitationUrl(string $url): void
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), parse_url($url, PHP_URL_HOST));
        $this->assertSame($this->user->email, $query['email']);
        $this->assertSame($this->school->code, $query['school_code']);
        $this->assertSame('staff_invitation', $query['purpose']);
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertTrue(app(TenantPasswordBroker::class)->invitationBroker()->tokenExists($this->user, $token));
        $this->assertSame(1440, config('auth.passwords.school_staff_invitations.expire'));
        $this->assertSame(1, DB::connection('school')->table('staff_invitation_tokens')->where('email', $this->user->email)->count());
    }
}
