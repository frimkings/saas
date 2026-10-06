@php
    $money = fn ($amount) => currency().' '.number_format((float) $amount, 2);
    // ▲/▼ against the previous period of the same length; for money owed, down is good.
    $delta = function (?float $change) use ($previousLabel) {
        if ($change === null) return '<span class="rp-delta">No earlier figure to compare</span>';
        $tone = $change > 0 ? 'up' : ($change < 0 ? 'down' : '');
        return '<span class="rp-delta '.$tone.'" title="Compared with '.e($previousLabel).'">'.($change > 0 ? '▲' : ($change < 0 ? '▼' : '■')).' '.number_format(abs($change), 1).'% <span>vs '.e($previousLabel).'</span></span>';
    };
    $range = ['from' => $fromDate, 'to' => $toDate];
    $ordersLink = route('optical.orders', ['dateFrom' => $fromDate, 'dateTo' => $toDate]);
    $colors = ['#0f766e', '#0ea5e9', '#8b5cf6', '#f59e0b', '#64748b'];
    $trendMax = max(1, collect($trend)->max('amount'));
    $labelEvery = max(1, (int) ceil(count($trend) / 12));
    $ageTones = ['0-30' => 'ok', '31-60' => 'warn', '61-90' => 'late', '90+' => 'bad'];
    $tabs = ['sales' => 'Sales', 'money' => 'Money in', 'owed' => 'Owed', 'lab' => 'Lab & quality', 'products' => 'Products'];
@endphp
<div class="clinic-ui ui-page space-y-5" x-data="{ tab: 'sales', tabs: @js(array_keys($tabs)), show(name) { this.tab = name; history.replaceState(null, '', '#' + name) } }"
     x-init="const hash = location.hash.slice(1); if (tabs.includes(hash)) tab = hash">
    <style>
        .rp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
        .rp-kpi{display:flex;flex-direction:column;gap:4px;border:1px solid var(--clinic-line);border-radius:12px;background:#fff;padding:14px 16px;text-align:left;color:inherit;text-decoration:none;transition:border-color .15s,box-shadow .15s;cursor:pointer;min-width:0}
        .rp-kpi:hover{border-color:#9fb9bd;box-shadow:0 4px 14px rgb(15 23 42 / .06)}
        .rp-kpi:focus-visible{outline:2px solid var(--clinic-accent);outline-offset:2px}
        .rp-kpi>span:first-child{font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--clinic-muted)}
        .rp-kpi b{font-size:22px;font-weight:800;font-variant-numeric:tabular-nums;color:var(--clinic-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .rp-kpi b.bad{color:#b91c1c}
        .rp-kpi small{font-size:12px;color:var(--clinic-muted)}
        .rp-delta{font-size:11.5px;font-weight:700;color:var(--clinic-muted)}.rp-delta span{font-weight:500}
        .rp-delta.up{color:#047857}.rp-delta.down{color:#b91c1c}
        .rp-tabs{display:flex;gap:4px;border-bottom:1px solid var(--clinic-line);overflow-x:auto}
        .rp-tabs button{border:0;background:none;padding:10px 14px;font-size:13px;font-weight:700;color:var(--clinic-muted);border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;cursor:pointer}
        .rp-tabs button[aria-selected=true]{color:var(--clinic-accent);border-bottom-color:var(--clinic-accent)}
        .rp-grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:16px}
        .rp-grid.even{grid-template-columns:repeat(2,minmax(0,1fr))}
        @media(max-width:900px){.rp-grid,.rp-grid.even{grid-template-columns:minmax(0,1fr)}}
        .rp-card{border:1px solid var(--clinic-line);border-radius:12px;background:#fff;padding:16px 18px;min-width:0}
        .rp-card h2{margin:0 0 2px;font-size:14px;font-weight:800;color:var(--clinic-ink)}
        .rp-card .rp-hint{margin:0 0 12px;font-size:12px;color:var(--clinic-muted)}
        .rp-chart{display:flex;align-items:flex-end;gap:3px;height:160px;border-bottom:1px solid var(--clinic-line);padding-top:8px}
        .rp-chart div{flex:1;min-width:3px;background:#99d5d0;border-radius:3px 3px 0 0;position:relative}
        .rp-chart div:hover{background:var(--clinic-accent)}
        .rp-chart div.zero{background:#e2e8f0;height:2px!important}
        .rp-axis{display:flex;gap:3px;margin-top:4px}.rp-axis span{flex:1;min-width:3px;font-size:10px;color:var(--clinic-muted);text-align:center;white-space:nowrap;overflow:visible}
        .rp-stack{display:flex;height:14px;border-radius:999px;overflow:hidden;background:#f1f5f9;margin:4px 0 14px}
        .rp-stack i{display:block;height:100%}
        .rp-rows{width:100%;border-collapse:collapse;font-size:13px}
        .rp-rows td{padding:7px 0;border-bottom:1px solid #f1f5f9;vertical-align:middle}
        .rp-rows td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;padding-left:12px}
        .rp-rows td.s{width:64px;text-align:right;color:var(--clinic-muted);font-size:12px}
        .rp-rows tr.total td{font-weight:800;border-top:2px solid var(--clinic-line);border-bottom:0}
        .rp-rows tr.sub td{color:var(--clinic-muted)}
        .rp-dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:8px;vertical-align:-1px}
        .rp-bar{height:6px;border-radius:999px;background:#f1f5f9;margin-top:4px;overflow:hidden}.rp-bar i{display:block;height:100%;background:var(--clinic-accent);border-radius:999px}
        .rp-ages{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        @media(max-width:700px){.rp-ages{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .rp-age{border-radius:10px;padding:10px 12px;border:1px solid}
        .rp-age span{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
        .rp-age b{display:block;font-size:17px;font-variant-numeric:tabular-nums}.rp-age small{font-size:11.5px}
        .rp-age.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}.rp-age.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
        .rp-age.late{background:#fff7ed;border-color:#fed7aa;color:#9a3412}.rp-age.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .rp-mini{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}
        .rp-mini div{border:1px solid var(--clinic-line);border-radius:10px;padding:10px 12px}
        .rp-mini span{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--clinic-muted)}
        .rp-mini b{font-size:17px;font-variant-numeric:tabular-nums}.rp-mini small{display:block;font-size:11.5px;color:var(--clinic-muted)}
        .rp-menu a{display:flex;justify-content:space-between;gap:10px;padding:4px 8px;border-radius:6px;font-weight:600;color:var(--clinic-accent);text-decoration:none}.rp-menu a:hover{background:#f1f5f9}
    </style>

    <div class="ui-heading">
        <div>
            <h1>Optical Reports</h1>
            <p class="ui-muted">Sales, money received, what customers owe, lab performance and best sellers.</p>
        </div>
        <div class="ui-actions flex flex-wrap items-center gap-2">
            <x-date-range from="fromDate" to="toDate" presets="finance" align="right" />
            <div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                <button type="button" x-ref="reports" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="menu" class="ui-button">Print &amp; export ▾</button>
                <template x-teleport="body">
                    <div x-show="open" x-cloak x-anchor.bottom-end.offset.4="$refs.reports" x-on:click.outside="if (! $refs.reports.contains($event.target)) open = false" role="menu"
                         class="clinic-ui rp-menu z-[1050] w-80 rounded-lg border border-slate-200 bg-white p-2 text-left text-xs shadow-lg">
                        @foreach(\App\Http\Controllers\OpticalReportExportController::REPORTS as $key => $label)
                            <div class="rounded-md px-2 py-2 {{ $loop->last ? '' : 'border-b border-slate-100' }}">
                                <p class="font-bold text-slate-900">{{ $label }}</p>
                                <p class="text-slate-500 mb-1">{{ ['end-of-day' => 'Money received by method and staff, refunds, net takings', 'sales' => 'Sales by category, discounts and net sales', 'owed' => 'Everyone who owes, by how long: 0–30, 31–60, 61–90, 90+ days'][$key] }}{{ $key === 'owed' ? ' (as of today)' : ' for the chosen dates' }}</p>
                                <div class="flex gap-1">
                                    <a role="menuitem" href="{{ route('optical.reports.export', ['report' => $key, 'format' => 'print'] + $range) }}" target="_blank" rel="noopener">Print</a>
                                    <a role="menuitem" href="{{ route('optical.reports.export', ['report' => $key, 'format' => 'pdf'] + $range) }}">PDF</a>
                                    <a role="menuitem" href="{{ route('optical.reports.export', ['report' => $key, 'format' => 'csv'] + $range) }}">Excel (CSV)</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Headline figures: each opens the detail behind it. --}}
    <section class="rp-kpis" aria-label="Headline figures">
        <button type="button" class="rp-kpi" x-on:click="show('sales')">
            <span>Net sales</span>
            <b>{{ $money($sales['revenue']) }}</b>
            {!! $delta($changes['revenue']) !!}
            <small>{{ $sales['jobs'] }} {{ Str::plural('job', $sales['jobs']) }} · {{ $sales['retailCount'] }} counter {{ Str::plural('sale', $sales['retailCount']) }}</small>
        </button>
        <button type="button" class="rp-kpi" x-on:click="show('money')">
            <span>Money received</span>
            <b>{{ $money($cash['net']) }}</b>
            {!! $delta($changes['received']) !!}
            <small>{{ $sales['paidShare'] === null ? 'Nothing sold in these dates' : number_format($sales['paidShare'], 0).'% of these sales paid so far' }}</small>
        </button>
        <button type="button" class="rp-kpi" x-on:click="show('owed')">
            <span>Owed to you</span>
            <b class="{{ $owed['total'] > 0 ? 'bad' : '' }}">{{ $money($owed['total']) }}</b>
            <span class="rp-delta">{{ $owed['count'] }} {{ Str::plural('job', $owed['count']) }} with a balance, as of today</span>
            <small>{{ $money($owed['fromPeriod']) }} of it from jobs in these dates</small>
        </button>
        <a class="rp-kpi" href="{{ $ordersLink }}">
            <span>Jobs ordered</span>
            <b>{{ number_format($sales['jobs']) }}</b>
            {!! $delta($changes['jobs']) !!}
            <small>Open the orders list →</small>
        </a>
        <button type="button" class="rp-kpi" x-on:click="show('sales')">
            <span>Average job value</span>
            <b>{{ $money($sales['avgOrder']) }}</b>
            {!! $delta($changes['avgOrder']) !!}
            <small>Spectacle jobs, after discounts</small>
        </button>
    </section>

    <nav class="rp-tabs" role="tablist" aria-label="Report sections">
        @foreach($tabs as $key => $label)
            <button type="button" role="tab" id="rp-tab-{{ $key }}" aria-controls="rp-panel-{{ $key }}" :aria-selected="tab === '{{ $key }}'" x-on:click="show('{{ $key }}')">{{ $label }}@if($key === 'owed' && $owed['count']) <span class="text-red-700">({{ $owed['count'] }})</span>@endif</button>
        @endforeach
    </nav>

    {{-- Sales --}}
    <section id="rp-panel-sales" role="tabpanel" aria-labelledby="rp-tab-sales" x-show="tab === 'sales'" class="rp-grid">
        <div class="rp-card">
            <h2>Sales {{ count($trend) > 0 && strlen($trend[0]['short']) > 2 ? 'by month' : 'by day' }}</h2>
            <p class="rp-hint">Jobs ordered, counter sales and fees kept, before refunds. Hover a bar for the figure.</p>
            @if($sales['gross'] > 0)
                <div class="rp-chart" role="img" aria-label="Sales trend, highest {{ $money($trendMax) }}">
                    @foreach($trend as $point)
                        <div class="{{ $point['amount'] > 0 ? '' : 'zero' }}" style="height:{{ max(2, round($point['amount'] / $trendMax * 100, 1)) }}%" title="{{ $point['label'] }}: {{ $money($point['amount']) }}"></div>
                    @endforeach
                </div>
                <div class="rp-axis" aria-hidden="true">
                    @foreach($trend as $point)<span>{{ $loop->index % $labelEvery === 0 ? $point['short'] : '' }}</span>@endforeach
                </div>
            @else
                <p class="ui-empty">No sales in these dates.</p>
            @endif
        </div>
        <div class="rp-card">
            <h2>What sold</h2>
            <p class="rp-hint">Share of gross sales, then discounts.</p>
            <div class="rp-stack" aria-hidden="true">
                @foreach($sales['categories'] as $label => $amount)
                    @if($amount > 0)<i style="width:{{ $amount / max(0.01, $sales['gross']) * 100 }}%;background:{{ $colors[$loop->index] }}" title="{{ $label }}"></i>@endif
                @endforeach
            </div>
            <table class="rp-rows">
                @foreach($sales['categories'] as $label => $amount)
                    <tr><td><span class="rp-dot" style="background:{{ $colors[$loop->index] }}"></span>{{ $label }}@if($label === 'Accessories & POS')<span class="text-slate-500"> · {{ $sales['retailCount'] }} {{ Str::plural('sale', $sales['retailCount']) }}</span>@endif</td>
                        <td class="n">{{ $money($amount) }}</td><td class="s">{{ $sales['gross'] > 0 ? number_format($amount / $sales['gross'] * 100, 0).'%' : '—' }}</td></tr>
                @endforeach
                <tr class="sub"><td>Order discounts</td><td class="n">− {{ $money($sales['discounts']) }}</td><td class="s"></td></tr>
                <tr class="total"><td>Net sales</td><td class="n">{{ $money($sales['revenue']) }}</td><td class="s"></td></tr>
            </table>
        </div>
    </section>

    {{-- Money in --}}
    <section id="rp-panel-money" role="tabpanel" aria-labelledby="rp-tab-money" x-show="tab === 'money'" x-cloak class="rp-grid even">
        <div class="rp-card">
            <h2>Money received</h2>
            <p class="rp-hint">Counted on the day it was paid, whenever the job was ordered. This is the end of day takings report.</p>
            @php $methodMax = max(0.01, max($cash['byMethod'] ?: [0])); @endphp
            <table class="rp-rows">
                @foreach($cash['byMethod'] as $method => $amount)
                    <tr><td>{{ $method }}<div class="rp-bar"><i style="width:{{ $amount / $methodMax * 100 }}%"></i></div></td><td class="n">{{ $money($amount) }}</td></tr>
                @endforeach
                <tr class="total"><td>Total received <span class="font-normal text-slate-500">· {{ $cash['payments'] }} {{ Str::plural('payment', $cash['payments']) }}</span></td><td class="n">{{ $money($cash['received']) }}</td></tr>
                <tr class="sub"><td>Refunds paid out</td><td class="n">− {{ $money($cash['refunded']) }}</td></tr>
                <tr class="total"><td>Net takings</td><td class="n">{{ $money($cash['net']) }}</td></tr>
            </table>
            <table class="rp-rows mt-4">
                <tr><td>Cash received</td><td class="n">{{ $money($cash['cashIn']) }}</td></tr>
                <tr class="sub"><td>Expenses paid in cash <a wire:navigate href="{{ route('optical.expenses', ['fromDate' => $fromDate, 'toDate' => $toDate]) }}" class="ml-1 text-xs font-semibold text-teal-700 hover:underline">view</a></td><td class="n">− {{ $money($cash['cashPaidOut']) }}</td></tr>
                <tr class="total"><td>Cash expected in the till <span class="block text-xs font-normal text-slate-500">Before cash refunds and any opening float</span></td><td class="n">{{ $money($cash['cashExpected']) }}</td></tr>
            </table>
            <a href="{{ route('optical.reports.export', ['report' => 'end-of-day', 'format' => 'print'] + $range) }}" target="_blank" rel="noopener" class="ui-button mt-3 inline-flex">Print end of day</a>
        </div>
        <div class="rp-card">
            <h2>Received by</h2>
            <p class="rp-hint">Who took the payments, to match against each till.</p>
            <table class="rp-rows">
                @forelse($cash['byStaff'] as $name => $amount)
                    <tr><td>{{ $name }}</td><td class="n">{{ $money($amount) }}</td></tr>
                @empty
                    <tr><td class="ui-empty">No payments in these dates.</td></tr>
                @endforelse
            </table>
            <a href="{{ route('optical.sales', ['fromDate' => $fromDate, 'toDate' => $toDate]) }}" class="mt-3 inline-block text-sm font-semibold text-teal-700 hover:underline">Open sales records →</a>
        </div>
    </section>

    {{-- Owed --}}
    <section id="rp-panel-owed" role="tabpanel" aria-labelledby="rp-tab-owed" x-show="tab === 'owed'" x-cloak class="space-y-4">
        <div class="rp-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div><h2>Owed to you, by age</h2><p class="rp-hint">Every job with a balance today, whenever it was ordered, grouped by days since ordering.</p></div>
                <div class="flex gap-2">
                    <a href="{{ route('optical.reports.export', ['report' => 'owed', 'format' => 'print']) }}" target="_blank" rel="noopener" class="ui-button">Print</a>
                    <a wire:navigate href="{{ route('optical.orders', ['statusFilter' => 'due']) }}" class="ui-button">Open in Orders</a>
                </div>
            </div>
            <div class="rp-ages">
                @foreach($owed['ages'] as $age => $bucket)
                    <div class="rp-age {{ $ageTones[$age] }}"><span>{{ $bucket['label'] }}</span><b>{{ $money($bucket['amount']) }}</b><small>{{ $bucket['count'] }} {{ Str::plural('job', $bucket['count']) }}</small></div>
                @endforeach
            </div>
        </div>
        <div class="rp-card" style="padding:0">
            <div class="ui-table-wrap"><table class="ui-table w-full">
                <thead><tr><th>Order</th><th>Customer</th><th>Ordered</th><th>Status</th><th class="text-right">Job total</th><th class="text-right">Balance</th></tr></thead>
                <tbody>
                @forelse($owed['rows']->take(50) as $row)
                    <tr wire:key="owed-{{ $row['id'] }}">
                        <td><a wire:navigate href="{{ route('optical.orders', ['searchTerm' => $row['order']]) }}" class="font-mono font-bold text-teal-800 hover:underline">{{ $row['order'] }}</a></td>
                        <td>{{ $row['customer'] }}@if($row['partner'])<span class="ml-1 rounded bg-sky-50 px-1.5 text-[11px] font-semibold text-sky-800">Partner</span>@endif @if($row['phone'])<span class="block text-xs text-slate-500">{{ $row['phone'] }}</span>@endif</td>
                        <td class="whitespace-nowrap">{{ $row['ordered']->format('M j, Y') }}<span class="block text-xs {{ $row['days'] > 60 ? 'font-semibold text-red-700' : 'text-slate-500' }}">{{ $row['days'] }} {{ Str::plural('day', $row['days']) }} ago</span></td>
                        <td>{{ $row['status'] }}</td>
                        <td class="text-right whitespace-nowrap">{{ $money($row['total']) }}</td>
                        <td class="text-right whitespace-nowrap font-bold text-red-700">{{ $money($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-empty">Nobody owes anything. Every job is paid up.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if($owed['count'] > 50)<p class="px-4 py-3 text-xs text-slate-500">Showing the 50 oldest of {{ $owed['count'] }}. Print the report for all of them.</p>@endif
        </div>
    </section>

    {{-- Lab & quality --}}
    <section id="rp-panel-lab" role="tabpanel" aria-labelledby="rp-tab-lab" x-show="tab === 'lab'" x-cloak class="space-y-4">
        <section id="turnaround" class="rp-card" style="padding:0" aria-labelledby="turnaround-title">
            <div class="p-4 pb-2">
                <h2 id="turnaround-title">Turnaround by lab</h2>
                <p class="rp-hint" style="margin:0">Jobs that became ready in the selected dates. Typical days is the median, so one forgotten job does not skew it. On time means ready by the date due back from the lab, or else by the pickup date promised.</p>
            </div>
            <div class="ui-table-wrap"><table class="ui-table w-full">
                <thead><tr><th>Lab</th><th class="text-right">Jobs</th><th class="text-right">Typical days, order → ready</th><th class="text-right">Typical days at lab</th><th class="text-right">On time</th><th class="text-right">Late</th></tr></thead>
                <tbody>
                @forelse($labTurnaround as $row)
                    <tr>
                        <td class="font-semibold">{{ $row['lab'] }}@if($row['lab'] === 'Lab not recorded')<span class="block text-[11px] font-normal text-slate-500">Sent out without choosing the lab</span>@endif</td>
                        <td class="text-right">{{ $row['jobs'] }}</td>
                        <td class="text-right" title="Average {{ number_format($row['avg_days'], 1) }}">{{ number_format($row['median_days'], 1) }}</td>
                        <td class="text-right">{{ $row['median_lab_days'] === null ? '—' : number_format($row['median_lab_days'], 1) }}</td>
                        <td class="text-right">{{ $row['on_time'] === null ? '—' : $row['on_time'].'%' }}</td>
                        <td class="text-right {{ $row['late'] ? 'text-red-700 font-semibold' : '' }}">{{ $row['late'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-empty">No jobs became ready in these dates.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <div class="rp-grid even">
            <div class="rp-card">
                <h2>Remakes</h2>
                <p class="rp-hint">Jobs redone in these dates, and why.</p>
                <div class="rp-mini">
                    <div><span>Remakes</span><b>{{ $remakes['count'] }}</b><small>{{ number_format($remakes['rate'], 1) }}% of jobs</small></div>
                    <div><span>Free remakes</span><b>{{ $remakes['free'] }}</b><small>No charge to the customer</small></div>
                    <div><span>Lens cost of free remakes</span><b class="{{ $remakes['lensCost'] > 0 ? 'text-red-700' : '' }}">{{ $money($remakes['lensCost']) }}</b><small>Stock lenses at cost</small></div>
                </div>
                @if($remakes['reasons'])
                    @php $reasonMax = max($remakes['reasons']); @endphp
                    <table class="rp-rows mt-3">
                        @foreach($remakes['reasons'] as $reason => $count)
                            <tr><td>{{ $reason }}<div class="rp-bar"><i style="width:{{ $count / $reasonMax * 100 }}%;background:#f59e0b"></i></div></td><td class="n">{{ $count }}</td></tr>
                        @endforeach
                    </table>
                @endif
            </div>
            <div class="rp-card">
                <h2>Refunds &amp; cancellations</h2>
                <p class="rp-hint">Jobs cancelled in these dates. Fees and deposits kept count as sales.</p>
                <div class="rp-mini">
                    <div><span>Refunded &amp; cancelled</span><b>{{ $sales['refundCount'] }}</b><small>{{ $money($sales['refundTotal']) }} refunded</small></div>
                    <div><span>Abandoned jobs closed</span><b>{{ $sales['abandonedCount'] }}</b><small>{{ $money($sales['depositsKept']) }} deposits kept</small></div>
                    <div><span>Fees &amp; deposits kept</span><b>{{ $money($sales['cancellationFees']) }}</b><small>Income from cancelled jobs</small></div>
                </div>
            </div>
        </div>
    </section>

    {{-- Products --}}
    <section id="rp-panel-products" role="tabpanel" aria-labelledby="rp-tab-products" x-show="tab === 'products'" x-cloak class="rp-card" style="padding:0">
        <div class="p-4 pb-2"><h2>Best sellers</h2><p class="rp-hint" style="margin:0">Stock items sold in these dates, by value, after returns. Includes frames and lenses from jobs and counter sales.</p></div>
        @php $topMax = max(0.01, (float) ($topProducts->max('gross') ?? 0)); @endphp
        <div class="ui-table-wrap"><table class="ui-table w-full">
            <thead><tr><th>Product</th><th class="text-right">Units</th><th class="text-right">Sales</th></tr></thead>
            <tbody>
            @forelse($topProducts as $line)
                <tr>
                    <td>{{ $line->opticalProduct?->name ?? 'Archived product' }}<span class="block font-mono text-[11px] text-slate-500">{{ $line->opticalProduct?->sku ?? '—' }}</span><div class="rp-bar" style="max-width:320px"><i style="width:{{ (float) $line->gross / $topMax * 100 }}%"></i></div></td>
                    <td class="text-right">{{ (int) $line->units }}</td>
                    <td class="text-right whitespace-nowrap">{{ $money($line->gross) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="ui-empty">No stock items sold in these dates.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
