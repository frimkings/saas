{{-- What a plan change would mean for a clinic (App\Services\PlanChangeImpact::for). --}}
@props(['impact', 'theme' => 'light'])
@php
    $dark = $theme === 'dark';
    $box = $dark ? 'border:1px solid #273750;background:#0b1526;color:#e8eef8' : 'border:1px solid #e2e8f0;background:#f8fafc;color:#0f172a';
    $muted = $dark ? 'color:#94a3b8' : 'color:#475569';
@endphp
@if($impact)
    <div role="status" aria-live="polite" style="{{ $box }};border-radius:10px;padding:12px 14px;margin:10px 0;font-size:13px;line-height:1.5">
        <b>{{ $impact['downgrade'] ? 'Before moving to a smaller plan' : 'What changes' }}</b>
        @if($impact['lost'])
            <p style="margin:6px 0 0"><span style="{{ $muted }}">Stops working:</span> {{ implode(', ', $impact['lost']) }}.</p>
        @else
            <p style="margin:6px 0 0;{{ $muted }}">Nothing the clinic uses now stops working.</p>
        @endif
        @foreach($impact['over'] as $line)<p style="margin:6px 0 0">⚠ {{ $line }}</p>@endforeach
        @foreach($impact['leftovers'] as $line)<p style="margin:6px 0 0">↳ {{ $line }}</p>@endforeach
        @if($impact['billing'])<p style="margin:6px 0 0;{{ $muted }}">{{ $impact['billing'] }}</p>@endif
        <p style="margin:6px 0 0;{{ $muted }}">Records are never deleted; add-ons the clinic pays for keep working.</p>
    </div>
@endif
