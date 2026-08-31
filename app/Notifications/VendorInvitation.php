<?php

namespace App\Notifications;

use App\Models\Business\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Vendor $vendor,
        protected string $acceptUrl,
        protected bool $requiresPasswordSetup,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to {$this->vendor->name} on Paperstick")
            ->view('emails.vendor-invitation', [
                'user' => $notifiable,
                'vendor' => $this->vendor,
                'acceptUrl' => $this->acceptUrl,
                'requiresPasswordSetup' => $this->requiresPasswordSetup,
            ]);
    }
}
