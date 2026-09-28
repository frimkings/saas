<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .pc-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .pc-tile{background:#fff;border:1px solid var(--clinic-line);border-radius:12px;padding:12px 14px}
        .pc-tile span{display:block;font-size:11px;font-weight:700;color:var(--clinic-muted);text-transform:uppercase;letter-spacing:.3px}
        .pc-tile b{display:block;font-size:22px;margin-top:4px;font-variant-numeric:tabular-nums}
        .pc-table col.c-name{width:auto}.pc-table col.c-contact{width:200px}.pc-table col.c-terms{width:130px}.pc-table col.c-jobs{width:130px}.pc-table col.c-owed{width:130px}.pc-table col.c-act{width:170px}
        .pc-field{display:flex;flex-direction:column;gap:4px}.pc-field>span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.pc-field .ui-input{padding:8px 10px}
        .pc-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.pc-2 .full{grid-column:1/-1}
        .pc-err{color:#b91c1c;font-size:11px}.pc-hint{color:var(--clinic-muted);font-size:11px}
        .pc-aging{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.pc-aging div{border:1px solid var(--clinic-line);border-radius:9px;padding:8px 10px}.pc-aging span{display:block;font-size:10px;color:var(--clinic-muted);font-weight:700}.pc-aging b{font-variant-numeric:tabular-nums}
        @media(max-width:900px){.pc-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:600px){.pc-2,.pc-aging{grid-template-columns:repeat(2,minmax(0,1fr))}}
    </style>

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Partner Clinics</h1>
            <p class="ui-muted text-sm">Clinics that send you optical jobs: contacts, billing terms, work in progress and what they owe.</p>
        </div>
        <button type="button" x-on:click="openLocal($wire, { editingId: null, name: '', contactPerson: '', phone: '', email: '', address: '', notificationPhone: '', billingTerms: 'pay_on_order', notifyVia: 'sms', isActive: true, viewPartnerId: null, showForm: true }, $root.querySelector('[data-partner-form]'))" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ Add partner clinic</button>
    </div>

    <x-ui.flash />

    <div class="pc-tiles">
        <div class="pc-tile"><span>Active partners</span><b>{{ $counts['active'] }}</b></div>
        <div class="pc-tile"><span>Owed on account</span><b style="color:{{ $totals['owed'] > 0 ? '#b91c1c' : 'inherit' }}">{{ currency() }} {{ number_format($totals['owed'], 2) }}</b><small class="pc-hint">{{ $totals['owing'] }} {{ \Illuminate\Support\Str::plural('partner', $totals['owing']) }} owing</small></div>
        <div class="pc-tile"><span>Jobs in the workshop</span><b>{{ $totals['inWork'] }}</b></div>
        <div class="pc-tile"><span>Jobs ready for pickup</span><b style="color:{{ $totals['ready'] > 0 ? '#92400e' : 'inherit' }}">{{ $totals['ready'] }}</b></div>
    </div>

    <section class="ui-panel bg-white">
        <div class="oo-toolbar" style="grid-template-columns:minmax(220px,1fr) auto auto">
            <input autocomplete="off" type="search" wire:model.live.debounce.250ms="search" placeholder="Search name, contact, phone or email…" class="ui-input text-sm" aria-label="Search partner clinics">
            <label class="flex items-center gap-2 text-sm" style="white-space:nowrap"><input type="checkbox" wire:model.live="owingOnly"> Owing money only</label>
            <span class="ui-muted" wire:loading.delay wire:target="search,owingOnly,setStatus">Updating…</span>
        </div>
        <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by status">
            @foreach(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $key => $label)
                <button type="button" wire:click="setStatus('{{ $key }}')" class="oo-chip {{ $statusFilter === $key ? 'active' : '' }}" aria-pressed="{{ $statusFilter === $key ? 'true' : 'false' }}">{{ $label }}<b>{{ $counts[$key] }}</b></button>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="oo-table pc-table w-full" style="min-width:900px">
                <colgroup><col class="c-name"><col class="c-contact"><col class="c-terms"><col class="c-jobs"><col class="c-owed"><col class="c-act"></colgroup>
                <thead><tr><th>Clinic</th><th>Contact</th><th>Billing</th><th>Open jobs</th><th class="oo-num">Owed</th><th style="text-align:right">Actions</th></tr></thead>
                <tbody>
                    @forelse($partners as $partner)
                        @php
                            $open = $jobs[$partner->id] ?? collect();
                            $ready = $open->whereIn('status', ['Ready for Collection', 'Ready'])->count();
                            $owed = $balances[$partner->id] ?? 0;
                        @endphp
                        <tr wire:key="partner-{{ $partner->id }}" class="{{ $viewPartnerId === $partner->id ? 'oo-active' : '' }}" wire:click="openPartner({{ $partner->id }})">
                            <td><b>{{ $partner->name }}</b>@if(! $partner->is_active) <span class="oo-badge oo-b-grey">Archived</span>@endif @if($partner->address)<span class="oo-sub" style="display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden">{{ $partner->address }}</span>@endif</td>
                            <td>{{ $partner->contact_person ?: '—' }}<span class="oo-sub">{{ $partner->phone ?: ($partner->email ?: 'No contact details') }}</span></td>
                            <td><span class="oo-badge {{ $partner->billing_terms === 'on_account' ? 'oo-b-purple' : 'oo-b-grey' }}">{{ $partner->billing_terms === 'on_account' ? 'On account' : 'Pay on order' }}</span></td>
                            <td>{{ $open->count() ?: '—' }}@if($ready)<span class="oo-sub" style="color:#92400e;font-weight:700">{{ $ready }} ready</span>@endif</td>
                            <td class="oo-num">@if($owed > 0)<b style="color:#b91c1c">{{ currency() }} {{ number_format($owed, 2) }}</b>@else<span class="ui-muted">0.00</span>@endif</td>
                            <td onclick="event.stopPropagation()">
                                <div class="oo-row-actions" style="grid-template-columns:80px 64px">
                                    <a href="{{ route('optical.partners.statement', $partner->id) }}" class="oo-btn">Account</a>
                                    <button type="button" class="oo-btn" wire:click="openPartner({{ $partner->id }})">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">{{ $search || $owingOnly || $statusFilter !== 'active' ? 'No partner clinics match these filters.' : 'No partner clinics yet.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $partners->links() }}</div>
    </section>

    {{-- PARTNER PANEL --}}
    @if($viewPartner)
        @php $owed = $balances[$viewPartner->id] ?? 0; @endphp
        <div x-show="! $wire.showForm">
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { viewPartnerId: null, showForm: false })" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="pc-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="! $wire.showForm && dismissLocal($el, $wire, { viewPartnerId: null, showForm: false })" wire:key="pc-drawer-{{ $viewPartner->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="pc-title">{{ $viewPartner->name }}</h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-badge {{ $viewPartner->is_active ? 'oo-b-green' : 'oo-b-grey' }}">{{ $viewPartner->is_active ? 'Active' : 'Archived' }}</span>
                        <span class="oo-badge {{ $viewPartner->billing_terms === 'on_account' ? 'oo-b-purple' : 'oo-b-grey' }}">{{ $viewPartner->billing_terms === 'on_account' ? 'On account' : 'Pay on order' }}</span>
                    </div>
                </div>
                <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { viewPartnerId: null, showForm: false })" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                <section class="oo-section">
                    <h3>Account</h3>
                    <div class="oo-money" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                        <div><span>Owed now</span><b style="color:{{ $owed > 0 ? '#b91c1c' : 'inherit' }}">{{ currency() }} {{ number_format($owed, 2) }}</b></div>
                        <div><span>Open jobs</span><b>{{ ($jobs[$viewPartner->id] ?? collect())->count() }}</b></div>
                    </div>
                    @if($owed > 0)
                        <div class="pc-aging" style="margin-top:10px">
                            @foreach(\App\Services\OpticalPartnerAccountService::AGING as $key => $label)
                                <div><span>{{ $label }}</span><b style="color:{{ ($viewAging[$key] ?? 0) > 0 && in_array($key, ['61_90', '90_plus'], true) ? '#b91c1c' : 'inherit' }}">{{ number_format($viewAging[$key] ?? 0, 2) }}</b></div>
                            @endforeach
                        </div>
                    @endif
                </section>
                <section class="oo-section">
                    <h3>Contact</h3>
                    <dl class="oo-dl">
                        <dt>Contact person</dt><dd>{{ $viewPartner->contact_person ?: '—' }}</dd>
                        <dt>Phone</dt><dd>{{ $viewPartner->phone ?: '—' }}</dd>
                        <dt>Email</dt><dd>{{ $viewPartner->email ?: '—' }}</dd>
                        <dt>Address</dt><dd style="white-space:pre-line">{{ $viewPartner->address ?: '—' }}</dd>
                        <dt>Job messages</dt><dd>{{ \App\Models\OpticalPartnerClinic::NOTIFY_VIA[$viewPartner->notify_via ?: 'sms'] ?? 'SMS' }}@if($viewPartner->notify_via !== 'none') to {{ $viewPartner->messagingPhone() ?: 'no phone set' }}@endif</dd>
                    </dl>
                </section>
                <section class="oo-section">
                    <h3>Recent jobs</h3>
                    @forelse($viewOrders as $order)
                        @php [$bl, $bc] = \App\Support\Optical\OrderPresenter::badge($order->status); @endphp
                        <div class="flex items-center justify-between gap-2" style="padding:6px 0;border-bottom:1px solid var(--clinic-line)">
                            <span><span class="oo-id">{{ $order->order_id }}</span> <span class="ui-muted text-xs">· {{ $order->customer_name ?: 'No wearer name' }} · {{ $order->created_at?->format('d M Y') }}</span></span>
                            <span class="flex items-center gap-2"><span class="oo-badge {{ $bc }}">{{ $bl }}</span><a class="oo-link" href="{{ route('optical.orders', ['searchTerm' => $order->order_id]) }}">Open</a></span>
                        </div>
                    @empty
                        <p class="ui-muted text-sm" style="margin:0">No jobs yet.</p>
                    @endforelse
                    @if($viewOrders->count() >= 8)<p style="margin:8px 0 0"><a class="oo-link" href="{{ route('optical.orders', ['sourceFilter' => 'partner', 'partnerFilter' => $viewPartner->id]) }}">See all jobs →</a></p>@endif
                </section>
                <section class="oo-section" style="border-top:1px solid var(--clinic-line);padding-top:14px">
                    @if($viewPartner->is_active)
                        <button type="button" class="oo-btn danger" wire:click="archive({{ $viewPartner->id }})" wire:confirm="Archive {{ $viewPartner->name }}? It can't be picked for new orders; existing jobs and its account stay available.">Archive partner</button>
                    @else
                        <button type="button" class="oo-btn" wire:click="restore({{ $viewPartner->id }})">Restore for new orders</button>
                    @endif
                </section>
            </div>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" x-on:click="openLocal($wire, {{ \Illuminate\Support\Js::from(['editingId' => $viewPartner->id, 'name' => $viewPartner->name, 'contactPerson' => $viewPartner->contact_person ?? '', 'phone' => $viewPartner->phone ?? '', 'email' => $viewPartner->email ?? '', 'address' => $viewPartner->address ?? '', 'billingTerms' => $viewPartner->billing_terms, 'notificationPhone' => $viewPartner->notification_phone ?? '', 'notifyVia' => $viewPartner->notify_via ?: 'sms', 'isActive' => (bool) $viewPartner->is_active, 'showForm' => true]) }}, $root.querySelector('[data-partner-form]'))">Edit details</button>
                <a href="{{ route('optical.partners.statement', $viewPartner->id) }}" class="oo-btn">Account statement</a>
                @if($viewPartner->is_active)<a href="{{ route('optical.orders.create', ['partner_id' => $viewPartner->id]) }}" class="oo-btn primary">New job</a>@endif
            </footer>
        </aside>
        </div>
    @endif

    {{-- ADD / EDIT PANEL: always in the page; Add and Edit open it in the browser (openLocal), only Save calls the server. --}}
    <div data-sheet data-keep data-partner-form x-show="$wire.showForm" x-cloak>
        <div class="oo-overlay" x-on:click="dismissLocal($el, $wire, $wire.editingId ? { showForm: false } : { showForm: false, viewPartnerId: null })" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="pc-form-title" tabindex="-1" x-on:keydown.escape.window="$wire.showForm && dismissLocal($el, $wire, $wire.editingId ? { showForm: false } : { showForm: false, viewPartnerId: null })">
            <header class="oo-drawer-head">
                <div><h2 id="pc-form-title" x-text="$wire.editingId ? 'Edit ' + $wire.name : 'Add a partner clinic'">{{ $editingId ? 'Edit '.$name : 'Add a partner clinic' }}</h2></div>
                <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, $wire.editingId ? { showForm: false } : { showForm: false, viewPartnerId: null })" aria-label="Close">&times;</button>
            </header>
            <form wire:submit="save" class="oo-drawer-body" id="pc-form">
                <section class="oo-section">
                    <h3>Clinic</h3>
                    <div class="pc-2">
                        <label class="pc-field full"><span>Clinic name *</span><input autocomplete="off" type="text" wire:model="name" class="ui-input" required>@error('name')<small class="pc-err">{{ $message }}</small>@enderror</label>
                        <label class="pc-field"><span>Contact person</span><input autocomplete="off" type="text" wire:model="contactPerson" class="ui-input"></label>
                        <label class="pc-field"><span>Phone</span><input autocomplete="off" type="tel" wire:model="phone" class="ui-input">@error('phone')<small class="pc-err">{{ $message }}</small>@enderror</label>
                        <label class="pc-field full"><span>Email</span><input autocomplete="off" type="email" wire:model="email" class="ui-input">@error('email')<small class="pc-err">{{ $message }}</small>@enderror</label>
                        <label class="pc-field full"><span>Address</span><textarea wire:model="address" rows="2" class="ui-input"></textarea></label>
                    </div>
                </section>
                <section class="oo-section">
                    <h3>Billing</h3>
                    <label class="pc-field"><span>Billing terms</span>
                        <select wire:model="billingTerms" class="ui-input"><option value="pay_on_order">Pay on order: each job is paid when ordered</option><option value="on_account">On account: jobs go on a running balance</option></select>
                    </label>
                </section>
                <section class="oo-section">
                    <h3>Job messages</h3>
                    <div class="pc-2">
                        <label class="pc-field"><span>Send by</span><select wire:model="notifyVia" class="ui-input">@foreach(\App\Models\OpticalPartnerClinic::NOTIFY_VIA as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="pc-field"><span>Notification phone</span><input autocomplete="off" type="tel" wire:model="notificationPhone" placeholder="Defaults to the phone above" class="ui-input"></label>
                    </div>
                    <p class="pc-hint" style="margin:6px 0 0">"Job ready" and pickup messages about this partner's jobs go to the partner, never to its patients.</p>
                </section>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isActive"> Active for new orders</label>
            </form>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" x-on:click="dismissLocal($el, $wire, $wire.editingId ? { showForm: false } : { showForm: false, viewPartnerId: null })">Cancel</button>
                <button type="submit" form="pc-form" class="oo-btn primary" wire:loading.attr="disabled" wire:target="save" x-text="$wire.editingId ? 'Save changes' : 'Add partner clinic'">{{ $editingId ? 'Save changes' : 'Add partner clinic' }}</button>
            </footer>
        </aside>
    </div>
</div>
