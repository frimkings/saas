@php
    $isClinicAdmin = auth()->user()?->hasRole('Super Admin');
    $licenseNotice = app(\App\Services\ClinicAccessService::class)->notice($isClinicAdmin);
    $renewRoute = ($licenseNotice['hosted'] ?? false) ? 'admin.subscription' : 'admin.license';
    $what = ($licenseNotice['hosted'] ?? false) ? 'subscription' : 'license';
    // Optical-only subscribers run a shop, not a clinic.
    $place = \App\Support\OpticalMode::opticalOnly() ? 'shop' : 'clinic';
@endphp
{{-- Start of shift: once a day per person, what needs attention today (App\Services\Reminders\AttentionItems). --}}
@php
    $attentionClinicId = app(\App\Support\Tenancy\TenantContext::class)->clinicId();
    $attentionSummary = null;
    // Offline (single-clinic) installs have no clinic in the session; they still get the summary.
    if (auth()->check() && ! auth()->user()->is_platform_admin && ! request()->routeIs('attention', 'optical.attention')) {
        $attentionLine = request()->routeIs('optical.*') ? 'optical' : 'clinic';
        $seenKey = 'attention-summary-seen:' . auth()->id() . ':' . ($attentionClinicId ?? 0) . ':' . $attentionLine . ':' . today()->toDateString();
        if (! \Illuminate\Support\Facades\Cache::has($seenKey)) {
            \Illuminate\Support\Facades\Cache::put($seenKey, true, now()->endOfDay());
            $counts = app(\App\Services\Reminders\AttentionItems::class)->counts($attentionLine);
            $attentionSummary = $counts['total'] > 0 ? $counts + ['line' => $attentionLine] : null;
        }
    }
@endphp
@if($attentionSummary)
    <div x-data="{ open: true }" x-show="open" style="position:fixed;inset:0;z-index:1060;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px"
         x-on:keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-labelledby="attention-summary-title">
        <div style="background:#fff;border-radius:12px;max-width:420px;width:100%;box-shadow:0 20px 50px rgba(15,23,42,.3);overflow:hidden" x-on:click.outside="open = false">
            <div style="padding:16px 20px;border-bottom:1px solid #eef1f5">
                <h5 id="attention-summary-title" style="margin:0;font-weight:700;color:#0f172a"><i class="fas fa-sun" style="color:#f59e0b"></i> Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h5>
                <div style="font-size:13px;color:#64748b;margin-top:2px">Here is what needs attention today.</div>
            </div>
            <ul style="list-style:none;margin:0;padding:12px 20px;font-size:14px;line-height:1.9;color:#1e293b">
                @if($attentionSummary['appt_soon'])<li><strong>{{ $attentionSummary['appt_soon'] }}</strong> {{ \Illuminate\Support\Str::plural('appointment', $attentionSummary['appt_soon']) }} coming up</li>@endif
                @if($attentionSummary['appt_missed'])<li><strong style="color:#b91c1c">{{ $attentionSummary['appt_missed'] }}</strong> missed today</li>@endif
                @if($attentionSummary['order_late'])<li><strong style="color:#b91c1c">{{ $attentionSummary['order_late'] }}</strong> {{ \Illuminate\Support\Str::plural('spectacle order', $attentionSummary['order_late']) }} past the promised date</li>@endif
                @if($attentionSummary['order_due'])<li><strong>{{ $attentionSummary['order_due'] }}</strong> due soon</li>@endif
                @if($attentionSummary['uncollected'])<li><strong>{{ $attentionSummary['uncollected'] }}</strong> ready but not collected</li>@endif
            </ul>
            <div style="padding:12px 20px;border-top:1px solid #eef1f5;display:flex;justify-content:flex-end;gap:8px">
                <button type="button" x-on:click="open = false" style="border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:6px;padding:6px 14px;cursor:pointer">Later</button>
                <a href="{{ $attentionSummary['line'] === 'optical' ? route('optical.attention') : route('attention') }}" style="background:#2563eb;color:#fff;border-radius:6px;padding:6px 14px;text-decoration:none;font-weight:600">See the list</a>
            </div>
        </div>
    </div>
@endif
{{-- A platform announcement for this clinic's Super Admins, until they dismiss it. --}}
@php
    $platformAnnouncement = app(\App\Services\Platform\Announcements::class)
        ->bannerFor(auth()->user(), app(\App\Support\Tenancy\TenantContext::class)->clinicId());
@endphp
@if($platformAnnouncement)
    <div x-data="{ shown: true }" x-show="shown">
        <div class="alert alert-primary m-3" role="status" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;background:#eef6ff;border:1px solid #bcd6f7;color:#12385f;padding:10px 14px;border-radius:8px;font-size:13px">
            <div style="min-width:0">
                <strong>{{ $platformAnnouncement->heading }}</strong>
                <div style="margin-top:4px">{{ \Illuminate\Support\Str::limit(str_replace(['**', '[CLINIC]'], ['', app(\App\Support\Tenancy\TenantContext::class)->clinic()?->name ?? 'your clinic'], $platformAnnouncement->body), 220) }}</div>
                @if($platformAnnouncement->button_label && $platformAnnouncement->button_url)
                    <a href="{{ $platformAnnouncement->button_url }}" class="alert-link" style="display:inline-block;margin-top:4px">{{ $platformAnnouncement->button_label }} &rarr;</a>
                @endif
            </div>
            <button type="button" aria-label="Dismiss announcement" title="Dismiss" style="border:0;background:none;font-size:18px;line-height:1;color:inherit;cursor:pointer"
                    x-on:click="shown = false; fetch(@js(route('announcements.dismiss', $platformAnnouncement)), { method: 'POST', headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' } })">&times;</button>
        </div>
    </div>
@endif
@if(session('product_notice'))
    <div class="alert alert-info m-3" role="status" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;padding:10px 14px;border-radius:8px">{{ session('product_notice') }}</div>
@endif
@if($licenseNotice)
    @switch($licenseNotice['stage'])
        @case('active')
        @case('expiring')
            {{-- An early reminder can be hidden for the day; in the last week it stays up. --}}
            @php $dismissible = ($licenseNotice['days'] ?? 0) > 7; $dismissKey = 'renewal-reminder-hidden-'.($licenseNotice['cutoff_date'] ?? ''); @endphp
            {{-- x-show sits on a wrapper: Alpine resets the inline display of the element it toggles. --}}
            <div @if($dismissible) x-data="{ hidden: (() => { try { return localStorage.getItem(@js($dismissKey)) === new Date().toDateString() } catch (e) { return false } })() }" x-show="! hidden" @endif>
            <div class="alert alert-info m-3" role="status" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 14px;font-size:13px">
                <span>
                    <strong>Renewal reminder:</strong> the {{ $place }} {{ $what }} expires in {{ $licenseNotice['days'] }} {{ \Illuminate\Support\Str::plural('day', $licenseNotice['days']) }}, on {{ $licenseNotice['cutoff_date'] ?? $licenseNotice['cutoff'] }}.
                    @if($isClinicAdmin)<a href="{{ route($renewRoute) }}" class="alert-link">Renew now</a>@else Please remind your {{ $place }} administrator to renew.@endif
                </span>
                @if($dismissible)
                    <button type="button" aria-label="Hide renewal reminder for today" title="Hide for today" style="border:0;background:none;font-size:18px;line-height:1;color:inherit;cursor:pointer"
                            x-on:click="hidden = true; try { localStorage.setItem(@js($dismissKey), new Date().toDateString()) } catch (e) {}">&times;</button>
                @endif
            </div>
            </div>
            @break
        @case('grace')
            <div class="alert alert-warning m-3" role="alert">
                <strong>The {{ $place }} {{ $what }} has expired.</strong> Staff will be locked out on {{ $licenseNotice['blocks'] }} unless it is renewed.
                @if($isClinicAdmin)<a href="{{ route($renewRoute) }}" class="alert-link">Renew now</a>@else Please tell your {{ $place }} administrator.@endif
            </div>
            @break
        @case('payment_due')
        @case('locked')
            @php($support = \App\Models\PlatformSetting::support())
            <div class="alert alert-danger m-3" role="alert">
                <strong>Staff are locked out.</strong> The {{ $place }} {{ $what }} has not been renewed, so only administrators can sign in, and only to renew.
                @if($licenseNotice['stage'] === 'locked')
                    Need help? Contact {{ $support['name'] }}{{ $support['phone'] ? ' on ' . $support['phone'] : '' }}{{ $support['email'] ? ' or ' . $support['email'] : '' }}.
                @endif
            </div>
            @break
        @default
            <div class="alert alert-warning m-3" role="status">
                <strong>Read-only access.</strong> Creating, editing, and deleting records is blocked until renewal.
                @if($isClinicAdmin)<a href="{{ route($renewRoute) }}" class="alert-link">View license and renewal</a>@endif
            </div>
    @endswitch
@endif
