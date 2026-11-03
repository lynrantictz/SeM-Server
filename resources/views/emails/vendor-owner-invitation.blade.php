<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Paperstic owner invitation</title>
</head>
<body style="margin:0;padding:0;background:#f5f8fc;color:#10233f;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;overflow:hidden;border:1px solid #e5eaf1;border-radius:16px;background:#fff;">
@include('emails.partials.header')
<tr><td style="padding:42px 36px;background:#0b4385;"><p style="margin:0 0 14px;color:#ffb27d;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">Owner invitation</p><h1 style="margin:0;color:#fff;font-size:31px;line-height:1.2;">Set up your {{ $vendor->name }} account.</h1></td></tr>
<tr><td style="padding:36px;"><p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Hello <strong>{{ $user->name }}</strong>,</p><p style="margin:0;font-size:16px;line-height:1.7;">Your Paperstic onboarding agent, <strong>{{ $inviterName }}</strong>, has set up <strong>{{ $vendor->name }}</strong> on Paperstic and invited you to take ownership. @if($requiresPasswordSetup) Accept the invitation to set up your secure account and manage your businesses. @else Accept the invitation to add this vendor to your existing Paperstic account and take ownership. Your password will not change. @endif</p>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0 18px;"><tr><td style="border-radius:999px;background:#ff7619;"><a href="{{ $acceptUrl }}" style="display:inline-block;padding:15px 26px;border-radius:999px;color:#fff;font-size:15px;font-weight:bold;text-decoration:none;">Accept invitation</a></td></tr></table>
<p style="margin:0;color:#64748b;font-size:13px;line-height:1.6;">This secure invitation expires in {{ $expiresInDays }} days. If you were not expecting it, you can safely ignore this email.</p>
<p style="margin:24px 0 0;color:#10233f;font-size:15px;line-height:1.6;">After accepting the invitation, open the Business Portal to continue.</p>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:14px 0 0;"><tr><td style="border-radius:999px;background:#0b4385;"><a href="{{ $businessPortalUrl }}" style="display:inline-block;padding:13px 22px;border-radius:999px;color:#fff;font-size:14px;font-weight:bold;text-decoration:none;">Open Business Portal</a></td></tr></table>
<p style="margin:12px 0 0;color:#64748b;font-size:12px;line-height:1.6;">Business Portal: <a href="{{ $businessPortalUrl }}" style="color:#0b4385;word-break:break-all;">{{ $businessPortalUrl }}</a></p>
<p style="margin:12px 0 0;color:#64748b;font-size:12px;line-height:1.6;">Invitation link: <a href="{{ $acceptUrl }}" style="color:#0b4385;word-break:break-all;">Accept invitation</a></p>
</td></tr>
<tr><td style="padding:0 36px 28px;"><p style="margin:0;color:#475569;font-size:14px;line-height:1.7;">Paperstic brings business information and hospitality operations together in one place.</p></td></tr>
@include('emails.partials.footer')
</table></td></tr></table>
</body>
</html>
