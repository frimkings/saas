<div class="clinic-ui ui-page space-y-6">
    <!-- Header -->
    <div class="ui-heading">
        <div>
            <h1>Optical Dashboard</h1>
            <p class="ui-muted">Today's metrics, ready collections, outstanding balances, and inventory alerts.</p>
        </div>
        <div class="ui-actions">
            <a href="{{ route('optical.orders.create') }}" class="ui-button ui-button-primary">
                + New Order
            </a>
            <a href="{{ route('optical.pos') }}" class="ui-button ui-button-secondary">
                Walk-in POS Sale
            </a>
        </div>
    </div>

    @if($overdueCount || $stuckCount)
        <a href="{{ route('optical.jobs') }}" class="ui-panel p-3 flex flex-wrap items-center justify-between gap-2 border-amber-200 bg-amber-50 text-amber-900 no-underline" role="status">
            <span class="text-sm"><strong>Jobs need attention:</strong>
                {{ $overdueCount }} overdue · {{ $stuckCount }} stuck</span>
            <span class="text-xs font-semibold underline">Open Job Tracking →</span>
        </a>
    @endif

    <!-- Stats Bar -->
    <div class="ui-stats">
        <div class="ui-panel ui-stat">
            <div class="flex items-center justify-between">
                <span class="ui-muted font-medium uppercase tracking-wider text-xs">Today's Orders</span>
                <span class="ui-badge">Active</span>
            </div>
            <div class="ui-value">{{ $todaysOrdersCount }}</div>
            <p class="ui-muted">New spectacle orders created today</p>
        </div>

        <div class="ui-panel ui-stat">
            <div class="flex items-center justify-between">
                <span class="ui-muted font-medium uppercase tracking-wider text-xs">Ready for Collection</span>
                <span class="ui-badge" style="background:#fff3d7; color:#795616;">{{ $readyCount }}</span>
            </div>
            <div class="ui-value text-amber-800">{{ $readyCount }}</div>
            <p class="ui-muted">Orders awaiting patient pickup</p>
        </div>

        <div class="ui-panel ui-stat">
            <div class="flex items-center justify-between">
                <span class="ui-muted font-medium uppercase tracking-wider text-xs">Outstanding Balances</span>
                <span class="ui-badge" style="background:#fee2e2; color:#991b1b;">{{ $outstandingCount }} Pending</span>
            </div>
            <div class="ui-value text-red-700">{{ currency() }} {{ number_format($outstandingTotal, 2) }}</div>
            <p class="ui-muted">Total uncollected balances</p>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Ready for Pickup Panel -->
        <div class="lg:col-span-2 ui-panel">
            <div class="ui-panel-heading">
                <h2 class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                    Ready for Collection
                </h2>
                <a href="{{ route('optical.orders', ['status' => 'Ready for Collection']) }}" class="text-xs font-semibold text-teal-700 hover:underline">View All →</a>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Frame / Specs</th>
                            <th class="ui-number">Balance Due</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($readyOrders as $order)
                            <tr>
                                <td class="font-semibold">{{ $order->order_id }}</td>
                                <td>{{ $order->display_customer_name }}</td>
                                <td class="ui-muted">{{ $order->frame_model_number ?: $order->serviceLines->pluck('description')->join(', ') }}</td>
                                <td class="ui-number font-bold {{ ($order->total - $order->paid_amount) > 0 ? 'text-red-700' : 'text-teal-700' }}">
                                    {{ currency() }} {{ number_format(max(0, $order->total - $order->paid_amount), 2) }}
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('optical.orders', ['search' => $order->order_id]) }}" class="ui-button ui-button-secondary py-1 text-xs">
                                        Collect & Pay
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="ui-empty">
                                    <p class="ui-muted">No orders currently ready for collection.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Low Stock & Quick Actions -->
        <div class="space-y-6">
            <div class="ui-panel p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-sm font-semibold text-slate-800">Low Stock Indicators</h2>
                    <span class="ui-badge" style="background:#fef3c7; color:#92400e;">{{ $lowStockCount }} Low</span>
                </div>
                <div class="space-y-2 text-xs">
                    @forelse($lowStockProducts as $product)
                        <div class="flex items-center justify-between border-b border-slate-100 pb-1">
                            <div>
                                <p class="font-medium text-slate-800">{{ $product->name }}</p>
                                <p class="ui-muted">{{ $product->category?->name }}</p>
                            </div>
                            <span class="font-bold text-red-600">{{ $product instanceof \App\Models\OpticalProduct ? ($product->stocks->first()?->quantity ?? 0) : app(\App\Services\Inventory\BranchInventoryService::class)->quantity($product) }} left</span>
                        </div>
                    @empty
                        <p class="ui-muted text-center py-2">Stock levels healthy.</p>
                    @endforelse
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="ui-panel p-4 space-y-3">
                <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-200 pb-2">Recent Activity</h2>
                <div class="space-y-2 text-xs">
                    @forelse($recentOrders as $order)
                        <div class="border-l-2 border-teal-600 pl-3 py-1">
                            <p class="font-semibold text-slate-800">Order {{ $order->order_id }}</p>
                            <p class="ui-muted">Status: {{ $order->status }}</p>
                            <span class="text-[10px] text-slate-400">{{ $order->created_at?->diffForHumans() }}</span>
                        </div>
                    @empty
                        <p class="ui-muted text-center py-2">No recent activity.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
