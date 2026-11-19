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
        @if($canManage)<button type="button" x-on:click="openLocal($wire, { editingId: null, name: '', contactPerson: '', phone: '', email: '', address: '', notificationPhone: '', billingTerms: 'pay_on_order', notifyVia: 'sms', isActive: true, viewPartnerId: null, showForm: true }, $root.querySelector('[data-partner-form]'))" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ Add partner clinic</button>@else<span class="ui-muted text-sm">A manager adds and changes partner clinics.</span>@endif
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
        <div class="px-4 pt-3 space-y-2" data-tour="partner-bulk">
            @include('livewire.optical.partials.bulk-report', ['report' => $bulkReport])
            @if($tickedCount)
                <div class="oo-bulkbar" role="region" aria-label="Ticked partner clinics">
                    <b>{{ $tickedCount }} ticked</b>
                    <button type="button" class="oo-btn primary" wire:click="bulkStatements"><i class="fab fa-whatsapp" aria-hidden="true"></i> This month's statements</button>
                    @if($canManage)<button type="button" class="oo-btn" wire:click="bulkArchive" wire:confirm="Archive {{ $tickedCount }} partner {{ \Illuminate\Support\Str::plural('clinic', $tickedCount) }}? Their orders stay available.">Archive</button>@endif
                    <button type="button" class="oo-btn ml-auto" wire:click="clearSelection">Clear</button>
                </div>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="oo-table pc-table w-full" style="min-width:900px">
                <colgroup><col style="width:40px"><col class="c-name"><col class="c-contact"><col class="c-terms"><col class="c-jobs"><col class="c-owed"><col class="c-act"></colgroup>
                <thead><tr><th><input type="checkbox" class="oo-check" aria-label="Tick every partner shown"
                        x-data="{ ids: @js($partners->pluck('id')->map(fn ($id) => (string) $id)->values()) }"
                        x-bind:checked="ids.length && ids.every(id => $wire.selected.includes(id))"
                        x-on:change="$wire.selected = $event.target.checked ? [...new Set([...$wire.selected, ...ids])] : $wire.selected.filter(id => ! ids.includes(id))"></th><th>Clinic</th><th>Contact</th><th>Billing</th><th>Open jobs</th><th class="oo-num">Owed</th><th class="!text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse($partners as $partner)
                        @php
                            $open = $jobs[$partner->id] ?? collect();
                            $ready = $open->whereIn('status', ['Ready for Collection', 'Ready'])->count();
                            $owed = $balances[$partner->id] ?? 0;
                        @endphp
                        <tr wire:key="partner-{{ $partner->id }}" class="{{ $viewPartnerId === $partner->id ? 'oo-active' : '' }}" wire:click="openPartner({{ $partner->id }})">
                            <td onclick="event.stopPropagation()"><input type="checkbox" class="oo-check" value="{{ $partner->id }}" wire:model.live="selected" aria-label="Tick {{ $partner->name }}"></td>
                            <td><b>{{ $partner->name }}</b>@if(! $partner->is_active) <span class="oo-badge oo-b-grey">Archived</span>@endif @if($partner->hasLink()) <span class="oo-badge {{ $partner->link_status === 'active' ? 'oo-b-green' : 'oo-b-grey' }}" title="Sends orders online">{{ $partner->link_status === 'active' ? 'Linked' : 'Link paused' }}</span>@endif @if($linkPaymentsWaiting->get($partner->id)) <span class="oo-badge oo-b-amber">Payment to confirm</span>@endif @if($partner->address)<span class="oo-sub" style="display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden">{{ $partner->address }}</span>@endif</td>
                            <td>{{ $partner->contact_person ?: '—' }}<span class="oo-sub">{{ $partner->phone ?: ($partner->email ?: 'No contact details') }}</span></td>
                            <td><span class="oo-badge {{ $partner->billing_terms === 'on_account' ? 'oo-b-purple' : 'oo-b-grey' }}">{{ $partner->billing_terms === 'on_account' ? 'On account' : 'Pay on order' }}</span></td>
                            <td>{{ $open->count() ?: '—' }}@if($ready)<span class="oo-sub" style="color:#92400e;font-weight:700">{{ $ready }} ready</span>@endif</td>
                            <td class="oo-num">@if($owed > 0)<b style="color:#b91c1c">{{ currency() }} {{ number_format($owed, 2) }}</b>@else<span class="ui-muted">0.00</span>@endif</td>
                            <td onclick="event.stopPropagation()">
                                <div class="oo-row-actions" style="grid-template-columns:80px 64px">
                                    <a wire:navigate href="{{ route('optical.partners.statement', $partner->id) }}" class="oo-btn">Account</a>
                                    <button type="button" class="oo-btn" wire:click="openPartner({{ $partner->id }})">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">{{ $search || $owingOnly || $statusFilter !== 'active' ? 'No partner clinics match these filters.' : 'No partner clinics yet.' }}</p></td></tr>
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
                        <div class="pc-aging mt-2.5">
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
                            <span class="flex items-center gap-2"><span class="oo-badge {{ $bc }}">{{ $bl }}</span><a class="oo-link" wire:navigate href="{{ route('optical.orders', ['searchTerm' => $order->order_id]) }}">Open</a></span>
                        </div>
                    @empty
                        <p class="ui-muted text-sm m-0">No jobs yet.</p>
                    @endforelse
                    @if($viewOrders->count() >= 8)<p style="margin:8px 0 0"><a class="oo-link" wire:navigate href="{{ route('optical.orders', ['sourceFilter' => 'partner', 'partnerFilter' => $viewPartner->id]) }}">See all jobs →</a></p>@endif
                </section>
                @if($viewLinkPayments->isNotEmpty())
                    <section class="oo-section" data-tour="partner-link-payments">
                        <h3>Payments from the clinic to confirm</h3>
                        <p class="pc-hint" style="margin:0 0 8px">{{ $viewPartner->linkedClinic?->name }} says it paid these. Confirm once the money is in your account or till: the jobs are then settled here and on their side.</p>
                        @foreach($viewLinkPayments as $payment)
                            <div class="rounded-lg border border-slate-200 p-2.5 mb-2" wire:key="olp-{{ $payment->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm"><b>{{ currency() }} {{ number_format((float) $payment->amount, 2) }}</b> · {{ $payment->methodLabel() }}@if($payment->reference) · ref {{ $payment->reference }}@endif
                                        <span class="pc-hint" style="display:block">{{ $payment->created_at->format('d M Y H:i') }}@if($payment->recorder) by {{ $payment->recorder->name }}@endif · {{ count((array) $payment->allocations) }} {{ \Illuminate\Support\Str::plural('job', count((array) $payment->allocations)) }}@if($payment->notes) · {{ $payment->notes }}@endif</span></div>
                                    @if($disputingPaymentId !== $payment->id)
                                        <div class="flex gap-2">
                                            <button type="button" class="oo-btn" wire:click="startDispute({{ $payment->id }})">Not received</button>
                                            <button type="button" class="oo-btn primary" wire:click="confirmLinkPayment({{ $payment->id }})" wire:confirm="Confirm you received {{ currency() }} {{ number_format((float) $payment->amount, 2) }}?">Confirm received</button>
                                        </div>
                                    @endif
                                </div>
                                @if($disputingPaymentId === $payment->id)
                                    <div class="mt-2">
                                        <textarea wire:model="disputeReason" rows="2" class="ui-input text-sm" aria-label="What is wrong with this payment" placeholder="e.g. Nothing arrived on our MoMo number yet."></textarea>
                                        @error('disputeReason')<small class="pc-err">{{ $message }}</small>@enderror
                                        <div class="flex gap-2 mt-2"><button type="button" class="oo-btn danger" wire:click="disputeLinkPayment">Tell the clinic</button><button type="button" class="oo-btn" wire:click="startDispute">Back</button></div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        @error('payment')<small class="pc-err">{{ $message }}</small>@enderror
                    </section>
                @endif
                <section class="oo-section" data-tour="partner-link">
                    <h3>Online link</h3>
                    @if($viewPartner->hasLink())
                        <p class="text-sm m-0">
                            <span class="oo-badge {{ $viewPartner->link_status === 'active' ? 'oo-b-green' : 'oo-b-grey' }}">{{ \App\Models\OpticalPartnerClinic::LINK_STATUSES[$viewPartner->link_status] }}</span>
                            to <b>{{ $viewPartner->linkedClinic?->name ?? 'a clinic' }}</b>@if($viewPartner->linked_at) since {{ $viewPartner->linked_at->format('d M Y') }}@endif.
                        </p>
                        <p class="pc-hint" style="margin:6px 0 0">{{ $viewPartner->link_status === 'active' ? 'They send orders straight to Incoming Orders: wearer name, phone and prescription.' : 'They can’t send new orders while the link is paused.' }}</p>
                        @if($viewPartner->link_paused_by_platform)
                            <p class="pc-err" style="margin:6px 0 0">Paused by Nexus support{{ $viewPartner->link_status_reason ? ': '.$viewPartner->link_status_reason : '' }}. Contact support to resume it.</p>
                        @endif
                        <div class="rounded-lg border border-slate-200 p-3 mt-3" data-tour="partner-link-terms">
                            <h4 class="text-sm font-semibold !mb-1">Agreed terms</h4>
                            <p class="pc-hint" style="margin:0 0 8px">Trade % comes off your own prices on every quote for this clinic. At the credit limit they can’t send new jobs until they pay. The clinic sees these.</p>
                            @php
                                $owedNow = (float) $viewLinkOwed;
                                $limitNow = $viewPartner->credit_limit;
                            @endphp
                            <p class="text-sm" style="margin:0 0 8px">Owed on linked jobs: <b>{{ currency() }} {{ number_format($owedNow, 2) }}</b>@if($limitNow !== null) of a {{ currency() }} {{ number_format($limitNow, 2) }} limit @if($owedNow >= $limitNow)<span class="oo-badge oo-b-red">At limit: no new jobs</span>@endif @endif
                                · Quotes {{ $viewPartner->auto_approve_limit !== null ? 'up to '.currency().' '.number_format($viewPartner->auto_approve_limit, 2).' are approved by the clinic automatically' : 'are approved by the clinic one by one' }}.</p>
                            @if($canManage)
                                <form wire:submit="saveTerms" class="pc-2">
                                    <label class="pc-field"><span>Lenses % off</span><input type="number" min="0" max="90" step="0.5" wire:model="terms.lens" class="ui-input" placeholder="0">@error('terms.lens')<small class="pc-err">{{ $message }}</small>@enderror</label>
                                    <label class="pc-field"><span>Frames % off</span><input type="number" min="0" max="90" step="0.5" wire:model="terms.frame" class="ui-input" placeholder="0">@error('terms.frame')<small class="pc-err">{{ $message }}</small>@enderror</label>
                                    <label class="pc-field"><span>Services % off</span><input type="number" min="0" max="90" step="0.5" wire:model="terms.service" class="ui-input" placeholder="0">@error('terms.service')<small class="pc-err">{{ $message }}</small>@enderror</label>
                                    <label class="pc-field"><span>Credit limit ({{ currency() }})</span><input type="number" min="0" step="0.01" wire:model="terms.credit" class="ui-input" placeholder="No limit">@error('terms.credit')<small class="pc-err">{{ $message }}</small>@enderror</label>
                                    <div class="full"><button type="submit" class="oo-btn primary" wire:loading.attr="disabled" wire:target="saveTerms">Save terms</button>@if($viewPartner->terms_updated_at)<span class="pc-hint" style="margin-left:8px">Last changed {{ $viewPartner->terms_updated_at->format('d M Y') }}</span>@endif</div>
                                </form>
                            @else
                                <p class="text-sm m-0">Trade discount: {{ $viewPartner->tradeSummary() }} · Credit limit: {{ $limitNow !== null ? currency().' '.number_format($limitNow, 2) : 'none' }}</p>
                            @endif
                        </div>
                        @if($canManage && ! $viewPartner->link_paused_by_platform)
                            <div class="flex flex-wrap gap-2 mt-2.5">
                                @if($viewPartner->link_status === 'active')
                                    <button type="button" class="oo-btn" wire:click="setLink({{ $viewPartner->id }}, 'paused')">Pause link</button>
                                @elseif(! $viewPartner->link_paused_by_clinic_id || (int) $viewPartner->link_paused_by_clinic_id === (int) $viewPartner->clinic_id)
                                    <button type="button" class="oo-btn" wire:click="setLink({{ $viewPartner->id }}, 'active')">Resume link</button>
                                @else
                                    <span class="pc-hint" style="align-self:center">The clinic paused this link, so only they can resume it.</span>
                                @endif
                                <button type="button" class="oo-btn danger" wire:click="setLink({{ $viewPartner->id }}, 'ended')" wire:confirm="Unlink {{ $viewPartner->linkedClinic?->name ?? 'this clinic' }}? They can't send new orders. Jobs already sent stay on both sides.">Unlink</button>
                            </div>
                        @endif
                    @elseif($viewInvite)
                        @php
                            $inviteUrl = route('optical-links.accept', ['code' => $viewInvite->code]);
                            $inviteText = 'To send us spectacle orders online, open '.$inviteUrl.' while signed in as the clinic owner or manager, or enter code '.$viewInvite->code.' under Optical Centres. The code expires on '.$viewInvite->expires_at->format('d M Y').'.';
                        @endphp
                        <p class="text-sm m-0">Invite code <b class="font-mono" style="font-size:18px;letter-spacing:2px">{{ $viewInvite->code }}</b></p>
                        <p class="pc-hint" style="margin:4px 0 0">Waiting for the clinic to accept · expires {{ $viewInvite->expires_at->format('d M Y') }}</p>
                        <div class="flex flex-wrap gap-2 mt-2.5" x-data="{ copied: false }">
                            <button type="button" class="oo-btn" x-on:click="navigator.clipboard?.writeText(@js($inviteText)); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Copied' : 'Copy message'">Copy message</span></button>
                            <a class="oo-btn" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/\D+/', '', (string) $viewPartner->messagingPhone()) }}?text={{ rawurlencode($inviteText) }}">WhatsApp</a>
                            @if($canManage)<button type="button" class="oo-btn danger" wire:click="revokeInvite({{ $viewPartner->id }})">Cancel invite</button>@endif
                        </div>
                    @else
                        <p class="pc-hint" style="margin:0">Is this clinic on Nexus too? Link it, and its staff send orders straight to you with the prescription filled in, then follow each job's progress.</p>
                        @if($canManage && $viewPartner->is_active)<button type="button" class="oo-btn mt-2.5" wire:click="createInvite({{ $viewPartner->id }})">Invite to link</button>@endif
                    @endif
                    @if(! $viewPartner->hasLink() && $canManage)
                        <div class="rounded-lg border border-slate-200 p-3 mt-3" data-tour="partner-order-page">
                            <h4 class="text-sm font-semibold !mb-1">Ordering page (clinic not on Nexus)</h4>
                            @if($viewPartner->hasOrderPage())
                                @php
                                    $pageUrl = route('order-page.show', $viewPartner->page_token);
                                    $linkText = 'Send us spectacle orders online and follow them here: '.$pageUrl.' — we will give you the PIN separately.';
                                @endphp
                                <p class="pc-hint" style="margin:0 0 6px">On. Their staff open the link, enter the PIN, send orders and follow each job. Orders arrive in Incoming Orders.@if($viewPartner->page_last_used_at) Last opened {{ $viewPartner->page_last_used_at->diffForHumans() }}.@endif @if($viewPartner->page_locked_until && $viewPartner->page_locked_until->isFuture())<b class="pc-err">Locked after wrong PINs until {{ $viewPartner->page_locked_until->format('H:i') }}.</b>@endif</p>
                                <p class="text-xs font-mono break-all m-0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px">{{ $pageUrl }}</p>
                                @if($shownPin !== '')
                                    <p class="text-sm" style="margin:8px 0 0">PIN <b class="font-mono" style="font-size:18px;letter-spacing:3px">{{ $shownPin }}</b> <span class="pc-hint">Shown only now. Send it separately from the link.</span></p>
                                @endif
                                <div class="flex flex-wrap gap-2 mt-2.5" x-data="{ copied: '' }">
                                    <button type="button" class="oo-btn" x-on:click="navigator.clipboard?.writeText(@js($linkText)); copied = 'link'; setTimeout(() => copied = '', 2000)"><span x-text="copied === 'link' ? 'Copied' : 'Copy link message'">Copy link message</span></button>
                                    <a class="oo-btn" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/\D+/', '', (string) $viewPartner->messagingPhone()) }}?text={{ rawurlencode($linkText) }}">WhatsApp the link</a>
                                    @if($shownPin !== '')<button type="button" class="oo-btn" x-on:click="navigator.clipboard?.writeText(@js('Your PIN for our ordering page: '.$shownPin)); copied = 'pin'; setTimeout(() => copied = '', 2000)"><span x-text="copied === 'pin' ? 'Copied' : 'Copy PIN message'">Copy PIN message</span></button>@endif
                                </div>
                                <label class="flex items-center gap-2 mt-2.5 text-sm" style="cursor:pointer">
                                    <input type="checkbox" @checked($viewPartner->page_show_prices) wire:click="toggleOrderPagePrices({{ $viewPartner->id }})">
                                    <span>Show lens prices on their page <span class="pc-hint">(after their trade %). Stock shows only as "In stock" or "Order in".</span></span>
                                </label>
                                <div class="flex flex-wrap items-end gap-2 mt-2.5">
                                    <label class="pc-field" style="max-width:150px"><span>New PIN</span><input type="text" inputmode="numeric" maxlength="6" wire:model="pagePin" class="ui-input font-mono" placeholder="Make one up"></label>
                                    <button type="button" class="oo-btn" wire:click="setOrderPage({{ $viewPartner->id }})">Set new PIN</button>
                                    <button type="button" class="oo-btn" wire:click="newOrderPageLink({{ $viewPartner->id }})" wire:confirm="Make a new link? The current link stops working at once.">New link</button>
                                    <button type="button" class="oo-btn danger" wire:click="disableOrderPage({{ $viewPartner->id }})" wire:confirm="Turn off the ordering page? The link and PIN stop working. Orders already sent stay.">Turn off</button>
                                </div>
                            @else
                                <p class="pc-hint" style="margin:0 0 6px">Not on Nexus? Give them a private link and a 6-digit PIN: they send orders with the prescription and follow each job, without an account. You price them as ordinary partner jobs.</p>
                                @if($viewPartner->is_active)
                                    <div class="flex flex-wrap items-end gap-2">
                                        <label class="pc-field" style="max-width:150px"><span>PIN (optional)</span><input type="text" inputmode="numeric" maxlength="6" wire:model="pagePin" class="ui-input font-mono" placeholder="Make one up"></label>
                                        <button type="button" class="oo-btn primary" wire:click="setOrderPage({{ $viewPartner->id }})">Turn on ordering page</button>
                                    </div>
                                @endif
                            @endif
                            @error('pagePin')<p class="pc-err">{{ $message }}</p>@enderror
                            @error('page')<p class="pc-err">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    @error('invite')<p class="pc-err">{{ $message }}</p>@enderror
                    @error('link')<p class="pc-err">{{ $message }}</p>@enderror
                </section>
                @if($canManage)<section class="oo-section" style="border-top:1px solid var(--clinic-line);padding-top:14px">
                    @if($viewPartner->is_active)
                        <button type="button" class="oo-btn danger" wire:click="archive({{ $viewPartner->id }})" wire:confirm="Archive {{ $viewPartner->name }}? It can't be picked for new orders; existing jobs and its account stay available.">Archive partner</button>
                    @else
                        <button type="button" class="oo-btn" wire:click="restore({{ $viewPartner->id }})">Restore for new orders</button>
                    @endif
                </section>@endif
            </div>
            <footer class="oo-drawer-foot">
                @if($canManage)<button type="button" class="oo-btn" x-on:click="openLocal($wire, {{ \Illuminate\Support\Js::from(['editingId' => $viewPartner->id, 'name' => $viewPartner->name, 'contactPerson' => $viewPartner->contact_person ?? '', 'phone' => $viewPartner->phone ?? '', 'email' => $viewPartner->email ?? '', 'address' => $viewPartner->address ?? '', 'billingTerms' => $viewPartner->billing_terms, 'notificationPhone' => $viewPartner->notification_phone ?? '', 'notifyVia' => $viewPartner->notify_via ?: 'sms', 'isActive' => (bool) $viewPartner->is_active, 'showForm' => true]) }}, $root.querySelector('[data-partner-form]'))">Edit details</button>@endif
                <a wire:navigate href="{{ route('optical.partners.statement', $viewPartner->id) }}" class="oo-btn">Account statement</a>
                @if($viewPartner->is_active)<a wire:navigate href="{{ route('optical.orders.create', ['partner_id' => $viewPartner->id]) }}" class="oo-btn primary">New job</a>@endif
            </footer>
        </aside>
        </div>
    @endif

    @if($canManage)
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
                        <label class="pc-field"><span>Phone</span><input aria-label="Phone number" autocomplete="off" type="tel" wire:model="phone" class="ui-input">@error('phone')<small class="pc-err">{{ $message }}</small>@enderror</label>
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
                        <label class="pc-field"><span>Notification phone</span><input aria-label="Defaults to the phone above" autocomplete="off" type="tel" wire:model="notificationPhone" placeholder="Defaults to the phone above" class="ui-input"></label>
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
    @endif
</div>
