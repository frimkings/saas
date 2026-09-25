<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $paymentDue ? 'Subscription payment due' : ucfirst($place ?? 'clinic').' access suspended' }}</title>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f6f9;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937;padding:16px;box-sizing:border-box}
        .card{background:#fff;max-width:480px;width:100%;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:32px;text-align:center}
        .icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:30px;background:{{ $paymentDue ? '#fef3c7' : '#fee2e2' }}}
        h1{font-size:22px;margin:0 0 8px} p{color:#4b5563;line-height:1.5;margin:0 0 12px}
        .contact{background:#f9fafb;border-radius:10px;padding:14px;margin:18px 0;text-align:left}
        .contact div{margin:4px 0} .contact a{color:#1d4ed8}
        .btn{display:inline-block;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;border:0;cursor:pointer;font-size:14px}
        .btn-wa{background:#16a34a;color:#fff;margin-bottom:10px} .btn-out{background:#e5e7eb;color:#111827}
    </style>
</head>
<body>
<div class="card">
    <div class="icon">{{ $paymentDue ? '⏳' : '🔒' }}</div>
    @if($paymentDue)
        <h1>Subscription payment has not been made</h1>
        <p>{{ $clinic }}'s subscription has expired and has not been renewed, so the system is unavailable.</p>
        <p>Please ask your {{ $place ?? 'clinic' }} administrator to renew the subscription. Access returns as soon as payment is confirmed.</p>
    @else
        <h1>{{ ucfirst($place ?? 'clinic') }} access suspended</h1>
        <p>{{ $clinic }}'s subscription has not been renewed, so access to the system is suspended.</p>
        <p>Please contact {{ $support['name'] }} to restore access.</p>
        @if($support['phone'] || $support['email'] || $whatsappUrl)
            <div class="contact">
                <strong>{{ $support['name'] }}</strong>
                @if($support['phone'])<div>📞 <a href="tel:{{ $support['phone'] }}">{{ $support['phone'] }}</a></div>@endif
                @if($support['email'])<div>✉️ <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a></div>@endif
            </div>
            @if($whatsappUrl)<a class="btn btn-wa" href="{{ $whatsappUrl }}" target="_blank" rel="noopener">Chat on WhatsApp</a><br>@endif
        @endif
    @endif
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-out">Sign out</button>
    </form>
</div>
</body>
</html>
