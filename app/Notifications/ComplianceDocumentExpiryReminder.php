<?php

namespace App\Notifications;

use App\Models\Business\ComplianceDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ComplianceDocumentExpiryReminder extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ComplianceDocument $document,
        private readonly string $reminderKey,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $isExpired = $this->reminderKey === 'expired';
        $label = $this->document->document_type_name
            ?? $this->document->documentType?->name
            ?? $this->document->document_type;
        $ownerName = $this->document->business?->name ?? $this->document->vendor?->name;

        return [
            'kind' => 'compliance_document_expiry',
            'title' => $isExpired ? "{$label} has expired" : "{$label} expires soon",
            'message' => $isExpired
                ? "The {$label} for {$ownerName} has expired. Upload a current document before payment activation or renewal."
                : "The {$label} for {$ownerName} expires on {$this->document->expires_at?->format('j M Y')}. Upload a renewal in time.",
            'document_uuid' => $this->document->uuid,
            'business_uuid' => $this->document->business?->uuid,
            'vendor_uuid' => $this->document->vendor?->uuid,
            'expires_at' => $this->document->expires_at?->toDateString(),
        ];
    }
}
