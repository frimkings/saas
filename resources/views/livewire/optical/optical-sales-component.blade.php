<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .sr-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .sr-tile{background:#fff;border:1px solid var(--clinic-line);border-radius:12px;padding:12px 14px}
        .sr-tile span{display:block;font-size:11px;font-weight:700;color:var(--clinic-muted);text-transform:uppercase;letter-spacing:.3px}
        .sr-tile b{display:block;font-size:22px;margin-top:4px;font-variant-numeric:tabular-nums}
        .sr-table col.c-date{width:130px}.sr-table col.c-rcpt{width:170px}.sr-table col.c-cust{width:190px}.sr-table col.c-items{width:auto}.sr-table col.c-amt{width:130px}.sr-table col.c-status{width:130px}.sr-table col.c-act{width:170px}
        .sr-field{display:flex;flex-direction:column;gap:4px}.sr-field>span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.sr-field .ui-input{padding:8px 10px}
        .sr-check{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--clinic-line);border-radius:8px;margin-top:6px;cursor:pointer}
        @media(max-width:900px){.sr-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
    </style>
    @php
        $money = fn ($v) => currency().' '.number_format((float) $v, 2);
        $statusOf = function ($sale) {
            if ($sale->is_refunded) return ['Refunded', 'oo-b-red'];
            if ($sale->pendingRefundLog) return ['Refund pending', 'oo-b-amber'];
            if ($sale->payment_status && $sale->payment_status !== 'paid' && (float) $sale->total_amount > (float) $sale->amount_paid) return ['Part paid', 'oo-b-amber'];
            return ['Paid', 'oo-b-green'];
        };
    @endphp

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Sales Records</h1>
            <p class="ui-muted text-sm">Optical orders and retail purchases for this branch.</p>
        </div>
        <button type="button" wire:click="exportCSV" class="oo-btn" style="padding:9px 14px">⇩ Export CSV</button>
    </div>

    <x-ui.flash />

    <div class="sr-tiles">
        <div class="sr-tile"><span>Sales</span><b>{{ $money($totalSales) }}</b></div>
        <div class="sr-tile"><span>Receipts</span><b>{{ $totalReceipts }}</b></div>
        <div class="sr-tile"><span>Refunded</span><b>{{ $refundCount }}</b></div>
        <div class="sr-tile"><span>Refund amount</span><b style="color:{{ $refundTotal > 0 ? '#b91c1c' : 'inherit' }}">{{ $money($refundTotal) }}</b></div>
    </div>

    <section class="ui-panel bg-white">
        <div class="oo-filters" style="border-bottom:1px solid var(--clinic-line)">
            <label class="oo-f" style="width:auto;flex:1;min-width:220px"><span>Search</span><input autocomplete="off" type="search" wire:model.live.debounce.300ms="searchTerm" placeholder="Receipt number, customer or patient…" class="ui-input text-sm"></label>
            <div class="oo-f" style="width:auto"><span>Dates</span><x-date-range from="fromDate" to="toDate" presets="finance" /></div>
            <label class="oo-f"><span>Status</span><select wire:model.live="filterRefunded" class="ui-input text-sm"><option value="">All</option><option value="0">Not refunded</option><option value="1">Refunded</option></select></label>
            <div class="oo-presets"><span class="ui-muted" wire:loading.delay wire:target="searchTerm,fromDate,toDate,filterRefunded,resetFilters">Updating…</span></div>
        </div>
        <div class="overflow-x-auto">
            <table class="oo-table sr-table w-full" style="min-width:1000px">
                <colgroup><col class="c-date"><col class="c-rcpt"><col class="c-cust"><col class="c-items"><col class="c-amt"><col class="c-status"><col class="c-act"></colgroup>
                <thead><tr><th>Date</th><th>Receipt</th><th>Customer</th><th>Items</th><th class="oo-num">Amount</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
                <tbody>
                    @forelse($sales as $sale)
                        @php [$sl, $sc] = $statusOf($sale); $count = $sale->items->count(); @endphp
                        <tr wire:key="sale-{{ $sale->id }}" :class="$wire.panelSaleId === {{ $sale->id }} && 'oo-active'" x-on:click="$wire.$set('panelSaleId', {{ $sale->id }}, false)">
                            <td>{{ $sale->created_at->format('d M Y') }}<span class="oo-sub">{{ $sale->created_at->format('H:i') }}</span></td>
                            <td><span class="oo-id">{{ $sale->transaction_id }}</span>@if($sale->user)<span class="oo-sub">by {{ $sale->user->name }}</span>@endif</td>
                            <td><b>{{ $sale->customer_display_name }}</b></td>
                            <td><span style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $sale->items->map(fn ($i) => $i->display_product_name.($i->dispensed_quantity > 1 ? ' × '.$i->dispensed_quantity : ''))->join(', ') ?: '—' }}</span>@if($count > 2)<span class="oo-sub">{{ $count }} items</span>@endif</td>
                            <td class="oo-num"><b>{{ $money($sale->total_amount) }}</b></td>
                            <td><span class="oo-badge {{ $sc }}">{{ $sl }}</span></td>
                            <td onclick="event.stopPropagation()">
                                <div class="oo-row-actions" style="grid-template-columns:80px 64px">
                                    <a class="oo-btn" href="{{ route('optical.receipt', $sale->id) }}" target="_blank">Receipt</a>
                                    <button type="button" class="oo-btn" x-on:click="$wire.$set('panelSaleId', {{ $sale->id }}, false)">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">No optical sales in this period.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $sales->links() }}</div>
    </section>

    {{-- SALE PANELS: one per listed sale, drawn hidden; View shows it in the browser, with no call. --}}
    @foreach($drawerSales as $panelSale)
        @php
            $panelOrders = $saleOrders[$panelSale->id] ?? collect();
            [$sl, $sc] = $statusOf($panelSale->loadMissing('pendingRefundLog'));
            $paid = (float) $panelSale->amount_paid;
            $balance = max(0, (float) $panelSale->total_amount - $paid);
            $user = auth()->user();
            // Order sales are refunded from the order (Refund & cancel), which also returns stock and cancels the job.
            $canRefund = $panelOrders->isEmpty() && ! $panelSale->is_refunded && ! $panelSale->pendingRefundLog && $panelSale->items->isNotEmpty()
                && ($user?->hasAnyRole(['Manager', 'Super Admin']) || ($user?->hasRole('Cashier') && $panelSale->user_id === $user->id));
        @endphp
        <div data-sheet data-keep data-sale-drawer="{{ $panelSale->id }}" wire:key="sale-drawer-{{ $panelSale->id }}" x-show="$wire.panelSaleId === {{ $panelSale->id }}" x-cloak>
        <div class="oo-overlay" x-on:click="dismissLocal($el, $wire, { panelSaleId: null })" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="sr-title-{{ $panelSale->id }}" tabindex="-1" x-effect="$wire.panelSaleId === {{ $panelSale->id }} && $nextTick(() => $el.focus())" x-on:keydown.escape.window="$wire.panelSaleId === {{ $panelSale->id }} && ! $event.target.closest('.app-confirm, dialog, .swal2-container') && ! document.getElementById('sr-refund-title') && dismissLocal($el, $wire, { panelSaleId: null })">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="sr-title-{{ $panelSale->id }}"><span class="oo-id" style="font-size:17px">{{ $panelSale->transaction_id }}</span></h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-badge {{ $sc }}">{{ $sl }}</span>
                        <span><b class="text-slate-800">{{ $panelSale->customer_display_name }}</b>@if($panelSale->patient?->contact) · {{ $panelSale->patient->contact }}@endif</span>
                        <span>· {{ $panelSale->created_at->format('d M Y, H:i') }}@if($panelSale->user) by {{ $panelSale->user->name }}@endif</span>
                    </div>
                </div>
                <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { panelSaleId: null })" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                <div class="oo-money">
                    <div><span>Total</span><b>{{ $money($panelSale->total_amount) }}</b></div>
                    <div><span>Paid</span><b style="color:#047857">{{ $money($paid) }}</b></div>
                    <div><span>Balance</span><b style="color:{{ $balance > 0 ? '#b91c1c' : 'inherit' }}">{{ $money($balance) }}</b></div>
                </div>
                <section class="oo-section">
                    <h3>Items</h3>
                    <table class="oo-lines">
                        <thead><tr><th>Item</th><th style="width:60px">Qty</th><th class="oo-num" style="width:110px">Price</th><th class="oo-num" style="width:110px">Subtotal</th></tr></thead>
                        <tbody>
                            @foreach($panelSale->items as $item)
                                <tr><td>{{ $item->display_product_name }}@if(($item->refunded_quantity ?? 0) > 0)<span class="oo-sub" style="color:#b91c1c">{{ $item->refunded_quantity }} refunded</span>@endif</td><td>{{ $item->dispensed_quantity }}</td><td class="oo-num">{{ number_format((float) $item->selling_price, 2) }}</td><td class="oo-num">{{ number_format((float) $item->subtotal, 2) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
                <section class="oo-section">
                    <h3>Payments</h3>
                    @forelse($panelSale->paymentTransactions as $payment)
                        <div class="flex justify-between gap-2" style="padding:6px 0;border-bottom:1px solid var(--clinic-line)">
                            <span>{{ ucwords(str_replace('_', ' ', $payment->payment_method)) }}<span class="oo-sub">{{ $payment->created_at?->format('d M Y H:i') }}@if($payment->notes) · {{ $payment->notes }}@endif</span></span>
                            <b style="font-variant-numeric:tabular-nums">{{ $money($payment->amount) }}</b>
                        </div>
                    @empty
                        <p class="ui-muted text-sm" style="margin:0">No separate payment records; paid at checkout.</p>
                    @endforelse
                </section>
                @if($panelOrders->isNotEmpty())
                    <section class="oo-section">
                        <h3>Optical orders on this sale</h3>
                        @if(! $panelSale->is_refunded)<p class="ui-muted text-xs" style="margin:0 0 6px">To refund this sale, open the order and use Refund &amp; cancel, so stock and the lab job are handled too.</p>@endif
                        @foreach($panelOrders as $order)
                            @php [$bl, $bc] = \App\Support\Optical\OrderPresenter::badge($order->status); @endphp
                            <div class="flex items-center justify-between gap-2" style="padding:6px 0;border-bottom:1px solid var(--clinic-line)">
                                <span class="oo-id">{{ $order->order_id }}</span>
                                <span class="flex items-center gap-2"><span class="oo-badge {{ $bc }}">{{ $bl }}</span><a class="oo-link" href="{{ route('optical.orders', ['searchTerm' => $order->order_id]) }}">Open</a></span>
                            </div>
                        @endforeach
                    </section>
                @endif
                @if($panelSale->refundLogs->isNotEmpty())
                    <section class="oo-section">
                        <h3>Refund requests</h3>
                        @foreach($panelSale->refundLogs as $log)
                            <div style="padding:6px 0;border-bottom:1px solid var(--clinic-line)">
                                <span class="oo-badge {{ $log->status === 'rejected' ? 'oo-b-red' : ($log->status === 'processed' ? 'oo-b-green' : 'oo-b-amber') }}">{{ ucfirst($log->status) }}</span>
                                <span class="text-sm">{{ ucfirst($log->request_type ?? 'refund') }}@if((float) ($log->refunded_amount ?? 0) > 0) · {{ $money($log->refunded_amount) }}@endif</span>
                                <span class="oo-sub">{{ \App\Models\RefundLog::REASON_CODES[$log->reason_code] ?? '' }}{{ $log->reason ? ' · '.$log->reason : '' }}</span>
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>
            <footer class="oo-drawer-foot">
                @if($canRefund)<button type="button" class="oo-btn danger" wire:click="initiateRefund({{ $panelSale->id }})">Request refund</button>@endif
                <a class="oo-btn primary" href="{{ route('optical.receipt', $panelSale->id) }}" target="_blank">Print receipt</a>
            </footer>
        </aside>
        </div>
    @endforeach

    {{-- REFUND REQUEST PANEL --}}
    @if($initiatingRefundSale)
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissCall($el.nextElementSibling, $wire, 'dismissRefundInitiation')" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="sr-refund-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissCall($el, $wire, 'dismissRefundInitiation')">
            <header class="oo-drawer-head">
                <div><h2 id="sr-refund-title">Request a refund</h2><p class="ui-muted text-xs" style="margin:0">{{ $initiatingRefundSale->transaction_id }} · a manager approves it before money is returned.</p></div>
                <button type="button" class="oo-x" x-on:click="dismissCall($el, $wire, 'dismissRefundInitiation')" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                @if($errors->any())<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
                <section class="oo-section">
                    <h3>Items to refund</h3>
                    @foreach($initiatingRefundSale->items as $item)
                        @php $left = $item->dispensed_quantity - ($item->refunded_quantity ?? 0); @endphp
                        <label class="sr-check" style="{{ $left <= 0 ? 'opacity:.5;cursor:not-allowed' : '' }}"><input type="checkbox" wire:model="initiateRefundItemIds" value="{{ $item->id }}" @disabled($left <= 0)> <span style="flex:1">{{ $item->display_product_name }}</span><span class="ui-muted text-xs">{{ $left <= 0 ? 'Already refunded' : number_format((float) $item->subtotal, 2) }}</span></label>
                    @endforeach
                </section>
                <section class="oo-section" style="display:grid;gap:12px">
                    <label class="sr-field"><span>Request type</span><select wire:model="initiateRefundType" class="ui-input"><option value="refund">Refund: return money for these items</option><option value="void">Void: the sale was a mistake</option></select></label>
                    <label class="sr-field"><span>Reason *</span><select wire:model="initiateRefundReasonCode" class="ui-input"><option value="">Choose a reason</option>@foreach(\App\Models\RefundLog::REASON_CODES as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="sr-field"><span>Details *</span><textarea wire:model="initiateRefundReason" rows="3" class="ui-input" placeholder="What happened and what was agreed with the customer (at least 10 characters)"></textarea></label>
                </section>
            </div>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" x-on:click="dismissCall($el, $wire, 'dismissRefundInitiation')">Cancel</button>
                <button type="button" class="oo-btn primary" wire:click="submitRefundRequest" wire:loading.attr="disabled" wire:target="submitRefundRequest">Send for approval</button>
            </footer>
        </aside>
    @endif
</div>
