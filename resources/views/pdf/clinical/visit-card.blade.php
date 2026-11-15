{{--
    One visit, in two columns. Needs $consultation with refraction, diagnoses, cartItems.product,
    addenda.user and doctor/user loaded. Optional $refraction (defaults to the visit's own).
--}}
@use('App\Support\Clinical\VisitCard')
@php
    $c = $consultation;
    $rx = $refraction ?? ($c->relationLoaded('refraction') ? $c->refraction : null);
    $exam = VisitCard::exam($c);
    $symptoms = VisitCard::symptoms($c);
    $given = VisitCard::prescribed($c);
    $diagnoses = $c->relationLoaded('diagnoses') ? $c->diagnoses->pluck('name')->implode(', ') : '';
    $doctor = $c->doctor->name ?? $c->user->name ?? null;
    $notes = trim((string) ($c->notes ?? ''));
    $hasObjective = $rx && $rx->hasStructuredObjective();
@endphp
<div class="card">
    <div class="card-head">
        <table><tr>
            <td><b>{{ $c->created_at->format('D, d M Y') }}</b> <span class="muted">· {{ $c->created_at->format('g:i A') }}</span></td>
            <td style="text-align:right;">@if($doctor)<span class="muted">Seen by</span> {{ $doctor }}@endif</td>
        </tr></table>
    </div>
    <div class="card-body">
        <table><tr>
            <td class="card-left">
                <div class="f"><span class="lbl">Chief complaint</span>{{ VisitCard::filled($c->chiefComplaint) ? $c->chiefComplaint : 'Not recorded' }}</div>
                @if($symptoms !== '')
                    <div class="f"><span class="lbl">Symptoms</span>{{ $symptoms }}</div>
                @endif
                @if(VisitCard::filled($c->others))
                    <div class="f"><span class="lbl">Systemic health / history</span>{{ $c->others }}</div>
                @endif
                <div class="f"><span class="lbl">Diagnosis</span><span class="dx">{{ $diagnoses !== '' ? $diagnoses : 'Not recorded' }}</span></div>
                @if($given['medicines'])
                    <div class="f"><span class="lbl">Medicines</span>
                        @foreach($given['medicines'] as $line)<div>• {{ $line }}</div>@endforeach
                    </div>
                @endif
                @if($given['items'])
                    <div class="f"><span class="lbl">Other items</span>{{ implode(', ', $given['items']) }}</div>
                @endif
                @if($notes !== '' || ($c->relationLoaded('addenda') && $c->addenda->count()))
                    <div class="f"><span class="lbl">Plan / notes</span>{{ $notes !== '' ? $notes : '' }}
                        @if($c->relationLoaded('addenda'))
                            @foreach($c->addenda as $addendum)
                                <div class="addendum small"><span class="muted">{{ $addendum->created_at->format('d M Y g:i A') }}, {{ $addendum->user->name ?? 'staff' }}:</span> {{ $addendum->note }}</div>
                            @endforeach
                        @endif
                    </div>
                @endif
            </td>
            <td>
                @if($exam['rows'])
                    <table class="grid">
                        <tr><th></th><th>OD (right)</th><th>OS (left)</th></tr>
                        @foreach($exam['rows'] as $label => [$od, $os])
                            <tr>
                                <td class="rowhead">{{ $label }}</td>
                                <td class="{{ $label === 'IOP' && VisitCard::iopHigh($od) ? 'warn' : '' }}">{{ VisitCard::filled($od) ? $od : '—' }}</td>
                                <td class="{{ $label === 'IOP' && VisitCard::iopHigh($os) ? 'warn' : '' }}">{{ VisitCard::filled($os) ? $os : '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                @else
                    <div class="muted">No examination recorded.</div>
                @endif
                @if($exam['normal'])
                    <div class="normal-line">Normal: {{ implode(', ', $exam['normal']) }}.</div>
                @endif

                @if($rx)
                    <table class="grid" style="margin-top:5px;">
                        <tr><th>Refraction</th><th>Prescription</th><th>VA</th><th>Add</th><th>Near</th></tr>
                        @foreach(['od' => 'OD', 'os' => 'OS'] as $eye => $label)
                            <tr>
                                <td class="rowhead">{{ $label }}</td>
                                <td>{{ $rx->subjectiveRx($eye) ?: '—' }}</td>
                                <td>{{ $rx->{'refraction'.strtoupper($eye).'_distance_va'} ?: '—' }}</td>
                                <td>{{ $rx->{'refraction'.strtoupper($eye).'_ADD'} ?: '—' }}</td>
                                <td>{{ $rx->{'refraction'.strtoupper($eye).'_near_va'} ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                    <div class="small muted" style="margin-top:2px;">
                        @if($hasObjective)
                            Objective{{ $rx->objective_method ? ' ('.str($rx->objective_method)->replace('_', ' ')->lower().')' : '' }}:
                            OD {{ \App\Models\Refractions::formatPrescription($rx->objective_od_sphere, $rx->objective_od_cylinder, $rx->objective_od_axis) ?: '—' }}{{ VisitCard::filled($rx->objective_od_va) ? ' ('.$rx->objective_od_va.')' : '' }},
                            OS {{ \App\Models\Refractions::formatPrescription($rx->objective_os_sphere, $rx->objective_os_cylinder, $rx->objective_os_axis) ?: '—' }}{{ VisitCard::filled($rx->objective_os_va) ? ' ('.$rx->objective_os_va.')' : '' }}.
                        @endif
                        @if($rx->pd) PD {{ $rx->pd }}.@endif
                        @if($rx->lensType) Lens: {{ $rx->lensType }}.@endif
                    </div>
                    @if(VisitCard::filled($rx->refractionnotes))
                        <div class="small"><span class="muted">Refraction notes:</span> {{ $rx->refractionnotes }}</div>
                    @endif
                @endif
            </td>
        </tr></table>
    </div>
</div>
