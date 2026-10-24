<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingAgentInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your Paperstic onboarding agent invitation')->greeting('Hello '.$notifiable->name)->line('You have been invited to help onboard vendors and businesses in the Paperstic Business portal.')->action('Set your password', $this->url)->line('This link expires in 48 hours. Operations portal access is not included.');
    }
}
