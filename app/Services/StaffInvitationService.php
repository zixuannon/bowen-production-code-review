<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TenantStaffInvitation;
use Illuminate\Support\Facades\URL;
use LogicException;

class StaffInvitationService
{
    public function __construct(private readonly TenantPasswordBroker $brokers) {}

    public function send(User $user): void
    {
        $token = $this->brokers->invitationBroker()->createToken($user);
        $user->notify(new TenantStaffInvitation($token, (int) $user->school_id));
    }

    public function createUrl(User $user, ?string $schoolCode = null): string
    {
        if (!$user->school_id) {
            throw new LogicException('A tenant School identity is required for a staff invitation.');
        }

        $school = trim((string) $schoolCode) !== ''
            ? app(SchoolCodeService::class)->resolveCanonical($schoolCode)
            : \App\Models\School::on('mysql')->find((int) $user->school_id);
        if (!$school || (int) $school->id !== (int) $user->school_id) {
            throw new LogicException('Staff invitation School identity mismatch.');
        }

        $token = $this->brokers->invitationBroker()->createToken($user);

        return URL::route('password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
            'school_code' => $school->code,
            'purpose' => 'staff_invitation',
        ]);
    }
}
