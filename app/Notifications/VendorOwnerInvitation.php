<?php

namespace App\Notifications;

use App\Models\Business\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorOwnerInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Vendor $vendor,
        protected string $acceptUrl,
        protected bool $requiresPasswordSetup,
        protected int $invitationId,
        protected string $inviterName,
        protected int $expiresInDays = 2,
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
            ->subject("Invitation to manage {$this->vendor->name} on Paperstic")
            ->view('emails.vendor-owner-invitation', [
                'user' => $notifiable,
                'vendor' => $this->vendor,
                'acceptUrl' => $this->acceptUrl,
                'businessPortalUrl' => rtrim(config('app.business_url'), '/'),
                'requiresPasswordSetup' => $this->requiresPasswordSetup,
                'inviterName' => $this->inviterName,
                'expiresInDays' => $this->expiresInDays,
            ]);
    }
}
