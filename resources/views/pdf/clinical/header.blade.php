{{-- Clinic on the left, patient on the right, in one band. Needs $clinicSettings, $patient, $title. --}}
@php
    $age = $patient?->dob ? \Carbon\Carbon::parse($patient->dob)->age : null;
@endphp
<table class="doc-head">
    <tr>
        <td style="width:55%;">
            <table><tr>
                @if(!empty($clinicSettings) && $clinicSettings->logoDataUri())
                    <td style="width:1%;"><img src="{{ $clinicSettings->logoDataUri() }}" class="doc-logo" alt=""></td>
                @endif
                <td>
                    <div class="clinic-name">{{ $clinicSettings->clinic_name ?? 'Eye Clinic' }}</div>
                    <div class="clinic-meta">
                        {{ $clinicSettings->clinic_address ?? '' }}
                        @if(!empty($clinicSettings->clinic_contact)) · Tel {{ $clinicSettings->clinic_contact }}@endif
                        @if(!empty($clinicSettings->clinic_email)) · {{ $clinicSettings->clinic_email }}@endif
                    </div>
                    <div class="doc-title">{{ $title }}</div>
                </td>
            </tr></table>
        </td>
        <td style="width:45%;">
            <div class="pt-box">
                <div class="pt-name">{{ $patient?->name ?? '—' }}</div>
                <table>
                    <tr><td class="k">Patient no.</td><td style="white-space:nowrap;">{{ $patient?->pxnumber ?? '—' }}</td><td class="k">Age / Sex</td><td style="white-space:nowrap;">{{ $age !== null ? $age.' yrs' : '—' }} / {{ $patient?->gender ?: '—' }}</td></tr>
                    <tr><td class="k">Date of birth</td><td>{{ $patient?->dob ? \Carbon\Carbon::parse($patient->dob)->format('d M Y') : '—' }}</td><td class="k">Phone</td><td>{{ $patient?->contact ?: '—' }}</td></tr>
                    @if($patient?->address || $patient?->occupation)
                        <tr><td class="k">Address</td><td>{{ $patient->address ?: '—' }}</td><td class="k">Occupation</td><td>{{ $patient->occupation ?: '—' }}</td></tr>
                    @endif
                </table>
            </div>
        </td>
    </tr>
</table>
