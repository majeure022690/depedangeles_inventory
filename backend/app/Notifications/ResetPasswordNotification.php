<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $broker = config('auth.defaults.passwords');

        return (new MailMessage)
            ->subject('Reset your password')
            ->view(['emails.auth.reset-password', 'emails.auth.reset-password-text'], [
                'name' => $notifiable->name,
                'url' => $this->resetUrl($notifiable),
                'expiresInMinutes' => config("auth.passwords.{$broker}.expire"),
                'sealPath' => resource_path('images/email/sdo-seal.png'),
            ]);
    }
}
