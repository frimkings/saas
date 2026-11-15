{{-- Clinic band, the slip's title and the patient. Needs $settings, $patient, $consultation; $title, $rx (show the ℞ mark). --}}
@php
    $age = $patient?->dob ? \Carbon\Carbon::parse($patient->dob)->age : null;
@endphp
<table class="s-head"><tr>
    @if(!empty($settings) && method_exists($settings, 'logoDataUri') && $settings->logoDataUri())
        <td style="width:1%;"><img src="{{ $settings->logoDataUri() }}" class="s-logo" alt=""></td>
    @endif
    <td>
        <div class="s-clinic">{{ $settings->clinic_name ?? config('app.name') }}</div>
        <div class="s-meta">{{ $settings->clinic_address ?? '' }}@if(!empty($settings->clinic_contact)) · Tel {{ $settings->clinic_contact }}@endif</div>
    </td>
</tr></table>

<div class="s-title">@if($rx ?? false)<span class="rx">Rx</span>@endif{{ $title }}</div>

<table class="s-patient">
    <tr>
        <td class="k">Patient</td><td class="b">{{ $patient?->name ?? '—' }}</td>
        <td class="k" style="text-align:right;">Date</td><td style="white-space:nowrap; padding-left:5px;">{{ $consultation->created_at->format('d M Y') }}</td>
    </tr>
    <tr>
        <td class="k">No. / Age / Sex</td><td>{{ $patient?->pxnumber ?? '—' }} · {{ $age !== null ? $age.' yrs' : '—' }} · {{ $patient?->gender ?: '—' }}</td>
        <td class="k" style="text-align:right;">Ref</td><td style="white-space:nowrap; padding-left:5px;">{{ $ref ?? ('RX-'.$consultation->id) }}</td>
    </tr>
</table>
