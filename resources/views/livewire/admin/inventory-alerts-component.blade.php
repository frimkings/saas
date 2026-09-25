<div class="content p-3">
    <div class="container-fluid">
        <h3 class="mb-0 text-primary font-weight-bold">Inventory Alerts</h3>
        <small class="text-muted text-uppercase font-weight-bold">Low stock and expiry monitoring</small>

        <div class="row mt-3">
            <div class="col-md-4"><button class="info-box btn btn-block text-left {{ $activeTab === 'low' ? 'bg-warning' : 'bg-white' }}" wire:click="$set('activeTab','low')"><span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span><span class="info-box-content"><span class="info-box-text">Low Stock</span><span class="info-box-number">{{ $lowCount }}</span></span></button></div>
            <div class="col-md-4"><button class="info-box btn btn-block text-left {{ $activeTab === 'expiring' ? 'bg-info' : 'bg-white' }}" wire:click="$set('activeTab','expiring')"><span class="info-box-icon"><i class="fas fa-clock"></i></span><span class="info-box-content"><span class="info-box-text">Expiring Soon</span><span class="info-box-number">{{ $expiringCount }}</span></span></button></div>
            <div class="col-md-4"><button class="info-box btn btn-block text-left {{ $activeTab === 'expired' ? 'bg-danger' : 'bg-white' }}" wire:click="$set('activeTab','expired')"><span class="info-box-icon"><i class="fas fa-times-circle"></i></span><span class="info-box-content"><span class="info-box-text">Expired</span><span class="info-box-number">{{ $expiredCount }}</span></span></button></div>
        </div>

        <div class="card shadow-sm border-0 mt-3">
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6"><input class="form-control" wire:model.live.debounce.400ms="search" placeholder="Search product, batch or category..."></div>
                    <div class="col-md-3">
                        <select class="form-control" wire:model.live="expiryWindow">
                            <option value="30">30-day expiry window</option>
                            <option value="60">60-day expiry window</option>
                            <option value="90">90-day expiry window</option>
                            <option value="120">120-day expiry window</option>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light"><tr><th>Product</th><th>Category</th><th>Batch</th><th class="text-center">Qty</th><th class="text-right">Cost</th><th class="text-right">Selling</th><th>Expiry</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse($products as $product)
                                @php
                                    $expired = $product->expiry_date->lt(now());
                                    $days = $expired ? 0 : now()->startOfDay()->diffInDays($product->expiry_date->startOfDay());
                                @endphp
                                <tr>
                                    <td><strong>{{ $product->name }}</strong></td>
                                    <td><span class="badge badge-info">{{ $product->category->name ?? 'N/A' }}</span></td>
                                    <td><code>{{ $product->batch_number }}</code></td>
                                    <td class="text-center"><span class="badge {{ $product->quantity <= 10 ? 'badge-warning' : 'badge-success' }}">{{ $product->quantity }}</span></td>
                                    <td class="text-right">{{ currency() }} {{ number_format($product->cost_price, 2) }}</td>
                                    <td class="text-right font-weight-bold">{{ currency() }} {{ number_format($product->selling_price, 2) }}</td>
                                    <td>{{ $product->expiry_date->format('M d, Y') }}</td>
                                    <td>
                                        @if($expired)
                                            <span class="badge badge-danger">Expired</span>
                                        @elseif($days <= (int) $expiryWindow)
                                            <span class="badge badge-info">{{ $days }} day(s) left</span>
                                        @else
                                            <span class="badge badge-warning">Low stock</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">No products match this alert.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white">{{ $products->links() }}</div>
        </div>
    </div>
</div>
