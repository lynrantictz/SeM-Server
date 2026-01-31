<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Verify Your Email</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: Arial, Helvetica, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8; padding:20px 0;">
    <tr>
        <td align="center">

            <!-- Container -->
            <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden;">

                <!-- Header -->
                <tr>
                    <td style="padding:30px; text-align:center; background:#0f172a;">
                        <h1 style="margin:0; color:#ffffff; font-size:28px; letter-spacing:1px;">
                            ORDOS
                        </h1>
                        <p style="margin:8px 0 0; color:#cbd5e1; font-size:14px;">
                            No Download. Just Scan & Order.
                        </p>
                    </td>
                </tr>

                <!-- Body -->
                <tr>
                    <td style="padding:40px 30px; color:#334155;">
                        <h2 style="margin-top:0; font-size:22px;">
                            Verify your email address
                        </h2>

                        <p style="font-size:15px; line-height:1.6;">
{{--                            Hi <strong>{{ $user->name }}</strong>,--}}
                            Hi <strong>Hamis Juma</strong>,
                        </p>

                        <p style="font-size:15px; line-height:1.6;">
                            Thank you for completing your registration on <strong>Ordos</strong>.
                            To activate your account and start managing your business, please confirm your email address by clicking the button below.
                        </p>

                        <!-- Button -->
                        <table cellpadding="0" cellspacing="0" style="margin:30px 0;">
                            <tr>
                                <td align="center">
{{--                                    <a href="{{ $verificationUrl }}"--}}
                                    <a href="#"
                                       style="
                         background:#22c55e;
                         color:#ffffff;
                         text-decoration:none;
                         padding:14px 28px;
                         border-radius:6px;
                         font-weight:bold;
                         display:inline-block;
                         font-size:15px;
                       ">
                                        Verify Email Address
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="font-size:14px; color:#64748b; line-height:1.6;">
                            If the button above does not work, copy and paste the following link into your browser:
                        </p>

                        <p style="word-break:break-all; font-size:13px; color:#2563eb;">
{{--                            {{ $verificationUrl }}--}}
{{--                            {{ $verificationUrl }}--}} none
                        </p>

                        <p style="font-size:14px; color:#64748b; margin-top:30px;">
                            If you did not create an account, no further action is required.
                        </p>

                        <p style="font-size:14px; margin-top:20px;">
                            Regards,<br/>
                            <strong>Ordos Team</strong>
                        </p>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="padding:20px; text-align:center; background:#f8fafc; font-size:12px; color:#94a3b8;">
                        © {{ date('Y') }} Ordos. All rights reserved.<br/>
                        Smart QR Ordering for Restaurants & Hotels
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
