<?php

namespace App\Notifications;

use App\Models\Business\BusinessInvoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessInvoiceNotification extends Notification
{
    public function __construct(public BusinessInvoice $invoice, public bool $reminder = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pdf = app(\App\Services\Business\BusinessInvoicePdfService::class);
        $viewData = $pdf->viewData($this->invoice);

        $message = (new MailMessage)->subject(($this->reminder ? 'Payment reminder: ' : 'Paperstic invoice: ').$this->invoice->number)
            ->view('emails.business-invoice', [...$viewData, 'reminder' => $this->reminder, 'url' => rtrim(config('app.business_url'), '/').'/businesses/'.$this->invoice->business->uuid.'?section=billing'])
            ->attachData($pdf->render($this->invoice), $pdf->filename($this->invoice), ['mime' => 'application/pdf']);
        if (app()->environment(['local', 'testing']) && $this->invoice->business?->vendor?->email === 'billing-demo-vendor@paperstic.test') {
            $message->mailer('log');
        }

        return $message;
    }
}
