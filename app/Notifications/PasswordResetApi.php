<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetApi extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $resetUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your Paperstick password')
            ->view('emails.password-reset', [
                'user' => $notifiable,
                'resetUrl' => $this->resetUrl,
            ]);
    }
}
