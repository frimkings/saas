@php
    $isClinicAdmin = auth()->user()?->hasRole('Super Admin');
    $licenseNotice = app(\App\Services\ClinicAccessService::class)->notice($isClinicAdmin);
    $renewRoute = ($licenseNotice['hosted'] ?? false) ? 'admin.subscription' : 'admin.license';
    $what = ($licenseNotice['hosted'] ?? false) ? 'subscription' : 'license';
    // Optical-only subscribers run a shop, not a clinic.
    $place = \App\Support\OpticalMode::opticalOnly() ? 'shop' : 'clinic';
@endphp
@if(session('product_notice'))
    <div class="alert alert-info m-3" role="status" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;padding:10px 14px;border-radius:8px">{{ session('product_notice') }}</div>
@endif
@if($licenseNotice)
    @switch($licenseNotice['stage'])
        @case('active')
        @case('expiring')
            <div class="alert alert-info m-3" role="status">
                <strong>Renewal reminder:</strong> the {{ $place }} {{ $what }} expires in {{ $licenseNotice['days'] }} day(s), on {{ $licenseNotice['cutoff'] }}.
                @if($isClinicAdmin)<a href="{{ route($renewRoute) }}" class="alert-link">Renew now</a>@else Please remind your {{ $place }} administrator to renew.@endif
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
