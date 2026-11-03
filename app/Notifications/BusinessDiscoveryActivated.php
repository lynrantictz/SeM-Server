<?php

namespace App\Notifications;

use App\Models\Business\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessDiscoveryActivated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Business $business)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $publicUrl = rtrim(config('paperstick.client_url'), '/').'/menu/'.$this->business->uuid;
        $businessPortalUrl = rtrim(config('app.business_url'), '/').'/businesses/'.$this->business->uuid;

        return (new MailMessage)
            ->subject("Congratulations — {$this->business->name} is now on Paperstic Discovery")
            ->view('emails.business-discovery-activated', [
                'user' => $notifiable,
                'business' => $this->business,
                'publicUrl' => $publicUrl,
                'businessPortalUrl' => $businessPortalUrl,
            ]);
    }
}
