<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome to Paperstick</title>
</head>
<body style="margin:0;background:#f8fafc;color:#10233f;font-family:Arial,sans-serif;">
    <div style="max-width:600px;margin:40px auto;padding:32px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;">
        <p style="margin:0 0 24px;color:#ff7619;font-size:13px;font-weight:700;letter-spacing:2px;">PAPERSTICK</p>
        <h1 style="margin:0 0 16px;font-size:28px;">Welcome{{ $user->name ? ', ' . $user->name : '' }}.</h1>
        <p style="font-size:16px;line-height:1.6;color:#475569;">
            Your Paperstick account is ready. Start by creating your vendor and adding your first restaurant, hotel, café, or venue.
        </p>
        <p style="margin:28px 0;">
            <a href="{{ $businessUrl }}" style="display:inline-block;padding:13px 20px;background:#0b4385;color:#ffffff;text-decoration:none;border-radius:999px;font-weight:700;">Continue setup</a>
        </p>
        <p style="font-size:14px;line-height:1.6;color:#64748b;">
            No downloads for your guests. Just scan, order, and pay.
        </p>
    </div>
</body>
</html>
