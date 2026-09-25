@php
    $money = fn ($value) => ($value < 0 ? '− ' : '').number_format(abs($value), 2);
    $row = function (string $label, $value, $before, string $class = '') use ($money) {
        return '<tr class="'.$class.'"><td class="py-1 pr-3">'.e($label).'</td><td class="py-1 text-right font-mono">'.$money($value).'</td><td class="py-1 text-right font-mono text-slate-400">'.$money($before).'</td></tr>';
    };
@endphp
<div class="clinic-ui ui-page space-y-5">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Optical Profit &amp; Loss</h1>
            <p class="ui-muted text-xs">Optical business only. Jobs count when ordered; costs are what each item cost when it was sold, and special-order lenses what was paid for them.</p>
        </div>
        <div class="flex flex-wrap gap-2 no-print">
            <a href="{{ route('optical.expenses', ['fromDate' => $fromDate->toDateString(), 'toDate' => $toDate->toDateString()]) }}" class="ui-button">Expenses</a>
            <a href="{{ route('optical.profit.export', ['format' => 'csv', 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()]) }}" class="ui-button">Export CSV</a>
            <a href="{{ route('optical.profit.export', ['format' => 'pdf', 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()]) }}" class="ui-button">Export PDF</a>
            <button type="button" onclick="window.print()" class="ui-button">Print</button>
        </div>
    </div>

    @if($lock)
        <div class="ui-panel p-4 max-w-3xl text-sm flex flex-wrap items-center justify-between gap-3" role="status">
            <div>
                <p class="font-semibold text-slate-900">Period locked{{ $lock->lockedBy ? ' by '.$lock->lockedBy->name : '' }} on {{ $lock->locked_at?->format('d M Y, h:i A') }}</p>
                <p class="text-xs text-slate-500">Optical expenses dated in this period cannot be changed.@if($lock->notes) {{ $lock->notes }}@endif</p>
                @if($lockedNet !== null)
                    <p class="mt-1 text-xs text-amber-800">Net profit was {{ currency() }} {{ $money($lockedNet) }} when locked and is now {{ currency() }} {{ $money($pl['netProfit']) }}. Sales, refunds or cancellations recorded since have changed it.</p>
                @endif
            </div>
            <button type="button" wire:click="unlockPeriod" wire:confirm="Unlock this period? Its expenses can then be changed again." class="ui-button no-print">Unlock period</button>
        </div>
    @elseif($canLock)
        <form wire:submit="lockPeriod" class="ui-panel p-4 max-w-3xl flex flex-wrap items-end gap-3 no-print" aria-label="Lock period">
            <label class="text-xs font-semibold flex-1 min-w-48">Close this period (optional note)<input type="text" wire:model="lockNotes" maxlength="1000" class="ui-input mt-1 w-full" placeholder="e.g. Reviewed with accountant"></label>
            <button type="submit" wire:confirm="Lock {{ $fromDate->format('d M') }} – {{ $toDate->format('d M Y') }}? Optical expenses in this period will no longer be editable." class="ui-button">Lock period</button>
            @error('lockNotes')<p class="w-full text-xs text-red-700">{{ $message }}</p>@enderror
        </form>
    @endif

    <x-finance.statement-switcher :links="$switcher" current="optical" />

    <div class="flex flex-wrap items-end gap-3 no-print">
        <x-date-range from="from" to="to" presets="finance" label="Period" />
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="ui-panel p-4"><p class="text-xs font-semibold uppercase text-slate-500">Revenue</p><p class="text-xl font-bold font-mono">{{ currency() }} {{ $money($pl['totalRevenue']) }}</p><p class="text-xs text-slate-500">{{ $pl['jobs'] }} jobs · {{ $pl['retailCount'] }} retail sales</p></div>
        <div class="ui-panel p-4"><p class="text-xs font-semibold uppercase text-slate-500">Gross profit</p><p class="text-xl font-bold font-mono">{{ currency() }} {{ $money($pl['grossProfit']) }}</p><p class="text-xs text-slate-500">{{ $pl['grossMargin'] === null ? '—' : $pl['grossMargin'].'% margin' }}</p></div>
        <div class="ui-panel p-4"><p class="text-xs font-semibold uppercase text-slate-500">Expenses</p><p class="text-xl font-bold font-mono">{{ currency() }} {{ $money($pl['totalOperating'] + $pl['totalNonOperating']) }}</p></div>
        <div class="ui-panel p-4"><p class="text-xs font-semibold uppercase text-slate-500">Net profit</p><p class="text-xl font-bold font-mono {{ $pl['netProfit'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ currency() }} {{ $money($pl['netProfit']) }}</p><p class="text-xs text-slate-500">previous period {{ $money($previous['netProfit']) }}</p></div>
    </div>

    <section class="ui-panel p-5 max-w-3xl" aria-label="Profit and loss statement">
        <table class="w-full text-sm">
            <thead class="text-xs uppercase text-slate-500">
                <tr><th class="py-1 text-left"></th><th class="py-1 text-right">{{ $fromDate->format('d M') }} – {{ $toDate->format('d M Y') }}</th><th class="py-1 text-right">{{ $previousFrom->format('d M') }} – {{ $previousTo->format('d M Y') }}</th></tr>
            </thead>
            <tbody>
                <tr><th colspan="3" class="pt-3 text-left text-xs uppercase text-slate-500">Revenue</th></tr>
                @foreach($pl['revenue'] as $label => $value){!! $row($label, $value, $previous['revenue'][$label] ?? 0) !!}@endforeach
                {!! $row('Total revenue', $pl['totalRevenue'], $previous['totalRevenue'], 'border-t font-semibold') !!}

                <tr><th colspan="3" class="pt-4 text-left text-xs uppercase text-slate-500">Cost of sales</th></tr>
                @foreach($pl['costs'] as $label => $value){!! $row($label, $value, $previous['costs'][$label] ?? 0) !!}@endforeach
                {!! $row('Total cost of sales', $pl['totalCosts'], $previous['totalCosts'], 'border-t font-semibold') !!}
                {!! $row('Gross profit', $pl['grossProfit'], $previous['grossProfit'], 'border-t-2 border-slate-300 font-bold') !!}

                <tr><th colspan="3" class="pt-4 text-left text-xs uppercase text-slate-500">Stock losses</th></tr>
                @foreach($pl['losses'] as $label => $value){!! $row($label, $value, $previous['losses'][$label] ?? 0) !!}@endforeach

                <tr><th colspan="3" class="pt-4 text-left text-xs uppercase text-slate-500">Operating expenses</th></tr>
                @forelse($pl['operating'] as $label => $value){!! $row($label, $value, $previous['operating'][$label] ?? 0) !!}@empty<tr><td colspan="3" class="py-1 text-xs text-slate-500">No optical expenses recorded.</td></tr>@endforelse
                {!! $row('Total operating expenses', $pl['totalOperating'], $previous['totalOperating'], 'border-t font-semibold') !!}
                {!! $row('Operating profit', $pl['operatingProfit'], $previous['operatingProfit'], 'border-t-2 border-slate-300 font-bold') !!}

                @if($pl['nonOperating'] || $previous['nonOperating'])
                    <tr><th colspan="3" class="pt-4 text-left text-xs uppercase text-slate-500">Non-operating expenses</th></tr>
                    @foreach($pl['nonOperating'] as $label => $value){!! $row($label, $value, $previous['nonOperating'][$label] ?? 0) !!}@endforeach
                @endif
                {!! $row('Net profit', $pl['netProfit'], $previous['netProfit'], 'border-t-2 border-slate-400 font-bold text-lg') !!}
            </tbody>
        </table>
        @if($pl['uncostedFrames'] > 0)
            <p class="mt-3 text-xs text-amber-800">{{ $pl['uncostedFrames'] }} {{ \Illuminate\Support\Str::plural('job', $pl['uncostedFrames']) }} sold a custom frame with no stock product, so the frame cost is not included.</p>
        @endif
    </section>

    <section class="ui-panel p-5 max-w-3xl text-sm" aria-label="Cash">
        <h2 class="text-sm font-semibold text-slate-900 mb-2">Cash in the period</h2>
        <div class="grid grid-cols-3 gap-3 text-xs">
            <div><p class="text-slate-500">Received</p><p class="font-mono font-semibold">{{ currency() }} {{ $money($pl['cash']['received']) }}</p></div>
            <div><p class="text-slate-500">Refunded</p><p class="font-mono font-semibold">{{ currency() }} {{ $money($pl['cash']['refunded']) }}</p></div>
            <div><p class="text-slate-500">Net cash</p><p class="font-mono font-semibold">{{ currency() }} {{ $money($pl['cash']['net']) }}</p></div>
        </div>
        <p class="mt-2 text-xs text-slate-500">Deposits, balances and partner payments received on optical sales. It differs from revenue when customers pay before or after the job is ordered.</p>
    </section>
</div>
