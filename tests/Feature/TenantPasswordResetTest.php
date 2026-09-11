<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Notifications\TenantResetPassword;
use App\Notifications\TenantStaffInvitation;
use App\Services\StaffInvitationService;
use App\Services\TenantPasswordBroker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class TenantPasswordResetTest extends TestCase
{
    private School $school;
    private School $otherSchool;
    private User $user;
    private string $schoolDatabase;
    private bool $createdHistoryTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolDatabase = (string) Config::get('database.connections.school.database');
        if (!Schema::connection('mysql')->hasTable('school_code_history')) {
            Schema::connection('mysql')->create('school_code_history', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('legacy_code', 64)->unique();
                $table->string('canonical_code', 64)->unique();
                $table->string('change_reason', 191);
                $table->timestamp('changed_at');
                $table->timestamps();
            });
            $this->createdHistoryTable = true;
        }
        $this->school = $this->school('RESETQA' . bin2hex(random_bytes(4)));
        $this->otherSchool = $this->school('OTHERQA' . bin2hex(random_bytes(4)));

        DB::setDefaultConnection('school');
        // Tenant schemas retain their own school row for the users.school_id
        // foreign key. Keep it aligned with the trusted central registry row.
        DB::connection('school')->table('schools')->insert([
            'id' => $this->school->id,
            'name' => $this->school->name,
        ]);
        $this->user = User::create([
            'first_name' => 'Tenant',
            'last_name' => 'Accountant',
            'email' => 'reset-' . bin2hex(random_bytes(6)) . '@qa.test',
            'password' => Hash::make('ExistingPassword9!'),
            'school_id' => $this->school->id,
            'status' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            DB::connection('school')->table('password_resets')->where('email', $this->user->email)->delete();
            DB::connection('school')->table('staff_invitation_tokens')->where('email', $this->user->email)->delete();
            DB::connection('school')->table('users')->where('id', $this->user->id)->delete();
        }

        if (isset($this->school)) {
            DB::connection('school')->table('schools')->where('id', $this->school->id)->delete();
        }

        $schoolIds = array_filter([
            $this->school->id ?? null,
            $this->otherSchool->id ?? null,
        ]);
        if ($schoolIds) {
            DB::connection('mysql')->table('school_code_history')->whereIn('school_id', $schoolIds)->delete();
            DB::connection('mysql')->table('schools')->whereIn('id', $schoolIds)->delete();
        }
        if ($this->createdHistoryTable) {
            Schema::connection('mysql')->drop('school_code_history');
        }

        DB::purge('school');
        Config::set('database.connections.school.database', $this->schoolDatabase);
        DB::setDefaultConnection('mysql');

        parent::tearDown();
    }

    public function test_tenant_notification_url_contains_token_email_and_trusted_school_code(): void
    {
        Notification::fake();

        $this->user->sendPasswordResetNotification('tenant-reset-token');

        Notification::assertSentTo($this->user, TenantResetPassword::class, function (TenantResetPassword $notification): bool {
            $url = $notification->toMail($this->user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_contains($url, '/password/reset/tenant-reset-token')
                && ($query['email'] ?? null) === $this->user->email
                && ($query['school_code'] ?? null) === $this->school->code;
        });
    }

    public function test_reset_form_preserves_tenant_identity_token_and_purpose(): void
    {
        $this->get(route('password.reset', [
            'token' => 'browser-form-token',
            'email' => $this->user->email,
            'school_code' => $this->school->code,
            'purpose' => 'staff_invitation',
        ]))
            ->assertOk()
            ->assertSee('name="token" value="browser-form-token"', false)
            ->assertSee('name="purpose" value="staff_invitation"', false)
            ->assertSee('name="school_code" value="'.$this->school->code.'"', false)
            ->assertSee('name="email"', false)
            ->assertSee('value="'.$this->user->email.'"', false);

        $this->get(route('password.reset', [
            'token' => 'forgot-form-token',
            'email' => $this->user->email,
            'school_code' => $this->school->code,
        ]))
            ->assertOk()
            ->assertSee('name="purpose" value="password_reset"', false);
    }

    public function test_password_link_request_requires_a_valid_registered_school_code(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => $this->user->email])
            ->assertSessionHasErrors('school_code');
        $this->post(route('password.email'), ['email' => $this->user->email, 'school_code' => 'UNKNOWN-SCHOOL'])
            ->assertSessionHasErrors('school_code');
        $this->post(route('password.email'), ['email' => $this->user->email, 'school_code' => $this->school->code])
            ->assertSessionHas('status');

        Notification::assertSentTo($this->user, TenantResetPassword::class);
    }

    public function test_audited_legacy_school_code_cannot_be_used_for_password_reset(): void
    {
        Notification::fake();
        $legacy = 'SCHLEGACY'.bin2hex(random_bytes(3));
        DB::connection('mysql')->table('school_code_history')->insert([
            'school_id' => $this->school->id,
            'legacy_code' => $legacy,
            'canonical_code' => $this->school->code,
            'change_reason' => 'Tenant reset canonical-only regression',
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('password.email'), ['email' => $this->user->email, 'school_code' => $legacy])
            ->assertSessionHasErrors('school_code');
        $token = $this->tokenForUser();
        $this->reset($token, $this->user->email, $legacy, 'LegacyCodePassword9!')
            ->assertSessionHasErrors('school_code');

        try {
            app(StaffInvitationService::class)->createUrl($this->user, $legacy);
            $this->fail('A deprecated School Code must not bind a staff invitation.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->useSchoolConnection();
        $this->assertFalse(Hash::check('LegacyCodePassword9!', $this->user->fresh()->password));
    }

    public function test_correct_tenant_reset_hashes_password_and_consumes_token(): void
    {
        $token = $this->tokenForUser();
        $password = 'TenantResetPassword9!';

        $response = $this->reset($token, $this->user->email, $this->school->code, $password);

        $response->assertRedirect(route('login'));
        $this->useSchoolConnection();
        $this->assertTrue(Hash::check($password, $this->user->fresh()->password));
        $this->assertSame(Password::INVALID_TOKEN, app(TenantPasswordBroker::class)->broker()->reset([
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'AnotherPassword9!',
            'password_confirmation' => 'AnotherPassword9!',
        ], static function (): void {
        }));
    }

    public function test_missing_invalid_or_cross_tenant_school_code_cannot_reset_a_tenant_user(): void
    {
        $token = $this->tokenForUser();
        $originalHash = $this->user->password;

        $this->reset($token, $this->user->email, null, 'MissingSchoolPassword9!')
            ->assertSessionHasErrors('school_code');
        $this->reset($token, $this->user->email, 'UNKNOWN-SCHOOL', 'InvalidSchoolPassword9!')
            ->assertSessionHasErrors('school_code');
        $this->reset($token, $this->user->email, $this->otherSchool->code, 'CrossTenantPassword9!')
            ->assertSessionHasErrors('email');

        $this->useSchoolConnection();
        $this->assertSame($originalHash, $this->user->fresh()->password);
    }

    public function test_tampered_email_and_expired_token_fail_without_changing_password(): void
    {
        $token = $this->tokenForUser();
        $originalHash = $this->user->password;

        $this->reset($token, 'tampered-' . $this->user->email, $this->school->code, 'TamperedEmailPassword9!')
            ->assertSessionHasErrors('email');

        DB::connection('school')->table('password_resets')
            ->where('email', $this->user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->reset($token, $this->user->email, $this->school->code, 'ExpiredTokenPassword9!')
            ->assertSessionHasErrors('email');

        $this->useSchoolConnection();
        $this->assertSame($originalHash, $this->user->fresh()->password);
    }

    public function test_staff_invitation_url_is_tenant_bound_and_marks_its_separate_purpose(): void
    {
        Notification::fake();

        $this->user->notify(new TenantStaffInvitation('staff-invitation-token', (int) $this->school->id));

        Notification::assertSentTo($this->user, TenantStaffInvitation::class, function (TenantStaffInvitation $notification): bool {
            $url = $notification->toMail($this->user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_contains($url, '/password/reset/staff-invitation-token')
                && ($query['email'] ?? null) === $this->user->email
                && ($query['school_code'] ?? null) === $this->school->code
                && ($query['purpose'] ?? null) === 'staff_invitation';
        });
    }

    public function test_staff_invitation_remains_valid_after_60_minutes_but_expires_after_24_hours(): void
    {
        $token = $this->invitationTokenForUser();
        DB::connection('school')->table('staff_invitation_tokens')->where('email', $this->user->email)->update(['created_at' => now()->subMinutes(61)]);

        $this->reset($token, $this->user->email, $this->school->code, 'InvitationPassword9!', 'staff_invitation')
            ->assertRedirect(route('login'));

        $expired = $this->invitationTokenForUser();
        DB::connection('school')->table('staff_invitation_tokens')->where('email', $this->user->email)->update(['created_at' => now()->subMinutes(1441)]);
        $currentHash = $this->user->fresh()->password;

        $this->reset($expired, $this->user->email, $this->school->code, 'ExpiredInvitation9!', 'staff_invitation')
            ->assertSessionHasErrors('email');
        $this->useSchoolConnection();
        $this->assertSame($currentHash, $this->user->fresh()->password);
    }

    public function test_new_staff_invitation_invalidates_old_token_and_successful_use_is_one_time(): void
    {
        $oldToken = $this->invitationTokenForUser();
        $newToken = $this->invitationTokenForUser();
        $broker = app(TenantPasswordBroker::class)->invitationBroker();

        $this->assertSame(Password::INVALID_TOKEN, $broker->reset($this->credentials($oldToken, 'OldInvitation9!'), static function (): void {}));
        $this->assertSame(Password::PASSWORD_RESET, $broker->reset($this->credentials($newToken, 'NewInvitation9!'), function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password)])->save();
        }));
        $this->assertSame(Password::INVALID_TOKEN, $broker->reset($this->credentials($newToken, 'ReplayInvitation9!'), static function (): void {}));
    }

    public function test_reset_and_invitation_tokens_cannot_be_used_across_purposes(): void
    {
        $invitation = $this->invitationTokenForUser();
        $this->reset($invitation, $this->user->email, $this->school->code, 'WrongPurpose9!')
            ->assertSessionHasErrors('email');

        $reset = $this->tokenForUser();
        $this->reset($reset, $this->user->email, $this->school->code, 'WrongPurposeAgain9!', 'staff_invitation')
            ->assertSessionHasErrors('email');
    }

    private function school(string $code): School
    {
        return School::on('mysql')->create([
            'name' => 'Tenant Reset QA ' . $code,
            'address' => 'Local test only',
            'support_phone' => '0',
            'support_email' => strtolower($code) . '@qa.test',
            'database_name' => $this->schoolDatabase,
            'code' => $code,
            'installed' => 1,
            'status' => 1,
        ]);
    }

    private function tokenForUser(): string
    {
        $this->useSchoolConnection();
        return app(TenantPasswordBroker::class)->broker()->createToken($this->user);
    }

    private function invitationTokenForUser(): string
    {
        $this->useSchoolConnection();
        return app(TenantPasswordBroker::class)->invitationBroker()->createToken($this->user);
    }

    /** @return array{token:string,email:string,password:string,password_confirmation:string} */
    private function credentials(string $token, string $password): array
    {
        return ['token' => $token, 'email' => $this->user->email, 'password' => $password, 'password_confirmation' => $password];
    }

    private function reset(string $token, string $email, ?string $schoolCode, string $password, ?string $purpose = null)
    {
        DB::setDefaultConnection('mysql');

        $payload = [
            'token' => $token,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ];

        if ($schoolCode !== null) {
            $payload['school_code'] = $schoolCode;
        }
        if ($purpose !== null) {
            $payload['purpose'] = $purpose;
        }

        return $this->from('/password/reset/' . $token)
            ->post(route('password.update'), $payload);
    }

    private function useSchoolConnection(): void
    {
        Config::set('database.connections.school.database', $this->schoolDatabase);
        DB::purge('school');
        DB::connection('school')->reconnect();
        DB::setDefaultConnection('school');
    }
}
