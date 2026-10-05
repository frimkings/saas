@php
    $money = fn ($value) => ($value < 0 ? '− ' : '').number_format(abs($value), 2);
    $current = $compare === 'months' ? count($columns) - 1 : 0;
    $showChange = $baseline !== null && $compare !== 'months';
    $linkFrom = $columns[$current]['from']->toDateString();
    $linkTo = $columns[$current]['to']->toDateString();
    $expenseTotal = fn ($statement) => $statement['totalOperating'] + $statement['totalNonOperating'];

    // ▲/▼ against the comparison; $costLike rows are better when they go down.
    $change = function (float $now, ?float $before, bool $costLike = false) {
        if ($before === null) return '';
        $diff = round($now - $before, 2);
        if (abs($diff) < 0.005) return '<span class="pl-chg">—</span>';
        $good = $costLike ? $diff < 0 : $diff > 0;
        $pct = abs($before) >= 0.005 ? ' <small>'.number_format(abs($diff / abs($before) * 100), 1).'%</small>' : '';
        return '<span class="pl-chg '.($good ? 'good' : 'bad').'">'.($diff > 0 ? '▲' : '▼').' '.number_format(abs($diff), 2).$pct.'</span>';
    };

    // Where each line's figures come from, for the chosen period.
    $categoryIds = \App\Models\ExpenseCategory::pluck('id', 'name');
    $orders = route('optical.orders', ['dateFrom' => $linkFrom, 'dateTo' => $linkTo]);
    $links = [
        'revenue' => ['Retail (POS)' => route('optical.sales', ['fromDate' => $linkFrom, 'toDate' => $linkTo]), 'Cancellation fees kept' => route('optical.orders', ['statusFilter' => 'Cancelled']), '*' => $orders],
        'costs' => ['Special-order lenses bought' => route('optical.purchasing'), 'Retail items' => route('optical.sales', ['fromDate' => $linkFrom, 'toDate' => $linkTo]), '*' => $orders],
        'losses' => ['Stock count differences' => route('optical.stock-counts'), '*' => null],
        'operating' => ['*' => 'expense'], 'nonOperating' => ['*' => 'expense'],
    ];
    $link = function (string $section, string $label) use ($links, $categoryIds, $linkFrom, $linkTo) {
        $target = $links[$section][$label] ?? $links[$section]['*'] ?? null;
        if ($target === 'expense') return route('optical.expenses', array_filter(['fromDate' => $linkFrom, 'toDate' => $linkTo, 'categoryId' => $categoryIds[$label] ?? null]));
        return $target;
    };

    $sections = [
        ['key' => 'revenue', 'title' => 'Revenue', 'total' => 'totalRevenue', 'totalLabel' => 'Total revenue', 'cost' => false],
        ['key' => 'costs', 'title' => 'Cost of sales', 'total' => 'totalCosts', 'totalLabel' => 'Total cost of sales', 'cost' => true, 'after' => ['grossProfit', 'Gross profit']],
        ['key' => 'losses', 'title' => 'Stock losses', 'total' => 'totalLosses', 'totalLabel' => 'Total stock losses', 'cost' => true],
        ['key' => 'operating', 'title' => 'Operating expenses', 'total' => 'totalOperating', 'totalLabel' => 'Total operating expenses', 'cost' => true, 'after' => ['operatingProfit', 'Operating profit']],
        ['key' => 'nonOperating', 'title' => 'Non-operating expenses', 'total' => 'totalNonOperating', 'totalLabel' => 'Total non-operating expenses', 'cost' => true],
    ];
    $revenueOf = $pl['totalRevenue'];
    $pctOf = fn ($value) => abs($revenueOf) >= 0.005 ? number_format($value / $revenueOf * 100, 1).'%' : '—';

    $warnings = [];
    if ($pl['totalRevenue'] > 0 && $expenseTotal($pl) == 0) {
        $warnings[] = ['No expenses are recorded for these dates, so net profit is overstated.', route('optical.expenses', ['fromDate' => $linkFrom, 'toDate' => $linkTo]), 'Add expenses'];
    }
    if ($pl['uncostedLenses'] > 0) {
        $warnings[] = [$pl['uncostedLenses'].' '.\Illuminate\Support\Str::plural('job', $pl['uncostedLenses']).' charged for lenses with no lens cost recorded (no stock lens, supplier order or lab charge), so gross profit is overstated.', $orders, 'View jobs'];
    }
    if ($pl['uncostedFrames'] > 0) {
        $warnings[] = [$pl['uncostedFrames'].' '.\Illuminate\Support\Str::plural('job', $pl['uncostedFrames']).' sold a custom frame with no stock product, so the frame cost is not included.', $orders, 'View jobs'];
    }
@endphp
<div class="clinic-ui ui-page space-y-4" x-data="{ pct: true }">
    <style>
        .pl-bar{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px 12px}
        .pl-bar .grow{flex:1}
        .pl-field{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:700;color:var(--clinic-muted)}
        .pl-field select{min-width:210px}
        .pl-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
        .pl-kpi{border:1px solid var(--clinic-line);border-radius:12px;background:#fff;padding:14px 16px;display:flex;flex-direction:column;gap:3px;min-width:0}
        .pl-kpi>span{font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--clinic-muted)}
        .pl-kpi b{font-size:22px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .pl-kpi small{font-size:12px;color:var(--clinic-muted)}
        .pl-kpi .pl-chg{font-size:12px}
        .pl-chg{font-weight:700;color:var(--clinic-muted);white-space:nowrap}.pl-chg small{font-weight:500}
        .pl-chg.good{color:#047857}.pl-chg.bad{color:#b91c1c}
        .pl-warn{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;border:1px solid #fde68a;background:#fffbeb;color:#78350f;border-radius:10px;padding:9px 14px;font-size:13px}
        .pl-warn a{font-weight:700;color:#92400e;text-decoration:underline;white-space:nowrap}
        .pl-note{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 12px;border:1px solid var(--clinic-line);background:#f8fafc;border-radius:10px;padding:10px 14px;font-size:13px}
        .pl-table{width:100%;border-collapse:collapse;font-size:13.5px;font-variant-numeric:tabular-nums}
        .pl-table th,.pl-table td{padding:7px 12px;text-align:right;white-space:nowrap}
        .pl-table th:first-child,.pl-table td:first-child{text-align:left;white-space:normal;width:40%}
        .pl-table thead th{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--clinic-muted);border-bottom:1px solid var(--clinic-line);padding-top:12px}
        .pl-table thead th.cur{color:var(--clinic-ink)}
        .pl-table tr.sec td{padding-top:18px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--clinic-muted)}
        .pl-table tr.line td:first-child{padding-left:24px}
        .pl-table tr.line:hover{background:#f8fafc}
        .pl-table tr.line a{color:inherit;text-decoration:none;border-bottom:1px dotted #94a3b8}.pl-table tr.line a:hover{color:var(--clinic-accent);border-bottom-color:var(--clinic-accent)}
        .pl-table tr.total td{font-weight:700;border-top:1px solid var(--clinic-line)}
        .pl-table tr.profit td{font-weight:800;border-top:2px solid #cbd5e1;background:#f8fafc}
        .pl-table tr.net td{font-weight:800;font-size:15px;border-top:2px solid #334155;background:#ecfdf5}
        .pl-table tr.net.loss td{background:#fef2f2}
        .pl-table td.dim{color:#64748b}
        .pl-table td.pct,.pl-table th.pct{color:var(--clinic-muted);font-size:12px}
        .pl-table.no-pct .pct{display:none}
        .pl-table .neg{color:#b91c1c}
        @media print{.pl-kpis{grid-template-columns:repeat(4,1fr)}.pl-table tr.line a{border:0}}
    </style>

    <div class="ui-heading">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Optical Profit &amp; Loss</h1>
            <p class="ui-muted text-xs">Optical business only. Jobs count when ordered; costs are what each item cost when it was sold, and special-order lenses what was paid for them.</p>
        </div>
    </div>

    {{-- One toolbar: which books, which dates, what to compare with, and export. --}}
    <div class="pl-bar no-print">
        <x-finance.statement-switcher :links="$switcher" current="optical" />
        <x-date-range from="from" to="to" presets="finance" label="Period" />
        <label class="pl-field">Compare with
            <select wire:model.live="compare" class="ui-input">
                @foreach(\App\Livewire\Optical\OpticalProfitComponent::COMPARE as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="flex items-center gap-2 pb-2 text-xs font-semibold text-slate-600"><input type="checkbox" x-model="pct"> % of revenue</label>
        <span class="grow"></span>
        <div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <button type="button" x-ref="export" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="menu" class="ui-button">Export ▾</button>
            <template x-teleport="body">
                <div x-show="open" x-cloak x-anchor.bottom-end.offset.4="$refs.export" x-on:click.outside="if (! $refs.export.contains($event.target)) open = false" role="menu"
                     class="clinic-ui z-[1050] w-48 rounded-lg border border-slate-200 bg-white p-1 text-left text-sm shadow-lg">
                    <a role="menuitem" href="{{ route('optical.profit.export', ['format' => 'pdf', 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()]) }}" class="block rounded px-3 py-2 hover:bg-slate-50">PDF</a>
                    <a role="menuitem" href="{{ route('optical.profit.export', ['format' => 'csv', 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()]) }}" class="block rounded px-3 py-2 hover:bg-slate-50">Excel (CSV)</a>
                    <button type="button" role="menuitem" x-on:click="open = false; $nextTick(() => window.print())" class="block w-full rounded px-3 py-2 text-left hover:bg-slate-50">Print this page</button>
                </div>
            </template>
        </div>
    </div>

    <section class="pl-kpis" aria-label="Headline figures">
        <div class="pl-kpi"><span>Revenue</span><b>{{ currency() }} {{ $money($pl['totalRevenue']) }}</b>{!! $change($pl['totalRevenue'], $baseline['totalRevenue'] ?? null) !!}<small>{{ $pl['jobs'] }} {{ Str::plural('job', $pl['jobs']) }} · {{ $pl['retailCount'] }} retail {{ Str::plural('sale', $pl['retailCount']) }}</small></div>
        <div class="pl-kpi"><span>Gross profit</span><b>{{ currency() }} {{ $money($pl['grossProfit']) }}</b>{!! $change($pl['grossProfit'], $baseline['grossProfit'] ?? null) !!}<small>{{ $pl['grossMargin'] === null ? 'No revenue' : $pl['grossMargin'].'% margin' }}</small></div>
        <div class="pl-kpi"><span>Expenses</span><b class="{{ $pl['totalRevenue'] > 0 && $expenseTotal($pl) == 0 ? 'text-amber-700' : '' }}">{{ currency() }} {{ $money($expenseTotal($pl)) }}</b>{!! $change($expenseTotal($pl), $baseline ? $expenseTotal($baseline) : null, true) !!}<small>@if($expenseTotal($pl) == 0)<a wire:navigate href="{{ route('optical.expenses', ['fromDate' => $linkFrom, 'toDate' => $linkTo]) }}" class="font-semibold text-amber-800 underline">None recorded, add them →</a>@else<a wire:navigate href="{{ route('optical.expenses', ['fromDate' => $linkFrom, 'toDate' => $linkTo]) }}" class="hover:underline">Operating and other expenses →</a>@endif</small></div>
        <div class="pl-kpi"><span>Net profit</span><b class="{{ $pl['netProfit'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ currency() }} {{ $money($pl['netProfit']) }}</b>{!! $change($pl['netProfit'], $baseline['netProfit'] ?? null) !!}<small>{{ $pl['totalRevenue'] > 0 ? number_format($pl['netProfit'] / $pl['totalRevenue'] * 100, 1).'% net margin' : '—' }}@if($baselineLabel) · vs {{ $baselineLabel }}@endif</small></div>
    </section>

    {{-- What makes these figures incomplete, before anyone reads them. --}}
    @foreach($warnings as [$text, $url, $action])
        <p class="pl-warn" role="status"><span aria-hidden="true">⚠</span><span class="flex-1 min-w-0">{{ $text }}</span><a href="{{ $url }}" class="no-print">{{ $action }} →</a></p>
    @endforeach
    @if($lock)
        <div class="pl-note" role="status">
            <div>
                <p class="font-semibold text-slate-900">🔒 Period locked{{ $lock->lockedBy ? ' by '.$lock->lockedBy->name : '' }} on {{ $lock->locked_at?->format('d M Y, h:i A') }}</p>
                <p class="text-xs text-slate-500">Optical expenses dated in this period cannot be changed.@if($lock->notes) {{ $lock->notes }}@endif</p>
                @if($lockedNet !== null)
                    <p class="mt-1 text-xs text-amber-800">Net profit was {{ currency() }} {{ $money($lockedNet) }} when locked and is now {{ currency() }} {{ $money($periodNet) }}. Sales, refunds or cancellations recorded since have changed it.</p>
                @endif
            </div>
            <button type="button" wire:click="unlockPeriod" wire:confirm="Unlock this period? Its expenses can then be changed again." class="ui-button no-print">Unlock period</button>
        </div>
    @endif

    <section class="ui-panel" style="padding:4px 8px 12px" aria-label="Profit and loss statement">
        <div class="ui-table-wrap">
            <table class="pl-table" :class="{ 'no-pct': ! pct }">
                <thead><tr>
                    <th scope="col"><span class="sr-only">Line</span></th>
                    @foreach($columns as $i => $column)<th scope="col" class="{{ $i === $current ? 'cur' : '' }}">{{ $column['label'] }}</th>@endforeach
                    @if($showChange)<th scope="col">Change</th>@endif
                    <th scope="col" class="pct">% of revenue</th>
                </tr></thead>
                <tbody>
                    @php
                        $cells = function (callable $value, bool $costLike, string $rowClass = '') use ($columns, $current, $money, $showChange, $change, $baseline, $pctOf) {
                            $html = '';
                            foreach ($columns as $i => $column) {
                                $v = (float) $value($column['pl']);
                                $html .= '<td class="'.($i === $current ? '' : 'dim').($v < 0 ? ' neg' : '').'">'.$money($v).'</td>';
                            }
                            if ($showChange) $html .= '<td>'.$change((float) $value($columns[$current]['pl']), (float) $value($baseline), $costLike).'</td>';
                            return $html.'<td class="pct">'.$pctOf((float) $value($columns[$current]['pl'])).'</td>';
                        };
                    @endphp
                    @foreach($sections as $section)
                        @php
                            $labels = collect($columns)->flatMap(fn ($column) => array_keys($column['pl'][$section['key']]))->unique()->values();
                            $empty = $labels->isEmpty();
                        @endphp
                        @continue($section['key'] === 'nonOperating' && $empty)
                        <tr class="sec"><td colspan="{{ count($columns) + ($showChange ? 3 : 2) }}">{{ $section['title'] }}@if(in_array($section['key'], ['operating', 'nonOperating'], true))<a wire:navigate href="{{ route('optical.expenses', ['fromDate' => $linkFrom, 'toDate' => $linkTo]) }}" class="no-print ml-2 font-semibold normal-case tracking-normal text-teal-700 hover:underline">Expenses →</a>@endif</td></tr>
                        @forelse($labels as $label)
                            @php $url = $link($section['key'], $label); @endphp
                            <tr class="line"><td>@if($url)<a href="{{ $url }}" title="Open the records behind this line">{{ $label }}</a>@else{{ $label }}@endif</td>{!! $cells(fn ($s) => $s[$section['key']][$label] ?? 0, $section['cost']) !!}</tr>
                        @empty
                            <tr class="line"><td class="text-xs text-slate-500">{{ $section['key'] === 'operating' ? 'No optical expenses recorded.' : 'None.' }}</td>@foreach($columns as $column)<td></td>@endforeach @if($showChange)<td></td>@endif<td class="pct"></td></tr>
                        @endforelse
                        <tr class="total"><td>{{ $section['totalLabel'] }}</td>{!! $cells(fn ($s) => $s[$section['total']], $section['cost']) !!}</tr>
                        @isset($section['after'])
                            <tr class="profit"><td>{{ $section['after'][1] }}</td>{!! $cells(fn ($s) => $s[$section['after'][0]], false) !!}</tr>
                        @endisset
                    @endforeach
                    <tr class="net {{ $pl['netProfit'] < 0 ? 'loss' : '' }}"><td>Net profit</td>{!! $cells(fn ($s) => $s['netProfit'], false) !!}</tr>
                </tbody>
            </table>
        </div>
        <p class="px-3 pt-2 text-xs text-slate-500 no-print">Click a line to open the records behind it. Changes in green help profit; red ones reduce it.</p>
    </section>

    <section class="ui-panel p-4 text-sm" aria-label="Cash">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-900">Cash in the period</h2>
            <a wire:navigate href="{{ route('optical.reports') }}#money" class="no-print text-xs font-semibold text-teal-700 hover:underline">By method and staff in Reports →</a>
        </div>
        <div class="mt-2 grid grid-cols-3 gap-3 text-xs">
            <div><p class="text-slate-500">Received</p><p class="font-semibold tabular-nums">{{ currency() }} {{ $money($pl['cash']['received']) }}</p></div>
            <div><p class="text-slate-500">Refunded</p><p class="font-semibold tabular-nums">{{ currency() }} {{ $money($pl['cash']['refunded']) }}</p></div>
            <div><p class="text-slate-500">Net cash</p><p class="font-semibold tabular-nums">{{ currency() }} {{ $money($pl['cash']['net']) }}</p></div>
        </div>
        <p class="mt-2 text-xs text-slate-500">Deposits, balances and partner payments received on optical sales. It differs from revenue when customers pay before or after the job is ordered.</p>
    </section>

    @if(! $lock && $canLock)
        {{-- Month end: close the period once the figures are checked. --}}
        <form wire:submit="lockPeriod" class="ui-panel p-4 flex flex-wrap items-end gap-3 no-print" aria-label="Lock period">
            <div class="w-full"><h2 class="text-sm font-semibold text-slate-900">Close this period</h2><p class="text-xs text-slate-500">When the figures are checked, lock {{ $fromDate->format('d M') }} – {{ $toDate->format('d M Y') }} so its optical expenses can no longer be changed.</p></div>
            <label class="text-xs font-semibold flex-1 min-w-48">Note (optional)<input autocomplete="off" type="text" wire:model="lockNotes" maxlength="1000" class="ui-input mt-1 w-full" placeholder="e.g. Reviewed with accountant"></label>
            <button type="submit" wire:confirm="Lock {{ $fromDate->format('d M') }} – {{ $toDate->format('d M Y') }}? Optical expenses in this period will no longer be editable." class="ui-button">Lock period</button>
            @error('lockNotes')<p class="w-full text-xs text-red-700">{{ $message }}</p>@enderror
        </form>
    @endif
</div>
