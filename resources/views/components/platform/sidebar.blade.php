@props(['active' => 'dashboard', 'overlimit' => 0])
@php
    $pendingSms = \App\Models\Setting::withoutGlobalScopes()->where('sms_sender_id_status', 'pending')->count()
        + \App\Models\PlatformInvoice::where('source', 'sms_bundle')->whereIn('status', ['unpaid', 'partial'])->count();
    $smsLow = (bool) (app(\App\Services\Messaging\PlatformSmsBalance::class)->last()['low'] ?? false);
    $tabs = ['dashboard' => '▥ Dashboard', 'clinics' => '✚ Clinics Directory', 'plans' => '◆ Subscription Plans', 'billing' => '▤ Invoices & Billing',
        'onboarding' => '＋ Clinic Onboarding', 'licenses' => '⚿ Offline Licenses', 'audit' => '⬡ Security & Audit'];
    $pages = ['analytics' => ['platform.subscription-analytics', '◔ Subscription Analytics'], 'imports' => ['platform.imports', '⇧ Legacy Imports'],
        'readiness' => ['platform.deployment-readiness', '✓ Deployment Readiness'], 'sms' => ['platform.sms', '✉ SMS Messaging'], 'support' => ['platform.support', '☏ Support Contact']];
@endphp
@once
<style>
.ps-side{width:245px;background:#0d1728;border-right:1px solid #22314a;padding:18px 10px;position:sticky;top:0;height:100vh;box-sizing:border-box;display:flex;flex-direction:column;flex-shrink:0;font:13px Arial;color:#e8eef8}
.ps-brand{font-size:17px;font-weight:800;padding:8px 12px 18px}.ps-brand small{display:block;font-size:10px;color:#8fa1bb;font-weight:400;margin-top:3px}
.ps-nav{flex:1 1 auto;min-height:0;overflow-y:auto;margin:4px 0}
.ps-nav a{display:flex;align-items:center;gap:6px;box-sizing:border-box;color:#aebbd0;padding:11px 12px;border-radius:9px;margin:2px 0;text-decoration:none}
.ps-nav a.active,.ps-nav a:hover{background:#16304a;color:#38bdf8}.ps-nav a:focus-visible{outline:2px solid #38bdf8;outline-offset:-2px}
.ps-badge{margin-left:auto;padding:2px 6px;border:1px solid #7b2740;border-radius:5px;font-size:10px;font-weight:800;background:#471c2c;color:#fb7185}
.ps-foot{flex-shrink:0;padding:12px 10px 0;border-top:1px solid #22314a;display:grid;gap:7px}.ps-foot form{margin:0}
.ps-foot button{width:100%;border:0;border-radius:7px;padding:9px 11px;font-weight:700;background:#26354b;color:#fff;cursor:pointer}.ps-foot button:hover{background:#314560}
@media(max-width:900px){.ps-side{width:auto;height:auto;position:static;padding:12px 10px}.ps-brand{padding:4px 8px 10px}.ps-nav{display:flex;overflow-x:auto;overflow-y:visible}.ps-nav a{white-space:nowrap}.ps-foot{grid-template-columns:1fr 1fr}}
</style>
@endonce
<aside class="ps-side" aria-label="Platform navigation">
    <div class="ps-brand">◉ Platform Admin<small>EyeClinic SaaS Control</small></div>
    <nav class="ps-nav">
        @foreach($tabs as $key => $label)
            <a href="{{ route('platform.dashboard', $key === 'dashboard' ? [] : ['tab' => $key]) }}" class="{{ $active === $key ? 'active' : '' }}" @if($active === $key) aria-current="page" @endif>{{ $label }}@if($key === 'clinics' && $overlimit)<span class="ps-badge">{{ $overlimit }}</span>@endif</a>
        @endforeach
        @foreach($pages as $key => [$route, $label])
            <a href="{{ route($route) }}" class="{{ $active === $key ? 'active' : '' }}" @if($active === $key) aria-current="page" @endif>{{ $label }}@if($key === 'sms' && $pendingSms)<span class="ps-badge">{{ $pendingSms }}</span>@endif @if($key === 'sms' && $smsLow)<span class="ps-badge" title="Platform SMS balance is low">Low</span>@endif</a>
        @endforeach
    </nav>
    <div class="ps-foot">
        <form method="POST" action="{{ route('tenant.mode.switch') }}">@csrf<input type="hidden" name="mode" value="clinic"><button type="submit">✚ Clinic mode</button></form>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
    </div>
</aside>
