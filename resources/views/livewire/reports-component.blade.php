<div class="clinic-ui ui-page reports-wrap">
<div>

    {{-- ═══════════════════════════ MAIN CONTENT ═══════════════════════════ --}}
    <div>
        <div>

            {{-- PAGE HEADER --}}
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h4 class="font-semibold mb-0">{{ \App\Support\FinanceStatements::hasBothLines() ? 'Clinic Sales Reports' : 'Sales Reports' }}</h4>
                    @if(\App\Support\FinanceStatements::hasBothLines())
                        <p class="text-slate-500 text-sm mb-0">Clinic sales only. Optical sales are in the <a href="{{ route('optical.reports') }}">Optical reports</a>.</p>
                    @endif
                    <p class="text-slate-500 text-xs mb-0" title="Figures are kept for 5 minutes; Refresh updates them now."><i class="far fa-clock mr-1" aria-hidden="true"></i>Figures as of {{ $summary['computed_at'] ?? now()->format('H:i') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="exportCsv" class="ui-button ui-button-secondary ui-button-sm">
                        <i class="fas fa-file-csv mr-1"></i>Export CSV
                    </button>
                    <a
                        href="{{ route('reports.export.pdf', array_filter([
                            'from' => $fromDate, 'to' => $toDate, 'search' => $searchQuery,
                            'trash' => $activeTab === 'trash' ? 1 : null, 'show_refunded' => $showRefunded ? 1 : null,
                            'payment_status' => $paymentStatus, 'purchase_type' => $purchaseType, 'insurance' => $insuranceFilter,
                        ])) }}"
                        target="_blank"
                        class="ui-button ui-button-danger ui-button-sm"
                    >
                        <i class="fas fa-file-pdf mr-1"></i>Export PDF
                    </a>
                </div>
            </div>

            {{-- ── FILTERS (one row; wraps only on narrow screens) ── --}}
            @php
                $fl = 'mb-1 block truncate text-xs font-semibold uppercase tracking-wide text-slate-500';
                $fs = 'ui-input !py-1.5 !text-sm';
                $inTrash = $activeTab === 'trash';
            @endphp
            <div class="reports-filters mb-6 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm lg:flex-nowrap">
                <div class="w-44 shrink-0">
                    <span class="{{ $fl }}">Period</span>
                    <x-date-range from="fromDate" to="toDate" presets="finance" class="w-full" />
                </div>
                <div class="min-w-[8rem] flex-[2]">
                    <label for="reports-search" class="{{ $fl }}">Search</label>
                    <div class="relative">
                        <i class="fas fa-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                        <input id="reports-search" type="search" wire:model.live.debounce.500ms="searchQuery" class="{{ $fs }} !pl-8"
                               placeholder="{{ $analyticsView === 'items' ? 'Product…' : 'Transaction or patient…' }}">
                    </div>
                </div>
                <div class="min-w-[6rem] flex-1">
                    <label for="reports-payment" class="{{ $fl }}">Payment</label>
                    <select id="reports-payment" wire:model.live="paymentStatus" class="{{ $fs }}" @disabled($inTrash)>
                        <option value="">All</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="unpaid">Unpaid</option>
                    </select>
                </div>
                <div class="min-w-[6rem] flex-1">
                    <label for="reports-purchase" class="{{ $fl }}">Purchase</label>
                    <select id="reports-purchase" wire:model.live="purchaseType" class="{{ $fs }}" @disabled($inTrash)>
                        <option value="">All</option>
                        <option value="patient">Patient</option>
                        <option value="direct">Walk-in</option>
                    </select>
                </div>
                <div class="min-w-[6rem] flex-1">
                    <label for="reports-insurance" class="{{ $fl }}">Insurance</label>
                    <select id="reports-insurance" wire:model.live="insuranceFilter" class="{{ $fs }}" @disabled($inTrash)>
                        <option value="">All bills</option>
                        <option value="insured">Insured only</option>
                        <option value="uninsured">Uninsured only</option>
                    </select>
                </div>
                <div class="min-w-[6rem] flex-1">
                    <label for="reports-refunds" class="{{ $fl }}">Refunds</label>
                    <select id="reports-refunds" wire:model.live="refundMode" class="{{ $fs }} {{ $inTrash ? '!border-red-300 !bg-red-50 !text-red-700' : '' }}">
                        <option value="exclude">Exclude</option>
                        <option value="include">Include</option>
                        <option value="only">Refunded only</option>
                    </select>
                </div>
                <div class="w-16 shrink-0">
                    <label for="reports-per-page" class="{{ $fl }}">Rows</label>
                    <select id="reports-per-page" wire:model.live="perPage" class="{{ $fs }} !pl-2 !pr-6">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="flex shrink-0 gap-1">
                    <button type="button" wire:click="refreshData" wire:loading.attr="disabled" class="ui-button ui-button-secondary !px-2.5 !py-1.5" title="Refresh" aria-label="Refresh">
                        <i class="fas fa-sync-alt" wire:loading.class="fa-spin" wire:target="refreshData" aria-hidden="true"></i>
                    </button>
                    <button type="button" wire:click="resetFilters" class="ui-button ui-button-secondary !px-2.5 !py-1.5" title="Reset filters" aria-label="Reset filters">
                        <i class="fas fa-undo" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            {{-- ── ANALYTICS NAVIGATION ── --}}
            <div class="analytics-nav mb-4" role="tablist" aria-label="Report view">
                @foreach([
                    'overview'     => ['icon' => 'fa-tachometer-alt', 'label' => 'Overview'],
                    'items'        => ['icon' => 'fa-boxes',           'label' => 'Sales by Item'],
                    'categories'   => ['icon' => 'fa-tags',            'label' => 'Sales by Category'],
                    'payments'     => ['icon' => 'fa-chart-pie',       'label' => 'Payment Methods'],
                    'transactions' => ['icon' => 'fa-list-alt',        'label' => 'Transactions'],
                ] as $view => $meta)
                    <button
                        type="button" role="tab" aria-selected="{{ $analyticsView === $view ? 'true' : 'false' }}"
                        wire:click="switchAnalyticsView('{{ $view }}')"
                        class="analytics-nav__btn {{ $analyticsView === $view ? 'analytics-nav__btn--active' : '' }}"
                    >
                        <i class="fas {{ $meta['icon'] }} mr-2"></i>{{ $meta['label'] }}
                    </button>
                @endforeach
            </div>

            {{-- ── KPI CARDS (each with a "vs previous period" line) ── --}}
            @php
                $money = fn ($v) => currency() . ' ' . number_format($v, 2);
                $marginBadge = fn ($m) => $m >= 40 ? 'bg-green-100 text-green-800' : ($m >= 20 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800');
                $kpis = [
                    ['key' => 'count', 'icon' => 'fa-receipt', 'label' => 'Transactions', 'value' => number_format($summary['count']), 'colour' => 'text-slate-900', 'good' => 'up'],
                    ['key' => 'total_sales', 'icon' => 'fa-coins', 'label' => 'Net revenue', 'value' => $money($summary['total_sales']), 'colour' => 'text-green-700', 'good' => 'up'],
                    ['key' => 'cost_of_sales', 'icon' => 'fa-shopping-cart', 'label' => 'Cost of sales', 'value' => $money($summary['cost_of_sales']), 'colour' => 'text-red-700', 'good' => null],
                    ['key' => 'gross_profit', 'icon' => 'fa-chart-line', 'label' => 'Gross profit', 'value' => $money($summary['gross_profit']), 'colour' => 'text-teal-700', 'good' => 'up'],
                    ['key' => 'margin', 'icon' => 'fa-percentage', 'label' => 'Profit margin', 'value' => number_format($summary['margin'], 1) . '%', 'colour' => 'text-teal-700', 'good' => 'up', 'points' => true],
                    ['key' => 'avg_transaction', 'icon' => 'fa-calculator', 'label' => 'Avg transaction', 'value' => $money($summary['avg_transaction']), 'colour' => 'text-slate-900', 'good' => 'up'],
                ];
            @endphp
            <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                @foreach($kpis as $kpi)
                    @php
                        $change = null;
                        if ($previous) {
                            $now = (float) $summary[$kpi['key']];
                            $before = (float) $previous[$kpi['key']];
                            if (!empty($kpi['points'])) {
                                $diff = round($now - $before, 1);
                                $change = ['dir' => $diff <=> 0, 'text' => ($diff > 0 ? '+' : '') . number_format($diff, 1) . ' pts'];
                            } elseif ($before != 0.0) {
                                $pct = round(($now - $before) / abs($before) * 100);
                                $change = ['dir' => $pct <=> 0, 'text' => ($pct > 0 ? '+' : '') . number_format($pct) . '%'];
                            } elseif ($now != 0.0) {
                                $change = ['dir' => 1, 'text' => 'new'];
                            } else {
                                $change = ['dir' => 0, 'text' => 'no change'];
                            }
                        }
                        $tone = match (true) {
                            !$change || $change['dir'] === 0 || $kpi['good'] === null => 'text-slate-500',
                            $change['dir'] > 0 => 'text-green-700',
                            default => 'text-red-700',
                        };
                    @endphp
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="mb-1 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500"><i class="fas {{ $kpi['icon'] }} text-slate-400" aria-hidden="true"></i>{{ $kpi['label'] }}</p>
                        <p class="text-lg font-bold leading-tight {{ $kpi['colour'] }}">{{ $kpi['value'] }}</p>
                        @if($kpi['key'] === 'margin')
                            <div class="mt-2 h-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-teal-600" style="width:{{ max(0, min($summary['margin'], 100)) }}%"></div></div>
                        @endif
                        @if($change)
                            <p class="mt-1 text-xs {{ $tone }}" title="Previous period: {{ !empty($kpi['points']) ? number_format($previous[$kpi['key']], 1) . '%' : ($kpi['key'] === 'count' ? number_format($previous['count']) : $money($previous[$kpi['key']])) }}">
                                @if($change['dir'] > 0)<i class="fas fa-caret-up" aria-hidden="true"></i>@elseif($change['dir'] < 0)<i class="fas fa-caret-down" aria-hidden="true"></i>@endif
                                <span class="font-semibold">{{ $change['text'] }}</span> <span class="text-slate-400">{{ $previous['label'] }}</span>
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
            {{-- end KPI cards --}}

            {{-- Insurance: who pays the bills above, and what insurers paid, owe and did not pay --}}
            @if($insurance)
            <div class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3" style="gap:6px;">
                    <span class="font-semibold"><i class="fas fa-shield-alt text-sky-700 mr-1"></i>Insurance</span>
                    <a href="{{ route('admin.insurance.receivables') }}" class="text-sm">Insurer receivables &rarr;</a>
                </div>
                <div class="p-4 py-4">
                    <div class="flex flex-wrap -mx-2 text-center">
                        <div class="w-6/12 w-full md:w-auto md:flex-1 px-2 mb-2">
                            <div class="text-sm text-slate-500">Patients' share</div>
                            <div class="text-sm font-semibold mb-0">{{ currency() }} {{ number_format($insurance['patient'], 2) }}</div>
                        </div>
                        <div class="w-6/12 w-full md:w-auto md:flex-1 px-2 mb-2">
                            <div class="text-sm text-slate-500">Billed to insurers</div>
                            <div class="text-sm font-semibold mb-0 text-sky-700">{{ currency() }} {{ number_format($insurance['billed'], 2) }}</div>
                        </div>
                        <div class="w-6/12 w-full md:w-auto md:flex-1 px-2 mb-2">
                            <div class="text-sm text-slate-500">Received from insurers</div>
                            <div class="text-sm font-semibold mb-0 text-green-700">{{ currency() }} {{ number_format($insurance['received'], 2) }}</div>
                        </div>
                        <div class="w-6/12 w-full md:w-auto md:flex-1 px-2 mb-2">
                            <div class="text-sm text-slate-500">Insurer shortfalls written off</div>
                            <div class="text-sm font-semibold mb-0 text-red-700">{{ currency() }} {{ number_format($insurance['writtenOff'], 2) }}</div>
                        </div>
                        <div class="w-full md:w-auto md:flex-1 px-2 mb-2">
                            <div class="text-sm text-slate-500">Insurers owe now</div>
                            <div class="text-sm font-semibold mb-0 text-amber-600">{{ currency() }} {{ number_format($insurance['owedNow'], 2) }}</div>
                        </div>
                    </div>
                    <div class="text-sm text-slate-500 mt-1">
                        Net Revenue includes the insurers' share. Received and written off cover payments and write-offs dated in this period;
                        "owe now" is every insured bill still unpaid by its insurer, whatever its date.
                    </div>
                </div>
            </div>
            @endif

            {{-- ════════════════════════════════════════════════ --}}
            {{-- OVERVIEW TAB                                     --}}
            {{-- ════════════════════════════════════════════════ --}}
            @if($analyticsView === 'overview')

            <div class="flex flex-wrap -mx-2 mb-6">
                {{-- Revenue & Profit Trend Chart --}}
                <div class="w-full lg:w-8/12 px-2 mb-4 lg:mb-0">
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm h-full">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                            <h3 class="!mb-0 text-sm font-semibold text-slate-800"><i class="fas fa-chart-area mr-2 text-teal-700"></i>Revenue &amp; Profit Trend</h3>
                            <select wire:model.live="chartPeriod" class="ui-input ui-input-sm w-auto">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="p-4">
                            @if($activeTab === 'today')
                                <p class="mb-2 text-xs text-slate-500"><i class="fas fa-info-circle mr-1" aria-hidden="true"></i>Today is selected, so the chart shows the surrounding {{ ['weekly' => 'month', 'monthly' => 'year', 'yearly' => 'five years'][$chartPeriod] ?? 'week' }} for context.</p>
                            @endif
                            <div wire:ignore style="height:260px;">
                                <canvas id="salesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Top 5 Products --}}
                <div class="w-full lg:w-4/12 px-2">
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm h-full">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                            <h3 class="!mb-0 text-sm font-semibold text-slate-800"><i class="fas fa-trophy mr-2 text-amber-600"></i>Top 5 Products</h3>
                        </div>
                        <div class="p-0">
                            <div>
                                @forelse($this->topProducts(5) as $i => $item)
                                    <div class="block w-full border-b border-slate-100 text-left last:border-b-0 py-4 px-4">
                                        <div class="flex items-center">
                                            <span class="rank-badge rank-badge--{{ $i + 1 }} mr-4">{{ $i + 1 }}</span>
                                            <div class="grow min-w-0">
                                                <div class="font-semibold text-sm truncate">{{ $item->product->name ?? 'Unknown' }}</div>
                                                <small class="text-slate-500">{{ number_format($item->qty_sold) }} units sold</small>
                                            </div>
                                            <div class="text-right ml-2">
                                                <div class="font-semibold text-green-700 text-sm">{{ currency() }} {{ number_format($item->revenue, 2) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="block w-full px-3 text-center py-12 text-slate-500">
                                        <i class="fas fa-inbox fa-2x mb-2 block"></i>
                                        <small>No data for this period</small>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @endif
            {{-- end overview --}}

            {{-- ════════════════════════════════════════════════ --}}
            {{-- SALES BY ITEM TAB                                --}}
            {{-- ════════════════════════════════════════════════ --}}
            @if($analyticsView === 'items')

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <h3 class="!mb-0 text-sm font-semibold text-slate-800"><i class="fas fa-boxes mr-2 text-teal-700"></i>Sales by Item</h3>
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $salesByItems->count() }} products</span>
                </div>
                <div class="p-0">
                    @if($salesByItems->isNotEmpty())
                    @php
                        $maxRevenue = $salesByItems->max('revenue') ?: 1;
                    @endphp
                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0 analytics-table">
                            <thead class="">
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th class="text-center">Units Sold</th>
                                    <th class="text-right">Revenue</th>
                                    <th class="text-right">Cost of Sales</th>
                                    <th class="text-right">Gross Profit</th>
                                    <th class="text-center">Margin</th>
                                    <th>Revenue Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($salesByItems as $i => $item)
                                @php
                                    $share = $summary['total_sales'] > 0 ? ($item->revenue / $summary['total_sales']) * 100 : 0;
                                    $marginClass = $marginBadge($item->margin);
                                @endphp
                                <tr>
                                    <td class="text-slate-500 text-sm">{{ $i + 1 }}</td>
                                    <td>
                                        <div class="font-semibold">{{ $item->product->name ?? 'Unknown' }}</div>
                                        @if($item->product?->category)
                                            <small class="text-slate-500">{{ $item->product->category->name }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600">{{ number_format($item->qty_sold) }}</span>
                                    </td>
                                    <td class="text-right font-semibold text-green-700">{{ currency() }} {{ number_format($item->revenue, 2) }}</td>
                                    <td class="text-right text-red-700">{{ currency() }} {{ number_format($item->cost_of_sales, 2) }}</td>
                                    <td class="text-right font-semibold text-teal-700">{{ currency() }} {{ number_format($item->gross_profit, 2) }}</td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $marginClass }}">{{ number_format($item->margin, 1) }}%</span>
                                    </td>
                                    <td style="min-width:120px;">
                                        <div class="flex items-center">
                                            <div class="h-2 overflow-hidden rounded-full bg-slate-200 grow mr-2" style="height:6px;">
                                                <div class="h-full bg-teal-700 text-white" style="width:{{ $share }}%"></div>
                                            </div>
                                            <small class="text-slate-500" style="width:34px; text-align:right;">{{ number_format($share, 1) }}%</small>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50 font-semibold">
                                <tr>
                                    <td colspan="2" class="uppercase text-sm">Totals</td>
                                    <td class="text-center">{{ number_format($salesByItems->sum('qty_sold')) }}</td>
                                    <td class="text-right text-green-700">{{ currency() }} {{ number_format($salesByItems->sum('revenue'), 2) }}</td>
                                    <td class="text-right text-red-700">{{ currency() }} {{ number_format($salesByItems->sum('cost_of_sales'), 2) }}</td>
                                    <td class="text-right text-teal-700">{{ currency() }} {{ number_format($salesByItems->sum('gross_profit'), 2) }}</td>
                                    <td class="text-center">
                                        @php
                                            $totalRev = $salesByItems->sum('revenue');
                                            $avgMargin = $totalRev > 0 ? ($salesByItems->sum('gross_profit') / $totalRev) * 100 : 0;
                                        @endphp
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $marginBadge($avgMargin) }}">
                                            {{ number_format($avgMargin, 1) }}%
                                        </span>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @else
                    <div class="text-center py-12 text-slate-500">
                        <i class="fas fa-inbox fa-3x mb-4 block"></i>
                        <p class="mb-0">No item sales data for this period.</p>
                    </div>
                    @endif
                </div>
            </div>

            @endif
            {{-- end by item --}}

            {{-- ════════════════════════════════════════════════ --}}
            {{-- SALES BY CATEGORY TAB                            --}}
            {{-- ════════════════════════════════════════════════ --}}
            @if($analyticsView === 'categories')

            @php
                $catColors = ['#087e83', '#0284c7', '#16a34a', '#d97706', '#dc2626', '#64748b', '#4f46e5'];
            @endphp

            {{-- Category cards --}}
            <div class="flex flex-wrap -mx-2 mb-6">
                @forelse($salesByCategory as $ci => $cat)
                @php $color = $catColors[$ci % count($catColors)]; @endphp
                <div class="w-full xl:w-3/12 md:w-4/12 sm:w-6/12 px-2 mb-4">
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm h-full">
                        <div class="p-4">
                            <div class="flex items-center mb-4">
                                <div class="category-icon text-white mr-4" style="background:{{ $color }};">
                                    <i class="fas fa-tag"></i>
                                </div>
                                <div class="font-semibold">{{ $cat->category_name }}</div>
                            </div>
                            <div class="flex flex-wrap -mx-2 text-center">
                                <div class="w-6/12 px-2 border-r border-slate-200">
                                    <div class="text-green-700 font-semibold text-sm">{{ currency() }} {{ number_format($cat->revenue, 0) }}</div>
                                    <div class="text-slate-500" style="font-size:10px;">Revenue</div>
                                </div>
                                <div class="w-6/12 px-2">
                                    <div class="text-teal-700 font-semibold text-sm">{{ currency() }} {{ number_format($cat->gross_profit, 0) }}</div>
                                    <div class="text-slate-500" style="font-size:10px;">Profit</div>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div class="flex justify-between items-center">
                                <small class="text-slate-500">{{ number_format($cat->qty_sold) }} units · {{ $cat->transaction_count }} txns</small>
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $marginBadge($cat->margin) }}">
                                    {{ number_format($cat->margin, 1) }}%
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-200 mt-2" style="height:4px;">
                                <div class="h-full" style="width:{{ min($cat->margin,100) }}%; background:{{ $color }};"></div>
                            </div>
                        </div>
                    </div>
                </div>
                @empty
                <div class="w-full px-2 text-center py-12 text-slate-500">
                    <i class="fas fa-inbox fa-3x mb-4 block"></i>
                    <p>No category data for this period.</p>
                </div>
                @endforelse
            </div>

            {{-- Category detail table --}}
            @if($salesByCategory->isNotEmpty())
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <h3 class="!mb-0 text-sm font-semibold text-slate-800"><i class="fas fa-table mr-2 text-teal-700"></i>Category Breakdown</h3>
                </div>
                <div class="p-0">
                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0 analytics-table">
                            <thead class="">
                                <tr>
                                    <th>Category</th>
                                    <th class="text-center">Transactions</th>
                                    <th class="text-center">Units Sold</th>
                                    <th class="text-right">Revenue</th>
                                    <th class="text-right">Cost of Sales</th>
                                    <th class="text-right">Gross Profit</th>
                                    <th class="text-center">Margin</th>
                                    <th>Revenue Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($salesByCategory as $ci => $cat)
                                @php
                                    $share = $summary['total_sales'] > 0 ? ($cat->revenue / $summary['total_sales']) * 100 : 0;
                                    $color = $catColors[$ci % count($catColors)];
                                @endphp
                                <tr>
                                    <td>
                                        <span class="mr-1 inline-block h-2.5 w-2.5 rounded-full align-middle" style="background:{{ $color }};"></span>
                                        <span class="font-semibold">{{ $cat->category_name }}</span>
                                    </td>
                                    <td class="text-center">{{ number_format($cat->transaction_count) }}</td>
                                    <td class="text-center">{{ number_format($cat->qty_sold) }}</td>
                                    <td class="text-right font-semibold text-green-700">{{ currency() }} {{ number_format($cat->revenue, 2) }}</td>
                                    <td class="text-right text-red-700">{{ currency() }} {{ number_format($cat->cost_of_sales, 2) }}</td>
                                    <td class="text-right font-semibold text-teal-700">{{ currency() }} {{ number_format($cat->gross_profit, 2) }}</td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $marginBadge($cat->margin) }}">{{ number_format($cat->margin, 1) }}%</span>
                                    </td>
                                    <td style="min-width:120px;">
                                        <div class="flex items-center">
                                            <div class="h-2 overflow-hidden rounded-full bg-slate-200 grow mr-2" style="height:6px;">
                                                <div class="h-full" style="width:{{ $share }}%; background:{{ $color }};"></div>
                                            </div>
                                            <small class="text-slate-500" style="width:34px; text-align:right;">{{ number_format($share, 1) }}%</small>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50 font-semibold">
                                <tr>
                                    <td class="uppercase text-sm">Totals</td>
                                    <td class="text-center">—</td>
                                    <td class="text-center">{{ number_format($salesByCategory->sum('qty_sold')) }}</td>
                                    <td class="text-right text-green-700">{{ currency() }} {{ number_format($salesByCategory->sum('revenue'), 2) }}</td>
                                    <td class="text-right text-red-700">{{ currency() }} {{ number_format($salesByCategory->sum('cost_of_sales'), 2) }}</td>
                                    <td class="text-right text-teal-700">{{ currency() }} {{ number_format($salesByCategory->sum('gross_profit'), 2) }}</td>
                                    <td class="text-center">—</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            @endif
            {{-- end by category --}}

            {{-- ════════════════════════════════════════════════ --}}
            {{-- PAYMENT METHODS TAB                               --}}
            {{-- ════════════════════════════════════════════════ --}}
            @if($analyticsView === 'payments')

            @php
                $pmTotal = $paymentMethods->sum('total');
                $pmChartLabels = $paymentMethods->pluck('label')->values()->toArray();
                $pmChartData   = $paymentMethods->map(fn($p) => round((float)$p->total, 2))->values()->toArray();
                $pmChartColors = $paymentMethods->pluck('color')->values()->toArray();
            @endphp

            <div class="flex flex-wrap -mx-2">
                {{-- Donut Chart --}}
                <div class="w-full lg:w-5/12 px-2 mb-6 lg:mb-0">
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm h-full">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                            <h3 class="!mb-0 text-sm font-semibold text-slate-800"><i class="fas fa-chart-pie mr-2 text-teal-700"></i>How We Receive Payments</h3>
                        </div>
                        <div class="p-4 flex items-center justify-center">
                            @if($paymentMethods->isNotEmpty())
                                <div style="position:relative; height:280px; width:100%;">
                                    <canvas id="pmDonutChart"></canvas>
                                </div>
                            @else
                                <div class="text-center py-12 text-slate-500">
                                    <i class="fas fa-chart-pie fa-3x mb-4 block" style="opacity:.25;"></i>
                                    <p class="mb-0">No payment data for this period.</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Breakdown Table --}}
                <div class="w-full lg:w-7/12 px-2">
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm h-full">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                            <h3 class="!mb-0 text-sm font-semibold text-slate-800"><i class="fas fa-table mr-2 text-teal-700"></i>Payment Breakdown</h3>
                            @if($pmTotal > 0)
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600">{{ currency() }} {{ number_format($pmTotal, 2) }} total</span>
                            @endif
                        </div>
                        <div class="p-0">
                            @if($paymentMethods->isNotEmpty())
                            <div class="ui-table-wrap">
                                <table class="table ui-table mb-0 analytics-table">
                                    <thead class="">
                                        <tr>
                                            <th>Method</th>
                                            <th class="text-center">Transactions</th>
                                            <th class="text-right">Amount</th>
                                            <th class="text-center">Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($paymentMethods as $pm)
                                        @php $share = $pmTotal > 0 ? ($pm->total / $pmTotal) * 100 : 0; @endphp
                                        <tr>
                                            <td>
                                                <span class="inline-block rounded-full mr-2" style="width:10px;height:10px;background:{{ $pm->color }};"></span>
                                                <span class="font-semibold">{{ $pm->label }}</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600">{{ number_format($pm->cnt) }}</span>
                                            </td>
                                            <td class="text-right font-semibold text-green-700">{{ currency() }} {{ number_format($pm->total, 2) }}</td>
                                            <td style="min-width:110px;">
                                                <div class="flex items-center">
                                                    <div class="h-2 overflow-hidden rounded-full bg-slate-200 grow mr-2" style="height:6px;">
                                                        <div class="h-full bg-teal-600" style="width:{{ $share }}%; background:{{ $pm->color }};"></div>
                                                    </div>
                                                    <small class="text-slate-500" style="width:36px; text-align:right;">{{ number_format($share, 1) }}%</small>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="bg-slate-50 font-semibold">
                                        <tr>
                                            <td>Total</td>
                                            <td class="text-center">{{ number_format($paymentMethods->sum('cnt')) }}</td>
                                            <td class="text-right text-green-700">{{ currency() }} {{ number_format($pmTotal, 2) }}</td>
                                            <td class="text-center">100%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            @else
                            <div class="text-center py-12 text-slate-500">
                                <i class="fas fa-inbox fa-3x mb-4 block"></i>
                                <p class="mb-0">No payment data for this period.</p>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if($paymentMethods->isNotEmpty())
            <script>
            (function() {
                var canvas = document.getElementById('pmDonutChart');
                if (!canvas) return;
                if (typeof Chart !== 'undefined' && Chart.getChart) {
                    var ex = Chart.getChart(canvas);
                    if (ex) ex.destroy();
                }
                var labels = @json($pmChartLabels);
                var data   = @json($pmChartData);
                var colors = @json($pmChartColors);
                var total  = data.reduce(function(a, b) { return a + b; }, 0);
                new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: colors,
                            borderWidth: 3,
                            borderColor: '#fff',
                            hoverOffset: 8,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '60%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    usePointStyle: true,
                                    padding: 18,
                                    font: { size: 12, weight: '600' },
                                    generateLabels: function(chart) {
                                        var ds = chart.data.datasets[0];
                                        return chart.data.labels.map(function(label, i) {
                                            var val = ds.data[i].toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2});
                                            return {
                                                text: label + '  {{ currency() }} ' + val,
                                                fillStyle: ds.backgroundColor[i],
                                                strokeStyle: ds.backgroundColor[i],
                                                pointStyle: 'circle',
                                                index: i,
                                            };
                                        });
                                    },
                                },
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0,0,0,.8)',
                                padding: 12,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(ctx) {
                                        var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                                        return ' ' + ctx.label + ': {{ currency() }} ' + ctx.parsed.toLocaleString('en-US', {minimumFractionDigits:2}) + ' (' + pct + '%)';
                                    },
                                },
                            },
                        },
                    },
                });
            })();
            </script>
            @endif

            @endif
            {{-- end payment methods --}}

            {{-- ════════════════════════════════════════════════ --}}
            {{-- TRANSACTIONS TAB                                  --}}
            {{-- ════════════════════════════════════════════════ --}}
            @if($analyticsView === 'transactions')

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <h3 class="!mb-0 text-sm font-semibold text-slate-800">
                        <i class="fas fa-list-alt mr-2 text-teal-700"></i>
                        {{ $activeTab === 'trash' ? 'Refunded Transactions' : 'Transactions' }}
                    </h3>
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $sales->total() }} total</span>
                </div>
                <div class="p-0">
                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0 analytics-table">
                            <thead class="">
                                <tr>
                                    <th class="uppercase text-sm">Status / Date</th>
                                    <th class="uppercase text-sm">Patient &amp; Transaction</th>
                                    <th class="uppercase text-sm text-right">Amount</th>
                                    <th class="uppercase text-sm text-right">Profit</th>
                                    <th class="uppercase text-sm text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($sales as $sale)
                                <tr>
                                    <td>
                                        @if($sale->is_refunded)
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 mb-1">REFUNDED</span>
                                        @else
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800 mb-1">COMPLETED</span>
                                        @endif
                                        <div class="text-sm text-slate-500">{{ $sale->created_at->format('M d, Y') }}</div>
                                        <div class="text-sm text-slate-500">{{ $sale->created_at->format('h:i A') }}</div>
                                    </td>
                                    <td>
                                        <div class="font-semibold">{{ $sale->customer_display_name }}</div>
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $sale->patient_id ? 'bg-teal-100 text-teal-800' : 'bg-green-100 text-green-800' }}">
                                            {{ $sale->patient_id ? 'Patient Purchase' : 'Direct Purchase' }}
                                        </span>
                                        <small class="text-slate-500">#{{ $sale->transaction_id }}</small>
                                    </td>
                                    <td class="text-right font-semibold">{{ currency() }} {{ number_format($sale->total_amount, 2) }}</td>
                                    <td class="text-right">
                                        <span class="font-semibold {{ $sale->profit > 0 ? 'text-green-700' : 'text-red-700' }}">
                                            {{ currency() }} {{ number_format($sale->profit, 2) }}
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <div class="inline-flex flex-wrap gap-1">
                                            <button wire:click="showItemsModal({{ $sale->id }})" class="ui-button ui-button-secondary" title="View Items">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            @if($sale->is_refunded)
                                                <button wire:click="showRefundDetailsModal({{ $sale->id }})" class="ui-button ui-button-secondary" title="Refund Details">
                                                    <i class="fas fa-info-circle"></i>
                                                </button>
                                            @else
                                                <button wire:click="showRefundModal({{ $sale->id }})" class="ui-button ui-button-secondary" title="Request Refund">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center py-12">
                                        <i class="fas fa-inbox fa-3x text-slate-500 mb-4 block"></i>
                                        <p class="text-slate-500 mb-0">No results for the current filters.</p>
                                        @if($searchQuery || $showRefunded)
                                            <button wire:click="resetFilters" class="ui-button ui-button-sm ui-button-secondary mt-2">
                                                Clear Filters
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($sales->hasPages())
                <div class="border-t border-slate-200 bg-white px-4 py-3">
                    {{ $sales->links() }}
                </div>
                @endif
            </div>

            @endif
            {{-- end transactions --}}

        </div>
    </div>
    {{-- end main content --}}

</div>
{{-- end row --}}

{{-- ═══════════════════════════ MODALS ═══════════════════════════ --}}

{{-- View Items Modal --}}
<div wire:ignore.self class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="itemsModal" tabindex="-1" role="dialog">
    <div class="mx-auto my-8 w-full max-w-3xl" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-teal-700 text-white">
                <h5 class="text-base font-semibold">
                    <i class="fas fa-receipt mr-2"></i>Transaction #{{ $viewingSale->transaction_id ?? '' }}
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="closeItemsModal"><span>&times;</span></button>
            </div>
            <div class="p-0">
                @if($viewingSale)
                <div class="px-6 pt-4 pb-1 bg-slate-50 border-b border-slate-200 flex justify-between">
                    <div><span class="text-slate-500 text-sm">Customer:</span> <strong>{{ $viewingSale->customer_display_name }}</strong></div>
                    <div><span class="text-slate-500 text-sm">Date:</span> <strong>{{ $viewingSale->created_at->format('M d, Y h:i A') }}</strong></div>
                </div>
                <div class="ui-table-wrap">
                    <table class="table ui-table mb-0 analytics-table">
                        <thead class="">
                            <tr>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($viewingSale->items as $item)
                            @php
                                $qty       = $item->dispensed_quantity ?? $item->quantity ?? 1;
                                $unitPrice = $item->unit_price ?? $item->price ?? ($qty > 0 ? $item->subtotal / $qty : 0);
                            @endphp
                            <tr>
                                <td>
                                    <div class="font-semibold">{{ $item->product->name ?? 'N/A' }}</div>
                                    @if(isset($item->product->description) && $item->product->description)
                                        <small class="text-slate-500">{{ Str::limit($item->product->description, 50) }}</small>
                                    @endif
                                </td>
                                <td class="text-center">{{ $qty }}</td>
                                <td class="text-right">{{ currency() }} {{ number_format($unitPrice, 2) }}</td>
                                <td class="text-right font-semibold">{{ currency() }} {{ number_format($item->subtotal, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-slate-50">
                            <tr class="font-semibold">
                                <td colspan="3" class="text-right uppercase text-sm">Total</td>
                                <td class="text-right text-green-700">{{ currency() }} {{ number_format($viewingSale->total_amount, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Refund Request Modal --}}
<div wire:ignore.self class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="refundModal" tabindex="-1" role="dialog">
    <div class="mx-auto my-8 w-full max-w-lg" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-amber-400 text-slate-900">
                <h5 class="text-base font-semibold"><i class="fas fa-undo mr-2"></i>Request Refund</h5>
                <button type="button" class="text-xl leading-none text-slate-500 hover:text-slate-800" wire:click="cancelRefund"><span>&times;</span></button>
            </div>
            <div class="p-4">
                @if($refundingSale)
                <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900">
                    Submitting a refund request for
                    <strong>#{{ $refundingSale->transaction_id }}</strong>
                    &mdash; {{ $refundingSale->items->count() }} item(s).
                    A manager will review and approve before the refund is executed.
                </div>
                <div class="mb-0">
                    <label class="font-semibold">Reason for Refund <span class="text-red-700">*</span></label>
                    <textarea
                        wire:model="refundReason"
                        class="ui-input @error('refundReason') is-invalid @enderror"
                        rows="4"
                        placeholder="Provide a detailed reason for this refund…"
                    ></textarea>
                    @error('refundReason')<div class="ui-error">{{ $message }}</div>@enderror
                    <small class="mt-1 block text-xs text-slate-500">Minimum 10 characters required</small>
                </div>
                @endif
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3 bg-slate-50">
                <button type="button" class="ui-button ui-button-secondary" wire:click="cancelRefund">Cancel</button>
                <button type="button" class="ui-button ui-button-secondary" wire:click="processRefund">
                    <i class="fas fa-paper-plane mr-2"></i>Submit Request
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Refund Details Modal --}}
<div wire:ignore.self class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="refundDetailsModal" tabindex="-1" role="dialog">
    <div class="mx-auto my-8 w-full max-w-lg" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <h5 class="text-base font-semibold"><i class="fas fa-info-circle mr-2 text-teal-700"></i>Refund Information</h5>
                <button type="button" class="text-xl leading-none text-slate-500 hover:text-slate-800" wire:click="closeRefundDetailsModal" aria-label="Close dialog"><span>&times;</span></button>
            </div>
            <div class="p-4">
                @if($viewingRefundSale)
                <div class="mb-4">
                    <label class="text-slate-500 text-sm uppercase font-semibold">Transaction ID</label>
                    <div class="text-base font-semibold mb-0">#{{ $viewingRefundSale->transaction_id }}</div>
                </div>
                <div class="mb-4">
                    <label class="text-slate-500 text-sm uppercase font-semibold">Refund Reason</label>
                    <div class="p-4 bg-slate-50 border border-slate-200 rounded-md text-sm">{{ $this->refundLog?->reason ?? $viewingRefundSale->refund_reason ?? '—' }}</div>
                </div>
                <div class="flex flex-wrap -mx-2">
                    <div class="w-6/12 px-2">
                        <label class="text-slate-500 text-sm uppercase font-semibold">Refunded At</label>
                        <div class="text-sm">{{ $viewingRefundSale->refunded_at?->format('M d, Y h:i A') ?? '—' }}</div>
                    </div>
                    <div class="w-6/12 px-2">
                        <label class="text-slate-500 text-sm uppercase font-semibold">Processed By</label>
                        <div class="text-sm">{{ $viewingRefundSale->refundedBy->name ?? 'System' }}</div>
                    </div>
                </div>
                @if($this->refundLog)
                    <hr>
                    <small class="text-slate-500">Refund Log ID: {{ $this->refundLog->id }}</small>
                @endif
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════ SCRIPTS ═══════════════════════════ --}}

<script>
document.addEventListener('livewire:init', function () {
    /* ─── Chart ─── */
    let chart = null;

    function initSalesChart(d) {
        if (!d.labels || !d.labels.length) return;

        // Always look up the canvas fresh — it is removed from DOM when the user
        // navigates away from the Overview analytics tab and re-created on return.
        const canvas = document.getElementById('salesChart');
        if (!canvas) return;

        const data = {
            labels: d.labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: d.revenue,
                    backgroundColor: 'rgba(40,167,69,0.75)',
                    borderColor: '#28a745',
                    borderWidth: 1.5,
                    borderRadius: 4,
                    barPercentage: 0.75,
                    categoryPercentage: 0.6,
                },
                {
                    label: 'Profit',
                    data: d.profit,
                    backgroundColor: 'rgba(23,162,184,0.75)',
                    borderColor: '#17a2b8',
                    borderWidth: 1.5,
                    borderRadius: 4,
                    barPercentage: 0.75,
                    categoryPercentage: 0.6,
                },
            ],
        };

        // Reuse existing chart only when it is still attached to the same canvas.
        // After an analytics-tab round-trip the canvas is a new element, so destroy
        // the stale instance and create a fresh one.
        if (chart && chart.canvas === canvas) {
            chart.data = data;
            chart.update('active');
            return;
        }
        if (chart) { chart.destroy(); chart = null; }

        chart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { usePointStyle: true, padding: 16, font: { size: 12, weight: '600' } },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,.8)',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: ctx => ctx.dataset.label + ': {{ currency() }} ' +
                                ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.05)', borderDash: [5, 5] },
                        ticks: {
                            callback: v => '{{ currency() }} ' + v.toLocaleString(),
                            font: { size: 11 },
                        },
                    },
                },
            },
        });
    }

    // Initialize on page load from server-rendered data
    initSalesChart(@json($chartPayload));

    // Re-draw on filter/tab/date changes (Livewire AJAX re-render)
    window.addEventListener('update-chart', e => initSalesChart(e.detail));

    /* ─── Modal events ─── */
    window.addEventListener('show-itemsModal-form',         () => uiModal('itemsModal', true));
    window.addEventListener('hide-itemsModal-modal',        () => uiModal('itemsModal', false));
    window.addEventListener('show-refundModal-form',        () => uiModal('refundModal', true));
    window.addEventListener('hide-refundModal-modal',       () => uiModal('refundModal', false));
    window.addEventListener('show-refundDetailsModal-form', () => uiModal('refundDetailsModal', true));
    window.addEventListener('hide-refundDetailsModal-modal',() => uiModal('refundDetailsModal', false));

    // Toasts for `notify` come from the layout (layouts/partials/toasts).
});
</script>

{{-- ═══════════════════════════ STYLES ═══════════════════════════ --}}
<style>
/* ── Filter bar ── */
.reports-filters .drp-trigger { min-width: 0; }

/* ── Analytics Nav ── */
.analytics-nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0;
}
.analytics-nav__btn {
    background: transparent;
    border: none;
    padding: .6rem 1rem;
    font-size: .85rem;
    font-weight: 500;
    color: #6c757d;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all .15s;
    border-radius: 0;
}
.analytics-nav__btn:hover { color: #087e83; }
.analytics-nav__btn--active { color: #087e83; border-bottom-color: #087e83; font-weight: 700; }

/* ── Analytics Table ── */
.analytics-table th {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #6c757d;
    border-top: none;
    padding: .75rem 1rem;
}
.analytics-table td { padding: .75rem 1rem; vertical-align: middle; font-size: .88rem; }
.analytics-table tbody tr:hover { background: #f8f9fa; }
.analytics-table tfoot td { padding: .75rem 1rem; font-size: .85rem; }

/* ── Rank Badges ── */
.rank-badge {
    width: 26px; height: 26px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .75rem;
    font-weight: 700;
    flex-shrink: 0;
    background: #e9ecef;
    color: #495057;
}
.rank-badge--1 { background: #ffd700; color: #856404; }
.rank-badge--2 { background: #c0c0c0; color: #495057; }
.rank-badge--3 { background: #cd7f32; color: #fff; }

/* ── Category Icon ── */
.category-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .9rem;
    flex-shrink: 0;
}

</style>
</div>
