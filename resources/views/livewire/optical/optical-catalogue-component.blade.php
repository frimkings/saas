<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .ct-tabs{display:flex;gap:4px;border-bottom:1px solid var(--clinic-line);overflow-x:auto}
        .ct-tabs button{background:none;border:0;border-bottom:2px solid transparent;padding:10px 14px;font-weight:700;font-size:13px;color:var(--clinic-muted);cursor:pointer;white-space:nowrap}
        .ct-tabs button.active{color:var(--clinic-accent);border-bottom-color:var(--clinic-accent)}
        .ct-table col.c-sku{width:150px}.ct-table col.c-name{width:auto}.ct-table col.c-cat{width:170px}.ct-table col.c-price{width:120px}.ct-table col.c-stock{width:110px}.ct-table col.c-status{width:120px}.ct-table col.c-act{width:90px}
        .ct-table td.ct-sku{overflow:hidden}.ct-table td.ct-sku .oo-id{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ct-field{display:flex;flex-direction:column;gap:4px}.ct-field>span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.ct-field .ui-input{padding:8px 10px}
        .ct-check{display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 0}
    </style>
    @php
        $isManager = auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
        $stockBadge = fn ($qty, $reorder) => $qty <= 0 ? ['Out of stock', 'oo-b-red'] : ($qty <= $reorder ? ['Low stock', 'oo-b-amber'] : ['In stock', 'oo-b-green']);
    @endphp

    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Catalogue &amp; Stock</h1>
            <p class="ui-muted text-sm">Frames, lenses, lens blank stock, coatings and service prices for this branch.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($isManager)
                <a wire:navigate href="{{ route('optical.categories') }}" class="oo-btn">Categories</a>
                <a wire:navigate href="{{ route('optical.stock') }}" class="oo-btn">Receive stock</a>
                @if(in_array($activeTab, ['frames', 'lenses']))<a wire:navigate href="{{ route('optical.products') }}" class="oo-btn primary">+ Add item</a>@endif
            @endif
        </div>
    </div>

    <x-ui.flash />

    <nav class="ct-tabs" role="tablist" aria-label="Catalogue sections">
        @foreach(['frames' => 'Frames', 'lenses' => 'Lenses', 'lens-matrix' => 'Lens blank stock', 'lens-prices' => 'Lens prices', 'options' => 'Coatings & options', 'services' => 'Services & prices'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" class="{{ $activeTab === $key ? 'active' : '' }}" wire:click="setTab('{{ $key }}')">{{ $label }}</button>
        @endforeach
    </nav>

    @if($activeTab === 'lens-prices')
        <livewire:optical.lens-price-list-component />
    @endif

    @if($activeTab === 'services')
        <div class="ui-panel p-5 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div><h2 class="font-bold text-slate-900">Service price list</h2><p class="text-xs text-slate-500">Activate a service after setting its price. Saved orders keep their original charge; editing a quotation uses current prices.</p></div>
                @hasanyrole('Manager|Super Admin')<button type="button" x-on:click="openLocal($wire, { editingServiceId: null, serviceName: '', servicePrice: '', serviceRequiresRx: false, serviceRequiresFrame: false, serviceActive: true, showServiceForm: true }, $root.querySelector('[data-service-form]'))" class="ui-button ui-button-primary">+ Add service</button>@endhasanyrole
            </div>
            <div class="ui-table-wrap"><table class="ui-table w-full"><thead><tr><th>Service</th><th>Needs Rx</th><th>Needs frame</th><th class="ui-number">Price</th><th>Status</th><th>Action</th></tr></thead><tbody>
                @foreach($opticalServices as $service)
                    <tr @if($isManager) x-on:click="openLocal($wire, {{ \Illuminate\Support\Js::from(['editingServiceId' => $service->id, 'serviceName' => $service->name, 'servicePrice' => (string) $service->price, 'serviceRequiresRx' => (bool) $service->requires_rx, 'serviceRequiresFrame' => (bool) $service->requires_frame, 'serviceActive' => (bool) $service->is_active, 'showServiceForm' => true]) }}, $root.querySelector('[data-service-form]'))" style="cursor:pointer" @endif><td class="font-semibold">{{ $service->name }}</td><td>{{ $service->requires_rx ? 'Yes' : 'No' }}</td><td>{{ $service->requires_frame ? 'Yes' : 'No' }}</td><td class="ui-number">{{ currency() }} {{ number_format((float) $service->price, 2) }}</td><td>{{ $service->is_active ? 'Active' : 'Inactive' }}</td><td>@hasanyrole('Manager|Super Admin')<button type="button" x-on:click.stop="$el.closest('tr').click()" class="text-teal-700 font-semibold underline">Edit</button>@endhasanyrole</td></tr>
                @endforeach
            </tbody></table></div>
        </div>
        @if($isManager)
            {{-- Always in the page: Add and a row click open it in the browser (openLocal); only Save calls the server. --}}
            <div data-sheet data-keep data-service-form x-show="$wire.showServiceForm" x-cloak x-data="{ close() { dismissLocal(this.$root, this.$wire, { showServiceForm: false }) } }">
            <div class="oo-overlay" x-on:click="close()" aria-hidden="true"></div>
            <aside class="oo-drawer" style="width:min(460px,100vw)" role="dialog" aria-modal="true" aria-labelledby="svc-title" tabindex="-1" x-on:keydown.escape.window="$wire.showServiceForm && close()">
                <header class="oo-drawer-head"><h2 id="svc-title" x-text="$wire.editingServiceId ? 'Edit service' : 'Add a service'">{{ $editingServiceId ? 'Edit service' : 'Add a service' }}</h2><button type="button" class="oo-x" x-on:click="close()" aria-label="Close">&times;</button></header>
                <form wire:submit="saveService" id="svc-form" class="oo-drawer-body">
                    <label class="ct-field"><span>Service name *</span><input autocomplete="off" type="text" wire:model="serviceName" class="ui-input" maxlength="255">@error('serviceName')<small class="ui-error" style="color:#b91c1c">{{ $message }}</small>@enderror</label>
                    <label class="ct-field"><span>Standard price ({{ currency() }}) *</span><input autocomplete="off" type="number" min="0" step="0.01" wire:model="servicePrice" class="ui-input">@error('servicePrice')<small class="ui-error" style="color:#b91c1c">{{ $message }}</small>@enderror</label>
                    <div>
                        <label class="ct-check"><input type="checkbox" wire:model="serviceRequiresRx"> Needs a prescription</label>
                        <label class="ct-check"><input type="checkbox" wire:model="serviceRequiresFrame"> Needs frame details</label>
                        <label class="ct-check"><input type="checkbox" wire:model="serviceActive"> Available for new orders</label>
                    </div>
                    <p class="ui-muted text-xs" style="margin:0">Saved orders keep the price they were charged. Editing a quotation uses the current price.</p>
                </form>
                <footer class="oo-drawer-foot"><button type="button" class="oo-btn" x-on:click="close()">Cancel</button><button type="submit" form="svc-form" class="oo-btn primary">Save service</button></footer>
            </aside>
            </div>
        @endif
    @elseif($activeTab === 'lens-matrix')
        @include('livewire.optical.partials.lens-blank-matrix')
    @elseif($activeTab === 'options')
        <div class="ui-panel">
            <div class="ui-panel-heading">
                <h2>Lens Options & Add-on Coatings</h2>
                <span class="ui-muted text-xs font-normal">{{ $lensOptions->count() }} option(s)</span>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>Option Name</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th class="ui-number">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lensOptions as $opt)
                            <tr>
                                <td class="font-semibold">{{ $opt->display_name }}</td>
                                <td class="font-mono text-xs">{{ $opt->family }}</td>
                                <td class="ui-muted">Lens option</td>
                                <td class="ui-number font-bold text-slate-900">—</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="ui-empty">
                                    <p class="ui-muted">No lens options or coatings configured.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif(in_array($activeTab, ['frames', 'lenses'], true))
        <section class="ui-panel bg-white">
            <div class="oo-toolbar">
                <input autocomplete="off" type="search" wire:model.live.debounce.300ms="searchTerm" placeholder="Search name, SKU or brand…" aria-label="Search the catalogue" class="ui-input text-sm">
                <span class="ui-muted" wire:loading.delay wire:target="searchTerm,setStockFilter">Updating…</span>
            </div>
            <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by stock">
                @foreach(['' => 'All', 'low' => 'Low stock', 'out' => 'Out of stock'] as $key => $label)
                    <button type="button" wire:click="setStockFilter('{{ $key }}')" class="oo-chip {{ $stockFilter === $key ? 'active' : '' }}" aria-pressed="{{ $stockFilter === $key ? 'true' : 'false' }}">{{ $label }}<b>{{ $stockCounts[$key] }}</b></button>
                @endforeach
            </div>
            <div class="overflow-x-auto">
                <table class="oo-table ct-table w-full" style="min-width:860px">
                    <colgroup><col class="c-sku"><col class="c-name"><col class="c-cat"><col class="c-price"><col class="c-stock"><col class="c-status"><col class="c-act"></colgroup>
                    <thead><tr><th>SKU</th><th>Item</th><th>Category</th><th class="oo-num">Price</th><th class="oo-num">In stock</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse($products as $product)
                            @php $qty = $stockByProduct[$product->id]; $reorder = $reorderByProduct[$product->id]; [$bl, $bc] = $stockBadge($qty, $reorder); @endphp
                            <tr wire:key="product-{{ $product->id }}" class="{{ $viewProductId === $product->id ? 'oo-active' : '' }}" wire:click="openProduct({{ $product->id }})">
                                <td class="ct-sku"><span class="oo-id" style="color:var(--clinic-ink)" title="{{ $product->sku }}">{{ $product->sku }}</span></td>
                                <td><b>{{ $product->name }}</b>@if($product->brand)<span class="oo-sub">{{ $product->brand }}</span>@endif</td>
                                <td>{{ $product->category?->name ?? '—' }}</td>
                                <td class="oo-num"><b>{{ currency() }} {{ number_format((float) $product->selling_price, 2) }}</b></td>
                                <td class="oo-num"><b style="color:{{ $qty <= 0 ? '#b91c1c' : ($qty <= $reorder ? '#92400e' : 'inherit') }}">{{ $qty }}</b><span class="oo-sub">reorder at {{ $reorder }}</span></td>
                                <td><span class="oo-badge {{ $bc }}">{{ $bl }}</span></td>
                                <td style="text-align:right" onclick="event.stopPropagation()"><button type="button" class="oo-btn" wire:click="openProduct({{ $product->id }})">View</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">{{ $searchTerm || $stockFilter ? 'No items match these filters.' : 'No items in this part of the catalogue yet.' }}</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-t border-slate-200">{{ $products->links() }}</div>
        </section>

        @if($viewProduct)
            @php
                $stock = $viewProduct->stocks->first();
                $qty = (int) ($stock?->quantity ?? 0); $reorder = (int) ($stock?->reorder_level ?? 5);
                [$bl, $bc] = $stockBadge($qty, $reorder);
                $cost = (float) $viewProduct->cost_price; $price = (float) $viewProduct->selling_price;
                $margin = $price > 0 ? round(($price - $cost) / $price * 100, 1) : null;
            @endphp
            {{-- Closing hides the panel at once; the server hears of it with the next request. --}}
            <div data-sheet wire:key="product-sheet-{{ $viewProduct->id }}" x-data="{ close() { this.$root.hidden = true; this.$wire.$set('viewProductId', null, false) } }">
            <div class="oo-overlay" x-on:click="close()" aria-hidden="true"></div>
            <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="ct-title" tabindex="-1" x-init="$el.focus()" x-on:keydown.escape.window="close()" wire:key="product-drawer-{{ $viewProduct->id }}">
                <header class="oo-drawer-head">
                    <div>
                        <h2 id="ct-title">{{ $viewProduct->name }}</h2>
                        <div class="flex flex-wrap items-center gap-2 text-xs ui-muted"><span class="oo-badge {{ $bc }}">{{ $bl }}</span><span class="oo-id" style="color:var(--clinic-ink)">{{ $viewProduct->sku }}</span><span>· {{ $viewProduct->category?->name }}</span></div>
                    </div>
                    <button type="button" class="oo-x" x-on:click="close()" aria-label="Close">&times;</button>
                </header>
                <div class="oo-drawer-body">
                    <div class="oo-money">
                        <div><span>In stock</span><b style="color:{{ $qty <= 0 ? '#b91c1c' : ($qty <= $reorder ? '#92400e' : 'inherit') }}">{{ $qty }}</b></div>
                        <div><span>Selling price</span><b>{{ currency() }} {{ number_format($price, 2) }}</b></div>
                        <div><span>Margin</span><b>{{ $margin !== null ? $margin.'%' : '—' }}</b></div>
                    </div>
                    @if($qty <= $reorder)<div class="oo-note {{ $qty <= 0 ? 'red' : 'amber' }}">{{ $qty <= 0 ? 'Out of stock at this branch.' : 'At or below the reorder level ('.$reorder.').' }}@if($isManager) Order more from Purchasing, or record a delivery in Receive stock.@endif</div>@endif
                    <section class="oo-section">
                        <h3>Details</h3>
                        <dl class="oo-dl">
                            <dt>Brand</dt><dd>{{ $viewProduct->brand ?: '—' }}</dd>
                            <dt>Cost price</dt><dd>{{ currency() }} {{ number_format($cost, 2) }}</dd>
                            <dt>Reorder level</dt><dd>{{ $reorder }}</dd>
                            @if($viewProduct->specifications)<dt>Specifications</dt><dd style="white-space:pre-line">{{ $viewProduct->specifications }}</dd>@endif
                            @if($viewProduct->lens_specs)<dt>Lens</dt><dd>{{ collect(['design', 'index', 'coating'])->map(fn ($k) => data_get($viewProduct->lens_specs, $k))->filter()->join(' · ') }}{{ data_get($viewProduct->lens_specs, 'diameter') ? ' · '.data_get($viewProduct->lens_specs, 'diameter').'mm' : '' }}</dd>@endif
                        </dl>
                    </section>
                    <section class="oo-section">
                        <h3>Recent stock movements</h3>
                        @forelse($viewMovements as $move)
                            <div class="flex justify-between gap-2" style="padding:6px 0;border-bottom:1px solid var(--clinic-line)">
                                <span>{{ ucwords(str_replace('_', ' ', $move->movement_type ?: ($move->reason ?: 'Adjustment'))) }}<span class="oo-sub">{{ $move->created_at?->format('d M Y H:i') }}{{ $move->user ? ' · '.$move->user->name : '' }}{{ $move->reference ? ' · '.$move->reference : '' }}</span></span>
                                <span style="text-align:right;font-variant-numeric:tabular-nums"><b style="color:{{ $move->quantity_change < 0 ? '#b91c1c' : '#047857' }}">{{ $move->quantity_change > 0 ? '+' : '' }}{{ $move->quantity_change }}</b><span class="oo-sub">balance {{ $move->balance_after }}</span></span>
                            </div>
                        @empty
                            <p class="ui-muted text-sm" style="margin:0">No stock movements recorded at this branch.</p>
                        @endforelse
                    </section>
                </div>
                @if($isManager)
                    <footer class="oo-drawer-foot">
                        <a wire:navigate href="{{ route('optical.stock') }}" class="oo-btn">Receive stock</a>
                        <a wire:navigate href="{{ route('optical.products', ['edit' => $viewProduct->id]) }}" class="oo-btn primary">Edit item</a>
                    </footer>
                @endif
            </aside>
            </div>
        @endif
    @endif
</div>
