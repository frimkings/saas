<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Optical Order Management</h1>
            <p class="ui-muted text-sm">Track order lifecycle, lab progress, payments, collections, and remakes.</p>
        </div>
        <button wire:click="openCreateOrderModal" type="button" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ New order</button>
    </div>

    <x-ui.flash />
    @if($errors->any() && ! $viewOrderId) <div class="oo-note red" role="alert">{{ $errors->first() }}</div> @endif

    <div class="ui-panel bg-white">
        <div class="oo-toolbar">
            <input autocomplete="off" type="search" wire:model.live.debounce.300ms="searchTerm" placeholder="Search order ID, customer, phone, frame or service…" class="ui-input text-sm" aria-label="Search orders">
            <span class="ui-muted" wire:loading.delay wire:target="searchTerm,setFilter,datePreset,dateFrom,dateTo,dateField,sourceFilter,partnerFilter,clearFilters">Updating…</span>
        </div>
        <div class="oo-filters">
            <label class="oo-f"><span>Date</span>
                <select wire:model.live="dateField" class="ui-input text-sm"><option value="created">Ordered on</option><option value="pickup">Pickup date</option></select>
            </label>
            <div class="oo-f" style="width:auto"><span>Dates</span><x-date-range from="dateFrom" to="dateTo" :presets="$dateField === 'pickup' ? 'upcoming' : 'activity'" max="none" clearable wire:key="orders-dates-{{ $dateField }}" /></div>
            <label class="oo-f"><span>Order source</span>
                <select wire:model.live="sourceFilter" class="ui-input text-sm"><option value="">All sources</option>@foreach(\App\Livewire\Optical\OpticalOrdersComponent::SOURCES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
            @if($sourceFilter === 'partner')
                <label class="oo-f"><span>Partner clinic</span>
                    <select wire:model.live="partnerFilter" class="ui-input text-sm"><option value="">All partner clinics</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}{{ $partner->is_active ? '' : ' (inactive)' }}</option>@endforeach</select>
                </label>
            @endif
            <div class="oo-presets">
                
                @if($narrowed || $statusFilter)<button type="button" class="oo-link" style="color:#b91c1c" wire:click="clearFilters">Clear all</button>@endif
            </div>
        </div>
        <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by status">
            @foreach(\App\Livewire\Optical\OpticalOrdersComponent::FILTERS as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" class="oo-chip {{ $statusFilter === $key ? 'active' : '' }}" aria-pressed="{{ $statusFilter === $key ? 'true' : 'false' }}">{{ $label }}@if($filterCounts[$key] !== null)<b>{{ $filterCounts[$key] }}</b>@endif</button>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="oo-table w-full">
                <colgroup><col class="c-order"><col class="c-cust"><col class="c-work"><col class="c-amt"><col class="c-pick"><col class="c-status"><col class="c-act"></colgroup>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Work</th>
                        <th class="oo-num">Amount</th>
                        <th>Pickup</th>
                        <th>Status</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $ord)
                        @php
                            $total = $ord->total;
                            $clinicBill = $clinicBills[$ord->id] ?? null;
                            $balance = $clinicBill ? (float) $clinicBill['balance'] : max(0, $total - (float) $ord->paid_amount);
                            [$badgeLabel, $badgeClass] = \App\Support\Optical\OrderPresenter::badge($ord->status);
                            $isReady = in_array($ord->status, ['Ready for Collection', 'Ready'], true);
                            $pickup = $ord->pickUpDate ? \Carbon\Carbon::parse($ord->pickUpDate) : null;
                            $late = $pickup && $pickup->lt(today()) && in_array($ord->status, ['Pending', 'Sent to Lab', 'In Production'], true);
                            $awaiting = $ord->lensLines->where('source', 'special_order')->where('status', 'ordered')->count();
                            $step = \App\Support\Optical\OrderPresenter::nextStep($ord);
                            $blocked = $isReady && $balance > 0 && ! ($ord->order_source === 'partner' && $ord->partner_billing_terms === 'on_account');
                            $canTakePayment = $ord->sale_id && ! in_array($ord->status, ['Quotation', 'Cancelled'], true);
                        @endphp
                        <tr wire:key="order-{{ $ord->id }}" class="{{ $viewOrderId === $ord->id ? 'oo-active' : '' }}" wire:click="openOrder({{ $ord->id }})">
                            <td>
                                <span class="oo-id" style="{{ $ord->status === 'Quotation' ? 'color:#6b21a8' : '' }}">{{ $ord->order_id }}</span>
                                <span class="oo-sub">{{ $ord->created_at?->format('d M Y') }}@if($ord->remakeOf) · <span style="color:#92400e;font-weight:700">Remake</span>@endif</span>
                            </td>
                            <td><b>{{ $ord->display_customer_name }}</b>@if($ord->display_customer_phone)<span class="oo-sub">{{ $ord->display_customer_phone }}</span>@endif @if($ord->order_source === 'partner')<span class="oo-sub" style="color:#6b21a8;font-weight:700">Partner · {{ $ord->partnerClinic?->name ?? $ord->partner_clinic_name ?? '—' }}</span>@elseif($ord->order_source === 'walk_in')<span class="oo-sub">Walk-in</span>@endif</td>
                            <td><span style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $ord->frame_model_number ?: ($ord->serviceLines->pluck('description')->join(', ') ?: '—') }}</span></td>
                            <td class="oo-num">
                                <b>{{ currency() }} {{ number_format($total, 2) }}</b>
                                @if(in_array($ord->status, ['Quotation', 'Cancelled'], true))
                                @elseif($balance > 0)<span class="oo-sub" style="color:#b91c1c;font-weight:700">Due {{ currency() }} {{ number_format($balance, 2) }}</span>@if($clinicBill)<span class="oo-sub">on clinic bill</span>@endif
                                @elseif($clinicBill && $clinicBill['status'] === 'none')<span class="oo-sub">Not on clinic bill</span>
                                @else<span class="oo-sub" style="color:#047857;font-weight:700">Paid</span>@endif
                            </td>
                            <td style="white-space:nowrap">{{ $pickup?->format('d M Y') ?? '—' }}@if($late)<span class="oo-sub" style="color:#b91c1c;font-weight:700">Late</span>@elseif($isReady && $ord->ready_at)<span class="oo-sub">Waiting {{ (int) $ord->ready_at->diffInDays(now()) }}d</span>@endif</td>
                            <td>
                                <span class="oo-badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                @if($awaiting)<span class="oo-sub" style="color:#92400e;font-weight:700">Awaiting {{ $awaiting }} lens</span>@endif
                            </td>
                            <td onclick="event.stopPropagation()">
                                <div class="oo-row-actions">
                                    @php $whatsApp = $isReady ? $notifier->whatsAppLink($ord, $ord->ready_notified_at ? \App\Services\OpticalCollectionNotifier::REMINDER : \App\Services\OpticalCollectionNotifier::READY) : null; @endphp
                                    @if($whatsApp)
                                        <a href="{{ $whatsApp }}" target="_blank" rel="noopener" wire:click="recordPanelWhatsApp({{ $ord->id }}, '{{ $ord->ready_notified_at ? \App\Services\OpticalCollectionNotifier::REMINDER : \App\Services\OpticalCollectionNotifier::READY }}')" title="Tell the customer on WhatsApp" aria-label="WhatsApp the customer" class="oo-btn wa wa-icon"><i class="fab fa-whatsapp" aria-hidden="true"></i></a>
                                    @else<span class="oo-slot"></span>@endif
                                    @if($blocked && $canTakePayment)
                                        <button type="button" x-on:click="openLocal($wire, { paymentOrderId: {{ $ord->id }}, paymentAmount: '{{ number_format(max(0, $ord->total - (float) $ord->paid_amount), 2, '.', '') }}', showPaymentModal: true }, $root.querySelector('[data-payment-form]'))" class="oo-btn warn">Take payment</button>
                                    @elseif($blocked)
                                        <span class="oo-btn warn" style="cursor:default" title="This clinic order is paid on the patient's clinic bill. Take the payment at reception or the cashier.">Pay at clinic</span>
                                    @elseif($step && ! $awaiting)
                                        <button type="button" wire:click="{{ $step[1] }}" @if($step[2]) wire:confirm="{{ $step[2] }}" @endif class="oo-btn primary">{{ $step[0] }}</button>
                                    @else<span class="oo-slot"></span>@endif
                                    <button type="button" wire:click="openOrder({{ $ord->id }})" class="oo-btn">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">No orders match these filters.</p>@if($narrowed || $statusFilter)<button type="button" class="oo-btn" wire:click="clearFilters">Clear filters</button>@endif</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $orders->links() }}</div>
    </div>

    @include('livewire.optical.partials.order-panel')
</div>
