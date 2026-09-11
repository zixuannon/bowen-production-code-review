<?php

namespace App\Services;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;

/**
 * Creates a fresh school-bound password broker for each tenant operation.
 *
 * PasswordBrokerManager caches a token repository connection. A tenant switch
 * must therefore never reuse a broker resolved before the school connection
 * was selected.
 */
class TenantPasswordBroker
{
    public function broker(): PasswordBrokerContract
    {
        return (new PasswordBrokerManager(app()))->broker('school_users');
    }

    public function invitationBroker(): PasswordBrokerContract
    {
        return (new PasswordBrokerManager(app()))->broker('school_staff_invitations');
    }
}
