<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Notifications\TenantResetPassword;
use App\Services\TenantPasswordBroker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantPasswordResetTest extends TestCase
{
    private School $school;
    private School $otherSchool;
    private User $user;
    private string $schoolDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolDatabase = (string) Config::get('database.connections.school.database');
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
            DB::connection('mysql')->table('schools')->whereIn('id', $schoolIds)->delete();
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

    private function reset(string $token, string $email, ?string $schoolCode, string $password)
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
