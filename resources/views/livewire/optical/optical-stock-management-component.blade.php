<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div><h1 class="text-xl font-bold text-slate-900">Stock Management &amp; Restocking Ledger <span class="ml-2 rounded-full bg-teal-50 border border-teal-200 px-2 py-1 text-xs text-teal-800">Inventory Movement &amp; GRN</span></h1><p class="ui-muted text-xs">Receive supplier stock, record adjustments, and review branch stock movements.</p></div>
        @hasanyrole('Manager|Super Admin')<div class="flex flex-wrap gap-2"><button type="button" wire:click="openAdjustment" class="ui-button">Adjust Stock</button><a href="{{ route('optical.stock.receive-lenses') }}" class="ui-button">Receive lens order (grid / Excel)</a><button type="button" wire:click="openReceipt" class="ui-button ui-button-primary">+ Receive / Restock Inventory</button></div>@endhasanyrole
    </div>

    <x-ui.flash link-label="View received lenses in power matrix" :link="session('lensMatrixUrl') ?? ($stockType === 'lens' ? route('optical.catalogue', ['activeTab' => 'lens-matrix', 'matrixRange' => $lensRange, 'matrixDesign' => $lensDesign, 'matrixIndex' => $lensIndex, 'matrixCoating' => $lensCoating, 'matrixDiameter' => $lensDiameter]) : null)" />
    @error('movement')<div class="ui-panel p-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Total items in stock</p><p class="text-2xl font-bold text-slate-900">{{ number_format($totalStock) }} Units</p><p class="text-xs text-teal-800">Active optical SKUs at this branch</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Stock received today</p><p class="text-2xl font-bold text-teal-800">+{{ number_format($receivedToday) }} Units</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Recent GRN value</p><p class="text-2xl font-bold text-violet-800">{{ currency() }} {{ number_format(($recentReceipt?->quantity_change ?? 0) * (float) ($recentReceipt?->unit_cost ?? 0), 2) }}</p><p class="text-xs text-slate-500">{{ $recentReceipt?->reference ?: ($recentReceipt ? 'MOV-'.$recentReceipt->id : 'No receipts yet') }}</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Low stock alert SKUs</p><p class="text-2xl font-bold text-amber-800">{{ $lowStockCount }} Alerts</p></div>
    </div>

    <div class="ui-panel overflow-hidden">
        <div class="p-4 flex flex-col md:flex-row gap-3"><input autocomplete="off" type="search" wire:model.live.debounce.250ms="search" placeholder="Filter by GRN ref, product, batch, or supplier..." aria-label="Search stock movements" class="ui-input flex-1"><select wire:model.live="typeFilter" aria-label="Filter movement type" class="ui-input md:w-48"><option value="">All Movement Types</option><option value="receipt">Stock Received</option><option value="adjustment">Adjustment</option><option value="reversal">Reversal</option><option value="system">System Movement</option></select></div>
        <div class="ui-table-wrap"><table class="ui-table w-full"><thead><tr><th>GRN / Movement Ref</th><th>Product SKU &amp; Description</th><th>Movement Type</th><th>Supplier / Source</th><th>Batch / Lot #</th><th>Unit Cost &amp; Price</th><th>Qty Change (New Stock)</th><th>Date &amp; User</th><th>Actions</th></tr></thead><tbody>
            @forelse($movements as $movement)
                <tr wire:key="stock-movement-{{ $movement->id }}">
                    <td class="font-mono text-xs font-semibold">{{ $movement->reference ?: 'MOV-'.$movement->id }}<div class="text-slate-500 font-normal">#{{ $movement->id }}</div></td>
                    <td><div class="font-semibold">{{ $movement->product?->name ?? 'Archived product' }}</div><div class="font-mono text-xs text-slate-500">{{ $movement->product?->sku }}</div></td>
                    <td><span class="ui-badge {{ $movement->quantity_change >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' }}">{{ match($movement->movement_type) { 'receipt' => 'Stock Received', 'adjustment' => 'Adjustment', 'reversal' => 'Reversal', 'supplier_return' => 'Supplier Return', 'count' => 'Stock Count', default => 'System' } }}</span><div class="text-xs text-slate-500 mt-1">{{ $movement->reason }}</div></td>
                    <td class="text-xs">{{ $movement->supplier ?: '—' }}</td><td class="font-mono text-xs">{{ $movement->batch_number ?: '—' }}</td>
                    <td class="font-mono text-xs">@if($movement->unit_cost !== null)<div>Cost: {{ currency() }} {{ number_format((float) $movement->unit_cost, 2) }}</div><div>Price: {{ currency() }} {{ number_format((float) $movement->unit_price, 2) }}</div>@else — @endif</td>
                    <td class="font-mono text-xs font-semibold {{ $movement->quantity_change >= 0 ? 'text-teal-800' : 'text-red-700' }}">{{ $movement->quantity_change > 0 ? '+' : '' }}{{ $movement->quantity_change }}<div class="font-normal text-slate-500">New stock: {{ $movement->balance_after }}</div></td>
                    <td class="text-xs">{{ $movement->created_at?->format('d M Y H:i') }}<div class="text-slate-500">{{ $movement->user?->name ?? 'System' }}</div></td>
                    <td>@hasanyrole('Manager|Super Admin')@if(in_array($movement->movement_type, ['receipt', 'adjustment'], true) && ! $movement->reversedBy && ! $movement->optical_purchase_order_line_id)<button type="button" wire:click="reverse({{ $movement->id }})" wire:confirm="Reverse this movement? A new offsetting ledger entry will be recorded." class="text-xs text-red-700 underline">Reverse</button>@elseif($movement->reversedBy)<span class="text-xs text-slate-500">Reversed</span>@else — @endif@else — @endhasanyrole</td>
                </tr>
            @empty
                <tr><td colspan="9" class="ui-empty">No optical stock movements found.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="p-4 border-t border-slate-200">{{ $movements->links() }}</div>
    </div>

    @include('livewire.optical.partials.lens-import-history')

    @if($showForm)
        @teleport('body')
        <div class="clinic-ui optical-stock-overlay" role="dialog" aria-modal="true" aria-label="{{ $formType === 'receipt' ? 'Receive optical stock' : 'Adjust optical stock' }}">
            <div class="optical-stock-dialog rounded-xl bg-white shadow-xl {{ $stockType === 'lens' ? ($entryMode === 'bulk' ? 'optical-stock-dialog--bulk' : 'optical-stock-dialog--lens') : '' }}">
                <div class="bg-teal-900 text-white px-5 py-4 flex items-center gap-3 flex-none"><span class="rounded-lg bg-teal-800 border border-teal-700 px-2 py-2" aria-hidden="true">📥</span><div class="flex-1"><h2 class="font-bold">{{ $formType === 'receipt' ? 'Receive / Restock Inventory' : 'Adjust Optical Stock' }}</h2><p class="text-xs text-teal-100">{{ $formType === 'receipt' ? 'Log incoming stock shipment, GRN, unit cost, and quantity.' : 'Record a counted stock increase or decrease with a reason.' }}</p></div><button type="button" x-on:click="dismissLocal($el, $wire, { showForm: false })" aria-label="Close" class="text-teal-100 text-lg">×</button></div>
                <form wire:submit="save" class="min-h-0 flex flex-col flex-1">
                    <div class="min-h-0 overflow-y-auto p-5 space-y-4">
                        @if($formType === 'receipt')
                        <label class="block text-xs font-semibold">Stock type<select wire:model.live="stockType" class="ui-input w-full"><option value="other">Other / existing SKU</option><option value="frame">Frame</option><option value="lens">Lens</option></select></label>
                        @endif
                        @if($formType === 'receipt' && $stockType === 'lens')
                            @include('livewire.optical.partials.shared-lens-receipt')
                            @error('importReceipt')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                            @if($duplicateImport)
                                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm" role="alert">
                                    <p>These worksheet quantities were already received as import #{{ $duplicateImport->id }} on {{ $duplicateImport->created_at->format('d M Y H:i') }} ({{ $duplicateImport->reference ?: 'no invoice reference' }}).</p>
                                    <button type="button" wire:click="viewImport({{ $duplicateImport->id }})" class="underline">View original receipt</button>
                                    <p class="mt-2">For a separate delivery, enter a different invoice reference and explain why the quantities repeat.</p>
                                    <label class="block mt-2">Repeat delivery reason<textarea wire:model="repeatDeliveryReason" maxlength="1000" class="ui-input w-full" rows="2"></textarea></label>
                                    @error('repeatDeliveryReason')<p class="text-red-700">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        @else

                        <div><label class="block text-xs font-semibold mb-1">Search &amp; Select Optical Product SKU <span class="text-red-600">*</span></label><input type="search" wire:model.live.debounce.250ms="productSearch" class="ui-input w-full" placeholder="Search product name or SKU" autocomplete="off">@error('productId')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            @if(!$productId)<div class="mt-1 max-h-36 overflow-y-auto rounded-lg border border-slate-200">@forelse($productMatches as $match)<button type="button" wire:click="selectProduct({{ $match->id }})" class="block w-full text-left px-3 py-2 text-xs hover:bg-teal-50 border-b border-slate-100"><span class="font-semibold">{{ $match->name }}</span> <span class="font-mono text-slate-500">({{ $match->sku }})</span></button>@empty<div class="px-3 py-2 text-xs text-slate-500">No active optical product found.</div>@endforelse</div>@else<div class="mt-1 text-xs text-teal-800">Selected: {{ $selectedProduct?->name }} · Current stock: {{ $selectedProduct?->stocks->first()?->quantity ?? 0 }} units</div>@endif
                        </div>
                        @if($formType === 'receipt')
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3"><div><label class="block text-xs font-semibold mb-1">Quantity Received *</label><input autocomplete="off" type="number" min="1" step="1" wire:model="quantity" class="ui-input w-full">@error('quantity')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div><div><label class="block text-xs font-semibold mb-1">Unit Cost ({{ currency() }}) *</label><input autocomplete="off" type="number" min="0" step="0.01" wire:model="unitCost" class="ui-input w-full">@error('unitCost')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div><div><label class="block text-xs font-semibold mb-1">Selling Price ({{ currency() }}) *</label><input autocomplete="off" type="number" min="0" step="0.01" wire:model="unitPrice" class="ui-input w-full">@error('unitPrice')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div></div>
                            <div class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-xs flex flex-wrap justify-between gap-2"><span>Batch Margin &amp; Pricing Preview:</span><strong class="font-mono text-teal-900" x-data x-text="stockMargin($wire, @js(currency()))">@if(is_numeric($unitCost) && is_numeric($unitPrice) && is_numeric($quantity) && (float) $unitCost > 0) Unit Profit: {{ currency() }} {{ number_format((float) $unitPrice - (float) $unitCost, 2) }} ({{ number_format(((float) $unitPrice - (float) $unitCost) / (float) $unitCost * 100, 1) }}% markup) · Batch Profit: {{ currency() }} {{ number_format(((float) $unitPrice - (float) $unitCost) * (int) $quantity, 2) }} @else — @endif</strong></div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div><label class="block text-xs font-semibold mb-1">Supplier / Vendor Name</label><input autocomplete="off" wire:model="supplier" maxlength="180" class="ui-input w-full">@error('supplier')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div><div><label class="block text-xs font-semibold mb-1">Batch / Lot / Serial #</label><input autocomplete="off" wire:model="batchNumber" maxlength="100" class="ui-input w-full">@error('batchNumber')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div></div>
                            <div><label class="block text-xs font-semibold mb-1">Expiry date <span class="font-normal text-slate-500">(only for items that expire, e.g. contact lenses, solutions)</span></label><input autocomplete="off" type="date" wire:model="expiryDate" class="ui-input w-full sm:w-1/2">@error('expiryDate')<p class="text-xs text-red-600">{{ $message }}</p>@enderror<p class="text-xs text-slate-500 mt-1">Staff are reminded before it expires (Needs attention).</p></div>
                            <div><label class="block text-xs font-semibold mb-1">GRN / Supplier Invoice Reference</label><input autocomplete="off" wire:model="reference" maxlength="100" class="ui-input w-full">@error('reference')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                            <p class="text-xs text-slate-500">Receipt prices are stored on this ledger entry. To change the product’s selling price, edit the Optical Product.</p>
                        @else
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div><label class="block text-xs font-semibold mb-1">Direction *</label><select wire:model="adjustmentDirection" class="ui-input w-full"><option value="add">Add stock</option><option value="remove">Remove stock</option></select></div><div><label class="block text-xs font-semibold mb-1">Quantity *</label><input autocomplete="off" type="number" min="1" step="1" wire:model="quantity" class="ui-input w-full">@error('quantity')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div></div><div><label class="block text-xs font-semibold mb-1">Reason *</label><input autocomplete="off" wire:model="adjustmentReason" maxlength="120" class="ui-input w-full" placeholder="e.g. Physical count correction">@error('adjustmentReason')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        @endif
                        @endif
                        <div><label class="block text-xs font-semibold mb-1">Notes</label><textarea wire:model="notes" rows="2" maxlength="2000" class="ui-input w-full"></textarea>@error('notes')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    </div>
                    <div class="flex-none p-4 border-t border-slate-200 flex justify-end gap-2 bg-white"><button type="button" x-on:click="dismissLocal($el, $wire, { showForm: false })" class="ui-button">Cancel</button><button type="submit" class="ui-button ui-button-primary">{{ $formType === 'receipt' ? 'Confirm & Receive Stock' : 'Save Adjustment' }}</button></div>
                </form>
            </div>
        </div>
        @endteleport
    @endif
</div>
