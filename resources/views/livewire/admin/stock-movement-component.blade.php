<div class="clinic-ui ui-page">
    <div class="w-full">
        <div class="ui-heading">
            <div>
                <h1 class="flex flex-wrap items-center gap-2">Stock Receiving</h1>
                <p class="ui-muted">Record stock arriving from suppliers, by batch.</p>
            </div>
            <a href="{{ route('admin.product') }}" class="ui-button ui-button-secondary">
                <i class="fas fa-boxes mr-1"></i> Products
            </a>
        </div>

        <div class="ui-tiles ui-tiles--3 mb-5">
            <x-ui.stat-tile label="Receipts today" :value="number_format($receiptsToday)" icon="fa-clipboard-check" hint="Deliveries recorded" />
            <x-ui.stat-tile label="Units received today" :value="number_format($totalReceivedToday)" icon="fa-dolly" hint="Across all products" />
            <x-ui.stat-tile label="Last GRN reference" :value="$lastMovement->reference_no ?? 'None'" icon="fa-receipt" hint="Most recent receipt" />
        </div>

        <div class="flex flex-wrap -mx-2">
            <div class="w-full lg:w-4/12 px-2">
                <div class="ui-panel">
                    <div class="ui-section-heading">
                        <h3 class="font-semibold mb-0">
                            <i class="fas fa-plus-circle mr-1"></i> Receive New Stock
                        </h3>
                    </div>
                    <form wire:submit="receiveStock">
                        <div class="p-5">
                            <div class="mb-4">
                                <label for="stock-moveme-productsearch">Find Product</label>
                                <input id="stock-moveme-productsearch" type="text" class="ui-input" wire:model.live.debounce.300ms="productSearch" placeholder="Search product, batch or category...">
                            </div>

                            <div class="mb-4">
                                <label for="stock-moveme-productid">Product <span class="text-red-700">*</span></label>
                                <select id="stock-moveme-productid" class="ui-input @error('productId') is-invalid @enderror" wire:model.live="productId">
                                    <option value="">Select product...</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}">
                                            {{ $product->name }} | {{ $product->category->name ?? 'No category' }} | Qty: {{ $product->quantity }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('productId') <span class="ui-error">{{ $message }}</span> @enderror
                            </div>

                            <div class="mb-4">
                                <label for="stock-moveme-supplier">Supplier</label>
                                <input id="stock-moveme-supplier" type="text" class="ui-input @error('supplier') is-invalid @enderror" wire:model="supplier" placeholder="Supplier name">
                                @error('supplier') <span class="ui-error">{{ $message }}</span> @enderror
                            </div>

                            <div class="flex flex-wrap -mx-2">
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label for="stock-moveme-quantity">Quantity <span class="text-red-700">*</span></label>
                                    <input id="stock-moveme-quantity" type="number" min="1" class="ui-input @error('quantity') is-invalid @enderror" wire:model="quantity">
                                    @error('quantity') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label for="stock-moveme-costprice">Cost Price</label>
                                    <input id="stock-moveme-costprice" type="number" min="0" step="0.01" class="ui-input @error('costPrice') is-invalid @enderror" wire:model="costPrice">
                                    @error('costPrice') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="stock-moveme-batchnumber">Batch Number</label>
                                <input id="stock-moveme-batchnumber" type="text" class="ui-input @error('batchNumber') is-invalid @enderror" wire:model="batchNumber">
                                @error('batchNumber') <span class="ui-error">{{ $message }}</span> @enderror
                            </div>

                            <div class="flex flex-wrap -mx-2">
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label for="stock-moveme-manufacturedate">Manufacture Date</label>
                                    <input id="stock-moveme-manufacturedate" type="date" class="ui-input @error('manufactureDate') is-invalid @enderror" wire:model="manufactureDate">
                                    @error('manufactureDate') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label for="stock-moveme-expirydate">Expiry Date</label>
                                    <input id="stock-moveme-expirydate" type="date" class="ui-input @error('expiryDate') is-invalid @enderror" wire:model="expiryDate">
                                    @error('expiryDate') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="mb-0">
                                <label for="stock-moveme-notes">Notes</label>
                                <textarea id="stock-moveme-notes" class="ui-input @error('notes') is-invalid @enderror" rows="3" wire:model="notes" placeholder="Invoice number, delivery note, condition of goods..."></textarea>
                                @error('notes') <span class="ui-error">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="border-t border-slate-200 px-4 py-2 bg-white flex justify-between">
                            <button type="button" class="ui-button ui-button-secondary border border-slate-200" wire:click="resetReceiveForm">
                                <i class="fas fa-undo mr-1"></i> Reset
                            </button>
                            <button type="submit" class="ui-button ui-button-primary">
                                <span wire:loading.remove wire:target="receiveStock">
                                    <i class="fas fa-save mr-1"></i> Save GRN
                                </span>
                                <span wire:loading wire:target="receiveStock">
                                    <i class="fas fa-spinner fa-spin mr-1"></i> Saving...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="w-full lg:w-8/12 px-2">
                <div class="ui-panel">
                    <div class="ui-section-heading">
                        <div class="flex flex-wrap -mx-2 items-end">
                            <div class="w-full md:w-5/12 px-2 mb-2">
                                <label for="stock-moveme-search" class="text-sm text-slate-500 font-semibold">Search Movements</label>
                                <input id="stock-moveme-search" type="text" class="ui-input" wire:model.live.debounce.400ms="search" placeholder="Reference, product, supplier, batch...">
                            </div>
                            <div class="w-full md:w-4/12 px-2 mb-2"><label class="text-sm text-slate-500 font-semibold block">Date</label><x-date-range from="fromDate" to="toDate" presets="activity" clearable /></div>
                            <div class="w-full md:w-1/12 px-2 mb-2">
                                <button class="ui-button ui-button-secondary border border-slate-200 w-full" wire:click="$set('search','')" title="Clear search">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="ui-table-wrap">
                        <table class="ui-table mb-0">
                            <thead class="">
                                <tr>
                                    <th>Reference</th>
                                    <th>Product</th>
                                    <th>Supplier</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-center">Balance</th>
                                    <th class="text-right">Cost</th>
                                    <th>Expiry</th>
                                    <th>Received By</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($movements as $movement)
                                    <tr>
                                        <td>
                                            <strong>{{ $movement->reference_no }}</strong><br>
                                            <small class="text-slate-500">{{ $movement->created_at->format('M d, Y h:i A') }}</small>
                                        </td>
                                        <td>
                                            <strong>{{ $movement->product->name ?? 'Deleted product' }}</strong><br>
                                            <small class="text-slate-500">
                                                {{ $movement->product->category->name ?? 'No category' }}
                                                @if($movement->batch_number)
                                                    | Batch: {{ $movement->batch_number }}
                                                @endif
                                            </small>
                                        </td>
                                        <td>{{ $movement->supplier ?: 'N/A' }}</td>
                                        <td class="text-center">
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">+{{ number_format($movement->quantity) }}</span>
                                        </td>
                                        <td class="text-center">
                                            <small class="text-slate-500">{{ number_format($movement->quantity_before) }}</small>
                                            <i class="fas fa-arrow-right mx-1 text-slate-500"></i>
                                            <strong>{{ number_format($movement->quantity_after) }}</strong>
                                        </td>
                                        <td class="text-right">
                                            {{ $movement->cost_price !== null ? currency() . ' ' . number_format($movement->cost_price, 2) : 'N/A' }}
                                        </td>
                                        <td>{{ optional($movement->expiry_date)->format('M d, Y') ?: 'N/A' }}</td>
                                        <td>
                                            {{ $movement->user->name ?? 'System' }}
                                            @if($movement->notes)
                                                <br><small class="text-slate-500">{{ \Illuminate\Support\Str::limit($movement->notes, 45) }}</small>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-slate-500 py-12">
                                            <i class="fas fa-clipboard-list fa-2x mb-2 block"></i>
                                            No stock movements found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-2 bg-white">
                        {{ $movements->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
