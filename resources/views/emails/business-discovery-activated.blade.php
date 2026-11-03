<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Your business is live on Paperstic Discovery</title>
</head>
<body style="margin:0;padding:0;background:#f5f8fc;color:#10233f;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;overflow:hidden;border:1px solid #e5eaf1;border-radius:16px;background:#fff;">
@include('emails.partials.header')
<tr><td style="padding:42px 36px;background:#0b4385;"><p style="margin:0 0 14px;color:#ffb27d;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">Business activated</p><h1 style="margin:0;color:#fff;font-size:31px;line-height:1.2;">{{ $business->name }} is live on Discovery.</h1></td></tr>
<tr><td style="padding:36px;"><p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Hello <strong>{{ $user->name }}</strong>,</p><p style="margin:0;font-size:16px;line-height:1.7;">Congratulations! <strong>{{ $business->name }}</strong> is active and now part of the Paperstic Discovery community. Guests can find your business, browse its menu, and place or schedule orders from their browser.</p>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0 16px;"><tr><td style="border-radius:999px;background:#ff7619;"><a href="{{ $publicUrl }}" style="display:inline-block;padding:15px 26px;border-radius:999px;color:#fff;font-size:15px;font-weight:bold;text-decoration:none;">View your business on Discovery</a></td></tr></table>
<p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;">Public page: <a href="{{ $publicUrl }}" style="color:#0b4385;word-break:break-all;">{{ $publicUrl }}</a></p>
<p style="margin:24px 0 0;color:#475569;font-size:14px;line-height:1.7;">Manage your business profile, menu, and operations in the Business Portal: <a href="{{ $businessPortalUrl }}" style="color:#0b4385;word-break:break-all;">Open {{ $business->name }}</a></p>
</td></tr>
<tr><td style="padding:0 36px 28px;"><p style="margin:0;color:#475569;font-size:14px;line-height:1.7;">Paperstic brings business information and hospitality operations together in one place.</p></td></tr>
@include('emails.partials.footer')
</table></td></tr></table>
</body>
</html>
