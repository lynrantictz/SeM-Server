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
        protected int $invitationId,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->invitationData();
    }

    /**
     * Keeps the invitation payload compatible with Laravel's database channel
     * and workers that resolve notification data through toArray().
     */
    public function toArray(object $notifiable): array
    {
        return $this->invitationData();
    }

    private function invitationData(): array
    {
        return [
            'kind' => 'vendor_invitation',
            'vendor_name' => $this->vendor->name,
            'vendor_uuid' => $this->vendor->uuid,
            'invitation_id' => $this->invitationId,
            'requires_password_setup' => $this->requiresPasswordSetup,
        ];
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
