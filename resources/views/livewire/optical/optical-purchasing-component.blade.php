<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .pu-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
        .pu-tile{background:#fff;border:1px solid var(--clinic-line);border-radius:12px;padding:12px 14px}
        .pu-tile span{display:block;font-size:11px;font-weight:700;color:var(--clinic-muted);text-transform:uppercase;letter-spacing:.3px}
        .pu-tile b{display:block;font-size:22px;margin-top:4px;font-variant-numeric:tabular-nums}
        .pu-tabs{display:flex;gap:4px;border-bottom:1px solid var(--clinic-line);overflow-x:auto}
        .pu-tabs button{background:none;border:0;border-bottom:2px solid transparent;padding:10px 14px;font-weight:700;font-size:13px;color:var(--clinic-muted);cursor:pointer;white-space:nowrap;display:flex;gap:6px;align-items:center}
        .pu-tabs button.active{color:var(--clinic-accent);border-bottom-color:var(--clinic-accent)}
        .pu-field{display:flex;flex-direction:column;gap:4px;min-width:0}.pu-field>span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.pu-field .ui-input{padding:8px 10px}
        .pu-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.pu-2 .full{grid-column:1/-1}
        .pu-lines input{width:100%;box-sizing:border-box;border:1px solid var(--clinic-line);border-radius:7px;padding:6px 8px;text-align:right;font:inherit}
        .pu-pick{position:relative}.pu-pick-list{position:absolute;z-index:5;left:0;right:0;margin-top:4px;background:#fff;border:1px solid var(--clinic-line);border-radius:9px;box-shadow:0 10px 24px rgb(15 23 42 / .12);max-height:260px;overflow-y:auto}
        .pu-pick-list button{display:flex;width:100%;justify-content:space-between;gap:10px;padding:8px 10px;border:0;border-bottom:1px solid var(--clinic-line);background:#fff;text-align:left;cursor:pointer;font:inherit;font-size:12.5px}.pu-pick-list button:hover{background:#f0f9f8}
        .pu-hint{color:var(--clinic-muted);font-size:11px}
        @media(max-width:700px){.pu-tiles,.pu-2{grid-template-columns:1fr}}
    </style>
    @php
        $statusClass = ['draft' => 'oo-b-grey', 'ordered' => 'oo-b-teal', 'partially_received' => 'oo-b-amber', 'received' => 'oo-b-green', 'cancelled' => 'oo-b-red'];
        $activeSuppliers = $suppliers->where('is_active', true);
    @endphp

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Purchasing</h1>
            <p class="ui-muted text-sm">Order stock and special-order lenses from suppliers, receive deliveries, and send back damaged or wrong items.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" x-on:click="openLocal($wire, { returnSupplierId: null, returnPoId: null, returnReason: '', returnNotes: '', returnLines: [], returnSearch: '', showOrderForm: false, showReturnForm: true, tab: 'returns' }, $root.querySelector('[data-return-form]'))" class="oo-btn">Return to supplier</button>
            <button type="button" x-on:click="openLocal($wire, { supplierId: null, expectedDate: '', orderNotes: '', draftLines: [], productSearch: '', showReturnForm: false, showOrderForm: true, tab: 'orders' }, $root.querySelector('[data-order-form]'))" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ New supplier order</button>
        </div>
    </div>

    <x-ui.flash />
    @if($errors->any() && ! $viewOrder && ! $showOrderForm && ! $showReturnForm && ! $showSupplierForm)<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif

    <div class="pu-tiles">
        <div class="pu-tile"><span>Orders awaiting delivery</span><b>{{ $openCount }}</b></div>
        <div class="pu-tile"><span>Special-order lenses to order</span><b style="color:{{ $backlogCount ? '#92400e' : 'inherit' }}">{{ $backlogCount }}</b></div>
        <div class="pu-tile"><span>Supplier credit outstanding</span><b>{{ currency() }} {{ number_format((float) $pendingCredits, 2) }}</b></div>
    </div>

    <nav class="pu-tabs" role="tablist" aria-label="Purchasing sections">
        @foreach(['orders' => 'Supplier orders', 'special' => 'Special-order lenses', 'returns' => 'Returns', 'suppliers' => 'Suppliers'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" class="{{ $tab === $key ? 'active' : '' }}">{{ $label }}@if($key === 'special' && $backlogCount)<span class="oo-badge oo-b-amber">{{ $backlogCount }}</span>@endif</button>
        @endforeach
    </nav>

    {{-- ── Supplier orders ─────────────────────────────────────────── --}}
    @if($tab === 'orders')
        <section class="ui-panel bg-white">
            <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by status">
                @foreach(['open' => 'Open'] + \App\Models\OpticalPurchaseOrder::STATUSES + ['all' => 'All'] as $key => $label)
                    <button type="button" wire:click="$set('statusFilter', '{{ $key }}')" class="oo-chip {{ $statusFilter === $key ? 'active' : '' }}" aria-pressed="{{ $statusFilter === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <div class="overflow-x-auto">
                <table class="oo-table w-full" style="min-width:820px">
                    <thead><tr><th>Order</th><th>Supplier</th><th>Status</th><th>Expected</th><th class="oo-num">Received</th><th class="oo-num">Value</th><th style="text-align:right">Actions</th></tr></thead>
                    <tbody>
                        @forelse($orders as $order)
                            @php $late = $order->isOpen() && $order->expected_date && $order->expected_date->isPast(); @endphp
                            <tr wire:key="po-{{ $order->id }}" class="{{ $viewOrderId === $order->id ? 'oo-active' : '' }}" wire:click="viewOrder({{ $order->id }})">
                                <td><span class="oo-id">{{ $order->po_number }}</span><span class="oo-sub">{{ $order->created_at?->format('d M Y') }}</span></td>
                                <td><b>{{ $order->supplier?->name }}</b></td>
                                <td><span class="oo-badge {{ $statusClass[$order->status] ?? 'oo-b-grey' }}">{{ \App\Models\OpticalPurchaseOrder::STATUSES[$order->status] }}</span></td>
                                <td>{{ $order->expected_date?->format('d M Y') ?? '—' }}@if($late)<span class="oo-sub" style="color:#b91c1c;font-weight:700">Late</span>@endif</td>
                                <td class="oo-num">{{ $order->lines->sum('quantity_received') }} / {{ $order->lines->sum('quantity_ordered') }}</td>
                                <td class="oo-num"><b>{{ currency() }} {{ number_format($order->totalCost(), 2) }}</b></td>
                                <td style="text-align:right" onclick="event.stopPropagation()"><button type="button" wire:click="viewOrder({{ $order->id }})" class="oo-btn {{ $order->isOpen() && $order->status !== 'draft' ? 'primary' : '' }}">{{ $order->status === 'draft' ? 'Open draft' : ($order->isOpen() ? 'Receive' : 'View') }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">No supplier orders here.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-t border-slate-200">{{ $orders->links() }}</div>
        </section>
    @endif

    {{-- ── Special-order lenses ─────────────────────────────────────── --}}
    @if($tab === 'special')
        <section class="ui-panel bg-white" aria-label="Special-order lenses to order">
            <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><p class="ui-muted text-xs" style="margin:0">Customer jobs whose lenses must be bought in and aren't on a supplier order yet. When the order is received, the lenses are marked received on the job, ready for glazing.</p></div>
            @if($backlog->isEmpty())
                <p class="ui-empty ui-muted text-sm">Nothing to order. Every special-order lens is on a supplier order.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="oo-table w-full" style="min-width:720px">
                        <thead><tr><th style="width:40px"></th><th>Lens</th><th>Job</th><th class="oo-num" style="width:140px">Unit cost ({{ currency() }})</th></tr></thead>
                        <tbody>
                            @foreach($backlog as $row)
                                @php $key = $row['order']->id.'|'.($row['eye'] ?? ''); @endphp
                                <tr wire:key="backlog-{{ $key }}" style="cursor:default">
                                    <td><input type="checkbox" wire:model="backlogSelected" value="{{ $key }}" aria-label="Order this lens"></td>
                                    <td><b>{{ $row['description'] }}</b><span class="oo-sub">{{ $row['eye'] ? 'One lens' : 'Pair (2 lenses)' }}</span></td>
                                    <td><span class="oo-id">{{ $row['order']->order_id }}</span><span class="oo-sub">{{ $row['order']->status }} · since {{ $row['order']->created_at->format('d M') }}</span></td>
                                    <td class="pu-lines"><input autocomplete="off" type="number" min="0" step="0.01" wire:model="backlogCosts.{{ $key }}" aria-label="Unit cost"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="oo-filters">
                    <label class="oo-f" style="width:240px"><span>Supplier *</span><select wire:model="backlogSupplierId" class="ui-input text-sm"><option value="">Choose a supplier</option>@foreach($activeSuppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
                    <button type="button" wire:click="orderBacklog" class="oo-btn primary" style="margin-bottom:2px">Create supplier order for ticked lenses</button>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Returns ──────────────────────────────────────────────────── --}}
    @if($tab === 'returns')
        <section class="ui-panel bg-white">
            <div class="overflow-x-auto">
                <table class="oo-table w-full" style="min-width:900px">
                    <thead><tr><th>Return</th><th>Supplier</th><th>Items</th><th>Reason</th><th class="oo-num">Credit</th><th style="width:300px">Settlement</th></tr></thead>
                    <tbody>
                        @forelse($returns as $return)
                            <tr wire:key="return-{{ $return->id }}" style="cursor:default">
                                <td><span class="oo-id">{{ $return->return_number }}</span><span class="oo-sub">{{ $return->created_at->format('d M Y') }}@if($return->purchaseOrder) · {{ $return->purchaseOrder->po_number }}@endif</span></td>
                                <td><b>{{ $return->supplier?->name }}</b></td>
                                <td>{{ $return->lines->map(fn ($l) => $l->quantity.' × '.$l->product?->name)->implode(', ') }}</td>
                                <td>{{ \App\Models\OpticalSupplierReturn::REASONS[$return->reason] ?? $return->reason }}</td>
                                <td class="oo-num"><b>{{ currency() }} {{ number_format((float) $return->credit_expected, 2) }}</b></td>
                                <td>
                                    @if($settleId === $return->id)
                                        <form wire:submit.prevent="settleReturn" class="flex flex-wrap items-center gap-2">
                                            <select wire:model="settleStatus" class="ui-input text-xs" style="width:auto;padding:6px" aria-label="Settlement">@foreach(array_slice(\App\Models\OpticalSupplierReturn::SETTLEMENTS, 1, null, true) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                                            <input autocomplete="off" type="text" wire:model="settleReference" maxlength="100" placeholder="Credit note ref" class="ui-input text-xs" style="width:120px;padding:6px" aria-label="Credit note reference">
                                            <button type="submit" class="oo-btn primary">Save</button>
                                            <button type="button" wire:click="cancelSettle" class="oo-link">Cancel</button>
                                        </form>
                                    @elseif($return->credit_status === 'pending')
                                        <span class="oo-badge oo-b-amber">Awaiting credit</span> <button type="button" wire:click="openSettle({{ $return->id }})" class="oo-btn" style="margin-left:6px">Settle</button>
                                    @else
                                        <span class="oo-badge oo-b-green">{{ \App\Models\OpticalSupplierReturn::SETTLEMENTS[$return->credit_status] }}</span>@if($return->credit_reference)<span class="oo-sub">{{ $return->credit_reference }}</span>@endif
                                        @if($return->credit_status === 'replaced')<span class="oo-sub">Receive the replacements on a supplier order.</span>@endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">No returns recorded.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(method_exists($returns, 'links'))<div class="p-3 border-t border-slate-200">{{ $returns->links() }}</div>@endif
        </section>
    @endif

    {{-- ── Suppliers ───────────────────────────────────────────────── --}}
    @if($tab === 'suppliers')
        <section class="ui-panel bg-white">
            <div class="flex justify-end" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)"><button type="button" wire:click="openSupplierForm" class="oo-btn primary">+ Add supplier</button></div>
            <div class="overflow-x-auto">
                <table class="oo-table w-full" style="min-width:720px">
                    <thead><tr><th>Supplier</th><th>Contact</th><th>Lead time</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
                    <tbody>
                        @forelse($suppliers as $supplier)
                            <tr wire:key="supplier-{{ $supplier->id }}" wire:click="openSupplierForm({{ $supplier->id }})">
                                <td><b>{{ $supplier->name }}</b></td>
                                <td>{{ $supplier->contact_person ?: '—' }}<span class="oo-sub">{{ trim($supplier->phone.' '.$supplier->email) ?: 'No contact details' }}</span></td>
                                <td>{{ $supplier->lead_time_days !== null ? $supplier->lead_time_days.' days' : '—' }}</td>
                                <td><span class="oo-badge {{ $supplier->is_active ? 'oo-b-green' : 'oo-b-grey' }}">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td style="text-align:right" onclick="event.stopPropagation()"><button type="button" wire:click="openSupplierForm({{ $supplier->id }})" class="oo-btn">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ui-empty"><p class="ui-muted text-sm">No suppliers yet.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Supplier order panel (view / receive) ─────────────────────── --}}
    @if($viewOrder)
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissCall($el.nextElementSibling, $wire, 'closeOrder')" aria-hidden="true"></div>
        <aside class="oo-drawer" style="width:min(720px,100vw)" role="dialog" aria-modal="true" aria-labelledby="po-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissCall($el, $wire, 'closeOrder')" wire:key="po-drawer-{{ $viewOrder->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="po-title"><span class="oo-id" style="font-size:17px">{{ $viewOrder->po_number }}</span></h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-badge {{ $statusClass[$viewOrder->status] ?? 'oo-b-grey' }}">{{ \App\Models\OpticalPurchaseOrder::STATUSES[$viewOrder->status] }}</span>
                        <span><b class="text-slate-800">{{ $viewOrder->supplier?->name }}</b></span>
                        @if($viewOrder->ordered_at)<span>· ordered {{ $viewOrder->ordered_at->format('d M Y') }}</span>@endif
                        @if($viewOrder->expected_date)<span style="{{ $viewOrder->isOpen() && $viewOrder->expected_date->isPast() ? 'color:#b91c1c;font-weight:700' : '' }}">· expected {{ $viewOrder->expected_date->format('d M Y') }}</span>@endif
                    </div>
                </div>
                <button type="button" class="oo-x" x-on:click="dismissCall($el, $wire, 'closeOrder')" aria-label="Close">&times;</button>
            </header>
            <form wire:submit.prevent="receiveOrder" id="po-receive" class="oo-drawer-body">
                @if($errors->any())<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
                <div class="oo-money">
                    <div><span>Order value</span><b>{{ currency() }} {{ number_format($viewOrder->totalCost(), 2) }}</b></div>
                    <div><span>Items received</span><b>{{ $viewOrder->lines->sum('quantity_received') }} / {{ $viewOrder->lines->sum('quantity_ordered') }}</b></div>
                    <div><span>Created by</span><b style="font-size:13px">{{ $viewOrder->creator?->name ?? '—' }}</b></div>
                </div>
                @if($viewOrder->notes)<div class="oo-note amber" style="white-space:pre-line">{{ $viewOrder->notes }}</div>@endif
                <section class="oo-section">
                    <h3>Items</h3>
                    <table class="oo-lines pu-lines">
                        <thead><tr><th>Item</th><th style="width:70px" class="oo-num">Ordered</th><th style="width:75px" class="oo-num">Received</th><th style="width:90px" class="oo-num">Unit cost</th>@if($viewOrder->isOpen())<th style="width:100px" class="oo-num">Receive now</th>@endif</tr></thead>
                        <tbody>
                            @foreach($viewOrder->lines as $line)
                                <tr wire:key="po-line-{{ $line->id }}">
                                    <td>{{ $line->description }}@if($line->isSpecialOrder())<span class="oo-sub" style="color:#92400e;font-weight:700">For job {{ $line->lensOrder?->order_id }}: goes to the job, not stock</span>@endif</td>
                                    <td class="oo-num">{{ $line->quantityLabel() }}</td>
                                    <td class="oo-num" style="color:{{ $line->outstanding() ? '#92400e' : '#047857' }};font-weight:700">{{ $line->quantityLabel($line->quantity_received) }}</td>
                                    <td class="oo-num">{{ number_format((float) $line->unit_cost, 2) }}</td>
                                    @if($viewOrder->isOpen())<td>@if($line->outstanding())<input autocomplete="off" type="number" min="0" max="{{ $line->outstanding() }}" wire:model="receiveQty.{{ $line->id }}" aria-label="Receive now for {{ $line->description }}">@if($line->product?->lens_specs)<small class="pu-hint">lenses</small>@endif @else<span class="ui-muted">Done</span>@endif</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
                @if($viewOrder->isOpen() && $viewOrder->status !== 'draft')
                    <section class="oo-section">
                        <h3>Record a delivery</h3>
                        <div class="pu-2">
                            <label class="pu-field"><span>Supplier invoice / delivery note</span><input autocomplete="off" type="text" wire:model="invoiceReference" maxlength="100" class="ui-input"></label>
                            <label class="pu-field"><span>Batch / lot</span><input autocomplete="off" type="text" wire:model="batchNumber" maxlength="100" class="ui-input"></label>
                        </div>
                        <p class="pu-hint" style="margin:6px 0 0">Enter what arrived in "Receive now", then Receive delivery. Stock items are added to this branch.</p>
                    </section>
                @endif
                @if(in_array($viewOrder->status, ['draft', 'ordered', 'partially_received'], true))
                    <section class="oo-section" style="border-top:1px solid var(--clinic-line);padding-top:14px">
                        @if(in_array($viewOrder->status, ['draft', 'ordered'], true))<button type="button" wire:click="cancelOrder({{ $viewOrder->id }})" wire:confirm="Cancel {{ $viewOrder->po_number }}?" class="oo-btn danger">Cancel order</button>@endif
                        @if($viewOrder->status === 'partially_received')<button type="button" wire:click="cancelOrder({{ $viewOrder->id }})" wire:confirm="Close {{ $viewOrder->po_number }}? Items not yet delivered will be dropped." class="oo-btn danger">Close short</button>@endif
                    </section>
                @endif
            </form>
            <footer class="oo-drawer-foot">
                <a href="{{ route('optical.purchasing.print', $viewOrder->id) }}" target="_blank" class="oo-btn">Print</a>
                @if($supplierWhatsApp && $viewOrder->status !== 'cancelled')<a href="{{ $supplierWhatsApp }}" target="_blank" rel="noopener" class="oo-btn wa"><i class="fab fa-whatsapp" aria-hidden="true"></i> Send to supplier</a>@endif
                @if(in_array($viewOrder->status, ['partially_received', 'received'], true))<button type="button" wire:click="openReturnForm({{ $viewOrder->id }})" class="oo-btn">Return items</button>@endif
                @if($viewOrder->status === 'draft')<button type="button" wire:click="placeOrder({{ $viewOrder->id }})" class="oo-btn primary">Place order</button>@endif
                @if($viewOrder->isOpen() && $viewOrder->status !== 'draft')<button type="submit" form="po-receive" wire:confirm="Record this delivery? Stock items are added to this branch." class="oo-btn primary">Receive delivery</button>@endif
            </footer>
        </aside>
    @endif

    {{-- ── New supplier order panel ─────────────────────────────────── --}}
    {{-- Always in the page: the header button opens it in the browser (openLocal); only saving calls the server. --}}
    <div data-sheet data-keep data-order-form x-show="$wire.showOrderForm" x-cloak>
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showOrderForm: false })" aria-hidden="true"></div>
        <aside class="oo-drawer" style="width:min(720px,100vw)" role="dialog" aria-modal="true" aria-labelledby="po-new-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.showOrderForm && dismissLocal($el, $wire, { showOrderForm: false })">
            <header class="oo-drawer-head"><h2 id="po-new-title">New supplier order</h2><button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { showOrderForm: false })" aria-label="Close">&times;</button></header>
            <div class="oo-drawer-body">
                @if($errors->any())<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
                <div class="pu-2">
                    <label class="pu-field"><span>Supplier *</span><select wire:model="supplierId" class="ui-input"><option value="">Choose a supplier</option>@foreach($activeSuppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
                    <label class="pu-field"><span>Expected delivery</span><input autocomplete="off" type="date" wire:model="expectedDate" class="ui-input"><small class="pu-hint">Leave blank to use the supplier's lead time.</small></label>
                    <label class="pu-field full"><span>Notes</span><input autocomplete="off" type="text" wire:model="orderNotes" maxlength="2000" class="ui-input"></label>
                </div>
                @if($activeSuppliers->isEmpty())<div class="oo-note amber">No suppliers yet. Add one on the Suppliers tab first.</div>@endif
                <section class="oo-section">
                    <h3>Items</h3>
                    <div class="pu-pick">
                        <input type="search" wire:model.live.debounce.250ms="productSearch" placeholder="Add an item: type an SKU or name (2+ letters)" class="ui-input text-sm" aria-label="Find an item to order" autocomplete="off">
                        @if($productMatches->isNotEmpty())
                            <div class="pu-pick-list" role="listbox">
                                @foreach($productMatches as $product)
                                    <button type="button" role="option" wire:click="addDraftProduct({{ $product->id }})"><span><b>{{ $product->sku }}</b> · {{ $product->name }}</span><span class="pu-hint">{{ (int) $product->stocks->sum('quantity') }} in stock</span></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if($draftLines)
                        <table class="oo-lines pu-lines" style="margin-top:10px">
                            @php $draftLineTotal = fn ($l) => ! empty($l['lens_pairs']) ? (int) $l['pairs'] * (float) $l['pair_cost'] : (int) $l['quantity'] * (float) $l['unit_cost']; @endphp
                            <thead><tr><th>Item</th><th style="width:90px" class="oo-num">Qty</th><th style="width:120px" class="oo-num">Unit cost</th><th style="width:100px" class="oo-num">Line</th><th style="width:40px"></th></tr></thead>
                            <tbody>
                                @foreach($draftLines as $i => $line)
                                    <tr wire:key="draft-{{ ! empty($line['lens_pairs']) ? 'pair-'.($line['key'] ?? implode('-', $line['product_ids'])) : $line['product_id'] }}">
                                        <td>{{ $line['label'] }}</td>
                                        @if(! empty($line['lens_pairs']))
                                            {{-- Lenses are ordered in pairs; saving records the individual lenses. --}}
                                            <td><input autocomplete="off" type="number" min="1" wire:model="draftLines.{{ $i }}.pairs" aria-label="Pairs"><small class="pu-hint">pairs</small></td>
                                            <td><input autocomplete="off" type="number" min="0" step="0.01" wire:model="draftLines.{{ $i }}.pair_cost" aria-label="Cost per pair"><small class="pu-hint">per pair</small></td>
                                        @else
                                            <td><input autocomplete="off" type="number" min="1" wire:model="draftLines.{{ $i }}.quantity" aria-label="Quantity"></td>
                                            <td><input autocomplete="off" type="number" min="0" step="0.01" wire:model="draftLines.{{ $i }}.unit_cost" aria-label="Unit cost"></td>
                                        @endif
                                        <td class="oo-num" x-text="money(draftLineTotal($wire.draftLines[{{ $i }}]))">{{ number_format($draftLineTotal($line), 2) }}</td>
                                        <td><button type="button" wire:click="removeDraftLine({{ $i }})" class="oo-x" style="font-size:16px" aria-label="Remove {{ $line['label'] }}">&times;</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot><tr><th colspan="3" class="oo-num">Total @if(collect($draftLines)->contains('lens_pairs', true))<small class="pu-hint" x-text="'(' + Object.values($wire.draftLines ?? {}).filter(l => l.lens_pairs).reduce((s, l) => s + whole(l.pairs), 0) + ' pairs of lenses)'">({{ collect($draftLines)->where('lens_pairs', true)->sum('pairs') }} pairs of lenses)</small>@endif</th><th class="oo-num">{{ currency() }} <span x-text="money(linesTotal($wire.draftLines, draftLineTotal))">{{ number_format(collect($draftLines)->sum($draftLineTotal), 2) }}</span></th><th></th></tr></tfoot>
                        </table>
                    @else
                        <p class="pu-hint" style="margin:8px 0 0">No items yet. Search above, use "Order from reorder list" on the lens stock grid, or order special-order lenses from their tab.</p>
                    @endif
                </section>
            </div>
            <footer class="oo-drawer-foot">
                <button type="button" x-on:click="dismissLocal($el, $wire, { showOrderForm: false })" class="oo-btn">Cancel</button>
                <button type="button" wire:click="saveDraft(false)" class="oo-btn">Save as draft</button>
                <button type="button" wire:click="saveDraft(true)" class="oo-btn primary">Place order</button>
            </footer>
        </aside>
    </div>

    {{-- ── Return to supplier panel ─────────────────────────────────── --}}
    {{-- Always in the page: the header button opens it in the browser (openLocal); only saving calls the server. --}}
    <div data-sheet data-keep data-return-form x-show="$wire.showReturnForm" x-cloak>
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showReturnForm: false })" aria-hidden="true"></div>
        <aside class="oo-drawer" style="width:min(720px,100vw)" role="dialog" aria-modal="true" aria-labelledby="ret-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.showReturnForm && dismissLocal($el, $wire, { showReturnForm: false })">
            <header class="oo-drawer-head"><div><h2 id="ret-title">Return stock to a supplier</h2><p class="ui-muted text-xs" style="margin:0">The items leave stock now; settle the credit when the supplier confirms it.</p></div><button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { showReturnForm: false })" aria-label="Close">&times;</button></header>
            <div class="oo-drawer-body">
                @if($errors->any())<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
                <div class="pu-2">
                    <label class="pu-field"><span>Supplier *</span><select wire:model.live="returnSupplierId" class="ui-input"><option value="">Choose a supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
                    <label class="pu-field"><span>From supplier order</span><select wire:model="returnPoId" class="ui-input"><option value="">Not linked</option>@foreach($returnOrders as $order)<option value="{{ $order->id }}">{{ $order->po_number }}</option>@endforeach</select></label>
                    <label class="pu-field"><span>Reason *</span><select wire:model="returnReason" class="ui-input"><option value="">Choose a reason</option>@foreach(\App\Models\OpticalSupplierReturn::REASONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="pu-field"><span>Notes</span><input autocomplete="off" type="text" wire:model="returnNotes" maxlength="2000" class="ui-input" placeholder="e.g. Scratched coating on 2 lenses"></label>
                </div>
                <section class="oo-section">
                    <h3>Items</h3>
                    <div class="pu-pick">
                        <input type="search" wire:model.live.debounce.250ms="returnSearch" placeholder="Add an item: type an SKU or name" class="ui-input text-sm" aria-label="Find an item to return" autocomplete="off">
                        @if($returnMatches->isNotEmpty())
                            <div class="pu-pick-list" role="listbox">
                                @foreach($returnMatches as $product)
                                    <button type="button" role="option" wire:click="addReturnProduct({{ $product->id }})"><span><b>{{ $product->sku }}</b> · {{ $product->name }}</span><span class="pu-hint">{{ (int) $product->stocks->sum('quantity') }} in stock</span></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if($returnLines)
                        <table class="oo-lines pu-lines" style="margin-top:10px">
                            <thead><tr><th>Item</th><th style="width:80px" class="oo-num">Qty</th><th style="width:110px" class="oo-num">Unit cost</th><th style="width:40px"></th></tr></thead>
                            <tbody>
                                @foreach($returnLines as $i => $line)
                                    <tr wire:key="return-line-{{ $i }}-{{ $line['product_id'] }}">
                                        <td>{{ $line['label'] }}</td>
                                        <td><input autocomplete="off" type="number" min="1" wire:model="returnLines.{{ $i }}.quantity" aria-label="Quantity"></td>
                                        <td><input autocomplete="off" type="number" min="0" step="0.01" wire:model="returnLines.{{ $i }}.unit_cost" aria-label="Unit cost"></td>
                                        <td><button type="button" wire:click="removeReturnLine({{ $i }})" class="oo-x" style="font-size:16px" aria-label="Remove {{ $line['label'] }}">&times;</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot><tr><th colspan="2" class="oo-num">Credit expected</th><th class="oo-num">{{ currency() }} <span x-text="money(linesTotal($wire.returnLines, returnLineTotal))">{{ number_format(collect($returnLines)->sum(fn ($l) => (int) $l['quantity'] * (float) $l['unit_cost']), 2) }}</span></th><th></th></tr></tfoot>
                        </table>
                    @endif
                </section>
            </div>
            <footer class="oo-drawer-foot">
                <button type="button" x-on:click="dismissLocal($el, $wire, { showReturnForm: false })" class="oo-btn">Cancel</button>
                <button type="button" wire:click="saveReturn" wire:confirm="Take these items out of stock and record the return?" class="oo-btn primary">Record return</button>
            </footer>
        </aside>
    </div>

    {{-- ── Supplier panel ──────────────────────────────────────────── --}}
    @if($showSupplierForm)
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { showSupplierForm: false })" aria-hidden="true"></div>
        <aside class="oo-drawer" style="width:min(520px,100vw)" role="dialog" aria-modal="true" aria-labelledby="sup-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { showSupplierForm: false })">
            <header class="oo-drawer-head"><h2 id="sup-title">{{ $editingSupplierId ? 'Edit '.$supplierName : 'Add a supplier' }}</h2><button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { showSupplierForm: false })" aria-label="Close">&times;</button></header>
            <form wire:submit.prevent="saveSupplier" id="sup-form" class="oo-drawer-body">
                @if($errors->any())<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
                <div class="pu-2">
                    <label class="pu-field full"><span>Name *</span><input autocomplete="off" type="text" wire:model="supplierName" maxlength="255" class="ui-input"></label>
                    <label class="pu-field"><span>Contact person</span><input autocomplete="off" type="text" wire:model="supplierContact" maxlength="255" class="ui-input"></label>
                    <label class="pu-field"><span>Phone / WhatsApp</span><input autocomplete="off" type="tel" wire:model="supplierPhone" maxlength="50" class="ui-input"></label>
                    <label class="pu-field"><span>Email</span><input autocomplete="off" type="email" wire:model="supplierEmail" maxlength="255" class="ui-input"></label>
                    <label class="pu-field"><span>Lead time (days)</span><input autocomplete="off" type="number" min="0" max="365" wire:model="supplierLeadTime" class="ui-input"><small class="pu-hint">Sets the expected delivery date on new orders.</small></label>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="supplierActive"> Active: can be chosen for new orders</label>
            </form>
            <footer class="oo-drawer-foot"><button type="button" x-on:click="dismissLocal($el, $wire, { showSupplierForm: false })" class="oo-btn">Cancel</button><button type="submit" form="sup-form" class="oo-btn primary">Save supplier</button></footer>
        </aside>
    @endif
</div>
