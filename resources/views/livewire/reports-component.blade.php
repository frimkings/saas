<div class="reports-wrap" style="background:#f0f2f5; min-height:100vh;">
<div class="row no-gutters">

    {{-- ═══════════════════════════ SIDEBAR ═══════════════════════════ --}}
    <div class="col-lg-2 reports-sidebar bg-white border-right" style="min-height:100vh;">
        <div class="p-3">

            <h6 class="sidebar-title font-weight-bold text-primary mb-4 pb-2 border-bottom">
                <i class="fas fa-chart-bar mr-2"></i>Sales Analytics
            </h6>

            {{-- PERIOD QUICK-SELECT --}}
            <div class="mb-4">
                <p class="sidebar-label">Period</p>
                <div class="d-flex flex-column" style="gap:4px;">
                    @foreach([
                        'today'   => ['label' => 'Today',      'icon' => 'fa-calendar-day'],
                        'week'    => ['label' => 'This Week',   'icon' => 'fa-calendar-week'],
                        'month'   => ['label' => 'This Month',  'icon' => 'fa-calendar-alt'],
                        'range'   => ['label' => 'Custom Range','icon' => 'fa-sliders-h'],
                        'history' => ['label' => 'All Time',    'icon' => 'fa-history'],
                        'trash'   => ['label' => 'Refunded',    'icon' => 'fa-undo'],
                    ] as $tab => $meta)
                        <button
                            wire:click="switchTab('{{ $tab }}')"
                            class="period-btn btn btn-sm text-left {{ $activeTab === $tab ? 'period-btn--active' : '' }}"
                        >
                            <i class="fas {{ $meta['icon'] }} mr-2 fa-fw"></i>{{ $meta['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- DATE RANGE PICKERS --}}
            @if($activeTab === 'range')
            <div class="mb-4 pb-3 border-bottom">
                <p class="sidebar-label"><i class="fas fa-calendar-alt mr-1"></i>Date Range</p>
                <div class="form-group mb-0"><x-date-range from="fromDate" to="toDate" presets="finance" /></div>
            </div>
            @else
            <div class="mb-4 pb-3 border-bottom">
                <p class="sidebar-label"><i class="fas fa-calendar-check mr-1"></i>Active Period</p>
                <div class="small text-muted">
                    <div>{{ \Carbon\Carbon::parse($fromDate)->format('M d, Y') }}</div>
                    @if($fromDate !== $toDate)
                    <div class="text-center my-1" style="font-size:10px; color:#aaa;">to</div>
                    <div>{{ \Carbon\Carbon::parse($toDate)->format('M d, Y') }}</div>
                    @endif
                </div>
            </div>
            @endif

            {{-- SEARCH --}}
            <div class="mb-4 pb-3 border-bottom">
                <p class="sidebar-label">
                    <i class="fas fa-search mr-1"></i>
                    @if($analyticsView === 'items') Search Products
                    @elseif($analyticsView === 'transactions') Search Transactions
                    @else Search
                    @endif
                </p>
                <input
                    type="text"
                    wire:model.live.debounce.500ms="searchQuery"
                    class="form-control form-control-sm"
                    placeholder="{{ $analyticsView === 'items' ? 'Product name...' : 'Transaction or Patient...' }}"
                >
            </div>

            {{-- OPTIONS --}}
            @if($activeTab !== 'trash')
            <div class="mb-4 pb-3 border-bottom">
                <p class="sidebar-label"><i class="fas fa-cog mr-1"></i>Options</p>
                <div class="custom-control custom-switch mb-2">
                    <input type="checkbox" class="custom-control-input" id="showRefundedSwitch" wire:model.live="showRefunded">
                    <label class="custom-control-label small" for="showRefundedSwitch">Include Refunds</label>
                </div>
                <select wire:model.live="paymentStatus" class="form-control form-control-sm">
                    <option value="">All Payment Statuses</option>
                    <option value="paid">Paid</option>
                    <option value="partial">Partial</option>
                    <option value="unpaid">Unpaid</option>
                </select>
                <select wire:model.live="purchaseType" class="form-control form-control-sm mt-2">
                    <option value="">All Purchase Types</option>
                    <option value="patient">Patient Purchase</option>
                    <option value="direct">Direct Purchase / Walk-in</option>
                </select>
            </div>
            @endif

            {{-- PER PAGE --}}
            <div class="mb-4 pb-3 border-bottom">
                <p class="sidebar-label"><i class="fas fa-list-ol mr-1"></i>Per Page</p>
                <select wire:model.live="perPage" class="form-control form-control-sm">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>

            {{-- ACTIONS --}}
            <button wire:click="refreshData" wire:loading.attr="disabled" class="btn btn-primary btn-sm btn-block mb-2">
                <span wire:loading.remove wire:target="refreshData"><i class="fas fa-sync-alt mr-1"></i>Refresh</span>
                <span wire:loading wire:target="refreshData"><span class="spinner-border spinner-border-sm mr-1"></span>Refreshing…</span>
            </button>
            <button wire:click="resetFilters" class="btn btn-outline-secondary btn-sm btn-block">
                <i class="fas fa-redo mr-1"></i>Reset Filters
            </button>

            {{-- ACTIVE FILTERS --}}
            @if($searchQuery || ($showRefunded && $activeTab !== 'trash') || $paymentStatus || $purchaseType)
            <div class="mt-3 pt-3 border-top">
                <p class="sidebar-label">Active Filters</p>
                @if($searchQuery)
                    <span class="badge badge-primary d-inline-block mb-1">{{ Str::limit($searchQuery, 12) }}</span>
                @endif
                @if($showRefunded && $activeTab !== 'trash')
                    <span class="badge badge-warning d-inline-block mb-1">+Refunds</span>
                @endif
                @if($paymentStatus)
                    <span class="badge badge-info d-inline-block mb-1">{{ ucfirst($paymentStatus) }}</span>
                @endif
                @if($purchaseType)
                    <span class="badge badge-success d-inline-block mb-1">
                        {{ $purchaseType === 'patient' ? 'Patient Purchase' : 'Direct Purchase' }}
                    </span>
                @endif
            </div>
            @endif

        </div>
    </div>
    {{-- end sidebar --}}

    {{-- ═══════════════════════════ MAIN CONTENT ═══════════════════════════ --}}
    <div class="col-lg-10">
        <div class="p-4">

            {{-- PAGE HEADER --}}
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="font-weight-bold mb-0">{{ \App\Support\FinanceStatements::hasBothLines() ? 'Clinic Sales Reports' : 'Sales Reports' }}</h4>
                    @if(\App\Support\FinanceStatements::hasBothLines())
                        <p class="text-muted small mb-0">Clinic sales only. Optical sales are in the <a href="{{ route('optical.reports') }}">Optical reports</a>.</p>
                    @endif
                    <p class="text-muted small mb-0">
                        @php
                            $periodLabels = [
                                'today'   => 'Today — ' . now()->format('F d, Y'),
                                'week'    => 'This Week — ' . now()->startOfWeek()->format('M d') . ' to ' . now()->endOfWeek()->format('M d, Y'),
                                'month'   => 'This Month — ' . now()->format('F Y'),
                                'range'   => \Carbon\Carbon::parse($fromDate)->format('M d, Y') . ' → ' . \Carbon\Carbon::parse($toDate)->format('M d, Y'),
                                'history' => 'All Time (last 12 months)',
                                'trash'   => 'Refunded Transactions',
                            ];
                        @endphp
                        {{ $periodLabels[$activeTab] ?? '' }}
                    </p>
                </div>
                <div class="text-right d-flex" style="gap:.5rem;">
                    <button wire:click="exportCsv" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-file-csv mr-1"></i>Export CSV
                    </button>
                    <a
                        href="{{ route('reports.export.pdf', ['from' => $fromDate, 'to' => $toDate]) }}"
                        target="_blank"
                        class="btn btn-outline-danger btn-sm"
                    >
                        <i class="fas fa-file-pdf mr-1"></i>Export PDF
                    </a>
                </div>
            </div>

            {{-- ── KPI CARDS ── --}}
            <div class="row mb-4" style="gap:0;">
                {{-- Transactions --}}
                <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                    <div class="kpi-card kpi-card--blue h-100">
                        <div class="kpi-card__icon"><i class="fas fa-receipt"></i></div>
                        <div class="kpi-card__label">Transactions</div>
                        <div class="kpi-card__value">{{ number_format($summary['count']) }}</div>
                    </div>
                </div>
                {{-- Net Revenue --}}
                <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                    <div class="kpi-card kpi-card--green h-100">
                        <div class="kpi-card__icon"><i class="fas fa-dollar-sign"></i></div>
                        <div class="kpi-card__label">Net Revenue</div>
                        <div class="kpi-card__value kpi-card__value--green">{{ currency() }} {{ number_format($summary['total_sales'], 2) }}</div>
                    </div>
                </div>
                {{-- Cost of Sales --}}
                <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                    <div class="kpi-card kpi-card--orange h-100">
                        <div class="kpi-card__icon"><i class="fas fa-shopping-cart"></i></div>
                        <div class="kpi-card__label">Cost of Sales</div>
                        <div class="kpi-card__value kpi-card__value--orange">{{ currency() }} {{ number_format($summary['cost_of_sales'], 2) }}</div>
                    </div>
                </div>
                {{-- Gross Profit --}}
                <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                    <div class="kpi-card kpi-card--teal h-100">
                        <div class="kpi-card__icon"><i class="fas fa-chart-line"></i></div>
                        <div class="kpi-card__label">Gross Profit</div>
                        <div class="kpi-card__value kpi-card__value--teal">{{ currency() }} {{ number_format($summary['gross_profit'], 2) }}</div>
                    </div>
                </div>
                {{-- Profit Margin --}}
                <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                    <div class="kpi-card kpi-card--purple h-100">
                        <div class="kpi-card__icon"><i class="fas fa-percentage"></i></div>
                        <div class="kpi-card__label">Profit Margin</div>
                        <div class="kpi-card__value kpi-card__value--purple">{{ number_format($summary['margin'], 1) }}%</div>
                        <div class="kpi-card__progress mt-2">
                            <div class="progress" style="height:4px; background:rgba(255,255,255,.3);">
                                <div class="progress-bar bg-white" style="width:{{ min($summary['margin'],100) }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
                {{-- Avg Transaction --}}
                <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                    <div class="kpi-card kpi-card--yellow h-100">
                        <div class="kpi-card__icon"><i class="fas fa-calculator"></i></div>
                        <div class="kpi-card__label">Avg Transaction</div>
                        <div class="kpi-card__value kpi-card__value--yellow">{{ currency() }} {{ number_format($summary['avg_transaction'], 2) }}</div>
                    </div>
                </div>
            </div>
            {{-- end KPI cards --}}

            {{-- ── ANALYTICS NAVIGATION ── --}}
            <div class="analytics-nav mb-4">
                @foreach([
                    'overview'     => ['icon' => 'fa-tachometer-alt', 'label' => 'Overview'],
                    'items'        => ['icon' => 'fa-boxes',           'label' => 'Sales by Item'],
                    'categories'   => ['icon' => 'fa-tags',            'label' => 'Sales by Category'],
                    'payments'     => ['icon' => 'fa-chart-pie',       'label' => 'Payment Methods'],
                    'transactions' => ['icon' => 'fa-list-alt',        'label' => 'Transactions'],
                ] as $view => $meta)
                    <button
                        wire:click="switchAnalyticsView('{{ $view }}')"
                        class="analytics-nav__btn {{ $analyticsView === $view ? 'analytics-nav__btn--active' : '' }}"
                    >
                        <i class="fas {{ $meta['icon'] }} mr-2"></i>{{ $meta['label'] }}
                    </button>
                @endforeach
            </div>

            {{-- ════════════════════════════════════════════════ --}}
            {{-- OVERVIEW TAB                                     --}}
            {{-- ════════════════════════════════════════════════ --}}
            @if($analyticsView === 'overview')

            <div class="row mb-4">
                {{-- Revenue & Profit Trend Chart --}}
                <div class="col-lg-8 mb-3 mb-lg-0">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-3">
                            <h6 class="mb-0 font-weight-bold"><i class="fas fa-chart-area mr-2 text-primary"></i>Revenue &amp; Profit Trend</h6>
                            <select wire:model.live="chartPeriod" class="form-control form-control-sm w-auto">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="card-body">
                            <div wire:ignore style="height:260px;">
                                <canvas id="salesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Top 5 Products --}}
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 py-3">
                            <h6 class="mb-0 font-weight-bold"><i class="fas fa-trophy mr-2 text-warning"></i>Top 5 Products</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                @forelse($this->topProducts(5) as $i => $item)
                                    <div class="list-group-item border-0 py-3 px-3">
                                        <div class="d-flex align-items-center">
                                            <span class="rank-badge rank-badge--{{ $i + 1 }} mr-3">{{ $i + 1 }}</span>
                                            <div class="flex-grow-1 min-w-0">
                                                <div class="font-weight-bold small text-truncate">{{ $item->product->name ?? 'Unknown' }}</div>
                                                <small class="text-muted">{{ number_format($item->qty_sold) }} units sold</small>
                                            </div>
                                            <div class="text-right ml-2">
                                                <div class="font-weight-bold text-success small">{{ currency() }} {{ number_format($item->revenue, 2) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="list-group-item border-0 text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        <small>No data for this period</small>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Profit Breakdown mini cards --}}
            <div class="row mb-0">
                <div class="col-md-4 mb-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <p class="text-muted small mb-1 text-uppercase font-weight-bold">Total Revenue</p>
                                    <h5 class="font-weight-bold mb-0 text-success">{{ currency() }} {{ number_format($summary['total_sales'], 2) }}</h5>
                                </div>
                                <div style="width:48px;height:48px;background:rgba(40,167,69,.1);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-arrow-up text-success fa-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <p class="text-muted small mb-1 text-uppercase font-weight-bold">Cost of Goods Sold</p>
                                    <h5 class="font-weight-bold mb-0 text-danger">{{ currency() }} {{ number_format($summary['cost_of_sales'], 2) }}</h5>
                                </div>
                                <div style="width:48px;height:48px;background:rgba(220,53,69,.1);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-arrow-down text-danger fa-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <p class="text-muted small mb-1 text-uppercase font-weight-bold">Net Profit</p>
                                    <h5 class="font-weight-bold mb-0 text-info">{{ currency() }} {{ number_format($summary['profit'], 2) }}</h5>
                                </div>
                                <div style="width:48px;height:48px;background:rgba(23,162,184,.1);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-chart-line text-info fa-lg"></i>
                                </div>
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

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 font-weight-bold"><i class="fas fa-boxes mr-2 text-primary"></i>Sales by Item</h6>
                    <span class="badge badge-secondary">{{ $salesByItems->count() }} products</span>
                </div>
                <div class="card-body p-0">
                    @if($salesByItems->isNotEmpty())
                    @php
                        $maxRevenue = $salesByItems->max('revenue') ?: 1;
                    @endphp
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 analytics-table">
                            <thead class="thead-light">
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
                                    $marginClass = $item->margin >= 40 ? 'success' : ($item->margin >= 20 ? 'warning' : 'danger');
                                @endphp
                                <tr>
                                    <td class="text-muted small">{{ $i + 1 }}</td>
                                    <td>
                                        <div class="font-weight-bold">{{ $item->product->name ?? 'Unknown' }}</div>
                                        @if($item->product?->category)
                                            <small class="text-muted">{{ $item->product->category->name }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-light font-weight-bold">{{ number_format($item->qty_sold) }}</span>
                                    </td>
                                    <td class="text-right font-weight-bold text-success">{{ currency() }} {{ number_format($item->revenue, 2) }}</td>
                                    <td class="text-right text-danger">{{ currency() }} {{ number_format($item->cost_of_sales, 2) }}</td>
                                    <td class="text-right font-weight-bold text-info">{{ currency() }} {{ number_format($item->gross_profit, 2) }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-{{ $marginClass }}">{{ number_format($item->margin, 1) }}%</span>
                                    </td>
                                    <td style="min-width:120px;">
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 mr-2" style="height:6px;">
                                                <div class="progress-bar bg-primary" style="width:{{ $share }}%"></div>
                                            </div>
                                            <small class="text-muted" style="width:34px; text-align:right;">{{ number_format($share, 1) }}%</small>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-light font-weight-bold">
                                <tr>
                                    <td colspan="2" class="text-uppercase small">Totals</td>
                                    <td class="text-center">{{ number_format($salesByItems->sum('qty_sold')) }}</td>
                                    <td class="text-right text-success">{{ currency() }} {{ number_format($salesByItems->sum('revenue'), 2) }}</td>
                                    <td class="text-right text-danger">{{ currency() }} {{ number_format($salesByItems->sum('cost_of_sales'), 2) }}</td>
                                    <td class="text-right text-info">{{ currency() }} {{ number_format($salesByItems->sum('gross_profit'), 2) }}</td>
                                    <td class="text-center">
                                        @php
                                            $totalRev = $salesByItems->sum('revenue');
                                            $avgMargin = $totalRev > 0 ? ($salesByItems->sum('gross_profit') / $totalRev) * 100 : 0;
                                        @endphp
                                        <span class="badge badge-{{ $avgMargin >= 40 ? 'success' : ($avgMargin >= 20 ? 'warning' : 'danger') }}">
                                            {{ number_format($avgMargin, 1) }}%
                                        </span>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @else
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
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
                $catColors = ['primary','success','info','warning','danger','secondary','dark'];
            @endphp

            {{-- Category cards --}}
            <div class="row mb-4">
                @forelse($salesByCategory as $ci => $cat)
                @php $color = $catColors[$ci % count($catColors)]; @endphp
                <div class="col-xl-3 col-md-4 col-sm-6 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="category-icon bg-{{ $color }} text-white mr-3">
                                    <i class="fas fa-tag"></i>
                                </div>
                                <div class="font-weight-bold">{{ $cat->category_name }}</div>
                            </div>
                            <div class="row text-center">
                                <div class="col-6 border-right">
                                    <div class="text-success font-weight-bold small">{{ currency() }} {{ number_format($cat->revenue, 0) }}</div>
                                    <div class="text-muted" style="font-size:10px;">Revenue</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-info font-weight-bold small">{{ currency() }} {{ number_format($cat->gross_profit, 0) }}</div>
                                    <div class="text-muted" style="font-size:10px;">Profit</div>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">{{ number_format($cat->qty_sold) }} units · {{ $cat->transaction_count }} txns</small>
                                <span class="badge badge-{{ $cat->margin >= 40 ? 'success' : ($cat->margin >= 20 ? 'warning' : 'danger') }}">
                                    {{ number_format($cat->margin, 1) }}%
                                </span>
                            </div>
                            <div class="progress mt-2" style="height:4px;">
                                <div class="progress-bar bg-{{ $color }}" style="width:{{ min($cat->margin,100) }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
                @empty
                <div class="col-12 text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                    <p>No category data for this period.</p>
                </div>
                @endforelse
            </div>

            {{-- Category detail table --}}
            @if($salesByCategory->isNotEmpty())
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="mb-0 font-weight-bold"><i class="fas fa-table mr-2 text-primary"></i>Category Breakdown</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 analytics-table">
                            <thead class="thead-light">
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
                                        <span class="badge badge-{{ $color }} mr-1">&nbsp;</span>
                                        <span class="font-weight-bold">{{ $cat->category_name }}</span>
                                    </td>
                                    <td class="text-center">{{ number_format($cat->transaction_count) }}</td>
                                    <td class="text-center">{{ number_format($cat->qty_sold) }}</td>
                                    <td class="text-right font-weight-bold text-success">{{ currency() }} {{ number_format($cat->revenue, 2) }}</td>
                                    <td class="text-right text-danger">{{ currency() }} {{ number_format($cat->cost_of_sales, 2) }}</td>
                                    <td class="text-right font-weight-bold text-info">{{ currency() }} {{ number_format($cat->gross_profit, 2) }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-{{ $cat->margin >= 40 ? 'success' : ($cat->margin >= 20 ? 'warning' : 'danger') }}">{{ number_format($cat->margin, 1) }}%</span>
                                    </td>
                                    <td style="min-width:120px;">
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 mr-2" style="height:6px;">
                                                <div class="progress-bar bg-{{ $color }}" style="width:{{ $share }}%"></div>
                                            </div>
                                            <small class="text-muted" style="width:34px; text-align:right;">{{ number_format($share, 1) }}%</small>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-light font-weight-bold">
                                <tr>
                                    <td class="text-uppercase small">Totals</td>
                                    <td class="text-center">—</td>
                                    <td class="text-center">{{ number_format($salesByCategory->sum('qty_sold')) }}</td>
                                    <td class="text-right text-success">{{ currency() }} {{ number_format($salesByCategory->sum('revenue'), 2) }}</td>
                                    <td class="text-right text-danger">{{ currency() }} {{ number_format($salesByCategory->sum('cost_of_sales'), 2) }}</td>
                                    <td class="text-right text-info">{{ currency() }} {{ number_format($salesByCategory->sum('gross_profit'), 2) }}</td>
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

            <div class="row">
                {{-- Donut Chart --}}
                <div class="col-lg-5 mb-4 mb-lg-0">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 py-3">
                            <h6 class="mb-0 font-weight-bold"><i class="fas fa-chart-pie mr-2 text-primary"></i>How We Receive Payments</h6>
                        </div>
                        <div class="card-body d-flex align-items-center justify-content-center">
                            @if($paymentMethods->isNotEmpty())
                                <div style="position:relative; height:280px; width:100%;">
                                    <canvas id="pmDonutChart"></canvas>
                                </div>
                            @else
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-pie fa-3x mb-3 d-block" style="opacity:.25;"></i>
                                    <p class="mb-0">No payment data for this period.</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Breakdown Table --}}
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 font-weight-bold"><i class="fas fa-table mr-2 text-primary"></i>Payment Breakdown</h6>
                            @if($pmTotal > 0)
                                <span class="badge badge-light font-weight-bold">{{ currency() }} {{ number_format($pmTotal, 2) }} total</span>
                            @endif
                        </div>
                        <div class="card-body p-0">
                            @if($paymentMethods->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 analytics-table">
                                    <thead class="thead-light">
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
                                                <span class="d-inline-block rounded-circle mr-2" style="width:10px;height:10px;background:{{ $pm->color }};"></span>
                                                <span class="font-weight-bold">{{ $pm->label }}</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-light">{{ number_format($pm->cnt) }}</span>
                                            </td>
                                            <td class="text-right font-weight-bold text-success">{{ currency() }} {{ number_format($pm->total, 2) }}</td>
                                            <td style="min-width:110px;">
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 mr-2" style="height:6px;">
                                                        <div class="progress-bar" style="width:{{ $share }}%; background:{{ $pm->color }};"></div>
                                                    </div>
                                                    <small class="text-muted" style="width:36px; text-align:right;">{{ number_format($share, 1) }}%</small>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="bg-light font-weight-bold">
                                        <tr>
                                            <td>Total</td>
                                            <td class="text-center">{{ number_format($paymentMethods->sum('cnt')) }}</td>
                                            <td class="text-right text-success">{{ currency() }} {{ number_format($pmTotal, 2) }}</td>
                                            <td class="text-center">100%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            @else
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
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

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 font-weight-bold">
                        <i class="fas fa-list-alt mr-2 text-primary"></i>
                        {{ $activeTab === 'trash' ? 'Refunded Transactions' : 'Transactions' }}
                    </h6>
                    <span class="badge badge-secondary">{{ $sales->total() }} total</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 analytics-table">
                            <thead class="thead-light">
                                <tr>
                                    <th class="text-uppercase small">Status / Date</th>
                                    <th class="text-uppercase small">Patient &amp; Transaction</th>
                                    <th class="text-uppercase small text-right">Amount</th>
                                    <th class="text-uppercase small text-right">Profit</th>
                                    <th class="text-uppercase small text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($sales as $sale)
                                <tr>
                                    <td>
                                        @if($sale->is_refunded)
                                            <span class="badge badge-danger mb-1">REFUNDED</span>
                                        @else
                                            <span class="badge badge-success mb-1">COMPLETED</span>
                                        @endif
                                        <div class="small text-muted">{{ $sale->created_at->format('M d, Y') }}</div>
                                        <div class="small text-muted">{{ $sale->created_at->format('h:i A') }}</div>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold">{{ $sale->customer_display_name }}</div>
                                        <span class="badge {{ $sale->patient_id ? 'badge-primary' : 'badge-success' }}">
                                            {{ $sale->patient_id ? 'Patient Purchase' : 'Direct Purchase' }}
                                        </span>
                                        <small class="text-muted">#{{ $sale->transaction_id }}</small>
                                    </td>
                                    <td class="text-right font-weight-bold">{{ currency() }} {{ number_format($sale->total_amount, 2) }}</td>
                                    <td class="text-right">
                                        <span class="font-weight-bold {{ $sale->profit > 0 ? 'text-success' : 'text-danger' }}">
                                            {{ currency() }} {{ number_format($sale->profit, 2) }}
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <div class="btn-group btn-group-sm">
                                            <button wire:click="showItemsModal({{ $sale->id }})" class="btn btn-outline-primary" title="View Items">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            @if($sale->is_refunded)
                                                <button wire:click="showRefundDetailsModal({{ $sale->id }})" class="btn btn-outline-info" title="Refund Details">
                                                    <i class="fas fa-info-circle"></i>
                                                </button>
                                            @else
                                                <button wire:click="showRefundModal({{ $sale->id }})" class="btn btn-outline-warning" title="Request Refund">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No results for the current filters.</p>
                                        @if($searchQuery || $showRefunded)
                                            <button wire:click="resetFilters" class="btn btn-sm btn-outline-primary mt-2">
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
                <div class="card-footer bg-white border-0 py-3">
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
<div wire:ignore.self class="modal fade" id="itemsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-receipt mr-2"></i>Transaction #{{ $viewingSale->transaction_id ?? '' }}
                </h5>
                <button type="button" class="close text-white" wire:click="closeItemsModal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-0">
                @if($viewingSale)
                <div class="px-4 pt-3 pb-1 bg-light border-bottom d-flex justify-content-between">
                    <div><span class="text-muted small">Customer:</span> <strong>{{ $viewingSale->customer_display_name }}</strong></div>
                    <div><span class="text-muted small">Date:</span> <strong>{{ $viewingSale->created_at->format('M d, Y h:i A') }}</strong></div>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 analytics-table">
                        <thead class="thead-light">
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
                                    <div class="font-weight-bold">{{ $item->product->name ?? 'N/A' }}</div>
                                    @if(isset($item->product->description) && $item->product->description)
                                        <small class="text-muted">{{ Str::limit($item->product->description, 50) }}</small>
                                    @endif
                                </td>
                                <td class="text-center">{{ $qty }}</td>
                                <td class="text-right">{{ currency() }} {{ number_format($unitPrice, 2) }}</td>
                                <td class="text-right font-weight-bold">{{ currency() }} {{ number_format($item->subtotal, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-light">
                            <tr class="font-weight-bold">
                                <td colspan="3" class="text-right text-uppercase small">Total</td>
                                <td class="text-right text-success">{{ currency() }} {{ number_format($viewingSale->total_amount, 2) }}</td>
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
<div wire:ignore.self class="modal fade" id="refundModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-undo mr-2"></i>Request Refund</h5>
                <button type="button" class="close" wire:click="cancelRefund"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                @if($refundingSale)
                <div class="alert alert-info border-0 small">
                    Submitting a refund request for
                    <strong>#{{ $refundingSale->transaction_id }}</strong>
                    &mdash; {{ $refundingSale->items->count() }} item(s).
                    A manager will review and approve before the refund is executed.
                </div>
                <div class="form-group mb-0">
                    <label class="font-weight-bold">Reason for Refund <span class="text-danger">*</span></label>
                    <textarea
                        wire:model="refundReason"
                        class="form-control @error('refundReason') is-invalid @enderror"
                        rows="4"
                        placeholder="Provide a detailed reason for this refund…"
                    ></textarea>
                    @error('refundReason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="form-text text-muted">Minimum 10 characters required</small>
                </div>
                @endif
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" wire:click="cancelRefund">Cancel</button>
                <button type="button" class="btn btn-warning" wire:click="processRefund">
                    <i class="fas fa-paper-plane mr-2"></i>Submit Request
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Refund Details Modal --}}
<div wire:ignore.self class="modal fade" id="refundDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Refund Information</h5>
                <button type="button" class="close text-white" wire:click="closeRefundDetailsModal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                @if($viewingRefundSale)
                <div class="mb-3">
                    <label class="text-muted small text-uppercase font-weight-bold">Transaction ID</label>
                    <div class="h5 mb-0">#{{ $viewingRefundSale->transaction_id }}</div>
                </div>
                <div class="mb-3">
                    <label class="text-muted small text-uppercase font-weight-bold">Refund Reason</label>
                    <div class="p-3 bg-light border rounded small">{{ $this->refundLog?->reason ?? $viewingRefundSale->refund_reason ?? '—' }}</div>
                </div>
                <div class="row">
                    <div class="col-6">
                        <label class="text-muted small text-uppercase font-weight-bold">Refunded At</label>
                        <div class="small">{{ $viewingRefundSale->refunded_at?->format('M d, Y h:i A') ?? '—' }}</div>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small text-uppercase font-weight-bold">Processed By</label>
                        <div class="small">{{ $viewingRefundSale->refundedBy->name ?? 'System' }}</div>
                    </div>
                </div>
                @if($this->refundLog)
                    <hr>
                    <small class="text-muted">Refund Log ID: {{ $this->refundLog->id }}</small>
                @endif
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════ SCRIPTS ═══════════════════════════ --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

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
    window.addEventListener('show-itemsModal-form',         () => $('#itemsModal').modal('show'));
    window.addEventListener('hide-itemsModal-modal',        () => $('#itemsModal').modal('hide'));
    window.addEventListener('show-refundModal-form',        () => $('#refundModal').modal('show'));
    window.addEventListener('hide-refundModal-modal',       () => $('#refundModal').modal('hide'));
    window.addEventListener('show-refundDetailsModal-form', () => $('#refundDetailsModal').modal('show'));
    window.addEventListener('hide-refundDetailsModal-modal',() => $('#refundDetailsModal').modal('hide'));

    /* ─── Toast notifications ─── */
    window.addEventListener('notify', e => {
        const cls   = e.detail.type === 'success' ? 'alert-success' : e.detail.type === 'error' ? 'alert-danger' : 'alert-info';
        const title = e.detail.type === 'success' ? 'Success!' : e.detail.type === 'error' ? 'Error!' : 'Info';
        const el    = document.createElement('div');
        el.className = `alert ${cls} alert-dismissible fade show position-fixed`;
        el.style.cssText = 'top:20px;right:20px;z-index:9999;min-width:280px;box-shadow:0 4px 16px rgba(0,0,0,.15);border-radius:10px;';
        el.innerHTML = `<strong>${title}</strong> ${e.detail.message}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>`;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    });
});
</script>

{{-- ═══════════════════════════ STYLES ═══════════════════════════ --}}
<style>
/* ── Sidebar ── */
.reports-sidebar { position: sticky; top: 0; }
.sidebar-title { font-size: .9rem; }
.sidebar-label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #6c757d;
    margin-bottom: .4rem;
}
.period-btn {
    background: transparent;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    color: #495057;
    font-size: .82rem;
    padding: .45rem .75rem;
    transition: all .15s;
}
.period-btn:hover { background: #f8f9fa; border-color: #ced4da; color: #007bff; }
.period-btn--active { background: #007bff !important; border-color: #007bff !important; color: #fff !important; font-weight: 600; }

/* ── KPI Cards ── */
.kpi-card {
    background: #fff;
    border-radius: 14px;
    padding: 1.1rem 1.2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    position: relative;
    overflow: hidden;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 14px 14px 0 0;
}
.kpi-card--blue::before   { background: #007bff; }
.kpi-card--green::before  { background: #28a745; }
.kpi-card--orange::before { background: #fd7e14; }
.kpi-card--teal::before   { background: #20c997; }
.kpi-card--purple::before { background: #6f42c1; }
.kpi-card--yellow::before { background: #ffc107; }
.kpi-card__icon {
    font-size: 1.1rem;
    margin-bottom: .4rem;
    color: #adb5bd;
}
.kpi-card--blue .kpi-card__icon   { color: #007bff; }
.kpi-card--green .kpi-card__icon  { color: #28a745; }
.kpi-card--orange .kpi-card__icon { color: #fd7e14; }
.kpi-card--teal .kpi-card__icon   { color: #20c997; }
.kpi-card--purple .kpi-card__icon { color: #6f42c1; }
.kpi-card--yellow .kpi-card__icon { color: #ffc107; }
.kpi-card__label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #6c757d;
    margin-bottom: .25rem;
}
.kpi-card__value {
    font-size: 1.1rem;
    font-weight: 800;
    color: #212529;
    line-height: 1.2;
}
.kpi-card__value--green  { color: #28a745; }
.kpi-card__value--orange { color: #fd7e14; }
.kpi-card__value--teal   { color: #20c997; }
.kpi-card__value--purple { color: #6f42c1; }
.kpi-card__value--yellow { color: #e0a800; }

/* ── Analytics Nav ── */
.analytics-nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    border-bottom: 2px solid #dee2e6;
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
.analytics-nav__btn:hover { color: #007bff; }
.analytics-nav__btn--active { color: #007bff; border-bottom-color: #007bff; font-weight: 700; }

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

@media (max-width: 991px) {
    .reports-sidebar { position: static; border-right: none !important; border-bottom: 1px solid #dee2e6; }
}
</style>
</div>
