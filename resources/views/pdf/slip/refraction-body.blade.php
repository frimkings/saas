{{--
    Spectacle prescription for the optical lab: the Rx grid, PD and lens, nothing clinical beyond that.
    Needs $refraction, $consultation, $patient, $settings.
--}}
@php
    $slip = \App\Support\Clinical\Slip::class;
    $eyes = ['od' => ['R', 'Right (OD)'], 'os' => ['L', 'Left (OS)']];
    $rows = collect($eyes)->map(fn ($label, $eye) => $slip::rx($refraction, $eye));
    $legacy = $rows->contains(fn ($r) => $r['text'] !== null);
    $lens = $slip::lens($refraction->lensType);
    $age = $patient?->dob ? \Carbon\Carbon::parse($patient->dob)->age : null;
    $notes = trim((string) ($refraction->refractionnotes ?: $refraction->subjective_notes));
@endphp
@include('pdf.slip.head', ['title' => 'Spectacle prescription', 'rx' => true, 'ref' => 'RF-'.$refraction->id])

<div class="s-section">Prescription</div>
<table class="rxgrid">
    <tr><th style="width:22%;"></th><th>Sphere</th><th>Cylinder</th><th>Axis</th><th>Add</th><th>VA</th><th>Near VA</th></tr>
    @foreach($eyes as $eye => [$short, $label])
        @php $r = $rows[$eye]; @endphp
        <tr>
            <td class="eye">{{ $label }}</td>
            @if($r['text'] !== null)
                <td colspan="3" class="legacy">{{ $r['text'] }}</td>
            @else
                <td>{{ $r['sph'] ?? '—' }}</td>
                <td>{{ $r['cyl'] ?? '—' }}</td>
                <td>{{ $r['axis'] ?? '—' }}</td>
            @endif
            <td>{{ $r['add'] ?? '—' }}</td>
            <td>{{ $r['va'] ?? '—' }}</td>
            <td>{{ $r['near'] ?? '—' }}</td>
        </tr>
    @endforeach
</table>
@if($legacy)<div class="small muted" style="margin-top:2px;">Recorded in the earlier format; copied as written.</div>@endif

<div class="s-section">Lenses</div>
<table>
    <tr>
        <td style="width:30%;"><span class="k">PD</span> <span class="b">{{ $refraction->pd ? $refraction->pd.' mm' : '—' }}</span></td>
        <td><span class="k">Lens</span> <span class="b">{{ $refraction->lensType ?: '—' }}</span></td>
    </tr>
</table>
<div class="chips" style="margin-top:4px;">
    @foreach($lens['design'] as $name => $on)<span><span class="box">{{ $on ? 'x' : '' }}</span>{{ $name }}</span>@endforeach
</div>
<div class="chips">
    @foreach($lens['coatings'] as $name => $on)<span><span class="box">{{ $on ? 'x' : '' }}</span>{{ $name }}</span>@endforeach
</div>

@if($notes !== '')
    <div class="s-section">Notes for the lab</div>
    <div>{{ $notes }}</div>
@endif

@include('pdf.slip.foot', [
    'doctorName' => $consultation->doctor->name ?? $consultation->user->name ?? null,
    'validUntil' => $slip::spectaclesValidUntil($consultation->created_at, $age),
])
