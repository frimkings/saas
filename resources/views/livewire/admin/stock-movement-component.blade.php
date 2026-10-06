<div class="clinic-ui ui-page">
    <div class="w-full">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="mb-0 text-teal-700 font-semibold">Stock Receiving</h3>
                <small class="text-slate-500 uppercase font-semibold">Goods received note and stock movement log</small>
            </div>
            <a href="{{ route('admin.product') }}" class="btn ui-button ui-button-secondary">
                <i class="fas fa-boxes mr-1"></i> Products
            </a>
        </div>

        <div class="flex flex-wrap -mx-2">
            <div class="w-full lg:w-4/12 px-2">
                <div class="small-box bg-sky-600 text-white">
                    <div class="inner">
                        <h3>{{ number_format($receiptsToday) }}</h3>
                        <p>Receipts Today</p>
                    </div>
                    <div class="icon"><i class="fas fa-clipboard-check"></i></div>
                </div>
            </div>
            <div class="w-full lg:w-4/12 px-2">
                <div class="small-box bg-green-600 text-white">
                    <div class="inner">
                        <h3>{{ number_format($totalReceivedToday) }}</h3>
                        <p>Units Received Today</p>
                    </div>
                    <div class="icon"><i class="fas fa-dolly"></i></div>
                </div>
            </div>
            <div class="w-full lg:w-4/12 px-2">
                <div class="small-box bg-slate-500 text-white">
                    <div class="inner">
                        <h3 style="font-size: 1.45rem;">{{ $lastMovement->reference_no ?? 'None' }}</h3>
                        <p>Last GRN Reference</p>
                    </div>
                    <div class="icon"><i class="fas fa-receipt"></i></div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap -mx-2">
            <div class="w-full lg:w-4/12 px-2">
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
                    <div class="card-header border-b border-slate-200 px-4 py-2 bg-teal-700 text-white">
                        <h3 class="font-semibold mb-0">
                            <i class="fas fa-plus-circle mr-1"></i> Receive New Stock
                        </h3>
                    </div>
                    <form wire:submit="receiveStock">
                        <div class="card-body p-4">
                            <div class="mb-4">
                                <label>Find Product</label>
                                <input type="text" class="form-control ui-input" wire:model.live.debounce.300ms="productSearch" placeholder="Search product, batch or category...">
                            </div>

                            <div class="mb-4">
                                <label>Product <span class="text-red-700">*</span></label>
                                <select class="form-control ui-input @error('productId') is-invalid @enderror" wire:model.live="productId">
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
                                <label>Supplier</label>
                                <input type="text" class="form-control ui-input @error('supplier') is-invalid @enderror" wire:model="supplier" placeholder="Supplier name">
                                @error('supplier') <span class="ui-error">{{ $message }}</span> @enderror
                            </div>

                            <div class="flex flex-wrap -mx-2">
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label>Quantity <span class="text-red-700">*</span></label>
                                    <input type="number" min="1" class="form-control ui-input @error('quantity') is-invalid @enderror" wire:model="quantity">
                                    @error('quantity') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label>Cost Price</label>
                                    <input type="number" min="0" step="0.01" class="form-control ui-input @error('costPrice') is-invalid @enderror" wire:model="costPrice">
                                    @error('costPrice') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="mb-4">
                                <label>Batch Number</label>
                                <input type="text" class="form-control ui-input @error('batchNumber') is-invalid @enderror" wire:model="batchNumber">
                                @error('batchNumber') <span class="ui-error">{{ $message }}</span> @enderror
                            </div>

                            <div class="flex flex-wrap -mx-2">
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label>Manufacture Date</label>
                                    <input type="date" class="form-control ui-input @error('manufactureDate') is-invalid @enderror" wire:model="manufactureDate">
                                    @error('manufactureDate') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="mb-4 w-full md:w-6/12 px-2">
                                    <label>Expiry Date</label>
                                    <input type="date" class="form-control ui-input @error('expiryDate') is-invalid @enderror" wire:model="expiryDate">
                                    @error('expiryDate') <span class="ui-error">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="mb-0">
                                <label>Notes</label>
                                <textarea class="form-control ui-input @error('notes') is-invalid @enderror" rows="3" wire:model="notes" placeholder="Invoice number, delivery note, condition of goods..."></textarea>
                                @error('notes') <span class="ui-error">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="border-t border-slate-200 px-4 py-2 bg-white flex justify-between">
                            <button type="button" class="btn ui-button ui-button-secondary border border-slate-200" wire:click="resetReceiveForm">
                                <i class="fas fa-undo mr-1"></i> Reset
                            </button>
                            <button type="submit" class="btn ui-button ui-button-primary">
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
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
                    <div class="card-header border-b border-slate-200 px-4 py-2 bg-white">
                        <div class="flex flex-wrap -mx-2 items-end">
                            <div class="w-full md:w-5/12 px-2 mb-2">
                                <label class="text-sm text-slate-500 font-semibold">Search Movements</label>
                                <input type="text" class="form-control ui-input" wire:model.live.debounce.400ms="search" placeholder="Reference, product, supplier, batch...">
                            </div>
                            <div class="w-full md:w-4/12 px-2 mb-2"><label class="text-sm text-slate-500 font-semibold block">Date</label><x-date-range from="fromDate" to="toDate" presets="activity" clearable /></div>
                            <div class="w-full md:w-1/12 px-2 mb-2">
                                <button class="btn ui-button ui-button-secondary border border-slate-200 w-full" wire:click="$set('search','')" title="Clear search">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0">
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
