<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading">
        <div>
            <h1>Optical Reports & Analytics</h1>
            <p class="ui-muted">Sales, order volumes, outstanding balances, lab turnaround time, and category breakdowns.</p>
        </div>
        <div class="ui-actions">
            <x-date-range from="fromDate" to="toDate" presets="finance" align="right" />
        </div>
    </div>

    <!-- Stats summary -->
    <div class="ui-stats">
        <div class="ui-panel ui-stat">
            <span class="ui-muted font-medium text-xs uppercase tracking-wider">Total Revenue</span>
            <div class="ui-value text-teal-800">{{ currency() }} {{ number_format($totalRevenue, 2) }}</div>
            <p class="ui-muted">Orders and retail sales</p>
        </div>

        <div class="ui-panel ui-stat">
            <span class="ui-muted font-medium text-xs uppercase tracking-wider">Payments Collected</span>
            <div class="ui-value text-emerald-700">{{ currency() }} {{ number_format($totalCollected, 2) }}</div>
            <p class="ui-muted">Deposits & balance payments</p>
        </div>

        <div class="ui-panel ui-stat">
            <span class="ui-muted font-medium text-xs uppercase tracking-wider">Outstanding Balances</span>
            <div class="ui-value text-red-700">{{ currency() }} {{ number_format($totalOutstanding, 2) }}</div>
            <p class="ui-muted">Uncollected customer debts</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="ui-panel p-5 space-y-3">
            <h2 class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2">Order Volume Breakdown</h2>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Total Orders Placed:</span>
                    <span class="font-bold">{{ $totalOrders }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Average Order Value:</span>
                    <span class="font-bold">{{ currency() }} {{ number_format($totalOrders > 0 ? $orderRevenue / $totalOrders : 0, 2) }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Average Lab Turnaround Time:</span>
                    <span class="font-bold text-teal-700">{{ $turnaround === null ? '—' : number_format($turnaround, 1).' days' }}</span>
                </div>
            </div>
        </div>

        <div class="ui-panel p-5 space-y-3">
            <h2 class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2">Category Mix</h2>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Frames Revenue:</span>
                    <span class="font-bold">{{ currency() }} {{ number_format($frameRevenue, 2) }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Lenses & Coatings:</span>
                    <span class="font-bold">{{ currency() }} {{ number_format($lensRevenue, 2) }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Optical Services:</span>
                    <span class="font-bold">{{ currency() }} {{ number_format($serviceRevenue, 2) }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Order Discounts:</span>
                    <span class="font-bold">− {{ currency() }} {{ number_format($discountTotal, 2) }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span>Accessories & POS:</span>
                    <span class="font-bold">{{ currency() }} {{ number_format($retailRevenue, 2) }} ({{ $retailCount }} sales)</span>
                </div>
            </div>
        </div>
    </div>
    <div class="ui-panel p-5 space-y-3">
        <h2 class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2">Remakes &amp; Refunds</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2 text-xs">
            <div class="flex justify-between py-1 border-b border-slate-100"><span>Remakes:</span><span class="font-bold">{{ $remakeCount }} ({{ number_format($remakeRate, 1) }}% of orders)</span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span>Free remakes:</span><span class="font-bold">{{ $freeRemakeCount }}</span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span>Lens cost of free remakes:</span><span class="font-bold text-red-700">{{ currency() }} {{ number_format($remakeLensCost, 2) }}</span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span>Remake reasons:</span><span class="font-bold text-right">@forelse($remakeReasons as $reason => $count){{ \App\Models\LensOrder::REMAKE_REASONS[$reason] ?? $reason }} ({{ $count }})@if(! $loop->last), @endif @empty — @endforelse</span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span>Orders refunded &amp; cancelled:</span><span class="font-bold">{{ $refundCount }} · {{ currency() }} {{ number_format($refundTotal, 2) }} refunded</span></div>
            <div class="flex justify-between py-1 border-b border-slate-100"><span>Cancellation fees kept:</span><span class="font-bold">{{ currency() }} {{ number_format($cancellationFees, 2) }}</span></div>
        </div>
        <p class="text-[11px] text-slate-500">Lens cost counts stock lenses at cost price; special-order lenses for remakes are not costed.</p>
    </div>
    <div class="ui-panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 border-b border-slate-200 pb-2 mb-3">Top Optical SKUs</h2>
        <div class="ui-table-wrap"><table class="ui-table w-full"><thead><tr><th>SKU</th><th>Product</th><th>Units sold</th><th>Gross sales</th></tr></thead><tbody>
            @forelse($topProducts as $line)
                <tr><td class="font-mono text-xs">{{ $line->opticalProduct?->sku ?? '—' }}</td><td>{{ $line->opticalProduct?->name ?? 'Archived product' }}</td><td>{{ $line->units }}</td><td>{{ currency() }} {{ number_format((float) $line->gross, 2) }}</td></tr>
            @empty<tr><td colspan="4" class="ui-empty">No optical SKU sales in this period.</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
