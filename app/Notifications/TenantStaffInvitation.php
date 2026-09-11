<?php

namespace App\Notifications;

use App\Models\School;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use LogicException;

final class TenantStaffInvitation extends ResetPassword
{
    public function __construct($token, private readonly int $schoolId)
    {
        parent::__construct($token);
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Set up your eSchool staff password'))
            ->line(__('Your staff account is ready. Use the secure link below to set your first password.'))
            ->action(__('Set Password'), $this->resetUrl($notifiable))
            ->line(__('This invitation expires in 24 hours and can be used only once. A newer invitation invalidates this one.'));
    }

    protected function resetUrl($notifiable): string
    {
        $school = School::on('mysql')->whereKey($this->schoolId)->where('installed', 1)->where('status', 1)->first();
        if (!$school || !$school->code) {
            throw new LogicException('Unable to build a tenant staff invitation link.');
        }

        return URL::route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'school_code' => $school->code,
            'purpose' => 'staff_invitation',
        ]);
    }
}
