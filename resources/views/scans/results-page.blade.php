<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Scan results · {{ $order->order_number }}</title>
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/nunito-local.css') }}">
    <style>
        :root{--ink:#16213e;--teal:#0d7377;--text:#1e293b;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc}
        *{box-sizing:border-box}
        body{margin:0;background:var(--soft);color:var(--text);font-family:Nunito,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:15px;line-height:1.5}
        .wrap{max-width:720px;margin:0 auto;padding:24px 16px 48px}
        .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;margin-top:16px}
        h1{font-size:20px;margin:0;color:var(--ink)}
        .muted{color:var(--muted);font-size:13px}
        dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}
        dt{color:var(--muted)}
        dd{margin:0;font-weight:600}
        .file{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-top:1px solid var(--line)}
        .file:first-child{border-top:0}
        .btn{display:inline-block;background:var(--teal);color:#fff;text-decoration:none;font-weight:700;padding:8px 14px;border-radius:8px;white-space:nowrap}
        .btn:focus-visible{outline:3px solid #14d9c4;outline-offset:2px}
    </style>
</head>
<body>
<main class="wrap">
    <p class="muted" style="margin:0">{{ $clinic?->name }}</p>
    <h1>Scan results for {{ $patient?->name ?? 'your patient' }}</h1>
    <p class="muted" style="margin:4px 0 0">Shared with {{ $partner?->name ?? 'the referring clinic' }} with the patient's agreement. This page works until {{ $expires->format('d M Y') }}.</p>

    <section class="card">
        <dl>
            <dt>Order</dt><dd>{{ $order->order_number }}</dd>
            <dt>Scans</dt><dd>{{ $items->map->label()->implode(', ') }}</dd>
            @if($order->reported_at)<dt>Reported</dt><dd>{{ $order->reported_at->format('d M Y') }}</dd>@endif
        </dl>
    </section>

    <section class="card" aria-labelledby="files-title">
        <h2 id="files-title" style="font-size:16px;margin:0 0 4px">Files</h2>
        @forelse($documents as $document)
            <div class="file">
                <div><b>{{ $document->title }}</b><div class="muted">{{ $document->original_name }} · {{ number_format(max(1, $document->file_size / 1024), 0) }} KB</div></div>
                <a class="btn" href="{{ $fileUrl($document) }}" target="_blank" rel="noopener">Open</a>
            </div>
        @empty
            <p class="muted" style="margin:0">No files are attached yet. Please contact {{ $clinic?->name ?? 'the clinic' }}.</p>
        @endforelse
    </section>
</main>
</body>
</html>
