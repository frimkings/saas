<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .rx-grid{width:100%;border-collapse:collapse;font-size:12.5px}.rx-grid th,.rx-grid td{padding:6px;border-bottom:1px solid var(--clinic-line);text-align:center}
        .rx-grid thead th{font-size:10px;text-transform:uppercase;letter-spacing:.3px;color:var(--clinic-muted)}.rx-grid tbody th{text-align:left;white-space:nowrap}
        .rx-grid input{width:100%;min-width:0;box-sizing:border-box;border:1px solid var(--clinic-line);border-radius:7px;padding:7px 6px;text-align:center;font:inherit;font-variant-numeric:tabular-nums}
        .rx-grid input:focus{outline:2px solid var(--clinic-accent);outline-offset:0}
        .rx-field{display:flex;flex-direction:column;gap:4px}.rx-field span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.rx-field .ui-input{padding:8px 10px}
        .rx-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .rx-err{color:#b91c1c;font-size:11px}
        .rx-pick{border:1px solid var(--clinic-line);border-radius:9px;overflow:hidden;margin-top:6px}.rx-pick button{display:flex;width:100%;justify-content:space-between;gap:10px;padding:8px 10px;border:0;border-bottom:1px solid var(--clinic-line);background:#fff;text-align:left;cursor:pointer;font:inherit}.rx-pick button:last-child{border-bottom:0}.rx-pick button:hover{background:#f0f9f8}
        .rx-chosen{display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid #bfe5e2;background:#f0f9f8;border-radius:9px;padding:8px 12px}
        .rx-eye{font-variant-numeric:tabular-nums;white-space:nowrap}
        .rx-table col.c-rx{width:120px}.rx-table col.c-cust{width:auto}.rx-table col.c-src{width:150px}.rx-table col.c-by{width:170px}.rx-table col.c-eye{width:150px}.rx-table col.c-act{width:200px}
        @media(max-width:600px){.rx-2{grid-template-columns:1fr}}
    </style>
    @php
        $fmt = fn ($v, $signed = true) => filled($v) && is_numeric($v) ? ($signed ? sprintf('%+.2f', (float) $v) : $v) : (filled($v) ? $v : '—');
        $eye = fn ($m, $side) => $fmt(data_get($m, "$side.sph")).(filled(data_get($m, "$side.cyl")) ? ' / '.$fmt(data_get($m, "$side.cyl")).(filled(data_get($m, "$side.axis")) ? ' × '.data_get($m, "$side.axis").'°' : '') : '').(filled(data_get($m, "$side.add")) && (float) data_get($m, "$side.add") > 0 ? ' Add '.$fmt(data_get($m, "$side.add")) : '');
        $sourceBadge = ['external' => ['External', 'oo-b-purple'], 'entered' => ['Entered with order', 'oo-b-grey'], 'clinic' => ['Clinic', 'oo-b-teal']];
    @endphp

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Prescriptions</h1>
            <p class="ui-muted text-sm">Clinic refractions ready to dispense, and prescriptions customers bring from elsewhere.</p>
        </div>
        <button type="button" wire:click="openExternalModal" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ Enter external Rx</button>
    </div>

    <x-ui.flash />

    @if($clinicRefractions->isNotEmpty())
        <section class="ui-panel bg-white">
            <div class="flex flex-wrap items-center justify-between gap-2" style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)">
                <div><h2 class="text-sm font-bold text-slate-900" style="margin:0">From the clinic</h2><p class="ui-muted text-xs" style="margin:2px 0 0">Refractions a doctor has authorized for dispensing.</p></div>
                <button type="button" class="oo-link" wire:click="$toggle('showAllClinic')">{{ $showAllClinic ? 'Show fewer' : 'Show more' }}</button>
            </div>
            <div class="overflow-x-auto">
                <table class="oo-table w-full" style="min-width:760px">
                    <thead><tr><th>Date</th><th>Patient</th><th>Right (OD)</th><th>Left (OS)</th><th>Examined by</th><th style="text-align:right">Order</th></tr></thead>
                    <tbody>
                        @foreach($clinicRefractions as $clinicRx)
                            @php $ordered = $orderedRefractions[$clinicRx->id] ?? null; @endphp
                            <tr wire:key="clinic-rx-{{ $clinicRx->id }}" style="cursor:default">
                                <td>{{ $clinicRx->created_at?->format('d M Y') }}</td>
                                <td><b>{{ $clinicRx->consultation?->patient?->name }}</b><span class="oo-sub">{{ $clinicRx->consultation?->patient?->contact }}</span></td>
                                <td class="rx-eye">{{ $fmt($clinicRx->subjective_od_sphere ?? $clinicRx->refractionOD) }}</td>
                                <td class="rx-eye">{{ $fmt($clinicRx->subjective_os_sphere ?? $clinicRx->refractionOS) }}</td>
                                <td>{{ $clinicRx->user?->name ?? '—' }}</td>
                                <td style="text-align:right">
                                    @if($ordered)<span class="oo-badge oo-b-green" title="An order already uses this refraction">Ordered · {{ $ordered->order_id }}</span>
                                    @else<a class="oo-btn primary" wire:navigate href="{{ route('optical.orders.create', ['refraction_id' => $clinicRx->id]) }}">Start order</a>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="ui-panel bg-white">
        <div class="oo-toolbar">
            <input autocomplete="off" type="search" wire:model.live.debounce.300ms="searchTerm" placeholder="Search customer, phone, PX number or prescriber…" class="ui-input text-sm" aria-label="Search prescriptions">
            <span class="ui-muted" wire:loading.delay wire:target="searchTerm,setSource">Updating…</span>
        </div>
        <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by source">
            @foreach(\App\Livewire\Optical\OpticalPrescriptionsComponent::SOURCES as $key => $label)
                <button type="button" wire:click="setSource('{{ $key }}')" class="oo-chip {{ $sourceFilter === $key ? 'active' : '' }}" aria-pressed="{{ $sourceFilter === $key ? 'true' : 'false' }}">{{ $label }}<b>{{ $sourceCounts[$key] }}</b></button>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="oo-table rx-table w-full" style="min-width:980px">
                <colgroup><col class="c-rx"><col class="c-cust"><col class="c-src"><col class="c-by"><col class="c-eye"><col class="c-eye"><col class="c-act"></colgroup>
                <thead><tr><th>Rx</th><th>Customer</th><th>Source</th><th>Prescriber</th><th>Right (OD)</th><th>Left (OS)</th><th style="text-align:right">Actions</th></tr></thead>
                <tbody>
                    @forelse($prescriptions as $rx)
                        @php [$srcLabel, $srcClass] = $sourceBadge[$rx->source] ?? [ucfirst((string) $rx->source), 'oo-b-grey']; @endphp
                        <tr wire:key="rx-{{ $rx->id }}" class="{{ $viewRxId === $rx->id ? 'oo-active' : '' }}" wire:click="openRx({{ $rx->id }})">
                            <td><span class="oo-id">RX-{{ $rx->id }}</span><span class="oo-sub">{{ ($rx->prescribed_at ?? $rx->created_at)?->format('d M Y') }}</span></td>
                            <td><b>{{ $rx->patient?->name ?? 'Customer unavailable' }}</b>@if($rx->patient?->contact)<span class="oo-sub">{{ $rx->patient->contact }}</span>@endif</td>
                            <td><span class="oo-badge {{ $srcClass }}">{{ $srcLabel }}</span>@if($rx->verified_at)<span class="oo-sub" style="color:#047857;font-weight:700">Verified</span>@endif</td>
                            <td>{{ $rx->prescriber_name ?: '—' }}@if($rx->prescriber_clinic)<span class="oo-sub">{{ $rx->prescriber_clinic }}</span>@endif</td>
                            <td class="rx-eye">{{ $eye($rx->measurements, 'od') }}</td>
                            <td class="rx-eye">{{ $eye($rx->measurements, 'os') }}</td>
                            <td onclick="event.stopPropagation()">
                                <div class="oo-row-actions" style="grid-template-columns:auto 64px">
                                    <a wire:navigate href="{{ route('optical.orders.create', ['prescription_id' => $rx->id]) }}" class="oo-btn primary">{{ $rx->orders_count ? 'New order' : 'Start order' }}</a>
                                    <button type="button" class="oo-btn" wire:click="openRx({{ $rx->id }})">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">{{ $searchTerm || $sourceFilter ? 'No prescriptions match these filters.' : 'No prescriptions yet.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $prescriptions->links() }}</div>
    </section>

    {{-- PRESCRIPTION PANEL --}}
    @if($viewRx)
        @php
            $m = $viewRx->measurements ?? [];
            [$srcLabel, $srcClass] = $sourceBadge[$viewRx->source] ?? [ucfirst((string) $viewRx->source), 'oo-b-grey'];
            $age = ($viewRx->prescribed_at ?? $viewRx->created_at)?->diffInMonths(now());
        @endphp
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { viewRxId: null })" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="rx-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { viewRxId: null })" wire:key="rx-drawer-{{ $viewRx->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="rx-title"><span class="oo-id" style="font-size:17px">RX-{{ $viewRx->id }}</span></h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-badge {{ $srcClass }}">{{ $srcLabel }}</span>
                        <span><b class="text-slate-800">{{ $viewRx->patient?->name }}</b>@if($viewRx->patient?->contact) · {{ $viewRx->patient->contact }}@endif</span>
                    </div>
                </div>
                <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { viewRxId: null })" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                @if($age !== null && $age >= 12)<div class="oo-note amber">This prescription is {{ (int) $age }} months old. Consider a new eye test before making glasses.</div>@endif
                <section class="oo-section">
                    <h3>Prescription</h3>
                    <table class="oo-lines oo-rx">
                        <thead><tr><th>Eye</th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th><th>VA</th><th>PD</th></tr></thead>
                        <tbody>
                            @foreach(['od' => 'R (OD)', 'os' => 'L (OS)'] as $side => $label)
                                <tr><th scope="row">{{ $label }}</th><td>{{ $fmt(data_get($m, "$side.sph")) }}</td><td>{{ $fmt(data_get($m, "$side.cyl")) }}</td><td>{{ filled(data_get($m, "$side.axis")) ? data_get($m, "$side.axis").'°' : '—' }}</td><td>{{ $fmt(data_get($m, "$side.add")) }}</td><td>{{ $fmt(data_get($m, "$side.va"), false) }}</td><td>{{ $fmt(data_get($m, "$side.pd"), false) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if(filled(data_get($m, 'pd')))<p class="ui-muted text-xs" style="margin:6px 0 0">Binocular PD: {{ data_get($m, 'pd') }} mm</p>@endif
                </section>
                <section class="oo-section">
                    <h3>Details</h3>
                    <dl class="oo-dl">
                        <dt>Prescribed</dt><dd>{{ ($viewRx->prescribed_at ?? $viewRx->created_at)?->format('D, d M Y') }}</dd>
                        <dt>Prescriber</dt><dd>{{ $viewRx->prescriber_name ?: ($viewRx->refraction?->user?->name ?? '—') }}@if($viewRx->prescriber_clinic) · {{ $viewRx->prescriber_clinic }}@endif</dd>
                        @if($viewRx->verified_at)<dt>Verified</dt><dd>{{ $viewRx->verified_at->format('d M Y') }}@if($viewRx->verifier) by {{ $viewRx->verifier->name }}@endif</dd>@endif
                        <dt>Recorded by</dt><dd>{{ $viewRx->creator?->name ?? '—' }} · {{ $viewRx->created_at?->format('d M Y H:i') }}</dd>
                        @if($viewRx->notes)<dt>Notes</dt><dd style="white-space:pre-line">{{ $viewRx->notes }}</dd>@endif
                    </dl>
                </section>
                <section class="oo-section">
                    <h3>Orders using this prescription</h3>
                    @forelse($viewRx->orders as $order)
                        @php [$bl, $bc] = \App\Support\Optical\OrderPresenter::badge($order->status); @endphp
                        <div class="flex items-center justify-between gap-2" style="padding:6px 0;border-bottom:1px solid var(--clinic-line)">
                            <span><span class="oo-id">{{ $order->order_id }}</span> <span class="ui-muted text-xs">· {{ $order->created_at?->format('d M Y') }}</span></span>
                            <span class="flex items-center gap-2"><span class="oo-badge {{ $bc }}">{{ $bl }}</span><a class="oo-link" wire:navigate href="{{ route('optical.orders', ['searchTerm' => $order->order_id]) }}">Open</a></span>
                        </div>
                    @empty
                        <p class="ui-muted text-sm" style="margin:0">No orders yet.</p>
                    @endforelse
                </section>
            </div>
            <footer class="oo-drawer-foot">
                @if($viewRx->patient)<button type="button" class="oo-btn" wire:click="openExternalModal({{ $viewRx->patient_id }})">New Rx for this customer</button>@endif
                <a wire:navigate href="{{ route('optical.orders.create', ['prescription_id' => $viewRx->id]) }}" class="oo-btn primary">Start order</a>
            </footer>
        </aside>
    @endif

    {{-- NEW EXTERNAL PRESCRIPTION PANEL --}}
    @if($showExternalModal)
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showExternalModal: false })" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="rx-new-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { showExternalModal: false })">
            <header class="oo-drawer-head">
                <div><h2 id="rx-new-title">Enter an external prescription</h2><p class="ui-muted text-xs" style="margin:0">For a prescription the customer brings from another optometrist or doctor.</p></div>
                <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { showExternalModal: false })" aria-label="Close">&times;</button>
            </header>
            <form wire:submit="saveExternalRx" class="oo-drawer-body" id="rx-form">
                <section class="oo-section">
                    <h3>Customer</h3>
                    @if($selectedCustomer)
                        <div class="rx-chosen"><span><b>{{ $selectedCustomer->name }}</b> <span class="ui-muted text-xs">{{ $selectedCustomer->pxnumber }}@if($selectedCustomer->contact) · {{ $selectedCustomer->contact }}@endif</span></span><button type="button" class="oo-link" wire:click="clearCustomer">Change</button></div>
                    @else
                        <input type="search" wire:model.live.debounce.250ms="customerSearch" class="ui-input text-sm" placeholder="Type a name, phone or PX number…" aria-label="Find customer" autocomplete="off">
                        @if($customerMatches->isNotEmpty())
                            <div class="rx-pick" role="listbox">
                                @foreach($customerMatches as $c)
                                    <button type="button" role="option" wire:click="pickCustomer({{ $c->id }})"><b>{{ $c->name }}</b><span class="ui-muted text-xs">{{ $c->pxnumber }}@if($c->contact) · {{ $c->contact }}@endif</span></button>
                                @endforeach
                            </div>
                        @elseif(strlen(trim($customerSearch)) >= 2)
                            <p class="ui-muted text-xs" style="margin:6px 0 0">No customer found. Register them first (for example from Retail POS), then come back.</p>
                        @endif
                    @endif
                    @error('patient_id')<p class="rx-err">{{ $message }}</p>@enderror
                </section>

                <section class="oo-section">
                    <h3>Prescriber</h3>
                    <div class="rx-2">
                        <label class="rx-field"><span>Name *</span><input autocomplete="off" type="text" wire:model="prescriber_name" class="ui-input" placeholder="e.g. Dr. Mensah">@error('prescriber_name')<small class="rx-err">{{ $message }}</small>@enderror</label>
                        <label class="rx-field"><span>Clinic or practice</span><input autocomplete="off" type="text" wire:model="prescriber_clinic" class="ui-input" placeholder="e.g. Korle Bu Eye Clinic">@error('prescriber_clinic')<small class="rx-err">{{ $message }}</small>@enderror</label>
                        <label class="rx-field"><span>Date on the prescription *</span><input autocomplete="off" type="date" wire:model="rx_date" max="{{ now()->toDateString() }}" class="ui-input">@error('rx_date')<small class="rx-err">{{ $message }}</small>@enderror</label>
                        <label class="rx-field"><span>Binocular PD (mm)</span><input autocomplete="off" type="number" step="0.5" wire:model="pd" class="ui-input" placeholder="e.g. 62">@error('pd')<small class="rx-err">{{ $message }}</small>@enderror</label>
                    </div>
                </section>

                <section class="oo-section">
                    <h3>Prescription</h3>
                    <div class="overflow-x-auto">
                        <table class="rx-grid" style="min-width:520px">
                            <thead><tr><th></th><th>SPH *</th><th>CYL</th><th>AXIS</th><th>ADD</th><th>VA</th><th>Mono PD</th></tr></thead>
                            <tbody>
                                @foreach(['od' => ['R (OD)', 'pd_right'], 'os' => ['L (OS)', 'pd_left']] as $side => [$label, $pdField])
                                    <tr>
                                        <th scope="row">{{ $label }}</th>
                                        <td><input autocomplete="off" type="number" step="0.25" wire:model="{{ $side }}_sphere" placeholder="0.00" aria-label="{{ $label }} sphere"></td>
                                        <td><input autocomplete="off" type="number" step="0.25" wire:model="{{ $side }}_cylinder" placeholder="0.00" aria-label="{{ $label }} cylinder"></td>
                                        <td><input autocomplete="off" type="number" min="0" max="180" wire:model="{{ $side }}_axis" placeholder="°" aria-label="{{ $label }} axis"></td>
                                        <td><input autocomplete="off" type="number" step="0.25" min="0" wire:model="{{ $side }}_add" placeholder="0.00" aria-label="{{ $label }} add"></td>
                                        <td><input autocomplete="off" type="text" wire:model="{{ $side }}_va" placeholder="6/6" aria-label="{{ $label }} visual acuity"></td>
                                        <td><input autocomplete="off" type="number" step="0.5" wire:model="{{ $pdField }}" placeholder="31.5" aria-label="{{ $label }} PD"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @foreach(['od_sphere', 'od_cylinder', 'od_axis', 'od_add', 'os_sphere', 'os_cylinder', 'os_axis', 'os_add', 'pd_right', 'pd_left'] as $field)
                        @error($field)<p class="rx-err" style="margin:4px 0 0">{{ $message }}</p>@enderror
                    @endforeach
                </section>

                <section class="oo-section">
                    <label class="rx-field"><span>Notes</span><textarea wire:model="notes" rows="2" maxlength="1000" class="ui-input" placeholder="Anything the lab or dispenser should know"></textarea></label>
                </section>
            </form>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" x-on:click="dismissLocal($el, $wire, { showExternalModal: false })">Cancel</button>
                <button type="submit" form="rx-form" class="oo-btn primary" wire:loading.attr="disabled" wire:target="saveExternalRx">Save prescription</button>
            </footer>
        </aside>
    @endif
</div>
