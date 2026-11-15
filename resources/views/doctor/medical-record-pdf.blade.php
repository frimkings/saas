<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Medical Record - {{ $patient->name }}</title>
    @include('pdf.clinical.styles', ['screen' => $screen ?? false])
</head>
<body>
@use('App\Support\Clinical\VisitCard')
@php
    $visits = $consultations;                       // newest first
    $latest = $visits->first();
    $first = $visits->last();
    $conditions = VisitCard::conditions($visits);
    $systemic = $visits->first(fn ($c) => VisitCard::filled($c->others))?->others;
    $iopHigh = $latest && (VisitCard::iopHigh($latest->IOPOD) || VisitCard::iopHigh($latest->IOPOS));
    // Columns nobody filled in on any visit are left out of the history table.
    $rows = $visits->map(fn ($c) => VisitCard::timelineRow($c));
    $has = fn (string $k) => $rows->contains(fn ($r) => is_array($r[$k]) ? collect($r[$k])->contains(fn ($v) => VisitCard::filled($v)) : VisitCard::filled($r[$k]));
    $cols = ['va' => $has('va'), 'iop' => $has('iop'), 'cdr' => $has('cdr'), 'rx' => $has('rx')];
@endphp
    @if($screen ?? false)
        <div class="print-bar"><button type="button" onclick="window.print()">Print</button></div>
    @endif
    @include('pdf.clinical.footer')
    @include('pdf.clinical.header', ['title' => 'Medical record'])

    {{-- At a glance --}}
    <table class="alerts">
        <tr>
            <td><div class="lbl">Visits</div><div class="val">{{ $visits->count() }}</div>
                <div class="small muted">@if($first){{ $first->created_at->format('d M Y') }} – {{ $latest->created_at->format('d M Y') }}@else None recorded @endif</div></td>
            <td><div class="lbl">Latest IOP (OD / OS)</div>
                <div class="val {{ $iopHigh ? 'warn' : '' }}">{{ $latest && (VisitCard::filled($latest->IOPOD) || VisitCard::filled($latest->IOPOS)) ? ($latest->IOPOD ?: '—').' / '.($latest->IOPOS ?: '—') : '—' }}</div>
                @if($iopHigh)<div class="small warn">Above {{ VisitCard::IOP_HIGH }} mmHg</div>@endif</td>
            <td><div class="lbl">Conditions on record</div><div class="val">{{ $conditions->isNotEmpty() ? $conditions->implode(', ') : 'None recorded' }}</div></td>
            <td><div class="lbl">Systemic health</div><div class="val">{{ $systemic ?: 'Not recorded' }}</div></td>
        </tr>
    </table>

    {{-- Every visit on one line: the trend of VA, IOP and the prescription over time --}}
    @if($visits->isNotEmpty())
        <div class="section-title">Visit history</div>
        <table class="grid">
            <tr><th>Date</th><th>Complaint</th>@if($cols['va'])<th>VA OD / OS</th>@endif @if($cols['iop'])<th>IOP OD / OS</th>@endif @if($cols['cdr'])<th>C/D OD / OS</th>@endif<th>Diagnosis</th>@if($cols['rx'])<th>Prescription OD / OS</th>@endif</tr>
            @foreach($rows as $row)
                <tr>
                    <td style="white-space:nowrap;">{{ $row['date']->format('d M Y') }}</td>
                    <td>{{ \Illuminate\Support\Str::limit((string) $row['complaint'], 60) ?: '—' }}</td>
                    @if($cols['va'])<td style="white-space:nowrap;">{{ $row['va'][0] ?: '—' }} / {{ $row['va'][1] ?: '—' }}</td>@endif
                    @if($cols['iop'])<td style="white-space:nowrap;"><span class="{{ VisitCard::iopHigh($row['iop'][0]) ? 'warn' : '' }}">{{ $row['iop'][0] ?: '—' }}</span> / <span class="{{ VisitCard::iopHigh($row['iop'][1]) ? 'warn' : '' }}">{{ $row['iop'][1] ?: '—' }}</span></td>@endif
                    @if($cols['cdr'])<td style="white-space:nowrap;">{{ $row['cdr'][0] ?: '—' }} / {{ $row['cdr'][1] ?: '—' }}</td>@endif
                    <td>{{ $row['diagnoses'] ?: '—' }}</td>
                    @if($cols['rx'])<td class="small">{{ $row['rx'] ? implode(' / ', $row['rx']) : '—' }}</td>@endif
                </tr>
            @endforeach
        </table>
    @endif

    {{-- The heading travels with the first card, so it is never left alone at the foot of a page. --}}
    @forelse($visits as $c)
        @if($loop->first)
            <div style="page-break-inside: avoid;">
                <div class="section-title">Visit details</div>
                @include('pdf.clinical.visit-card', ['consultation' => $c, 'refraction' => null])
            </div>
        @else
            @include('pdf.clinical.visit-card', ['consultation' => $c, 'refraction' => null])
        @endif
    @empty
        <div class="section-title">Visit details</div>
        <div class="muted">No consultations recorded.</div>
    @endforelse

    <div class="small muted" style="margin-top:6px;">This is a record from {{ $clinicSettings->clinic_name ?? 'the clinic' }}'s system, generated {{ ($generatedAt ?? now())->format('d M Y, g:i A') }}@if(!empty($generatedBy)) by {{ $generatedBy->name }}@endif.</div>
</body>
</html>
