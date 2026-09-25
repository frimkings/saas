@php
    $money = fn ($value) => ($value < 0 ? '− ' : '').number_format(abs($value), 2);
    $emphasis = ['gross_profit' => 'font-semibold', 'operating_profit' => 'font-semibold', 'profit_before_tax' => 'font-semibold', 'net_profit' => 'font-bold'];
@endphp
<div class="clinic-ui ui-page">
    <header class="ui-heading">
        <div>
            <p class="ui-muted">Administration / Finance</p>
            <h1>Combined Statement</h1>
            <p class="ui-muted">Clinic and optical side by side, and the whole business. {{ $fromDate->format('d M Y') }} – {{ $toDate->format('d M Y') }}</p>
        </div>
        <div class="ui-actions">
            <x-ui.button :href="route('admin.combined-statement.export', ['format' => 'csv', 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()])">Export CSV</x-ui.button>
            <x-ui.button :href="route('admin.combined-statement.export', ['format' => 'pdf', 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()])">Export PDF</x-ui.button>
        </div>
    </header>

    <x-finance.statement-switcher :links="$switcher" current="combined" />

    <x-ui.panel>
        <div class="ui-grid">
            <x-date-range from="from" to="to" presets="finance" label="Period" />
        </div>
        <div class="ui-actions">
            
            <span class="ui-muted" wire:loading role="status">Updating…</span>
        </div>
    </x-ui.panel>

    <div class="ui-stats">
        <x-ui.stat label="Revenue · whole business" :value="currency().' '.$money($statement['total']['revenue'])" />
        <x-ui.stat label="Gross profit · whole business" :value="currency().' '.$money($statement['total']['gross_profit'])" />
        <x-ui.stat label="Net profit · whole business" :value="currency().' '.$money($statement['total']['net_profit'])">
            <p class="ui-muted">Previous period {{ currency() }} {{ $money($previous['total']['net_profit']) }}</p>
        </x-ui.stat>
    </div>

    <x-ui.panel>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <caption class="sr-only">Clinic, optical and whole-business figures for the selected period</caption>
                <thead>
                    <tr>
                        <th scope="col"></th>
                        <th scope="col" class="ui-number">Clinic @if($locked['clinic'])<span class="ui-muted">(locked)</span>@endif</th>
                        <th scope="col" class="ui-number">Optical @if($locked['optical'])<span class="ui-muted">(locked)</span>@endif</th>
                        <th scope="col" class="ui-number">Whole business</th>
                        <th scope="col" class="ui-number">Previous period<br><span class="ui-muted">{{ $previousFrom->format('d M') }} – {{ $previousTo->format('d M Y') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $key => $label)
                        <tr class="{{ $emphasis[$key] ?? '' }}">
                            <th scope="row">{{ $label }}</th>
                            <td class="ui-number">{{ $key === 'stock_losses' ? '—' : $money($statement['lines']['clinic'][$key]) }}</td>
                            <td class="ui-number">{{ $key === 'tax' ? '—' : $money($statement['lines']['optical'][$key]) }}</td>
                            <td class="ui-number">{{ $money($statement['total'][$key]) }}</td>
                            <td class="ui-number ui-muted">{{ $money($previous['total'][$key]) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="ui-muted">
            <p>Each business is worked out by its own statement: the clinic counts revenue at the sale, optical when a job is ordered. Open the Clinic or Optical statement for the detailed lines.</p>
            <p>Tax is the clinic income statement's tax{{ $statement['clinicTaxRate'] > 0 ? ' ('.number_format($statement['clinicTaxRate'], 2).'% of clinic profit)' : '' }}. Optical has no tax setting, so no tax is worked out on optical profit.</p>
            @if($statement['uncostedFrames'] > 0)<p>{{ $statement['uncostedFrames'] }} optical {{ \Illuminate\Support\Str::plural('job', $statement['uncostedFrames']) }} sold a custom frame with no stock product, so the frame cost is not included.</p>@endif
        </div>
    </x-ui.panel>
</div>
