<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeOwnerApi extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to Paperstick')
            ->view('emails.welcome-owner', [
                'user' => $notifiable,
                'businessUrl' => config('app.business_url'),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
