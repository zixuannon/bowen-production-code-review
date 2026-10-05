<?php

namespace App\Services;

use App\Models\School;
use App\Models\User;
use App\Notifications\TenantStaffInvitation;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;
use LogicException;

class StaffInvitationService
{
    public function __construct(private readonly TenantPasswordBroker $brokers) {}

    public function send(User $user): void
    {
        $school = $this->schoolForCurrentTenant($user);
        $token = $this->brokers->invitationBroker()->createToken($user);
        $user->notify(new TenantStaffInvitation($token, (int) $school->id));
    }

    public function createUrl(User $user): string
    {
        return $this->issueUrl($user, $this->schoolForCurrentTenant($user));
    }

    /** Normal Staff requests must already have an authenticated tenant context. */
    public function schoolForCurrentTenant(User $user): School
    {
        $school = $this->canonicalSchool($user);
        if (app(TrustedTenantContextService::class)->trustedSchoolIdForCurrentRequest(request()) !== (int) $school->id) {
            throw new LogicException('Staff invitation School identity mismatch.');
        }

        $this->assertTenantIdentity($user, $school);

        return $school;
    }

    /** Only authorized School provisioning/admin callers select a tenant here. */
    public function createUrlForSchool(User $user, School $target): string
    {
        $school = $this->canonicalSchool($user);
        $requestSchool = app(TrustedTenantContextService::class)->trustedSchoolIdForCurrentRequest(request());
        if ((int) $target->getKey() !== (int) $school->id
            || ($requestSchool !== null && $requestSchool !== (int) $school->id)) {
            throw new LogicException('Staff invitation School identity mismatch.');
        }

        return app(TenantConnectionScope::class)->forSchool($school, function () use ($user, $school): string {
            $this->assertTenantIdentity($user, $school);

            return $this->issueUrl($user, $school);
        });
    }

    private function canonicalSchool(User $user): School
    {
        // Neither the tenant-local School replica nor User's Guardian accessor
        // is an authority for invitation ownership.
        $schoolId = (int) $user->getRawOriginal('school_id');
        $school = $user->exists && $schoolId > 0
            ? School::on('mysql')->whereKey($schoolId)->where('installed', 1)->where('status', 1)->first()
            : null;
        if (!$school || trim((string) $school->code) === '' || trim((string) $school->database_name) === '') {
            throw new LogicException('An active canonical School is required for a staff invitation.');
        }

        return $school;
    }

    private function assertTenantIdentity(User $user, School $school): void
    {
        if (DB::getDefaultConnection() !== 'school'
            || config('database.connections.school.database') !== $school->database_name
            || DB::connection('school')->getDatabaseName() !== $school->database_name
            || !DB::connection('school')->table('users')->where('id', $user->getKey())
                ->where('school_id', $school->id)->where('email', $user->getEmailForPasswordReset())
                ->whereNull('deleted_at')->exists()) {
            throw new LogicException('Staff invitation tenant identity mismatch.');
        }
    }

    private function issueUrl(User $user, School $school): string
    {
        $token = $this->brokers->invitationBroker()->createToken($user);

        return URL::route('password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
            'school_code' => $school->code,
            'purpose' => 'staff_invitation',
        ]);
    }
}
