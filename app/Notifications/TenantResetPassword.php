<?php

namespace App\Notifications;

use App\Models\School;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * A password-reset notification whose tenant is fixed at issuance time.
 *
 * Tenant users are stored in their school database, while the school code is
 * held in the trusted central registry. Capturing the school ID avoids relying
 * on a request/session when this notification is delivered synchronously or
 * from a queue.
 */
class TenantResetPassword extends ResetPassword
{
    public function __construct($token, private readonly int $schoolId)
    {
        parent::__construct($token);
    }

    protected function resetUrl($notifiable)
    {
        $school = School::on('mysql')
            ->whereKey($this->schoolId)
            ->where('installed', 1)
            ->where('status', 1)
            ->first();

        if (!$school || !$school->code) {
            throw new LogicException('Unable to build a tenant password reset link.');
        }

        return URL::route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'school_code' => $school->code,
        ]);
    }
}
