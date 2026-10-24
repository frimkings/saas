@php
    $brand = $support['name'] ?: config('app.name');
    $fmt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0);
    $gb = fn ($mb) => $mb === null ? 'Unlimited' : ($mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB' : $mb . ' MB');
    $allOptional = collect($extras['optional'])->keyBy('key');
    $previous = null;
    $old = ['plan' => old('plan'), 'interval' => old('interval', 'monthly'), 'features' => old('features', []), 'users' => old('users'),
        'storage_mb' => old('storage_mb'), 'visits' => old('visits'), 'branches' => old('branches'), 'staff' => old('staff'),
        'tab' => old('plan') ? (old('features') || old('plan') === 'attendance' ? 'build' : 'plans') : null];
    $attPackages = collect($attendance['packages'])->keyBy('key');
    $attModules = collect($attendance['modules'])->keyBy('key');
    $attFirst = array_key_first($attendance['sizes']);
    $attPrev = null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Pricing · {{ $brand }}</title>
    <meta name="description" content="Plans and prices for {{ $brand }}: the eye clinic management platform and the Attendance Suite for staff attendance. Pick a plan or tick the features you need.">
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/nunito-local.css') }}">
    <style>
        :root{--navy:#0f3460;--ink:#16213e;--teal:#0d7377;--mint:#14d9c4;--text:#1e293b;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc;--ok:#0f766e;--warn:#b45309}
        *{box-sizing:border-box}
        body{margin:0;font-family:'Nunito',system-ui,sans-serif;color:var(--text);background:var(--soft);line-height:1.5}
        a{color:var(--teal)}
        .wrap{max-width:1180px;margin:0 auto;padding:0 16px}
        .top{background:linear-gradient(145deg,#0f3460 0%,#16213e 45%,#0d7377 100%);color:#fff;position:relative;overflow:hidden}
        .top:before{content:'';position:absolute;width:420px;height:420px;border-radius:50%;background:rgba(255,255,255,.04);top:-160px;left:-120px}
        .nav{display:flex;align-items:center;gap:12px;padding:10px 0 2px;position:relative}
        .brand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-weight:800;font-size:18px;margin-right:auto}
        .brand svg{width:34px;height:34px;padding:6px;border-radius:50%;background:rgba(255,255,255,.12);stroke:#fff;fill:none;stroke-width:1.8}
        .nav a.link{color:rgba(255,255,255,.8);text-decoration:none;font-weight:700;font-size:14px;padding:8px 10px;border-radius:8px}
        .nav a.link:hover{color:#fff;background:rgba(255,255,255,.08)}
        .nav a.signin{background:#fff;color:var(--navy);text-decoration:none;font-weight:800;font-size:14px;padding:9px 16px;border-radius:9px}
        .hero{text-align:center;padding:0 0 14px;position:relative}
        .hero h1{font-size:clamp(22px,2.6vw,28px);margin:0;line-height:1.15}
        .hero p{margin:0 auto;max-width:640px;color:rgba(255,255,255,.75);font-size:15px}
        .bar{width:54px;height:3px;border-radius:2px;background:linear-gradient(90deg,#14d9c4,#0d7377);margin:18px auto}
        .hero-row{display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:10px;margin-top:8px}
        .toggle{display:inline-flex;background:rgba(255,255,255,.1);border-radius:999px;padding:4px}
        .toggle button{border:0;background:transparent;color:rgba(255,255,255,.8);font:inherit;font-weight:800;font-size:14px;padding:8px 18px;border-radius:999px;cursor:pointer}
        .toggle button[aria-pressed=true]{background:#fff;color:var(--navy)}
        .toggle small{font-weight:700;color:var(--mint);margin-left:4px}
        .toggle button[aria-pressed=true] small{color:var(--teal)}
        .tabs{display:inline-flex;gap:4px;background:rgba(255,255,255,.1);border-radius:999px;padding:4px}
        .tabs button{border:0;background:transparent;color:rgba(255,255,255,.85);font:inherit;font-weight:800;padding:8px 18px;border-radius:999px;cursor:pointer;font-size:14px}
        .tabs button[aria-selected=true]{background:var(--mint);color:var(--ink)}
        main.wrap{padding-top:16px}
        .tabs button:focus-visible,.toggle button:focus-visible,.btn:focus-visible{outline:3px solid var(--mint);outline-offset:2px}
        .cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;align-items:stretch}
        .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 14px;display:flex;flex-direction:column;position:relative;box-shadow:0 1px 2px rgba(15,23,42,.04)}
        .card.featured{border:2px solid var(--teal);box-shadow:0 12px 32px rgba(13,115,119,.16)}
        .ribbon{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--teal);color:#fff;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:4px 12px;border-radius:999px;white-space:nowrap}
        .card h3{margin:0;font-size:17px}
        .card .tag{color:var(--muted);font-size:12.5px;line-height:1.4;min-height:36px;margin:2px 0 8px}
        .price{font-size:26px;font-weight:800;color:var(--ink);line-height:1.1}
        .price{white-space:nowrap}.price small{font-size:12px;font-weight:700;color:var(--muted)}
        .per{font-size:11.5px;color:var(--muted);min-height:16px}
        .facts{list-style:none;padding:0;margin:8px 0 10px;font-size:12.5px;line-height:1.35;border-top:1px solid var(--line);padding-top:8px}
        .facts li{display:flex;gap:6px;padding:2px 0}
        .facts li b{color:var(--ink)}
        .facts .plus{font-weight:800;color:var(--muted);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;margin-top:6px}
        .tick{color:var(--ok);font-weight:900;flex-shrink:0}
        .card .btn{margin-top:auto}
        .btn{display:inline-flex;justify-content:center;align-items:center;gap:8px;border:0;border-radius:10px;padding:9px 14px;font:inherit;font-weight:800;font-size:14px;cursor:pointer;text-decoration:none;background:linear-gradient(135deg,#0f3460,#0d7377);color:#fff;width:100%}
        .btn.alt{background:#fff;color:var(--navy);border:1px solid var(--line)}
        .btn.wa{background:#128c4a}
        .btn[disabled]{opacity:.55;cursor:not-allowed}
        h2{font-size:20px;margin:28px 0 4px;color:var(--ink)}
        .lead{color:var(--muted);margin:0 0 16px}
        .table-wrap{overflow-x:auto;background:#fff;border:1px solid var(--line);border-radius:14px}
        table{border-collapse:collapse;width:100%;min-width:760px;font-size:14px}
        th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:center}
        th:first-child,td:first-child{text-align:left;position:sticky;left:0;background:#fff}
        thead th{background:var(--soft);font-size:13px;color:var(--ink);position:sticky;top:0;z-index:2;box-shadow:inset 0 -1px 0 var(--line)}
        thead th:first-child{background:var(--soft);z-index:3}
        /* In the compare dialog the table scrolls itself, so its header row stays in view. */
        #compare .table-wrap{overflow:auto;max-height:calc(100vh - 190px)}
        tr.group td{background:#f1f5f9;font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left}
        td.yes{color:var(--ok);font-weight:900}td.no{color:#cbd5e1}
        th.featured{color:var(--teal)}
        .addons{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
        .addon{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px}
        .addon b{display:block}.addon span{color:var(--muted);font-size:13px}
        .builder{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
        .panel{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px}
        .panel h3{margin:0 0 4px;font-size:16px}
        .group-title{font-size:12px;text-transform:uppercase;letter-spacing:.05em;font-weight:800;color:var(--muted);margin:18px 0 6px}
        .opt{display:flex;align-items:flex-start;gap:9px;padding:6px 8px;border-radius:9px;cursor:pointer;border:1px solid transparent;font-size:14px}
        .opt:hover{background:var(--soft)}
        .opt input{width:18px;height:18px;margin-top:2px;accent-color:var(--teal);flex-shrink:0}
        .opt .name{flex:1}.opt .name small{display:block;color:var(--muted);font-size:12px}
        .opt .cost{font-weight:800;color:var(--ink);white-space:nowrap;font-size:13px}
        .opt.core{cursor:default;opacity:.8}
        .controls{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:6px}
        .field{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:700;color:var(--ink)}
        .field input,.field select,.field textarea{font:inherit;font-weight:600;border:1.5px solid var(--line);border-radius:10px;padding:8px 10px;background:#fff;color:var(--text);width:100%}
        .field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,115,119,.15)}
        .field small{font-weight:600;color:var(--muted)}
        .summary{position:sticky;top:16px}
        .summary .best{font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em}
        .summary .plan{font-size:19px;font-weight:800;color:var(--ink);margin:0}
        .summary .total{font-size:28px;font-weight:800;color:var(--navy);line-height:1.15}
        .summary .total small{font-size:14px;color:var(--muted)}
        .lines{list-style:none;margin:8px 0 0;padding:0;font-size:13px}.lines:empty{display:none}
        .lines li{display:flex;justify-content:space-between;gap:10px;padding:3px 0}
        .save{background:#ecfdf5;color:#065f46;border-radius:9px;padding:7px 10px;font-size:12.5px;font-weight:700;margin:8px 0 0}
        .others{font-size:12px;margin:10px 0 12px;padding:8px 0 0;list-style:none;border-top:1px solid var(--line)}
        .others li{display:flex;justify-content:space-between;gap:8px;padding:1px 0;color:var(--muted)}
        .others li.on{color:var(--ink);font-weight:800}
        .stack{display:grid;gap:6px}
        .request{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start;margin-bottom:40px}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .form-grid .wide{grid-column:1/-1}
        .chosen{background:linear-gradient(145deg,#0f3460,#0d7377);color:#fff;border-radius:16px;padding:20px}
        .chosen .plan{font-size:22px;font-weight:800}.chosen .total{font-size:28px;font-weight:800}
        .chosen ul{padding-left:18px;margin:10px 0 0;color:rgba(255,255,255,.85);font-size:14px}
        .chosen a{color:#fff}
        .alert{border-radius:12px;padding:14px 16px;margin-bottom:14px;font-weight:700}
        .alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
        .alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .err-text{color:#b91c1c;font-size:12px;font-weight:700}
        .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
        .muted{color:var(--muted)}
        footer{color:var(--muted);padding:10px 0 12px;font-size:13px}
        footer .wrap{display:flex;flex-wrap:wrap;gap:16px;justify-content:space-between}
        footer a{color:var(--teal)}
        .foot-link{border:0;background:none;padding:0;font:inherit;font-weight:800;color:var(--teal);cursor:pointer}
        .foot-link:hover{text-decoration:underline}.foot-link:focus-visible{outline:3px solid var(--mint);outline-offset:2px;border-radius:4px}
        .dlg-scroll{padding:14px 20px 20px;overflow:auto;max-height:calc(100vh - 140px)}
        dialog.req#compare{width:min(1100px,calc(100% - 32px))}
        .compare{margin-top:22px}
        .compare summary{cursor:pointer;font-weight:800;color:var(--teal);list-style:none;display:inline-flex;align-items:center;gap:6px;padding:8px 0}
        .compare summary::-webkit-details-marker{display:none}
        .compare summary:before{content:'\25B8';transition:transform .15s}.compare[open] summary:before{transform:rotate(90deg)}
        .compare summary:focus-visible{outline:3px solid var(--mint);outline-offset:2px;border-radius:6px}
        .core-line{background:var(--soft);border-radius:9px;padding:7px 10px;font-size:12px;color:var(--muted);margin:10px 0 8px}
        .tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
        .tiles-group{margin:14px 0 6px;font-size:12px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--muted)}
        .tile{display:grid;grid-template-columns:18px minmax(0,1fr) auto 38px;gap:8px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:7px 10px;font-size:13px;cursor:pointer}
        .tile:hover{border-color:#94a3b8}.tile:has(input:checked){border-color:var(--teal);background:#f0fdfa}
        .tile input{width:16px;height:16px;margin:0;accent-color:var(--teal)}
        .tile .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .tile .in,.in-eg{font-size:11px;font-weight:700;color:var(--teal);background:#e6f4f3;border-radius:999px;padding:1px 7px;white-space:nowrap}
        .tile .cost{text-align:right;font-weight:800;color:var(--ink);font-size:12.5px}
        .tiles-note{font-size:11.5px;color:var(--muted);margin:8px 0 0}
        .core-line b{color:var(--ink)}
        .feat-list{border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-top:12px}
        .feat-head,.feat{display:grid;grid-template-columns:20px minmax(0,1fr) 110px 80px;gap:12px;align-items:center;padding:9px 14px}
        .feat-head{background:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding-top:10px;padding-bottom:8px}
        .feat-head span:nth-child(3),.feat-head span:nth-child(4){text-align:right}
        .feat-group{background:var(--soft);border-top:1px solid var(--line);padding:6px 14px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
        .feat{border-top:1px solid var(--line);cursor:pointer;font-size:14px}
        .feat:hover{background:#f8fafc}.feat:has(input:checked){background:#f0fdfa}
        .feat input{width:18px;height:18px;margin:0;accent-color:var(--teal)}
        .feat .in{justify-self:end;font-size:12px;font-weight:700;color:var(--teal);background:#e6f4f3;border-radius:999px;padding:2px 9px;white-space:nowrap}
        .feat .cost{text-align:right;font-weight:800;color:var(--ink);white-space:nowrap}
        .feat .cost small{font-weight:600;color:var(--muted);font-size:11px;margin-left:1px}
        dialog.req{border:0;border-radius:18px;padding:0;width:min(900px,calc(100% - 32px));max-height:calc(100vh - 32px);box-shadow:0 30px 80px rgba(15,23,42,.35)}
        dialog.req::backdrop{background:rgba(15,23,42,.6)}
        .dlg-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:18px 20px 0}
        .dlg-head h2{margin:0}.dlg-head p{margin:2px 0 0;color:var(--muted);font-size:14px}
        .dlg-close{border:0;background:var(--soft);border-radius:10px;width:36px;height:36px;font-size:20px;cursor:pointer;color:var(--ink);flex-shrink:0}
        .dlg-body{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:16px;padding:14px 20px 20px}
        .dlg-body .panel{padding:0;border:0}
        [hidden]{display:none!important}
        .products{display:inline-flex;gap:2px;background:rgba(0,0,0,.18);border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:3px;margin:6px 0 10px}
        .products button{border:0;background:transparent;color:rgba(255,255,255,.75);font:inherit;font-weight:800;font-size:14px;padding:7px 16px;border-radius:9px;cursor:pointer}
        .products button[aria-selected=true]{background:rgba(255,255,255,.16);color:#fff}
        .products button:focus-visible{outline:3px solid var(--mint);outline-offset:2px}
        .cards.four{grid-template-columns:repeat(4,minmax(0,1fr))}
        .free-sms{color:#dc2626;font-weight:800}
        .compare-card{border-style:dashed;background:transparent;box-shadow:none;align-self:start;gap:10px;text-align:center}
        .compare-card .btn,.compare-card .foot-link{margin-top:0}
        .compare-card h3{margin:0}.compare-card .tag{margin:0}
        .staffbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:10px 16px;margin-bottom:16px}
        .staffbar label{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--ink);font-size:14px}
        .staffbar input{font:inherit;font-weight:700;border:1.5px solid var(--line);border-radius:10px;padding:7px 10px;width:110px}
        .staffbar input:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,115,119,.15)}
        .staffbar .note{color:var(--muted);font-size:13px;flex:1;min-width:220px}
        .staffbar .note b{color:var(--ink)}
        .staffbar.special{border-color:#f59e0b;background:#fffbeb}
        .price.special{font-size:20px;white-space:normal}
        .addon .foot-link{margin-top:6px;display:inline-block;font-size:13px}
        @media(max-width:1100px){.cards{grid-template-columns:repeat(3,minmax(0,1fr))}.cards.four{grid-template-columns:repeat(2,minmax(0,1fr))}.tiles{grid-template-columns:1fr}}
        @media(max-width:900px){.builder,.request,.dlg-body{grid-template-columns:1fr}.summary{position:static}.dlg-body .chosen{order:-1}.controls{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:700px){.tiles{grid-template-columns:1fr}.tile .name{white-space:normal}.cards,.cards.four{grid-template-columns:1fr}.nav a.link{display:none}.form-grid{grid-template-columns:1fr}.tabs button,.toggle button{padding:7px 12px;font-size:13px}.card .tag{min-height:0}}
    </style>
</head>
<body>
<header class="top">
    <div class="wrap">
        <nav class="nav" aria-label="Main">
            <a class="brand" href="{{ route('pricing') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/><circle cx="12" cy="12" r="3"/></svg>{{ $brand }}</a>
            <a class="link" href="#plans" data-tab-link="plans">Plans</a>
            <a class="link" href="#build" data-tab-link="build">Customize</a>
            <a class="link" href="#attendance" data-product-link="attendance">Staff attendance</a>
            <a class="link" href="#request" data-open-request>Request a plan</a>
            <a class="signin" href="{{ route('login') }}">Sign in</a>
        </nav>
        <div class="hero">
            <div class="products" role="tablist" aria-label="Product">
                <button type="button" role="tab" id="product-tab-clinic" aria-controls="product-clinic" aria-selected="true" data-product="clinic">Eye clinics</button>
                <button type="button" role="tab" id="product-tab-attendance" aria-controls="product-attendance" aria-selected="false" data-product="attendance">Staff attendance</button>
            </div>
            <h1 data-att="Simple pricing for staff attendance">Simple pricing for eye clinics</h1>
            <div class="hero-row">
                <div class="tabs" role="tablist" aria-label="Pricing">
                    <button type="button" role="tab" id="tab-plans" aria-controls="panel-plans" aria-selected="true" data-tab="plans">Plans</button>
                    <button type="button" role="tab" id="tab-build" aria-controls="panel-build" aria-selected="false" data-tab="build">Customize</button>
                </div>
                <div class="toggle" role="group" aria-label="Billing">
                    <button type="button" data-interval="monthly" aria-pressed="true">Monthly</button>
                    <button type="button" data-interval="yearly" aria-pressed="false">Yearly <small data-clinic-only>+ free SMS</small></button>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="wrap">

    @if(session('pricing_sent'))<div class="alert ok" role="status">{{ session('pricing_sent') }}</div>@endif

    <div id="product-clinic" role="tabpanel" aria-labelledby="product-tab-clinic">
    @if($tiers->isEmpty())
        <div class="panel" style="text-align:center;margin-bottom:40px">
            <h3>Prices are on their way</h3>
            <p class="muted">Contact us for a quote. @if($support['phone']) ☏ {{ $support['phone'] }} @endif @if($support['email']) ✉ {{ $support['email'] }} @endif</p>
        </div>
    @else
    {{-- ── Plans ── --}}
    <section id="panel-plans" role="tabpanel" aria-labelledby="tab-plans">
        <div class="cards">
            @foreach($tiers as $tier)
                @php($new = $previous ? array_values(array_diff($tier['features'], $previous['features'])) : $tier['features'])
                <article class="card {{ $tier['featured'] ? 'featured' : '' }}" aria-label="{{ $tier['name'] }}">
                    @if($tier['featured'])<span class="ribbon">Most popular</span>@endif
                    <h3>{{ $tier['name'] }}</h3>
                    <p class="tag">{{ $tier['tagline'] }}</p>
                    <div class="price" data-monthly="{{ $tier['monthly'] }}" data-yearly="{{ $tier['yearly'] }}">{{ $tier['currency'] }} {{ $fmt($tier['monthly']) }} <small>/mo</small></div>
                    <div class="per" data-per data-monthly-text="" data-yearly-text="{{ $tier['currency'] }} {{ $fmt($tier['yearly'] / 12) }} a month, paid yearly{!! $tier['yearly_sms'] ? ' &middot; <b class=&quot;free-sms&quot;>' . number_format($tier['yearly_sms']) . ' free SMS</b>' : '' !!}">&nbsp;</div>
                    <ul class="facts">
                        <li><span class="tick">✓</span><span><b>{{ $tier['users'] ?? 'Unlimited' }}</b> {{ $tier['users'] === 1 ? 'user' : 'users' }}</span></li>
                        <li><span class="tick">{{ $tier['storage_mb'] === 0 ? '–' : '✓' }}</span><span>{!! $tier['storage_mb'] === 0 ? 'No scan uploads' : '<b>' . e($gb($tier['storage_mb'])) . '</b> for scans &amp; attachments' . (in_array('clinical_documents', $tier['features'], true) ? '' : ' (with the Clinical documents add-on)') !!}</span></li>
                        <li class="plus">{{ $previous ? 'Everything in ' . $previous['name'] . ', plus' : 'Patients, cashier & receipts, plus' }}</li>
                        @if(!$previous)
                            <li><span class="tick">✓</span><span>{{ \App\Services\PricingCatalogue::sentence($new) }}</span></li>
                        @else
                            @foreach($new as $feature)<li><span class="tick">✓</span><span>{{ \App\Services\PricingCatalogue::label($feature) }}</span></li>@endforeach
                        @endif
                    </ul>
                    <button type="button" class="btn {{ $tier['featured'] ? '' : 'alt' }}" data-choose="{{ $tier['family'] }}">Request this plan</button>
                </article>
                @php($previous = $tier)
            @endforeach
            {{-- Straight after the last plan, where people look for it --}}
            <aside class="card compare-card" aria-label="Compare the plans">
                <h3>Not sure which?</h3>
                <p class="tag">See every feature side by side, or add single features to a smaller plan.</p>
                <button type="button" class="btn alt" data-open="compare">Compare every feature</button>
                <button type="button" class="foot-link" data-open="addons">See the add-ons</button>
            </aside>
        </div>

    </section>

    {{-- ── Customize (build your own plan) ── --}}
    <section id="panel-build" role="tabpanel" aria-labelledby="tab-build" hidden>
        <div class="builder">
            <div class="panel">
                <h3>Tell us about your clinic <span class="muted" style="font-weight:600;font-size:13px">· we'll find the cheapest way to get it</span></h3>

                <div class="controls" style="margin-top:10px">
                    <label class="field">Patients a day<input type="number" min="0" max="10000" id="b-visits" value="10" inputmode="numeric"></label>
                    <label class="field">Users (staff)<input type="number" min="1" max="1000" id="b-users" value="2" inputmode="numeric"></label>
                    <label class="field">Scan storage
                        <select id="b-storage" title="About 300 scans per GB">@foreach([0, 20, 150, 300, 1024, 2048, 5120, 10240, 20480] as $size)<option value="{{ $size }}">{{ $size ? \App\Services\PricingCatalogue::storageLabel($size) . ' (~' . number_format(round($size / 1024 * 300)) . ' scans)' : 'None' }}</option>@endforeach</select>
                    </label>
                    <label class="field">Branches<input type="number" min="1" max="100" id="b-branches" value="1" inputmode="numeric"></label>
                </div>

                <p class="core-line"><b>Every plan:</b> patient records &amp; clearance · the full consultation, refraction &amp; prescriptions · the till, receipts &amp; refunds · sales records · daily cash summary · {{ \App\Services\PricingCatalogue::sentence(collect($extras['core'])->pluck('key')->all(), ' · ', true) }} · moving your records from other software</p>

                @foreach(\App\Services\PricingCatalogue::grouped($extras['optional']) as $group => $items)
                    <h4 class="tiles-group">{{ $group }}</h4>
                    <div class="tiles">
                        @foreach($items as $item)
                            <label class="tile">
                                <input type="checkbox" class="b-feature" value="{{ $item['key'] }}">
                                <span class="name" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                                <span class="in" title="Included in {{ $item['from'] }} and above">{{ preg_replace('/^Clinic\s+/', '', (string) $item['from']) }}+</span>
                                <span class="cost" @unless($item['offered']) title="Comes with the plan only" @endunless>{{ $item['offered'] ? '+' . $fmt($item['price']) : '—' }}</span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
                <p class="tiles-note"><span class="in-eg">Plus+</span> means it's included from that plan. A price is what it costs a month on its own on a smaller plan; — means it comes with the plan only.</p>
            </div>

            <aside class="panel summary" aria-live="polite">
                <div class="best">Best for you</div>
                <div class="plan" id="s-plan">—</div>
                <div class="total" id="s-total">—</div>
                <ul class="lines" id="s-lines"></ul>
                <div class="save" id="s-save" hidden></div>
                <ul class="others" id="s-others" aria-label="Every plan for this selection"></ul>
                <div class="stack">
                    <button type="button" class="btn" id="s-request">Request this setup</button>
                    @if($whatsapp)<a class="btn wa" id="s-wa" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">Chat on WhatsApp</a>@endif
                </div>
            </aside>
        </div>
    </section>
    @endif
    </div>

    {{-- ── Attendance Suite: priced by staff, packages or modules ── --}}
    <div id="product-attendance" role="tabpanel" aria-labelledby="product-tab-attendance" hidden>
        <div class="staffbar" id="a-bar">
            <label for="a-staff">How many staff will clock in?</label>
            <input type="number" id="a-staff" min="1" max="100000" value="{{ $attFirst }}" inputmode="numeric">
            <span class="note" id="a-note" aria-live="polite">Priced for up to <b>{{ $attFirst }}</b> staff. Every package has the terminal link, live board and reports.</span>
        </div>

        <section id="panel-att-plans" role="tabpanel" aria-labelledby="tab-plans">
            <div class="cards four">
                @foreach($attendance['packages'] as $package)
                    <article class="card {{ $package['featured'] ? 'featured' : '' }}" aria-label="Attendance {{ $package['name'] }}" data-att-card="{{ $package['key'] }}">
                        @if($package['featured'])<span class="ribbon">Most popular</span>@endif
                        <h3>{{ $package['name'] }}</h3>
                        <p class="tag">{{ $package['tagline'] }}</p>
                        <div class="price" data-att-price="{{ $package['key'] }}">{{ $currency }} {{ $fmt($attendance['sizes'][$attFirst] + $package['uplift']) }} <small>/ month</small></div>
                        <div class="per" data-att-per>Up to {{ $attFirst }} staff</div>
                        <ul class="facts">
                            @if(!$attPrev)
                                <li class="plus">Included</li>
                                @foreach($attendance['core'] as $line)<li><span class="tick">✓</span><span>{{ $line }}</span></li>@endforeach
                            @else
                                <li class="plus">Everything in {{ $attPrev['name'] }}, plus</li>
                                @foreach($package['adds'] as $module)<li><span class="tick">✓</span><span title="{{ $attModules[$module]['about'] }}">{{ $attModules[$module]['label'] }}</span></li>@endforeach
                            @endif
                        </ul>
                        <button type="button" class="btn {{ $package['featured'] ? '' : 'alt' }}" data-att-choose="{{ $package['key'] }}">Request this package</button>
                    </article>
                    @php($attPrev = $package)
                @endforeach
            </div>
        </section>

        <section id="panel-att-build" role="tabpanel" aria-labelledby="tab-build" hidden>
            <div class="builder">
                <div class="panel">
                    <h3>Start with Basic and add what you need <span class="muted" style="font-weight:600;font-size:13px">· we'll point out when a package is cheaper</span></h3>
                    <p class="core-line"><b>Basic:</b> {{ implode(' · ', $attendance['core']) }}</p>
                    <div class="tiles">
                        @foreach($attendance['modules'] as $module)
                            <label class="tile" title="{{ $module['about'] }}">
                                <input type="checkbox" class="a-module" value="{{ $module['key'] }}">
                                <span class="name">{{ $module['label'] }}</span>
                                <span class="in">{{ collect($attendance['packages'])->first(fn ($p) => in_array($module['key'], $p['adds'], true))['name'] ?? '' }}+</span>
                                <span class="cost">+{{ $fmt($module['price']) }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="tiles-note">Module prices are per month at any number of staff; <span class="in-eg">Standard+</span> means it's included from that package.</p>
                </div>

                <aside class="panel summary" aria-live="polite">
                    <div class="best">Best for you</div>
                    <div class="plan" id="as-plan">—</div>
                    <div class="total" id="as-total">—</div>
                    <ul class="lines" id="as-lines"></ul>
                    <div class="save" id="as-save" hidden></div>
                    <div class="stack" style="margin-top:12px">
                        <button type="button" class="btn" id="as-request">Request this setup</button>
                        @if($whatsapp)<a class="btn wa" id="as-wa" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">Chat on WhatsApp</a>@endif
                    </div>
                </aside>
            </div>
        </section>
    </div>

    {{-- ── Request ── --}}
    <dialog class="req" id="request" aria-labelledby="request-title">
        <div class="dlg-head">
            <div><h2 id="request-title" data-att="Request the Attendance Suite">Request a plan</h2><p data-att="Leave your details and we'll call you to set it up. No payment now.">Leave your details and we'll call you to set your clinic up. No payment now.</p></div>
            <button type="button" class="dlg-close" data-close aria-label="Close">&times;</button>
        </div>
        @if($errors->any())<div class="alert err" role="alert" style="margin:12px 20px 0">Please check the form: {{ $errors->first() }}</div>@endif
        <div class="dlg-body">
            <form method="POST" action="{{ route('pricing.request') }}" class="panel" id="request-form" novalidate>
                @csrf
                <input type="hidden" name="plan" id="f-plan" value="{{ old('plan', $tiers->firstWhere('featured', true)['family'] ?? $tiers->first()['family'] ?? 'attendance:basic') }}">
                <input type="hidden" name="interval" id="f-interval" value="{{ old('interval', 'monthly') }}">
                <input type="hidden" name="users" id="f-users"><input type="hidden" name="storage_mb" id="f-storage"><input type="hidden" name="visits" id="f-visits"><input type="hidden" name="branches" id="f-branches"><input type="hidden" name="staff" id="f-staff">
                <div id="f-features"></div>
                <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                <div class="form-grid">
                    <label class="field"><span data-att="Organisation name">Clinic name</span><input type="text" name="clinic_name" value="{{ old('clinic_name') }}" required maxlength="150" autocomplete="organization">@error('clinic_name')<span class="err-text">{{ $message }}</span>@enderror</label>
                    <label class="field">Town or city<input type="text" name="town" value="{{ old('town') }}" maxlength="100" autocomplete="address-level2">@error('town')<span class="err-text">{{ $message }}</span>@enderror</label>
                    <label class="field">Your name<input type="text" name="contact_name" value="{{ old('contact_name') }}" required maxlength="120" autocomplete="name">@error('contact_name')<span class="err-text">{{ $message }}</span>@enderror</label>
                    <label class="field">Phone<input type="tel" name="phone" value="{{ old('phone') }}" required maxlength="30" autocomplete="tel" placeholder="e.g. 0241234567">@error('phone')<span class="err-text">{{ $message }}</span>@enderror</label>
                    <label class="field wide">Email (optional)<input type="email" name="email" value="{{ old('email') }}" maxlength="150" autocomplete="email">@error('email')<span class="err-text">{{ $message }}</span>@enderror</label>
                    <label class="field wide">Anything we should know? (optional)<textarea name="message" rows="3" maxlength="1000" placeholder="e.g. We have two doctors and want to start next month">{{ old('message') }}</textarea>@error('message')<span class="err-text">{{ $message }}</span>@enderror</label>
                </div>
                @error('staff')<p class="err-text">{{ $message }}</p>@enderror
                @error('plan')<p class="err-text">{{ $message }}</p>@enderror
                <div class="stack" style="margin-top:14px">
                    <button type="submit" class="btn">Request this plan</button>
                    @if($whatsapp)<a class="btn wa" id="f-wa" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">Or chat with us on WhatsApp</a>@endif
                </div>
            </form>
            <aside class="chosen" aria-live="polite">
                <div style="font-size:13px;opacity:.8;font-weight:700;text-transform:uppercase;letter-spacing:.05em">You're requesting</div>
                <div class="plan" id="c-plan">—</div>
                <div class="total" id="c-total">—</div>
                <ul id="c-lines"></ul>
                <p style="font-size:13px;opacity:.8;margin:14px 0 0"><a href="#plans" data-tab-link="plans" data-close>Change plan</a> · <a href="#build" data-tab-link="build" data-close>Customize</a></p>
            </aside>
        </div>
    </dialog>

    {{-- Compare and add-ons open over the page, so it never needs scrolling. --}}
    @if($tiers->isNotEmpty())
    <dialog class="req" id="compare" aria-labelledby="compare-title">
        <div class="dlg-head">
            <div><h2 id="compare-title">Compare every feature</h2><p>Every plan includes patient records, consultations, the cashier and receipts, and daily automatic backups.</p></div>
            <button type="button" class="dlg-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="dlg-scroll">
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Feature</th>@foreach($tiers as $tier)<th scope="col" class="{{ $tier['featured'] ? 'featured' : '' }}">{{ $tier['name'] }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr><td>Free SMS when paid yearly</td>@foreach($tiers as $tier)<td>@if($tier['yearly_sms'])<b class="free-sms">{{ number_format($tier['yearly_sms']) }}</b>@else — @endif</td>@endforeach</tr>
                    <tr><td>Price</td>@foreach($tiers as $tier)<td><b class="js-price" data-monthly="{{ $tier['monthly'] }}" data-yearly="{{ $tier['yearly'] }}">{{ $fmt($tier['monthly']) }}</b></td>@endforeach</tr>
                    <tr><td>Users</td>@foreach($tiers as $tier)<td>{{ $tier['users'] ?? 'Unlimited' }}</td>@endforeach</tr>
                    <tr><td>Scans &amp; attachments</td>@foreach($tiers as $tier)<td class="{{ $tier['storage_mb'] === 0 ? 'no' : '' }}">{{ $tier['storage_mb'] === 0 ? '—' : $gb($tier['storage_mb']) }}</td>@endforeach</tr>
                    <tr><td>Branches</td>@foreach($tiers as $tier)<td>{{ $tier['branches'] === null ? 'Unlimited' : ($tier['extra_branch'] > 0 ? $tier['branches'] . ' + ' . $fmt($tier['extra_branch']) . ' each extra' : $tier['branches']) }}</td>@endforeach</tr>
                    @foreach(collect([...$extras['core'], ...$extras['optional']])->groupBy('group') as $group => $items)
                        <tr class="group"><td colspan="{{ $tiers->count() + 1 }}">{{ $group }}</td></tr>
                        @foreach($items as $item)
                            <tr><td>{{ $item['label'] }}</td>@foreach($tiers as $tier)@php($has = in_array($item['key'], $tier['features'], true))<td class="{{ $has ? 'yes' : 'no' }}" aria-label="{{ $has ? 'Included' : 'Not included' }}">{{ $has ? '✓' : '—' }}</td>@endforeach</tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        </div>
    </dialog>
    <dialog class="req" id="addons" aria-labelledby="addons-title">
        <div class="dlg-head">
            <div><h2 id="addons-title">Add-ons</h2><p>Add these to any plan, any time.</p></div>
            <button type="button" class="dlg-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="dlg-scroll">
        <div class="addons">
            @if($extras['storage']['offered'])<div class="addon"><b>Scan storage</b><span>{{ $currency }} {{ $fmt($extras['storage']['price']) }} a month per {{ $extras['storage']['gb'] }} GB. Switches scan uploads on for Starter and Essential.</span></div>@endif
            @if($tiers->contains(fn ($t) => $t['extra_branch'] > 0))<div class="addon"><b>Extra branch</b><span>Each extra branch costs the same as your plan, e.g. {{ $tiers->firstWhere('featured', true)['name'] ?? $tiers->first()['name'] }} with 2 branches is {{ $currency }} {{ $fmt(2 * ($tiers->firstWhere('featured', true)['monthly'] ?? $tiers->first()['monthly'])) }} a month.</span></div>@endif
            <div class="addon"><b>SMS credits</b><span>Buy as you need them for reminders, receipts and campaigns. They never expire.</span></div>
            <div class="addon"><b>Staff attendance</b><span>Fingerprint and face terminals, leave, rosters and payroll, from {{ $currency }} {{ $fmt($attendance['sizes'][$attFirst]) }} a month for up to {{ $attFirst }} staff.</span><button type="button" class="foot-link" data-product-link="attendance" data-close>See attendance prices →</button></div>
            <div class="addon"><b>Optical shop</b><span>Orders, lab and POS for an optical shop beside the clinic. Ask us for a price.</span></div>
        </div>
        </div>
    </dialog>
    @endif

    <dialog class="req" id="compare-att" aria-labelledby="compare-att-title">
        <div class="dlg-head">
            <div><h2 id="compare-att-title">Compare the attendance packages</h2><p>Prices follow the number of staff who clock in. Yearly is twelve months, paid upfront. From {{ $attendance['special_from'] }} staff we agree a price with you.</p></div>
            <button type="button" class="dlg-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="dlg-scroll">
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Feature</th>@foreach($attendance['packages'] as $package)<th scope="col" class="{{ $package['featured'] ? 'featured' : '' }}">{{ $package['name'] }}</th>@endforeach<th scope="col">On its own</th></tr></thead>
                <tbody>
                    <tr class="group"><td colspan="6">Basic</td></tr>
                    @foreach($attendance['core'] as $line)<tr><td>{{ $line }}</td>@foreach($attendance['packages'] as $package)<td class="yes" aria-label="Included">✓</td>@endforeach<td class="no">—</td></tr>@endforeach
                    <tr class="group"><td colspan="6">Modules</td></tr>
                    @foreach($attendance['modules'] as $module)
                        <tr><td title="{{ $module['about'] }}">{{ $module['label'] }}</td>@foreach($attendance['packages'] as $package)@php($has = in_array($module['key'], $package['modules'], true))<td class="{{ $has ? 'yes' : 'no' }}" aria-label="{{ $has ? 'Included' : 'Not included' }}">{{ $has ? '✓' : '—' }}</td>@endforeach<td>+{{ $fmt($module['price']) }} / month</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <h2 style="font-size:16px;margin:18px 0 8px">Monthly price by number of staff</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Staff, up to</th>@foreach($attendance['packages'] as $package)<th scope="col">{{ $package['name'] }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($attendance['sizes'] as $size => $basic)
                        <tr data-att-size="{{ $size }}"><td>{{ $size }}</td>@foreach($attendance['packages'] as $package)<td>{{ $currency }} {{ $fmt($basic + $package['uplift']) }}</td>@endforeach</tr>
                    @endforeach
                    <tr><td>{{ $attendance['special_from'] }} and above</td><td colspan="4"><b>Special pricing</b>: <button type="button" class="foot-link" data-att-quote>request a quote</button></td></tr>
                </tbody>
            </table>
        </div>
        </div>
    </dialog>
</main>

<footer>
    <div class="wrap">
        <span>@if($tiers->isNotEmpty())<span data-clinic-only><button type="button" class="foot-link" data-open="addons">Add-ons</button> · </span>@endif<span data-att-only hidden><button type="button" class="foot-link" data-open="compare-att">Compare packages &amp; every size</button> · </span>Prices in {{ $currency }}</span>
        <span>@if($support['phone'])☏ {{ $support['phone'] }}@endif @if($support['email']) · ✉ <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a>@endif</span>
    </div>
</footer>

<script>
(() => {
    const TIERS = @json($tiers->values());
    const OPTIONAL = @json(collect($extras['optional'])->keyBy('key'));
    const STORAGE = @json($extras['storage']);
    const ATT = @json($attendance);
    const CUR = @json($currency);
    const WA = @json($whatsapp);
    const OLD = @json($old);
    const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const money = n => CUR + ' ' + (Math.round(n * 100) / 100).toLocaleString('en-GH', {minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2});
    const hasClinic = TIERS.length > 0;
    let interval = OLD.interval === 'yearly' ? 'yearly' : 'monthly';
    let product = String(OLD.plan || '').startsWith('attendance') || location.hash.startsWith('#attendance') || !hasClinic ? 'attendance' : 'clinic';
    let tab = OLD.tab === 'build' || ['#build', '#attendance-build'].includes(location.hash) ? 'build' : 'plans';
    let chosen = {family: OLD.plan || (hasClinic ? (TIERS.find(t => t.featured) || TIERS[0]).family : 'attendance:basic'), from: OLD.tab === 'build' ? 'build' : 'plans'};

    // Same rules as PricingCatalogue::quote(): cheapest plan that covers it, add-ons where offered.
    function quote(sel) {
        const options = TIERS.map(t => {
            const lines = []; let why = null;
            if (t.max_visits !== null && sel.visits > t.max_visits) why = `Meant for up to ${t.max_visits} patients a day`;
            if (!why && t.users !== null && sel.users > t.users) why = `Up to ${t.users} users`;
            for (const f of sel.features.filter(f => !t.features.includes(f))) {
                if (why) break;
                const x = OPTIONAL[f]; x.offered ? lines.push([x.label, x.price]) : why = `${x.label} isn't in this plan`;
            }
            const planMb = t.storage_mb === null ? null : t.storage_mb;
            // Clinical documents bought on a plan with no scan storage need somewhere to keep them: one pack at least.
            const needMb = sel.features.includes('clinical_documents') && planMb === 0 ? Math.max(sel.storage_mb, 1) : sel.storage_mb;
            if (!why && planMb !== null && needMb > planMb) {
                const packs = Math.ceil((needMb - planMb) / (Math.max(1, STORAGE.gb) * 1024));
                STORAGE.offered ? lines.push([`Scan storage: ${packs} × ${STORAGE.gb} GB`, packs * STORAGE.price]) : why = 'Not enough scan storage';
            }
            if (!why && t.branches !== null && sel.branches > t.branches) {
                const more = sel.branches - t.branches;
                const yearly = t.monthly > 0 ? t.extra_branch * t.yearly / t.monthly : 12 * t.extra_branch;
                t.extra_branch > 0 ? lines.push([`${more} extra ${more === 1 ? 'branch' : 'branches'}`, more * t.extra_branch, more * yearly]) : why = 'One branch only';
            }
            const addons = lines.reduce((s, l) => s + l[1], 0);
            const addonsYearly = lines.reduce((s, l) => s + (l[2] ?? 12 * l[1]), 0);
            return {family: t.family, name: t.name, ok: !why, why, plan: t.monthly, lines, monthly: t.monthly + addons, yearly: t.yearly + addonsYearly};
        });
        let best = null;
        options.forEach(o => { if (o.ok && (!best || o.monthly <= best.monthly)) best = o; });
        const first = options[0];
        return {best, options, saving: best && first.ok && first.family !== best.family ? first.monthly - best.monthly : 0};
    }

    // Same rules as AttendanceSuitePrices::quote(): Basic by size plus modules, or a package when that costs no more.
    const SIZES = Object.keys(ATT.sizes).map(Number).sort((a, b) => a - b);
    const PACKAGES = Object.fromEntries(ATT.packages.map(p => [p.key, p]));
    const MODULES = Object.fromEntries(ATT.modules.map(m => [m.key, m]));
    const sizeFor = staff => SIZES.find(s => Math.max(1, staff) <= s) ?? null;
    function attQuote(staff, modules, pkg) {
        staff = Math.max(1, staff);
        modules = ATT.modules.map(m => m.key).filter(k => modules.includes(k));
        const size = sizeFor(staff);
        const base = {staff, size, modules, special: size === null};
        if (size === null) return {...base, package: pkg, name: (pkg ? 'Attendance ' + PACKAGES[pkg].name : 'Attendance Suite') + ` · ${ATT.special_from}+ staff`, lines: [], monthly: 0, yearly: 0, saving: 0};
        const basic = ATT.sizes[size];
        const price = key => basic + PACKAGES[key].uplift;
        const build = (key, lines) => {
            const monthly = (key === 'custom' ? basic : price(key)) + lines.reduce((s, l) => s + l[1], 0);
            return {...base, package: key === 'custom' ? null : key, name: (key === 'custom' ? 'Attendance Basic' : 'Attendance ' + PACKAGES[key].name) + ` · up to ${size} staff`,
                lines, monthly, yearly: monthly * ATT.yearly_months, saving: 0};
        };
        if (pkg) return build(pkg, []);
        const custom = build('custom', modules.map(m => [MODULES[m].label, MODULES[m].price]));
        for (const p of ATT.packages) {
            if (p.key === 'basic' || modules.some(m => !p.modules.includes(m))) continue;
            if (price(p.key) <= custom.monthly) return {...build(p.key, []), saving: custom.monthly - price(p.key)};
            break;
        }
        return custom;
    }

    const plain = () => ({features: [], users: 1, storage_mb: 0, visits: 0, branches: 1});
    function selection() {
        const int = (id, min, d) => Math.max(min, parseInt($(id).value, 10) || d);
        return {features: $$('.b-feature:checked').map(i => i.value), users: int('#b-users', 1, 1), storage_mb: parseInt($('#b-storage').value, 10) || 0,
            visits: int('#b-visits', 0, 0), branches: int('#b-branches', 1, 1)};
    }
    const staff = () => Math.max(1, parseInt($('#a-staff').value, 10) || 1);
    const modules = () => $$('.a-module:checked').map(i => i.value);
    const amount = o => interval === 'yearly' ? o.yearly : o.monthly;
    const per = () => interval === 'yearly' ? '/ year' : '/ month';

    function renderBuilder() {
        if (!hasClinic) return;
        const q = quote(selection());
        $('#s-plan').textContent = q.best ? q.best.name : 'Talk to us';
        $('#s-total').innerHTML = q.best ? `${money(amount(q.best))} <small>${per()}</small>` : '<small>No plan covers this yet. Ask us for a quote.</small>';
        $('#s-lines').innerHTML = q.best && q.best.lines.length ? [[q.best.name, null], ...q.best.lines].map(([l, p, y]) => `<li><span>${p === null ? l + ' plan' : '+ ' + l}</span><b>${money(p === null ? (interval === 'yearly' ? TIERS.find(t => t.family === q.best.family).yearly : q.best.plan) : (interval === 'yearly' ? (y ?? 12 * p) : p))}</b></li>`).join('') : '';
        // Paying yearly brings the plan's free SMS.
        const sms = interval === 'yearly' && q.best ? (TIERS.find(t => t.family === q.best.family)?.yearly_sms || 0) : 0;
        if (sms) $('#s-lines').innerHTML += `<li class="free-sms"><span>Free SMS with the yearly plan</span><b>${sms.toLocaleString()}</b></li>`;
        const save = $('#s-save');
        save.hidden = !(q.saving > 0);
        if (q.saving > 0) save.textContent = `Saves ${money(q.saving)} a month compared with ${TIERS[0].name} plus add-ons.`;
        $('#s-others').innerHTML = q.options.map(o => `<li class="${q.best && o.family === q.best.family ? 'on' : ''}"><span>${o.name}</span><span>${o.ok ? money(amount(o)) : o.why}</span></li>`).join('');
        $('#s-request').disabled = !q.best;
        if (WA && $('#s-wa')) $('#s-wa').href = waLink(q.best, selection());
    }

    // The attendance cards, the staff note and the attendance builder all follow the staff number.
    function renderAttendance() {
        const n = staff(), size = sizeFor(n), special = size === null;
        $('#a-bar').classList.toggle('special', special);
        $('#a-note').innerHTML = special
            ? `From <b>${ATT.special_from} staff</b> we agree a price with you. Pick a package and request a quote.`
            : `Priced for up to <b>${size}</b> staff. Every package has the terminal link, live board and reports.`;
        ATT.packages.forEach(p => {
            const el = $(`[data-att-price="${p.key}"]`), q = attQuote(n, [], p.key);
            el.classList.toggle('special', special);
            el.innerHTML = special ? 'Special pricing' : `${money(amount(q))} <small>${per()}</small>`;
            $(`[data-att-card="${p.key}"] [data-att-per]`).textContent = special ? `${ATT.special_from} staff or more` : `Up to ${size} staff`;
            $(`[data-att-choose="${p.key}"]`).textContent = special ? 'Request a quote' : 'Request this package';
        });
        $$('[data-att-size]').forEach(tr => tr.style.fontWeight = +tr.dataset.attSize === size ? '800' : '');

        const q = attQuote(n, modules());
        $('#as-plan').textContent = q.name.replace(/ · .*$/, '');
        $('#as-total').innerHTML = special ? '<small>Special pricing: we\'ll agree a price with you.</small>' : `${money(amount(q))} <small>${per()}</small>`;
        const months = interval === 'yearly' ? ATT.yearly_months : 1;
        $('#as-lines').innerHTML = special || !q.lines.length ? '' : [[q.name, (q.package ? q.monthly : ATT.sizes[size])], ...q.lines]
            .map(([l, p], i) => `<li><span>${i ? '+ ' + l : l}</span><b>${money(p * months)}</b></li>`).join('');
        const save = $('#as-save');
        save.hidden = special || !q.package || !modules().length;
        if (!save.hidden) save.textContent = q.saving > 0
            ? `${PACKAGES[q.package].name} has all of these and saves ${money(q.saving)} a month on buying them one by one.`
            : `${PACKAGES[q.package].name} has all of these for the same price, and more.`;
        $('#as-request').textContent = special ? 'Request a quote' : 'Request this setup';
        if (WA && $('#as-wa')) $('#as-wa').href = attWaLink(q);
    }

    function waLink(option, sel) {
        if (!option) return `https://wa.me/${WA}?text=` + encodeURIComponent('Hello, I would like a quote for my clinic.');
        const bits = [`Hello, I'd like ${option.name} for my clinic (${money(amount(option))} ${per().replace('/ ', 'a ')}).`];
        if (option.lines.length) bits.push('Add-ons: ' + option.lines.map(l => l[0]).join(', ') + '.');
        if (sel.features.length || sel.visits) bits.push(`About ${sel.visits} patients a day, ${sel.users} users.`);
        return `https://wa.me/${WA}?text=` + encodeURIComponent(bits.join(' '));
    }
    function attWaLink(q) {
        const text = q.special
            ? `Hello, I'd like a quote for the Attendance Suite for ${q.staff} staff.`
            : `Hello, I'd like ${q.name.replace(/ · .*$/, '')} for ${q.staff} staff (${money(amount(q))} ${per().replace('/ ', 'a ')}).` + (q.lines.length ? ' Modules: ' + q.lines.map(l => l[0]).join(', ') + '.' : '');
        return `https://wa.me/${WA}?text=` + encodeURIComponent(text);
    }

    // The request form follows the last thing chosen: a plan or package card, or a builder's best option.
    function renderChosen() {
        $('#f-interval').value = interval;
        if (product === 'attendance') {
            const fromBuild = chosen.from === 'build';
            const pkg = fromBuild ? null : (String(chosen.family).split(':')[1] || 'basic');
            const q = attQuote(staff(), fromBuild ? modules() : [], pkg);
            $('#f-plan').value = fromBuild ? 'attendance' : 'attendance:' + pkg;
            $('#f-staff').value = q.staff;
            ['#f-users', '#f-storage', '#f-visits', '#f-branches'].forEach(id => $(id).value = '');
            $('#f-features').innerHTML = q.modules.map(f => `<input type="hidden" name="features[]" value="${f}">`).join('');
            $('#c-plan').textContent = q.name.replace(/ · .*$/, '');
            $('#c-total').textContent = q.special ? 'Special pricing' : `${money(amount(q))} ${per()}`;
            const included = q.package ? PACKAGES[q.package].modules.map(m => MODULES[m].label) : [];
            $('#c-lines').innerHTML = [`<li>${q.staff.toLocaleString('en-GH')} staff${q.special ? ', we\'ll agree a price' : ''}</li>`,
                ...(q.lines.length ? q.lines.map(l => `<li>${l[0]}</li>`) : included.length ? [`<li>Includes ${included.join(', ')}</li>`] : ['<li>Basic: terminal link, live board, reports</li>'])].join('');
            if (WA && $('#f-wa')) $('#f-wa').href = attWaLink(q);
            return;
        }
        const sel = chosen.from === 'build' ? selection() : plain();
        const q = quote(sel);
        let o = chosen.from === 'build' ? q.best : q.options.find(x => x.family === chosen.family);
        if (!o) o = q.options.find(x => x.ok);
        $('#f-plan').value = o.family; $('#f-staff').value = '';
        $('#f-users').value = sel.users; $('#f-storage').value = sel.storage_mb; $('#f-visits').value = sel.visits; $('#f-branches').value = sel.branches;
        $('#f-features').innerHTML = sel.features.map(f => `<input type="hidden" name="features[]" value="${f}">`).join('');
        $('#c-plan').textContent = o.name;
        $('#c-total').textContent = `${money(amount(o))} ${per()}`;
        $('#c-lines').innerHTML = (o.lines.length ? o.lines.map(l => `<li>${l[0]}</li>`) : ['<li>No add-ons</li>']).join('')
            + (chosen.from === 'build' ? `<li>${sel.users} users · ${sel.visits} patients a day</li>` : '');
        if (WA && $('#f-wa')) $('#f-wa').href = waLink(o, sel);
    }

    function setInterval_(value) {
        interval = value;
        $$('[data-interval]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.interval === value)));
        $$('#product-clinic .card .price').forEach(el => { el.innerHTML = `${money(+el.dataset[value])} <small>${per()}</small>`; });
        $$('[data-per]').forEach(el => { el.innerHTML = value === 'yearly' ? el.dataset.yearlyText : '&nbsp;'; });
        $$('.js-price').forEach(el => { el.textContent = money(+el.dataset[value]).replace(CUR + ' ', ''); });
        renderBuilder(); renderAttendance(); renderChosen();
    }

    const hashFor = () => product === 'attendance' ? (tab === 'build' ? '#attendance-build' : '#attendance') : '#' + tab;
    function showTab(name, focus) {
        tab = name;
        $$('[role=tab][data-tab]').forEach(t => t.setAttribute('aria-selected', String(t.dataset.tab === name)));
        const panels = {clinic: ['#panel-plans', '#panel-build'], attendance: ['#panel-att-plans', '#panel-att-build']}[product];
        $$('#panel-plans, #panel-build, #panel-att-plans, #panel-att-build').forEach(p => { p.hidden = true; });
        const panel = $(panels[name === 'build' ? 1 : 0]);
        if (panel) panel.hidden = false;
        if (focus) $('#tab-' + name).focus();
    }

    // Eye clinics or staff attendance: swaps the panels, the heading and the words that differ.
    function setProduct(value, focus) {
        product = value;
        $$('[data-product]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.product === value)));
        $('#product-clinic').hidden = value !== 'clinic'; $('#product-attendance').hidden = value !== 'attendance';
        $$('[data-att]').forEach(el => { el.dataset.clinic ??= el.textContent; el.textContent = value === 'attendance' ? el.dataset.att : el.dataset.clinic; });
        $$('[data-clinic-only]').forEach(el => { el.hidden = value !== 'clinic'; });
        $$('[data-att-only]').forEach(el => { el.hidden = value !== 'attendance'; });
        if (chosen.from === 'plans' && String(chosen.family).startsWith('attendance') !== (value === 'attendance'))
            chosen = {family: value === 'attendance' ? 'attendance:basic' : (TIERS.find(t => t.featured) || TIERS[0])?.family, from: 'plans'};
        showTab(tab);
        renderChosen();
        if (focus) $('#product-tab-' + value).focus();
    }

    $$('[data-interval]').forEach(b => b.addEventListener('click', () => setInterval_(b.dataset.interval)));
    $$('[role=tab][data-tab]').forEach(t => t.addEventListener('click', () => { showTab(t.dataset.tab); history.replaceState(null, '', hashFor()); }));
    $('.tabs').addEventListener('keydown', e => {
        if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
        showTab(tab === 'plans' ? 'build' : 'plans', true); history.replaceState(null, '', hashFor());
    });
    $$('[data-product]').forEach(b => b.addEventListener('click', () => { setProduct(b.dataset.product); history.replaceState(null, '', hashFor()); }));
    $('.products').addEventListener('keydown', e => {
        if (!['ArrowLeft', 'ArrowRight'].includes(e.key) || !hasClinic) return;
        setProduct(product === 'clinic' ? 'attendance' : 'clinic', true); history.replaceState(null, '', hashFor());
    });
    $$('[data-product-link]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); setProduct(a.dataset.productLink); showTab('plans'); $('.products').scrollIntoView({behavior: 'smooth'}); history.replaceState(null, '', hashFor()); }));
    $$('[data-tab-link]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); showTab(a.dataset.tabLink); $('.tabs').scrollIntoView({behavior: 'smooth'}); history.replaceState(null, '', hashFor()); }));
    const dialog = $('#request');
    function openRequest() { renderChosen(); if (!dialog.open) dialog.showModal(); $('[name=clinic_name]').focus(); }
    $$('[data-choose]').forEach(b => b.addEventListener('click', () => { chosen = {family: b.dataset.choose, from: 'plans'}; openRequest(); }));
    $$('[data-att-choose]').forEach(b => b.addEventListener('click', () => { chosen = {family: 'attendance:' + b.dataset.attChoose, from: 'plans'}; openRequest(); }));
    $$('[data-att-quote]').forEach(b => b.addEventListener('click', () => {
        b.closest('dialog')?.close();
        if (staff() < ATT.special_from) $('#a-staff').value = ATT.special_from;
        renderAttendance(); chosen = {family: 'attendance:basic', from: 'plans'}; openRequest();
    }));
    if (hasClinic) $('#s-request').addEventListener('click', () => { chosen = {family: null, from: 'build'}; openRequest(); });
    $('#as-request').addEventListener('click', () => { chosen = {family: 'attendance', from: 'build'}; openRequest(); });
    $$('[data-open-request]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); openRequest(); }));
    $$('[data-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog')?.close()));
    $$('dialog').forEach(d => d.addEventListener('click', e => { if (e.target === d) d.close(); }));
    $$('[data-open]').forEach(b => b.addEventListener('click', () => $('#' + b.dataset.open).showModal()));
    $$('#panel-build input, #panel-build select').forEach(el => el.addEventListener('input', () => { renderBuilder(); if (chosen.from === 'build') renderChosen(); }));
    $$('#a-staff, .a-module').forEach(el => el.addEventListener('input', () => { renderAttendance(); if (product === 'attendance') renderChosen(); }));

    // Back after a failed submit: put the earlier choices back.
    if (OLD.tab === 'build' && product === 'clinic') {
        $$('.b-feature').forEach(i => { i.checked = (OLD.features || []).includes(i.value); });
        if (OLD.users) $('#b-users').value = OLD.users; if (OLD.visits !== null) $('#b-visits').value = OLD.visits;
        if (OLD.storage_mb !== null) $('#b-storage').value = OLD.storage_mb; if (OLD.branches) $('#b-branches').value = OLD.branches;
    }
    if (product === 'attendance') {
        if (OLD.staff) $('#a-staff').value = OLD.staff;
        if (OLD.tab === 'build') $$('.a-module').forEach(i => { i.checked = (OLD.features || []).includes(i.value); });
    }
    if (!hasClinic) $('#product-tab-clinic').hidden = true;
    setProduct(product);
    setInterval_(interval);
    if (@json($errors->any()) || location.hash === '#request') openRequest();
})();
</script>
</body>
</html>
