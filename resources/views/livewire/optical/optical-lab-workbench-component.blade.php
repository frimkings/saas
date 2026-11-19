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
        .wb-check{width:18px;height:18px;cursor:pointer;accent-color:var(--clinic-accent)}
        .wb-bulkbar{position:sticky;top:0;z-index:30;display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:10px 12px;padding:10px 14px;background:#f0fdfa;border:1px solid var(--clinic-accent);border-radius:12px;box-shadow:0 6px 18px rgb(15 23 42 / .12)}
        .wb-bulkbar .oo-btn[disabled]{opacity:.45;cursor:not-allowed}
        .wb-report ul{margin:4px 0 0;padding-left:18px}
    </style>

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Lab Workbench</h1>
            <p class="ui-muted text-sm">Glazing, frame transfers, repairs and quality checks. Most urgent jobs are listed first.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2"><x-guide-tour page="optical.lab" class="oo-btn" /><a data-tour="opt-lab-print" href="{{ route('optical.lab-workbench.print', array_filter(['stage' => $stage !== 'active' ? $stage : null, 'typeFilter' => $typeFilter, 'searchTerm' => $searchTerm])) }}" target="_blank" rel="noopener" class="oo-btn wb-noprint" style="padding:9px 14px" title="Every job in this view with Rx, measurements and frame details">Print bench sheet</a></div>
    </div>

    <x-ui.flash />
    @if($errors->any() && ! $viewOrderId)<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
    @if($lateCount)<div class="oo-note red wb-noprint"><b>{{ $lateCount }} {{ \Illuminate\Support\Str::plural('job', $lateCount) }} past the pickup date.</b> They are at the top of "All open work".</div>@endif

    @if($bulkReport)
        <div class="oo-note {{ $bulkReport['done'] ? '' : 'amber' }} wb-report wb-noprint" role="status" style="{{ $bulkReport['done'] ? 'background:#ecfdf5;border-color:#a7f3d0;color:#065f46' : '' }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <b>{{ $bulkReport['title'] }}:</b>
                    {{ count($bulkReport['done']) }} {{ \Illuminate\Support\Str::plural('job', count($bulkReport['done'])) }} done{{ $bulkReport['done'] ? ' ('.implode(', ', $bulkReport['done']).')' : '' }}.
                    @if($bulkReport['note']) {{ $bulkReport['note'] }}@endif
                    @if($bulkReport['skipped'])
                        <div class="mt-1" style="color:#92400e"><b>{{ count($bulkReport['skipped']) }} skipped</b> (still ticked):<ul>@foreach($bulkReport['skipped'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></div>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if(! empty($bulkReport['labLink']))<a href="{{ $bulkReport['labLink'] }}" target="_blank" rel="noopener" class="oo-btn wa"><i class="fab fa-whatsapp" aria-hidden="true"></i> Message {{ $bulkReport['labName'] }} the list</a>
                    @elseif(! empty($bulkReport['labName']) && $bulkReport['done'])<span class="ui-muted text-xs">No WhatsApp number for {{ $bulkReport['labName'] }}.</span>@endif
                    <button type="button" class="oo-btn" wire:click="dismissBulkReport" aria-label="Dismiss">Close</button>
                </div>
            </div>
        </div>
    @endif

    <div class="wb-tiles wb-noprint" data-tour="opt-lab-stages" role="group" aria-label="Workshop stage">
        @foreach(\App\Livewire\Optical\OpticalLabWorkbenchComponent::STAGES as $key => [$label])
            <button type="button" class="wb-tile {{ $stage === $key ? 'active' : '' }}" wire:click="setStage('{{ $key }}')" aria-pressed="{{ $stage === $key ? 'true' : 'false' }}">
                <span>{{ $label }}</span><b>{{ $stageCounts[$key] }}</b>
            </button>
        @endforeach
    </div>

    {{-- overflow: clip keeps the rounded corners without stopping the ticked-jobs bar from staying in view. --}}
    <div class="ui-panel bg-white" style="overflow:clip">
        <div class="oo-toolbar wb-noprint" data-tour="opt-lab-search" style="grid-template-columns:minmax(220px,1fr) 220px auto">
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

        @if($selected)
            @php $n = count($selected); $total = $orders->total(); @endphp
            <div class="wb-bulkbar wb-noprint" role="region" aria-label="Ticked jobs" data-tour="opt-lab-bulk">
                <b>{{ $n }} ticked</b>
                @if($n < $total && $n < \App\Livewire\Optical\OpticalLabWorkbenchComponent::BULK_LIMIT)<button type="button" class="oo-btn" wire:click="selectAllMatching">Tick all {{ min($total, \App\Livewire\Optical\OpticalLabWorkbenchComponent::BULK_LIMIT) }} shown</button>@endif
                <span class="mx-1 h-5 border-l border-slate-200" aria-hidden="true"></span>
                @if($canBench)
                    <button type="button" class="oo-btn primary" wire:click="bulkMove('In Production')" @disabled(! $bulkCounts['In Production'])
                            wire:confirm="Start edging {{ $bulkCounts['In Production'] }} {{ \Illuminate\Support\Str::plural('job', $bulkCounts['In Production']) }}? Held lenses are taken from stock.">Start edging ({{ $bulkCounts['In Production'] }})</button>
                    <button type="button" class="oo-btn" wire:click="openBulkSendToLab" @disabled(! $bulkCounts['Sent to Lab'])>Send to lab ({{ $bulkCounts['Sent to Lab'] }})</button>
                    <button type="button" class="oo-btn" wire:click="bulkMove('Ready for Collection')" @disabled(! $bulkCounts['Ready for Collection'])
                            wire:confirm="Quality check passed for {{ $bulkCounts['Ready for Collection'] }} {{ \Illuminate\Support\Str::plural('job', $bulkCounts['Ready for Collection']) }}?{{ $readySmsOn ? ' Each customer is texted that their glasses are ready.' : '' }}">QC pass · ready ({{ $bulkCounts['Ready for Collection'] }})</button>
                @endif
                <a href="{{ route('optical.orders.dockets', ['ids' => implode(',', $ticked->pluck('id')->all()), 'print' => 1]) }}" target="_blank" class="oo-btn" title="One A5 docket per page. Choose 2 pages per sheet in the print dialog to fit two on an A4.">Print dockets ({{ $ticked->count() }})</a>
                <button type="button" class="oo-btn ml-auto" wire:click="clearSelection">Clear</button>
            </div>
        @endif
        <div class="overflow-x-auto" data-tour="opt-lab-list">
            <table class="oo-table wb-table w-full" style="min-width:960px">
                <colgroup><col class="wb-noprint" style="width:40px"><col class="c-job"><col class="c-cust"><col class="c-work"><col class="c-due"><col class="c-status"><col class="c-act wb-noprint"></colgroup>
                <thead>
                    <tr><th class="wb-noprint" data-tour="opt-lab-select">
                        @php $pageIds = $orders->pluck('id')->map(fn ($id) => (string) $id)->values(); @endphp
                        <input type="checkbox" class="wb-check" aria-label="Tick every job on this page"
                               x-data="{ ids: @js($pageIds) }"
                               x-bind:checked="ids.length && ids.every(id => $wire.selected.includes(id))"
                               x-on:change="$wire.selected = $event.target.checked ? [...new Set([...$wire.selected, ...ids])] : $wire.selected.filter(id => ! ids.includes(id))">
                    </th><th>Job</th><th>Customer</th><th>Work</th><th>Due</th><th>Status</th><th class="wb-noprint !text-right">Actions</th></tr>
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
                            <td class="wb-noprint" onclick="event.stopPropagation()"><input type="checkbox" class="wb-check" value="{{ $ord->id }}" wire:model.live="selected" aria-label="Tick {{ $ord->order_id }}"></td>
                            <td><span class="oo-id">{{ $ord->order_id }}</span><span class="oo-sub">{{ $job }}</span></td>
                            <td><b>{{ $ord->display_customer_name }}</b>@if($ord->order_source === 'partner')<span class="oo-sub" style="color:#6b21a8;font-weight:700">Partner · {{ $ord->partnerClinic?->name ?? $ord->partner_clinic_name }}</span>@elseif($ord->display_customer_phone && \App\Support\OpticalAccess::seesMoneyAndContacts(auth()->user()))<span class="oo-sub">{{ $ord->display_customer_phone }}</span>@endif</td>
                            <td>
                                <span style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $ord->frame_model_number ?: ($ord->serviceLines->pluck('description')->join(', ') ?: '—') }}</span>
                                @if($lensSpec !== '')<span class="oo-sub">{{ $lensSpec }}</span>@endif
                                @foreach($ord->lensLines as $lens)
                                    @php
                                        // Noted on the job is not the same as ordered: Purchasing buys it.
                                        $supplierState = $lens->source === 'special_order' && $lens->status === 'ordered' ? \App\Support\Optical\OrderPresenter::supplierOrderState($ord, $lens->eye) : null;
                                        $lensState = match (true) {
                                            $supplierState === 'none' => ['not ordered from a supplier yet', '#b91c1c'],
                                            $supplierState === 'draft' => ['on a supplier order not sent yet', '#b91c1c'],
                                            $supplierState === 'ordered' => ['awaited from supplier', '#92400e'],
                                            $lens->source === 'special_order' && $lens->status === 'received' => ['arrived', '#047857'],
                                            $lens->status === 'held' => ['in stock, held', '#047857'],
                                            $lens->status === 'consumed' => ['taken from stock', '#475569'],
                                            default => [$lens->status, '#475569'],
                                        };
                                    @endphp
                                    <span class="oo-sub" style="white-space:nowrap">{{ strtoupper($lens->eye) }} {{ sprintf('%+.2f', $lens->sphere) }} / {{ sprintf('%+.2f', $lens->power) }} · <b style="color:{{ $lensState[1] }}">{{ $lensState[0] }}</b></span>
                                @endforeach
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
                                    @elseif($step && $open && \App\Support\OpticalAccess::canMoveTo(auth()->user(), in_array($ord->status, ['Pending', 'Sent to Lab', 'In Production'], true) ? 'Sent to Lab' : 'Collected'))
                                        <button type="button" class="oo-btn primary" wire:click="{{ $step[1] }}" @if($step[2]) wire:confirm="{{ $step[2] }}" @endif>{{ $step[0] }}</button>
                                    @else<span></span>@endif
                                    <button type="button" class="oo-btn" wire:click="openOrder({{ $ord->id }})">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">{{ $searchTerm || $typeFilter ? 'No jobs match these filters.' : 'Nothing here right now.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200 wb-noprint">{{ $orders->links() }}</div>
    </div>

    @if($showBulkLab)
        <div class="oo-overlay wb-noprint" wire:click="$set('showBulkLab', false)" aria-hidden="true"></div>
        <div class="wb-noprint" role="dialog" aria-modal="true" aria-labelledby="bulk-lab-title" style="position:fixed;inset:0;z-index:1042;display:grid;place-items:center;pointer-events:none">
            <form wire:submit.prevent="bulkSendToLab" class="ui-panel bg-white p-5 space-y-3" style="width:min(460px,92vw);pointer-events:auto">
                <h3 id="bulk-lab-title" class="text-base font-bold text-slate-900">Send {{ $bulkCounts['Sent to Lab'] }} {{ \Illuminate\Support\Str::plural('job', $bulkCounts['Sent to Lab']) }} to a lab</h3>
                <p class="text-xs text-slate-600">Ticked jobs waiting to start go to this lab, due back on one date. Others are left as they are. Afterwards you can WhatsApp the lab the whole list.</p>
                <div><label for="bulk-lab" class="block text-xs font-semibold mb-1">Lab</label>
                    <select id="bulk-lab" wire:model="bulkLabSupplierId" class="ui-input w-full" x-data
                            x-on:change="const lead = parseInt($event.target.selectedOptions[0]?.dataset.lead, 10); const back = new Date(); back.setDate(back.getDate() + (lead || 0)); $wire.$set('bulkExpectedBack', lead ? back.toLocaleDateString('en-CA') : '', false)">
                        <option value="">Not recorded</option>
                        @foreach($labs as $lab)<option value="{{ $lab->id }}" data-lead="{{ $lab->lead_time_days }}">{{ $lab->name }}{{ $lab->lead_time_days ? ' · '.$lab->lead_time_days.' '.\Illuminate\Support\Str::plural('day', $lab->lead_time_days) : '' }}</option>@endforeach
                    </select>@error('bulkLabSupplierId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label for="bulk-back" class="block text-xs font-semibold mb-1">Expected back</label>
                    <input id="bulk-back" type="date" wire:model="bulkExpectedBack" min="{{ today()->toDateString() }}" class="ui-input w-full">@error('bulkExpectedBack')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div class="flex justify-end gap-2"><button type="button" class="oo-btn" wire:click="$set('showBulkLab', false)">Cancel</button><button type="submit" class="oo-btn primary" wire:loading.attr="disabled" wire:target="bulkSendToLab">Send to lab</button></div>
            </form>
        </div>
    @endif

    @include('livewire.optical.partials.order-panel', ['bench' => true])
</div>
