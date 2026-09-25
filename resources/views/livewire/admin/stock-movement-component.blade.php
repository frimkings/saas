<div class="content p-3">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0 text-primary font-weight-bold">Stock Receiving</h3>
                <small class="text-muted text-uppercase font-weight-bold">Goods received note and stock movement log</small>
            </div>
            <a href="{{ route('admin.product') }}" class="btn btn-outline-primary">
                <i class="fas fa-boxes mr-1"></i> Products
            </a>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3>{{ number_format($receiptsToday) }}</h3>
                        <p>Receipts Today</p>
                    </div>
                    <div class="icon"><i class="fas fa-clipboard-check"></i></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3>{{ number_format($totalReceivedToday) }}</h3>
                        <p>Units Received Today</p>
                    </div>
                    <div class="icon"><i class="fas fa-dolly"></i></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="small-box bg-secondary">
                    <div class="inner">
                        <h3 style="font-size: 1.45rem;">{{ $lastMovement->reference_no ?? 'None' }}</h3>
                        <p>Last GRN Reference</p>
                    </div>
                    <div class="icon"><i class="fas fa-receipt"></i></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-plus-circle mr-1"></i> Receive New Stock
                        </h3>
                    </div>
                    <form wire:submit="receiveStock">
                        <div class="card-body">
                            <div class="form-group">
                                <label>Find Product</label>
                                <input type="text" class="form-control" wire:model.live.debounce.300ms="productSearch" placeholder="Search product, batch or category...">
                            </div>

                            <div class="form-group">
                                <label>Product <span class="text-danger">*</span></label>
                                <select class="form-control @error('productId') is-invalid @enderror" wire:model.live="productId">
                                    <option value="">Select product...</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}">
                                            {{ $product->name }} | {{ $product->category->name ?? 'No category' }} | Qty: {{ $product->quantity }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('productId') <span class="invalid-feedback">{{ $message }}</span> @enderror
                            </div>

                            <div class="form-group">
                                <label>Supplier</label>
                                <input type="text" class="form-control @error('supplier') is-invalid @enderror" wire:model="supplier" placeholder="Supplier name">
                                @error('supplier') <span class="invalid-feedback">{{ $message }}</span> @enderror
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Quantity <span class="text-danger">*</span></label>
                                    <input type="number" min="1" class="form-control @error('quantity') is-invalid @enderror" wire:model="quantity">
                                    @error('quantity') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Cost Price</label>
                                    <input type="number" min="0" step="0.01" class="form-control @error('costPrice') is-invalid @enderror" wire:model="costPrice">
                                    @error('costPrice') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Batch Number</label>
                                <input type="text" class="form-control @error('batchNumber') is-invalid @enderror" wire:model="batchNumber">
                                @error('batchNumber') <span class="invalid-feedback">{{ $message }}</span> @enderror
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Manufacture Date</label>
                                    <input type="date" class="form-control @error('manufactureDate') is-invalid @enderror" wire:model="manufactureDate">
                                    @error('manufactureDate') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Expiry Date</label>
                                    <input type="date" class="form-control @error('expiryDate') is-invalid @enderror" wire:model="expiryDate">
                                    @error('expiryDate') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <label>Notes</label>
                                <textarea class="form-control @error('notes') is-invalid @enderror" rows="3" wire:model="notes" placeholder="Invoice number, delivery note, condition of goods..."></textarea>
                                @error('notes') <span class="invalid-feedback">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="card-footer bg-white d-flex justify-content-between">
                            <button type="button" class="btn btn-light border" wire:click="resetReceiveForm">
                                <i class="fas fa-undo mr-1"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-success">
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

            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white">
                        <div class="row align-items-end">
                            <div class="col-md-5 mb-2">
                                <label class="small text-muted font-weight-bold">Search Movements</label>
                                <input type="text" class="form-control" wire:model.live.debounce.400ms="search" placeholder="Reference, product, supplier, batch...">
                            </div>
                            <div class="col-md-4 mb-2"><label class="small text-muted font-weight-bold d-block">Date</label><x-date-range from="fromDate" to="toDate" presets="activity" clearable /></div>
                            <div class="col-md-1 mb-2">
                                <button class="btn btn-light border btn-block" wire:click="$set('search','')" title="Clear search">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
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
                                            <small class="text-muted">{{ $movement->created_at->format('M d, Y h:i A') }}</small>
                                        </td>
                                        <td>
                                            <strong>{{ $movement->product->name ?? 'Deleted product' }}</strong><br>
                                            <small class="text-muted">
                                                {{ $movement->product->category->name ?? 'No category' }}
                                                @if($movement->batch_number)
                                                    | Batch: {{ $movement->batch_number }}
                                                @endif
                                            </small>
                                        </td>
                                        <td>{{ $movement->supplier ?: 'N/A' }}</td>
                                        <td class="text-center">
                                            <span class="badge badge-success">+{{ number_format($movement->quantity) }}</span>
                                        </td>
                                        <td class="text-center">
                                            <small class="text-muted">{{ number_format($movement->quantity_before) }}</small>
                                            <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                            <strong>{{ number_format($movement->quantity_after) }}</strong>
                                        </td>
                                        <td class="text-right">
                                            {{ $movement->cost_price !== null ? currency() . ' ' . number_format($movement->cost_price, 2) : 'N/A' }}
                                        </td>
                                        <td>{{ optional($movement->expiry_date)->format('M d, Y') ?: 'N/A' }}</td>
                                        <td>
                                            {{ $movement->user->name ?? 'System' }}
                                            @if($movement->notes)
                                                <br><small class="text-muted">{{ \Illuminate\Support\Str::limit($movement->notes, 45) }}</small>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-5">
                                            <i class="fas fa-clipboard-list fa-2x mb-2 d-block"></i>
                                            No stock movements found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white">
                        {{ $movements->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
