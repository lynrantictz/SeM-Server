<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome to Paperstic</title>
</head>
<body style="margin:0;padding:0;background:#f5f8fc;color:#10233f;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f8fc;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #e5eaf1;border-radius:16px;overflow:hidden;">
                @include('emails.partials.header')
                <tr>
                    <td style="padding:42px 36px;background:#0b4385;">
                        <p style="margin:0 0 14px;color:#ffb27d;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">Account ready</p>
                        <h1 style="margin:0;color:#ffffff;font-size:31px;line-height:1.2;">Welcome{{ $user->name ? ', ' . $user->name : '' }}.</h1>
                        <p style="margin:18px 0 0;color:#dce9f7;font-size:16px;line-height:1.65;">Your Paperstic account is ready for your first business.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:36px;color:#334155;">
                        <p style="margin:0;font-size:16px;line-height:1.7;">Start by creating your vendor and adding your first restaurant, hotel, café, or venue. You can then set up your menu, service locations, and team.</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:30px 0 0;">
                            <tr>
                                <td style="border-radius:999px;background:#ff7619;">
                                    <a href="{{ $businessUrl }}" style="display:inline-block;padding:15px 26px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;border-radius:999px;">Continue setup</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @include('emails.partials.footer')
            </table>
        </td>
    </tr>
</table>
</body>
</html>
