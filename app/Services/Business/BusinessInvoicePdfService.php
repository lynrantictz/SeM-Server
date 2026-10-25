<?php

namespace App\Services\Business;

use App\Models\Business\BusinessInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;

class BusinessInvoicePdfService
{
    public function filename(BusinessInvoice $invoice): string
    {
        return 'Paperstic-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->number) . '.pdf';
    }

    public function viewData(BusinessInvoice $invoice): array
    {
        $invoice->loadMissing(['business.vendor', 'items', 'receipts']);
        $logoPath = config('business_billing.logo', public_path('paperstic-invoice-logo.png'));
        $logo = $logoPath && is_file($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;

        return [
            'invoice' => $invoice,
            'summary' => $invoice->summary(),
            'issuer' => $invoice->issuer_snapshot ?? config('business_billing.issuer', ['name' => 'Paperstic', 'email' => config('mail.from.address')]),
            'recipient' => $invoice->recipient_snapshot ?? ['business' => $invoice->business->name, 'email' => $invoice->billing_email],
            'timezone' => $invoice->business_timezone ?: 'UTC',
            'logo' => $logo
        ];
    }

    public function render(BusinessInvoice $invoice): string
    {
        $temporary = sys_get_temp_dir() . '/paperstic-invoice-pdf';
        if (! is_dir($temporary) && ! mkdir($temporary, 0700, true) && ! is_dir($temporary)) {
            throw new \RuntimeException('Invoice PDF temporary directory is unavailable.');
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $temporary);
        $options->set('fontCache', $temporary);
        $options->set('chroot', [public_path(), base_path('vendor/dompdf')]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('invoices.business-invoice', $this->viewData($invoice))->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(44, 810, 'Paperstic  |  ' . $invoice->number . '  |  Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.4, 0.4, 0.4]);

        return $pdf->output();
    }
}
