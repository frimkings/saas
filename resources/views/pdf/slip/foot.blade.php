{{-- Doctor, signature, validity and the clinic's phone. Needs $settings, $doctorName, $validUntil. --}}
<table class="s-sign"><tr>
    <td><div class="line"><span class="b">{{ $doctorName ?: 'Doctor' }}</span><br><span class="muted">Signature / stamp</span></div></td>
    <td style="text-align:right;">
        <div class="b">Valid until {{ $validUntil->format('d M Y') }}</div>
        @if(!empty($settings->clinic_contact))<div class="muted">Questions: {{ $settings->clinic_contact }}</div>@endif
    </td>
</tr></table>
<div class="s-foot">Issued {{ now()->format('d M Y, g:i A') }}@if(auth()->user()) by {{ auth()->user()->name }}@endif · {{ $settings->clinic_name ?? config('app.name') }}</div>
