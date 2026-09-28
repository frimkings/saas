<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Optical Product Master Data <span class="ml-2 rounded-full bg-teal-50 border border-teal-200 px-2 py-1 text-xs text-teal-800">Inventory SKU Master</span></h1>
            <p class="ui-muted text-xs">Manage frames, lenses, accessories, prices and branch stock levels.</p>
        </div>
        @hasanyrole('Manager|Super Admin')
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="downloadTemplate" class="ui-button text-xs">CSV Template</button>
                <button type="button" wire:click="exportCsv" class="ui-button text-xs">Export CSV</button>
                <button type="button" wire:click="openImport" class="ui-button text-xs">Import CSV</button>
                <button type="button" wire:click="add" class="ui-button ui-button-primary text-xs">+ Add Optical Product</button>
            </div>
        @endhasanyrole
    </div>

    <x-ui.flash />
    @error('product')<div class="ui-panel p-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Total active product SKUs</p><p class="text-2xl font-bold text-teal-800">{{ $activeCount }}</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Total stock inventory value</p><p class="text-2xl font-bold text-teal-800">{{ currency() }} {{ number_format($inventoryValue, 2) }}</p><p class="text-xs text-slate-500">Retail valuation at this branch</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Low stock alert items</p><p class="text-2xl font-bold text-amber-800">{{ $lowStockCount }}</p><p class="text-xs text-slate-500">At or below reorder level</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Avg price markup</p><p class="text-2xl font-bold text-violet-800">{{ number_format($averageMarkup, 1) }}%</p></div>
    </div>

    <div class="ui-panel overflow-hidden">
        <div class="p-4 flex flex-col md:flex-row gap-3">
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Filter by SKU barcode, product name, brand, or category..." class="ui-input flex-1" aria-label="Search optical products" autocomplete="off">
            <select wire:model.live="categoryFilter" class="ui-input md:w-48" aria-label="Filter category"><option value="">All Categories</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>
            <select wire:model.live="stockFilter" class="ui-input md:w-40" aria-label="Filter stock status"><option value="">All Stock Status</option><option value="in">In Stock</option><option value="low">Low Stock</option><option value="out">Out of Stock</option></select>
            @if($search !== '' || $categoryFilter !== '' || $stockFilter !== '')<button type="button" wire:click="clearFilters" class="ui-button whitespace-nowrap">Clear filters</button>@endif
        </div>
        <div class="px-4 pb-3 text-xs text-slate-500" aria-live="polite">{{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }} found <span wire:loading wire:target="search,categoryFilter,stockFilter" class="ml-2 text-teal-700">Searching…</span></div>
        <div class="ui-table-wrap"><table class="ui-table w-full">
            <thead><tr><th>SKU Barcode</th><th>Product Name &amp; Specifications</th><th>Category</th><th>Cost Price</th><th>Selling Price (Markup)</th><th>In Stock</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse($products as $product)
                    @php $balance = $product->stocks->first(); $qty = $balance?->quantity ?? 0; $reorder = $balance?->reorder_level ?? 5; $markup = (float) $product->cost_price > 0 ? ((float) $product->selling_price - (float) $product->cost_price) / (float) $product->cost_price * 100 : 0; @endphp
                    <tr wire:key="optical-product-{{ $product->id }}">
                        <td class="font-mono text-xs font-semibold">{{ $product->sku }}</td>
                        <td><div class="font-semibold">{{ $product->name }}</div><div class="text-xs text-slate-500">{{ $product->brand ? 'Brand: '.$product->brand : '' }}{{ $product->brand && $product->specifications ? ' | ' : '' }}{{ $product->specifications }}</div></td>
                        <td><span class="ui-badge bg-blue-50 text-blue-800">{{ $product->category?->name ?? '—' }}</span></td>
                        <td class="font-mono text-xs">{{ currency() }} {{ number_format((float) $product->cost_price, 2) }}</td>
                        <td class="font-mono text-xs font-semibold">{{ currency() }} {{ number_format((float) $product->selling_price, 2) }}<div class="text-teal-700">{{ number_format($markup, 1) }}% markup</div></td>
                        <td class="font-mono text-xs {{ $qty <= $reorder ? 'text-amber-700' : 'text-teal-800' }}">{{ $qty }} units</td>
                        <td><span class="ui-badge {{ $product->is_active ? ($qty <= $reorder ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800') : 'bg-slate-100 text-slate-600' }}">{{ $product->is_active ? ($qty <= $reorder ? 'Reorder' : 'Active') : 'Inactive' }}</span></td>
                        <td><div class="flex gap-2 text-xs">@hasanyrole('Manager|Super Admin')<button type="button" wire:click="edit({{ $product->id }})" class="text-amber-700 underline">Edit</button><button type="button" wire:click="delete({{ $product->id }})" wire:confirm="Archive this optical product?" class="text-red-700 underline">Delete</button>@else — @endhasanyrole</div></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ui-empty">No optical products found. Add a product to begin tracking optical stock.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="p-4 border-t border-slate-200">{{ $products->links() }}</div>
    </div>

    @if($showImport)
        <div class="fixed inset-0 z-50 bg-slate-950/60 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Import optical products">
            <div class="w-full max-w-lg max-h-[calc(100dvh-2rem)] rounded-xl bg-white shadow-xl overflow-y-auto">
                <div class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between"><div><h2 class="font-bold">Import Optical Products</h2><p class="text-xs text-slate-300">Use the CSV template. Category codes must exist in Optical Categories.</p></div><button type="button" x-on:click="dismissLocal($el, $wire, { showImport: false })" aria-label="Close" class="text-slate-300 text-lg">×</button></div>
                <form wire:submit="importCsv" class="p-5 space-y-4">
                    <p class="text-xs text-slate-600">Existing SKUs are updated. Quantity sets the stock balance at the current branch. The whole file must pass validation before any products are saved.</p>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-xs font-semibold">CSV file <span class="text-red-600">*</span></label>
                        <button type="button" wire:click="downloadTemplate" class="text-xs text-teal-700 hover:underline flex items-center gap-1 font-medium">📥 Download CSV Template</button>
                    </div>
                    <div><input type="file" wire:model="importFile" accept=".csv,text/csv" class="ui-input w-full">@error('importFile')<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror</div>
                    <div wire:loading wire:target="importFile,importCsv" class="text-xs text-teal-700">Processing file…</div>
                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4"><button type="button" x-on:click="dismissLocal($el, $wire, { showImport: false })" class="ui-button">Cancel</button><button type="submit" class="ui-button ui-button-primary" wire:loading.attr="disabled" wire:target="importFile,importCsv">Import CSV</button></div>
                </form>
            </div>
        </div>
    @endif

    @if($showForm)
        <div class="fixed inset-0 z-50 bg-slate-950/60 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="{{ $editingId ? 'Edit optical product' : 'Add optical product' }}">
            <div class="optical-product-dialog rounded-xl bg-white shadow-xl">
                <div class="bg-slate-900 text-white px-5 py-4 flex items-center gap-3 flex-none">
                    <span class="rounded-lg bg-teal-900/40 border border-teal-700 px-2 py-2" aria-hidden="true">📦</span>
                    <div class="flex-1"><h2 class="font-bold">{{ $editingId ? 'Edit Optical Product SKU' : 'Add New Optical Product SKU' }}</h2><p class="text-xs text-slate-300">Register product item in the Optical Inventory Master catalogue.</p></div>
                    <button type="button" x-on:click="dismissLocal($el, $wire, { showForm: false })" aria-label="Close" class="text-slate-300 text-lg">×</button>
                </div>
                <form wire:submit="save" class="flex flex-col flex-1 min-h-0">
                  <div class="optical-product-dialog__body p-5 space-y-3">
                    <div><label class="block text-xs font-semibold mb-1">Product Name / Title <span class="text-red-600">*</span></label><input autocomplete="off" wire:model="name" maxlength="180" class="ui-input w-full" placeholder="e.g. Gucci GG0061S Gold Metal Frame">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><label class="block text-xs font-semibold mb-1">SKU Barcode / Item Code <span class="text-red-600">*</span></label><input autocomplete="off" wire:model="sku" maxlength="80" class="ui-input w-full" placeholder="SKU-FRM-001">@error('sku')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Optical Category <span class="text-red-600">*</span></label><select wire:model="categoryId" class="ui-input w-full"><option value="">Select category</option>@foreach($categories->where('is_active', true) as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>@error('categoryId')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Brand / Manufacturer</label><input autocomplete="off" wire:model="brand" maxlength="120" class="ui-input w-full" placeholder="e.g. Gucci, Ray-Ban, Essilor">@error('brand')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Status</label><select wire:model="active" class="ui-input w-full"><option value="1">Active</option><option value="0">Inactive</option></select>@error('active')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Cost Price ({{ currency() }}) <span class="text-red-600">*</span></label><input autocomplete="off" type="number" min="0" step="0.01" wire:model="costPrice" class="ui-input w-full">@error('costPrice')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Selling Price ({{ currency() }}) <span class="text-red-600">*</span></label><input autocomplete="off" type="number" min="0" step="0.01" wire:model="sellingPrice" class="ui-input w-full">@error('sellingPrice')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 flex justify-between text-xs"><span>Calculated Profit Margin:</span><strong class="font-mono text-teal-800" x-data x-text="isNumeric($wire.costPrice) && num($wire.costPrice) > 0 && isNumeric($wire.sellingPrice) ? ((num($wire.sellingPrice) - num($wire.costPrice)) / num($wire.costPrice) * 100).toFixed(1) + '% Markup (Profit: ' + @js(currency()) + ' ' + money(num($wire.sellingPrice) - num($wire.costPrice)) + ')' : '—'">@if(is_numeric($costPrice) && (float) $costPrice > 0 && is_numeric($sellingPrice)){{ number_format(((float) $sellingPrice - (float) $costPrice) / (float) $costPrice * 100, 1) }}% Markup (Profit: {{ currency() }} {{ number_format((float) $sellingPrice - (float) $costPrice, 2) }})@else — @endif</strong></div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><label class="block text-xs font-semibold mb-1">{{ $editingId ? 'Current Branch Stock Quantity' : 'Initial Stock Quantity' }} <span class="text-red-600">*</span></label><input autocomplete="off" type="number" min="0" step="1" wire:model="quantity" class="ui-input w-full">@error('quantity')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Reorder Level Warning Threshold</label><input autocomplete="off" type="number" min="0" step="1" wire:model="reorderLevel" class="ui-input w-full">@error('reorderLevel')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    </div>
                    <div><label class="block text-xs font-semibold mb-1">Product Specifications &amp; Lens / Frame Details</label><textarea wire:model="specifications" rows="2" maxlength="3000" class="ui-input w-full" placeholder="e.g. Size 54-18-140 Full Rim Metal"></textarea>@error('specifications')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                  </div>
                  <div class="optical-product-dialog__footer flex justify-end gap-3 p-4"><button type="button" x-on:click="dismissLocal($el, $wire, { showForm: false })" class="ui-button">Cancel</button><button type="submit" class="ui-button ui-button-primary">Save Optical Product</button></div>
                </form>
            </div>
        </div>
    @endif
</div>
