<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Verify your Paperstick account</title>
</head>
<body style="margin:0;padding:0;background:#f5f8fc;color:#10233f;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f8fc;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #e5eaf1;border-radius:16px;overflow:hidden;">
                <tr>
                    <td style="height:5px;background:#ff7619;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td style="padding:28px 36px;background:#ffffff;">
                        <a href="{{ config('app.business_url') }}" style="text-decoration:none;">
                            <img src="{{ rtrim(config('app.business_url'), '/') }}/paperstic.png" alt="Paperstick" width="178" style="display:block;border:0;max-width:178px;height:auto;">
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:44px 36px 40px;background:#0b4385;">
                        <p style="margin:0 0 16px;color:#ffb27d;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">Welcome to Paperstick</p>
                        <h1 style="margin:0;color:#ffffff;font-size:32px;line-height:1.2;font-weight:700;">Let’s get your account ready.</h1>
                        <p style="margin:20px 0 0;color:#dce9f7;font-size:16px;line-height:1.65;">You are one step away from managing your hospitality business with a simpler, more connected service experience.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:36px;color:#334155;">
                        <p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Hello <strong>{{ $user->name }}</strong>,</p>
                        <p style="margin:0;font-size:16px;line-height:1.7;">Thank you for creating your Paperstick account. Please verify your email address to activate your account and continue setting up your vendor and first business.</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:30px 0;">
                            <tr>
                                <td style="border-radius:999px;background:#ff7619;">
                                    <a href="{{ $verificationUrl }}" style="display:inline-block;padding:15px 26px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;border-radius:999px;">Verify my email</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:0 0 10px;color:#64748b;font-size:13px;line-height:1.6;">This verification link expires in 60 minutes. If the button does not work, copy this link into your browser:</p>
                        <p style="margin:0;word-break:break-all;color:#0b4385;font-size:12px;line-height:1.6;">{{ $verificationUrl }}</p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:30px 0 0;background:#f5f8fc;border-radius:10px;">
                            <tr>
                                <td style="padding:16px 18px;color:#475569;font-size:13px;line-height:1.6;"><strong style="color:#10233f;">Didn’t create this account?</strong><br> You can safely ignore this email. No changes will be made to your account.</td>
                            </tr>
                        </table>
                        <p style="margin:30px 0 0;font-size:15px;line-height:1.6;">We look forward to helping you serve more guests, miss fewer moments, and grow with confidence.</p>
                        <p style="margin:22px 0 0;font-size:15px;line-height:1.6;">Warm regards,<br><strong>The Paperstick Team</strong></p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 36px;background:#f8fafc;border-top:1px solid #e5eaf1;text-align:center;">
                        <p style="margin:0 0 8px;color:#0b4385;font-size:13px;font-weight:bold;letter-spacing:1.5px;">NO DOWNLOADS. JUST SCAN, ORDER &amp; PAY.</p>
                        <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">© {{ date('Y') }} Paperstick. All rights reserved.<br>Hospitality operations, made simpler.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
