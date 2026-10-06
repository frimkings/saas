<div class="clinic-ui ui-page">
    <div class="w-full">
        <h3 class="mb-0 text-teal-700 font-semibold">Inventory Alerts</h3>
        <small class="text-slate-500 uppercase font-semibold">Low stock and expiry monitoring</small>

        <div class="flex flex-wrap -mx-2 mt-4">
            <div class="w-full md:w-4/12 px-2"><button class="info-box btn ui-button w-full text-left {{ $activeTab === 'low' ? 'bg-amber-400' : 'bg-white' }}" wire:click="$set('activeTab','low')"><span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span><span class="info-box-content"><span class="info-box-text">Low Stock</span><span class="info-box-number">{{ $lowCount }}</span></span></button></div>
            <div class="w-full md:w-4/12 px-2"><button class="info-box btn ui-button w-full text-left {{ $activeTab === 'expiring' ? 'bg-sky-600 text-white' : 'bg-white' }}" wire:click="$set('activeTab','expiring')"><span class="info-box-icon"><i class="fas fa-clock"></i></span><span class="info-box-content"><span class="info-box-text">Expiring Soon</span><span class="info-box-number">{{ $expiringCount }}</span></span></button></div>
            <div class="w-full md:w-4/12 px-2"><button class="info-box btn ui-button w-full text-left {{ $activeTab === 'expired' ? 'bg-red-600 text-white' : 'bg-white' }}" wire:click="$set('activeTab','expired')"><span class="info-box-icon"><i class="fas fa-times-circle"></i></span><span class="info-box-content"><span class="info-box-text">Expired</span><span class="info-box-number">{{ $expiredCount }}</span></span></button></div>
        </div>

        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mt-4">
            <div class="card-body p-4">
                <div class="flex flex-wrap -mx-2 mb-4">
                    <div class="w-full md:w-6/12 px-2"><input class="form-control ui-input" wire:model.live.debounce.400ms="search" placeholder="Search product, batch or category..."></div>
                    <div class="w-full md:w-3/12 px-2">
                        <select class="form-control ui-input" wire:model.live="expiryWindow">
                            <option value="30">30-day expiry window</option>
                            <option value="60">60-day expiry window</option>
                            <option value="90">90-day expiry window</option>
                            <option value="120">120-day expiry window</option>
                        </select>
                    </div>
                </div>
                <div class="ui-table-wrap">
                    <table class="table ui-table">
                        <thead class=""><tr><th>Product</th><th>Category</th><th>Batch</th><th class="text-center">Qty</th><th class="text-right">Cost</th><th class="text-right">Selling</th><th>Expiry</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse($products as $product)
                                @php
                                    $expired = $product->expiry_date->lt(now());
                                    $days = $expired ? 0 : now()->startOfDay()->diffInDays($product->expiry_date->startOfDay());
                                @endphp
                                <tr>
                                    <td><strong>{{ $product->name }}</strong></td>
                                    <td><span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800">{{ $product->category->name ?? 'N/A' }}</span></td>
                                    <td><code>{{ $product->batch_number }}</code></td>
                                    <td class="text-center"><span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $product->quantity <= 10 ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' }}">{{ $product->quantity }}</span></td>
                                    <td class="text-right">{{ currency() }} {{ number_format($product->cost_price, 2) }}</td>
                                    <td class="text-right font-semibold">{{ currency() }} {{ number_format($product->selling_price, 2) }}</td>
                                    <td>{{ $product->expiry_date->format('M d, Y') }}</td>
                                    <td>
                                        @if($expired)
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800">Expired</span>
                                        @elseif($days <= (int) $expiryWindow)
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800">{{ $days }} day(s) left</span>
                                        @else
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">Low stock</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-slate-500 py-6">No products match this alert.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="border-t border-slate-200 px-4 py-2 bg-white">{{ $products->links() }}</div>
        </div>
    </div>
</div>
