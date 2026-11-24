{{--
    Drug prescription for the pharmacy: only the drugs the patient buys outside (what the clinic dispensed is
    not on it), and no diagnosis. Needs $outsideItems, $consultation, $patient, $settings, $doctor.
--}}
@php
    $slip = \App\Support\Clinical\Slip::class;
    $lines = collect($outsideItems ?? [])->map(fn ($item) => [
        'name' => trim((string) $item->name),
        'eye' => $slip::eye($item->eye),
        'often' => $item->frequency,
        'long' => ($label = $item->durationLabel()) ? lcfirst($label) : null,
        'taper' => (bool) $item->taper,
        'note' => \App\Support\Clinical\DrugInstructions::clean($item->instructions),
    ])->values();
@endphp
@include('pdf.slip.head', ['title' => 'Prescription', 'rx' => true])

<table class="s-patient" style="margin-top:2px;">
    <tr><td class="k" style="width:1%;">Allergies</td><td style="padding-left:5px;">Not recorded</td></tr>
</table>

<div class="s-section">Medicines to buy at a pharmacy</div>
@if($lines->isEmpty())
    <div class="muted">No medicines to buy outside were prescribed at this visit.</div>
@else
    <table class="drugs">
        @foreach($lines as $i => $line)
            <tr>
                <td class="n">{{ $i + 1 }}.</td>
                <td>
                    <div class="drug">{{ $line['name'] }}@if($line['taper']) <span style="font-size:8pt; font-weight:bold; border:1px solid #000; padding:0 3px; margin-left:4px;">TAPER</span>@endif</div>
                    <div class="how">{{ collect([$line['often'] ? ucfirst(strtolower($line['often'])) : null, $line['long']])->filter()->implode(' · ') ?: 'As directed' }}</div>
                    @if($line['note'])<div class="how" style="font-style:italic;">{{ $line['note'] }}</div>@endif
                </td>
                <td style="width:18%; text-align:right;">@if($line['eye'])<span class="eye-big">{{ $line['eye'] }}</span>@endif</td>
            </tr>
        @endforeach
    </table>
@endif

@include('pdf.slip.foot', [
    'doctorName' => $doctor->name ?? $consultation->user->name ?? null,
    'validUntil' => $slip::drugsValidUntil($consultation->created_at),
])
