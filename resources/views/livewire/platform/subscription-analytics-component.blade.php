<x-platform.page active="analytics" title="Subscription Analytics" subtitle="Revenue, retention, conversion, collections and renewal forecasting.">
    <x-slot:actions>
        <a class="pp-btn alt" href="{{ route('platform.subscription-analytics.csv', ['from' => $analyticsFrom->toDateString(), 'to' => $analyticsTo->toDateString(), 'clinic_id' => $clinicId, 'plan_id' => $planId, 'status' => $status]) }}">⇩ Export CSV</a>
        <a class="pp-btn" href="{{ route('platform.dashboard', ['tab' => 'billing']) }}">Billing workspace</a>
    </x-slot:actions>

    <style>
        .an-filters{display:grid;grid-template-columns:repeat(2,minmax(130px,1fr)) repeat(4,minmax(140px,1.2fr));gap:10px;align-items:end}
        .an-presets{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;align-items:center}
        .an-chip{background:#0b1526;border:1px solid #273750;color:#aebbd0;border-radius:999px;padding:5px 12px;font:inherit;font-size:12px;cursor:pointer}
        .an-chip:hover{border-color:#38bdf8;color:#e8eef8}.an-chip.active{background:#16304a;border-color:#38bdf8;color:#38bdf8}
        .an-group{margin-bottom:6px;font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#8fa1bb}
        .an-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
        .an-kpis .pp-stat b{font-size:22px}
        .an-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .an-bar-row{display:grid;grid-template-columns:minmax(90px,150px) 1fr auto;gap:10px;align-items:center;margin:10px 0}
        .an-bar{height:10px;background:#07101f;border-radius:8px;overflow:hidden}.an-bar i{display:block;height:100%;border-radius:8px;background:#38bdf8}
        .an-trend{display:flex;align-items:flex-end;gap:10px;height:190px;padding-top:10px;overflow-x:auto}
        .an-month{flex:1;min-width:46px;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%}
        .an-cols{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:3px}
        .an-cols i{width:40%;max-width:18px;border-radius:4px 4px 0 0;min-height:2px}
        .an-month small{color:#8fa1bb;font-size:10px;white-space:nowrap}
        .an-legend{display:flex;gap:14px;font-size:11px;color:#aebbd0}.an-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
        .an-loading{opacity:.5;pointer-events:none}
        .an-total{display:flex;justify-content:space-between;border-top:1px solid #22314a;padding-top:10px;margin-top:6px;font-weight:700}
        @media(max-width:1100px){.an-kpis{grid-template-columns:repeat(2,1fr)}.an-filters{grid-template-columns:1fr 1fr}}
        @media(max-width:900px){.an-2{grid-template-columns:1fr}}
        @media(max-width:600px){.an-kpis,.an-filters{grid-template-columns:1fr}}
    </style>

    @php
        $money = fn ($v) => 'GHS ' . number_format((float) $v, 2);
        $presets = ['month' => 'This month', '30d' => 'Last 30 days', 'quarter' => 'This quarter', 'ytd' => 'Year to date', '12m' => 'Last 12 months', 'lastyear' => 'Last year'];
        $activePreset = collect([
            'month' => [now()->startOfMonth(), now()], '30d' => [now()->subDays(29), now()], 'quarter' => [now()->startOfQuarter(), now()],
            'ytd' => [now()->startOfYear(), now()], '12m' => [now()->subMonths(11)->startOfMonth(), now()], 'lastyear' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
        ])->search(fn ($r) => $r[0]->toDateString() === $analyticsFrom->toDateString() && $r[1]->toDateString() === $analyticsTo->toDateString());
        $filtered = $clinicId || $planId || $status || $product || $activePreset !== 'ytd';
        $trendMax = max(1, $trend->max('invoiced'), $trend->max('collected'));
        $agingTotal = $aging->sum();
    @endphp

    {{-- Filters --}}
    <section class="pp-card">
        <div class="an-presets" role="group" aria-label="Date range">
            
            @if($filtered)<button type="button" class="an-chip" style="margin-left:auto" wire:click="resetFilters">✕ Reset filters</button>@endif
        </div>
        <div class="an-filters">
            <div class="pp-field" style="grid-column:span 2"><span>Dates</span><x-date-range from="from" to="to" presets="finance" theme="dark" /></div>
            <label class="pp-field"><span>Product</span><select wire:model.live="product"><option value="">All products</option>@foreach(\App\Support\PlanProduct::PRODUCTS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="pp-field"><span>Clinic</span><select wire:model.live="clinicId"><option value="">All clinics</option>@foreach($clinics as $clinic)<option value="{{ $clinic->id }}">{{ $clinic->name }}</option>@endforeach</select></label>
            <label class="pp-field"><span>Plan</span><select wire:model.live="planId"><option value="">All plans</option>@foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }}</option>@endforeach</select></label>
            <label class="pp-field"><span>Subscription status</span><select wire:model.live="status"><option value="">All statuses</option>@foreach(['trial','active','overdue','restricted','suspended','cancelled'] as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>@endforeach</select></label>
        </div>
        <p class="pp-hint" style="margin:10px 0 0">Showing {{ $analyticsFrom->format('d M Y') }} – {{ $analyticsTo->format('d M Y') }}. MRR, ARR and renewals reflect subscriptions today; invoice and payment figures cover the selected dates.</p>
    </section>

    <div wire:loading.delay.class="an-loading" style="transition:opacity .15s">
    {{-- Recurring revenue --}}
    <div class="an-group">Recurring revenue (today)</div>
    <div class="an-kpis">
        <div class="pp-stat" title="Monthly recurring revenue from active subscriptions; yearly plans count as 1/12 of the annual price."><span>MRR</span><b class="pass">{{ $money($metrics['mrr']) }}</b><small>Monthly recurring revenue</small></div>
        <div class="pp-stat" title="MRR × 12"><span>ARR</span><b>{{ $money($metrics['arr']) }}</b><small>Annual run rate (MRR × 12)</small></div>
        <div class="pp-stat"><span>Paying clinics</span><b>{{ $metrics['active_clinics'] }}</b><small>{{ $metrics['trial_clinics'] }} on trial</small></div>
        <div class="pp-stat" title="MRR ÷ paying clinics"><span>Avg. revenue per clinic</span><b>{{ $money($metrics['arpa']) }}</b><small>Per month</small></div>
    </div>

    {{-- Cash in the period --}}
    <div class="an-group">Billing &amp; collections (selected dates)</div>
    <div class="an-kpis">
        <div class="pp-stat"><span>Invoiced</span><b>{{ $money($metrics['invoiced']) }}</b><small>Excludes void invoices</small></div>
        <div class="pp-stat"><span>Net collected</span><b class="pass">{{ $money($metrics['net_collections']) }}</b><small>{{ $money($metrics['collected']) }} received − {{ $money($metrics['refunded']) }} refunded</small></div>
        <div class="pp-stat" title="Payments received ÷ amount invoiced"><span>Collection rate</span><b class="{{ $metrics['invoiced'] > 0 ? ($metrics['collection_rate'] >= 80 ? 'pass' : ($metrics['collection_rate'] >= 50 ? 'warn' : 'fail')) : '' }}">{{ $metrics['invoiced'] > 0 ? $metrics['collection_rate'] . '%' : '—' }}</b><small>Received ÷ invoiced</small></div>
        <div class="pp-stat"><span>Outstanding</span><b class="{{ $metrics['outstanding'] > 0 ? 'warn' : 'pass' }}">{{ $money($metrics['outstanding']) }}</b><small>{{ $agingTotal - $aging['current'] > 0 ? $money($agingTotal - $aging['current']) . ' overdue' : 'Nothing overdue' }}</small></div>
    </div>

    {{-- Growth & retention --}}
    <div class="an-group">Growth &amp; retention (selected dates)</div>
    <div class="an-kpis">
        <div class="pp-stat" title="Clinics that moved from a trial to a paid subscription"><span>Trial conversion</span><b>{{ $metrics['trials'] ? $metrics['conversion_rate'] . '%' : '—' }}</b><small>{{ $metrics['converted'] }} of {{ $metrics['trials'] }} trial(s) converted</small></div>
        <div class="pp-stat" title="Clinics cancelled or suspended in the period ÷ clinics at the start"><span>Churn</span><b class="{{ $metrics['churned'] ? 'fail' : 'pass' }}">{{ $metrics['churn_rate'] }}%</b><small>{{ $metrics['churned'] }} clinic(s) cancelled or suspended</small></div>
        <div class="pp-stat"><span>Upgrades</span><b class="{{ $metrics['upgrades'] ? 'pass' : '' }}">{{ $metrics['upgrades'] }}</b><small>Moved to a higher-priced plan</small></div>
        <div class="pp-stat"><span>Downgrades</span><b class="{{ $metrics['downgrades'] ? 'warn' : '' }}">{{ $metrics['downgrades'] }}</b><small>Moved to a lower-priced plan</small></div>
    </div>

    {{-- Trend --}}
    <section class="pp-card">
        <div class="pp-card-head">
            <div><h2>Invoiced vs collected by month</h2><p>Payments are counted in the month they were received.</p></div>
            <div class="an-legend"><span><i style="background:#38bdf8"></i>Invoiced</span><span><i style="background:#34d399"></i>Collected</span></div>
        </div>
        @if($trend->sum('invoiced') + $trend->sum('collected') > 0)
            <div class="an-trend">
                @foreach($trend as $month)
                    <div class="an-month" title="{{ $month['label'] }}: invoiced {{ $money($month['invoiced']) }}, collected {{ $money($month['collected']) }}">
                        <div class="an-cols">
                            <i style="height:{{ round($month['invoiced'] / $trendMax * 100) }}%;background:#38bdf8"></i>
                            <i style="height:{{ round($month['collected'] / $trendMax * 100) }}%;background:#34d399"></i>
                        </div>
                        <small>{{ $month['label'] }}</small>
                    </div>
                @endforeach
            </div>
        @else
            <p class="pp-empty">No invoices or payments in this period.</p>
        @endif
    </section>

    <section class="pp-card">
        <div class="pp-card-head"><div><h2>By product</h2><p>Who subscribes to what. MRR counts active subscriptions only.</p></div></div>
        <div class="pp-stats">
            @foreach($by_product as $key => $row)
                <div class="pp-stat"><span>{{ $row['label'] }}</span><b>{{ $money($row['mrr']) }}</b><small>{{ $row['clinics'] }} {{ \Illuminate\Support\Str::plural('subscriber', $row['clinics']) }} · {{ $metrics['mrr'] > 0 ? round($row['mrr'] / $metrics['mrr'] * 100) : 0 }}% of MRR</small></div>
            @endforeach
        </div>
    </section>

    <div class="an-2">
        <section class="pp-card">
            <div class="pp-card-head"><div><h2>MRR by plan</h2><p>Active subscriptions only. Trials and suspended clinics count as clinics but add no MRR.</p></div></div>
            @php $max = max(1, $by_plan->max('mrr') ?? 1); @endphp
            @forelse($by_plan as $name => $row)
                <div class="an-bar-row">
                    <span><b>{{ $name }}</b><small class="pp-hint" style="display:block">{{ $row['clinics'] }} clinic(s)</small></span>
                    <div class="an-bar"><i style="width:{{ round($row['mrr'] / $max * 100) }}%"></i></div>
                    <b>{{ $money($row['mrr']) }} <small class="pp-hint">{{ $metrics['mrr'] > 0 ? round($row['mrr'] / $metrics['mrr'] * 100) : 0 }}%</small></b>
                </div>
            @empty
                <p class="pp-empty">No subscription data for this selection.</p>
            @endforelse
        </section>

        <section class="pp-card">
            <div class="pp-card-head"><div><h2>Outstanding aging</h2><p>Unpaid balances on this period's invoices, by days past the due date.</p></div></div>
            @php $ageMax = max(1, $aging->max() ?? 1); @endphp
            @foreach($aging as $bucket => $value)
                <div class="an-bar-row">
                    <span>{{ $bucket === 'current' ? 'Not yet due' : $bucket . ' days' }}</span>
                    <div class="an-bar"><i style="width:{{ round($value / $ageMax * 100) }}%;background:{{ $bucket === 'current' ? '#38bdf8' : (in_array($bucket, ['61-90', '90+']) ? '#fb7185' : '#fbbf24') }}"></i></div>
                    <b>{{ $money($value) }}</b>
                </div>
            @endforeach
            <div class="an-total"><span>Total outstanding</span><span>{{ $money($agingTotal) }}</span></div>
            @if($agingTotal - $aging['current'] > 0)<p style="margin:12px 0 0"><a class="pp-btn alt sm" href="{{ route('platform.dashboard', ['tab' => 'billing']) }}">Open collections →</a></p>@endif
        </section>
    </div>

    <div class="an-2">
        <section class="pp-card">
            <div class="pp-card-head"><div><h2>Renewals due in the next 90 days</h2><p>{{ $metrics['renewals_90'] }} subscription(s) worth {{ $money($metrics['renewal_value']) }}{{ $metrics['promises_90'] > 0 ? ', plus ' . $money($metrics['promises_90']) . ' in payment promises' : '' }}.</p></div></div>
            <div class="pp-table-wrap"><table class="pp-table">
                <thead><tr><th>Clinic</th><th>Plan</th><th>Renews</th><th style="text-align:right">Value</th></tr></thead>
                <tbody>
                @forelse($renewals->take(10) as $sub)
                    <tr>
                        <td><b>{{ $sub->clinic?->name ?? 'Deleted clinic' }}</b></td>
                        <td>{{ data_get($sub->plan_snapshot, 'name', $sub->plan?->name) }}<small>{{ $sub->billing_interval === 'yearly' ? 'Yearly' : 'Monthly' }}</small></td>
                        <td>{{ $sub->current_period_ends_at->format('d M Y') }}<small>in {{ (int) now()->diffInDays($sub->current_period_ends_at) }} day(s)</small></td>
                        <td style="text-align:right">{{ $money($sub->billing_interval === 'yearly' ? data_get($sub->pricing_snapshot, 'annual_price', 0) : data_get($sub->pricing_snapshot, 'base_price', 0)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="pp-empty">No renewals in the next 90 days.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if($renewals->count() > 10)<p class="pp-hint">Showing the next 10 of {{ $renewals->count() }}.</p>@endif
        </section>

        <section class="pp-card">
            <div class="pp-card-head"><div><h2>Collection movement</h2><p>Cash in and out during the selected dates.</p></div></div>
            <div class="pp-table-wrap"><table class="pp-table">
                <tbody>
                    <tr><td>Payments received</td><td style="text-align:right" class="pass">+ {{ $money($metrics['collected']) }}</td></tr>
                    <tr><td>Refunded</td><td style="text-align:right" class="{{ $metrics['refunded'] > 0 ? 'fail' : '' }}">− {{ $money($metrics['refunded']) }}</td></tr>
                    <tr><td><b>Net collected</b></td><td style="text-align:right"><b>{{ $money($metrics['net_collections']) }}</b></td></tr>
                    <tr><td>Credit notes issued <small>Reduce what clinics owe; no cash moves</small></td><td style="text-align:right">{{ $money($metrics['credited']) }}</td></tr>
                </tbody>
            </table></div>
        </section>
    </div>
    </div>
</x-platform.page>
