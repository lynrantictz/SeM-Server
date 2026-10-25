<!doctype html><html lang="en"><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;color:#172033;line-height:1.6;max-width:600px;margin:0 auto;padding:24px">
<p style="font-weight:bold;color:#102b4e;font-size:22px">Paperstic</p>
<h1 style="font-size:24px">{{ $reminder ? 'Invoice payment reminder' : 'Your Paperstic invoice' }}</h1>
<p>{{ $recipient['business'] }}<br><strong>{{ $invoice->number }}</strong></p>
<p>Billing period: {{ $invoice->period_from->copy()->setTimezone($timezone)->format('d M Y H:i') }} - {{ $invoice->period_to->copy()->setTimezone($timezone)->format('d M Y H:i') }} (end exclusive).<br><span style="color:#64748b">All dates use {{ $timezone }}.</span></p>
<table cellpadding="10" cellspacing="0" style="border-collapse:collapse;width:100%;text-align:left;background:#f3f6fa">
<tr><td>Eligible orders</td><td style="text-align:right">{{ number_format($summary['order_count']) }}</td></tr>
<tr><td>Sales including order taxes</td><td style="text-align:right">{{ $summary['gross_sales'] === null ? 'Not recorded' : $invoice->currency.' '.number_format($summary['gross_sales'],2) }}</td></tr>
<tr><td>Subtotal excluding tax (commission base)</td><td style="text-align:right">{{ $invoice->currency }} {{ number_format($summary['commission_base_total'],2) }}</td></tr>
<tr><td>Total Paperstic fees</td><td style="text-align:right">{{ $invoice->currency }} {{ number_format($invoice->total,2) }}</td></tr>
<tr><td>Credits applied / paid</td><td style="text-align:right">{{ number_format($invoice->credit_amount,2) }} / {{ number_format($invoice->paid_amount,2) }}</td></tr>
<tr><td><strong>Amount due</strong></td><td style="text-align:right"><strong>{{ $invoice->currency }} {{ number_format($invoice->outstanding(),2) }}</strong></td></tr>
</table>
<p><strong>Payment deadline: {{ $invoice->due_at?->copy()->setTimezone($timezone)->format('d M Y H:i') }}.</strong></p>
<p>Your PDF invoice is attached. It includes the commission breakdown and payment instructions. Individual order details are available in the Business Portal.</p>
<p style="white-space:pre-line">{{ $invoice->payment_instructions }}</p>
<p><a href="{{ $url }}" style="color:#0b4689">View invoice and order details</a></p>
<p style="font-size:12px;color:#64748b">If overdue restrictions are enabled, new ordering may be paused after the deadline. Account access and completion of existing orders remain available.</p>
</body></html>
