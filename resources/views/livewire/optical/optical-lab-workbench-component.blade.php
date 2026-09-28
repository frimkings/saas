<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .wb-tiles{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
        .wb-tile{text-align:left;background:#fff;border:1px solid var(--clinic-line);border-radius:12px;padding:12px 14px;cursor:pointer;transition:border-color .12s,box-shadow .12s}
        .wb-tile:hover{border-color:#9fb9bd}.wb-tile.active{border-color:var(--clinic-accent);box-shadow:0 0 0 3px #d7efed}
        .wb-tile span{display:block;font-size:11px;font-weight:700;color:var(--clinic-muted);text-transform:uppercase;letter-spacing:.3px}
        .wb-tile b{display:block;font-size:24px;line-height:1.2;margin-top:4px;font-variant-numeric:tabular-nums}
        .wb-table col.c-job{width:150px}.wb-table col.c-cust{width:170px}.wb-table col.c-work{width:auto}.wb-table col.c-due{width:120px}.wb-table col.c-status{width:130px}.wb-table col.c-act{width:210px}
        .wb-actions{display:grid;grid-template-columns:136px 64px;gap:6px;justify-content:end;align-items:center}.wb-actions>*{height:32px;box-sizing:border-box}
        @media(max-width:1000px){.wb-tiles{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:600px){.wb-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media print{.wb-noprint{display:none!important}}
    </style>

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Lab Workbench</h1>
            <p class="ui-muted text-sm">Glazing, frame transfers, repairs and quality checks. Most urgent jobs are listed first.</p>
        </div>
        <a href="{{ route('optical.lab-workbench.print', array_filter(['stage' => $stage !== 'active' ? $stage : null, 'typeFilter' => $typeFilter, 'searchTerm' => $searchTerm])) }}" target="_blank" rel="noopener" class="oo-btn wb-noprint" style="padding:9px 14px" title="Every job in this view with Rx, measurements and frame details">Print bench sheet</a>
    </div>

    <x-ui.flash />
    @if($errors->any() && ! $viewOrderId)<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
    @if($lateCount)<div class="oo-note red wb-noprint"><b>{{ $lateCount }} {{ \Illuminate\Support\Str::plural('job', $lateCount) }} past the pickup date.</b> They are at the top of "All open work".</div>@endif

    <div class="wb-tiles wb-noprint" role="group" aria-label="Workshop stage">
        @foreach(\App\Livewire\Optical\OpticalLabWorkbenchComponent::STAGES as $key => [$label])
            <button type="button" class="wb-tile {{ $stage === $key ? 'active' : '' }}" wire:click="setStage('{{ $key }}')" aria-pressed="{{ $stage === $key ? 'true' : 'false' }}">
                <span>{{ $label }}</span><b>{{ $stageCounts[$key] }}</b>
            </button>
        @endforeach
    </div>

    <div class="ui-panel bg-white">
        <div class="oo-toolbar wb-noprint" style="grid-template-columns:minmax(220px,1fr) 220px auto">
            <input autocomplete="off" type="search" wire:model.live.debounce.300ms="searchTerm" placeholder="Search job, customer, partner, frame or service…" class="ui-input text-sm" aria-label="Search jobs">
            <select wire:model.live="typeFilter" class="ui-input text-sm" aria-label="Job type">
                <option value="">All job types</option>
                <option value="Glazing">Lens glazing &amp; edging</option>
                <option value="Transfer">Lens transfer</option>
                <option value="Repair">Frame repair &amp; adjustment</option>
                <option value="Tinting">Other optical service</option>
            </select>
            <span class="ui-muted" wire:loading.delay wire:target="searchTerm,typeFilter,setStage">Updating…</span>
        </div>

        <div class="overflow-x-auto">
            <table class="oo-table wb-table w-full" style="min-width:960px">
                <colgroup><col class="c-job"><col class="c-cust"><col class="c-work"><col class="c-due"><col class="c-status"><col class="c-act wb-noprint"></colgroup>
                <thead>
                    <tr><th>Job</th><th>Customer</th><th>Work</th><th>Due</th><th>Status</th><th class="wb-noprint" style="text-align:right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse($orders as $ord)
                        @php
                            [$badgeLabel, $badgeClass] = \App\Support\Optical\OrderPresenter::badge($ord->status);
                            $docket = \App\Support\Optical\OrderPresenter::docket($ord) ?? [];
                            $awaiting = \App\Support\Optical\OrderPresenter::awaitedLenses($ord);
                            $step = \App\Support\Optical\OrderPresenter::nextStep($ord, true);
                            $pickup = $ord->pickUpDate ? \Carbon\Carbon::parse($ord->pickUpDate) : null;
                            $open = in_array($ord->status, \App\Livewire\Optical\OpticalLabWorkbenchComponent::STAGES['active'][1], true);
                            $job = $ord->work_type === 'service' ? ($ord->serviceLines->first()?->description ?? 'Optical service') : 'Glazing & edging';
                            $lensSpec = trim(implode(' · ', array_filter([data_get($docket, 'lens_details.type'), data_get($docket, 'lens_details.index') ? 'index '.data_get($docket, 'lens_details.index') : null, data_get($docket, 'lens_details.color')])));
                        @endphp
                        <tr wire:key="job-{{ $ord->id }}" class="{{ $viewOrderId === $ord->id ? 'oo-active' : '' }}" wire:click="openOrder({{ $ord->id }})">
                            <td><span class="oo-id">{{ $ord->order_id }}</span><span class="oo-sub">{{ $job }}</span></td>
                            <td><b>{{ $ord->display_customer_name }}</b>@if($ord->order_source === 'partner')<span class="oo-sub" style="color:#6b21a8;font-weight:700">Partner · {{ $ord->partnerClinic?->name ?? $ord->partner_clinic_name }}</span>@elseif($ord->display_customer_phone)<span class="oo-sub">{{ $ord->display_customer_phone }}</span>@endif</td>
                            <td>
                                <span style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $ord->frame_model_number ?: ($ord->serviceLines->pluck('description')->join(', ') ?: '—') }}</span>
                                @if($lensSpec !== '')<span class="oo-sub">{{ $lensSpec }}</span>@endif
                            </td>
                            <td style="white-space:nowrap">
                                {{ $pickup?->format('d M Y') ?? '—' }}
                                @if($pickup && $open)
                                    @if($pickup->lt(today()))<span class="oo-sub" style="color:#b91c1c;font-weight:700">Late by {{ (int) $pickup->diffInDays(today()) }}d</span>
                                    @elseif($pickup->isToday())<span class="oo-sub" style="color:#b91c1c;font-weight:700">Due today</span>
                                    @elseif($pickup->isTomorrow())<span class="oo-sub" style="color:#92400e;font-weight:700">Due tomorrow</span>@endif
                                @endif
                            </td>
                            <td>
                                <span class="oo-badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                @foreach($awaiting as $line)<span class="oo-sub" style="color:#92400e;font-weight:700">Awaiting {{ strtoupper($line->eye) }} lens</span>@endforeach
                            </td>
                            <td class="wb-noprint" onclick="event.stopPropagation()">
                                <div class="wb-actions">
                                    @if($awaiting->isNotEmpty() && $open)
                                        @php $line = $awaiting->first(); @endphp
                                        <button type="button" class="oo-btn warn" wire:click="receiveLens({{ $ord->id }}, '{{ $line->eye }}')" wire:confirm="Confirm the {{ strtoupper($line->eye) }} lens has arrived from the supplier?">{{ strtoupper($line->eye) }} lens arrived</button>
                                    @elseif($step && $open)
                                        <button type="button" class="oo-btn primary" wire:click="{{ $step[1] }}" @if($step[2]) wire:confirm="{{ $step[2] }}" @endif>{{ $step[0] }}</button>
                                    @else<span></span>@endif
                                    <button type="button" class="oo-btn" wire:click="openOrder({{ $ord->id }})">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">{{ $searchTerm || $typeFilter ? 'No jobs match these filters.' : 'Nothing here right now.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200 wb-noprint">{{ $orders->links() }}</div>
    </div>

    @include('livewire.optical.partials.order-panel', ['bench' => true])
</div>
