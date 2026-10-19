@php
    $money = fn ($v) => currency().' '.number_format((float) $v, 2);
    $canCloseDay = (bool) auth()->user()?->hasAnyRole(['Cashier', 'Manager', 'Super Admin']);
    $shortcut = 'flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 no-underline hover:border-teal-400 hover:bg-teal-50 hover:no-underline';
@endphp
<div class="clinic-ui ui-page space-y-6" data-livewire-root>

    <x-ui.page-header title="Cashier Dashboard" :description="now()->format('l, d F Y')">
        @if($canCloseDay)<x-ui.button :href="route('admin.day-close')"><i class="fas fa-lock" aria-hidden="true"></i>Day close</x-ui.button>@endif
        <x-ui.button variant="primary" :href="route('cashier.seller-desk')"><i class="fas fa-cash-register" aria-hidden="true"></i>Open POS</x-ui.button>
    </x-ui.page-header>

    <div class="ui-tiles">
        <x-ui.stat-tile label="Sales today" :value="$money($todaySales)" hint="Collected, all methods" icon="fa-coins" tone="accent" :href="route('cashier.sales-records')" />
        <x-ui.stat-tile label="Transactions" :value="number_format($transactionsToday)" :hint="number_format($paidToday).' fully paid · '.number_format($partialToday).' part paid'" icon="fa-receipt" :href="route('cashier.sales-records')" />
        <x-ui.stat-tile label="Awaiting clearance" :value="number_format($queueSize)" :tone="$queueSize > 0 ? 'warning' : 'neutral'" :hint="$queueSize > 0 ? 'Patients waiting to pay' : 'Nobody waiting'" icon="fa-user-clock" :href="route('secretary.patient-clearance')" />
        <x-ui.stat-tile label="Outstanding balances" :value="number_format($outstandingCount)" :tone="$outstandingCount > 0 ? 'warning' : 'neutral'" hint="Sales part paid" icon="fa-balance-scale" :href="route('cashier.outstanding-balances')" />
    </div>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <x-ui.section title="Awaiting clearance today" icon="fa-user-clock">
            <x-slot:actions><x-ui.button class="ui-button-sm" :href="route('secretary.patient-clearance')">Open clearance</x-ui.button></x-slot:actions>
            @if($todayQueue->isEmpty())
                <p class="ui-section-empty"><i class="fas fa-check-circle mr-1 text-green-700" aria-hidden="true"></i>No patients awaiting clearance.</p>
            @else
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead><tr><th class="w-10">#</th><th>Patient</th><th>PX number</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach($todayQueue as $i => $row)
                                <tr>
                                    <td class="text-slate-500">{{ $i + 1 }}</td>
                                    <td class="font-semibold">{{ $row->patient->name ?? '—' }}</td>
                                    <td class="font-mono text-xs">{{ $row->patient->pxnumber ?? '—' }}</td>
                                    <td><span class="inline-flex rounded-md bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Awaiting</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.section>

        <x-ui.section title="Shortcuts" icon="fa-bolt">
            <nav class="grid gap-2 p-4" aria-label="Cashier shortcuts">
                <a href="{{ route('cashier.outstanding-balances') }}" class="{{ $shortcut }}"><span><i class="fas fa-balance-scale mr-2 text-slate-400" aria-hidden="true"></i>Collect balances</span>@if($outstandingCount > 0)<span class="rounded-md bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{{ $outstandingCount }}</span>@endif</a>
                <a href="{{ route('cashier.sales-records') }}" class="{{ $shortcut }}"><span><i class="fas fa-list mr-2 text-slate-400" aria-hidden="true"></i>Sales records</span></a>
                <a href="{{ route('refunds.logs') }}" class="{{ $shortcut }}"><span><i class="fas fa-undo mr-2 text-slate-400" aria-hidden="true"></i>Refund log</span></a>
                @if($canCloseDay)<a href="{{ route('admin.day-close') }}" class="{{ $shortcut }}"><span><i class="fas fa-lock mr-2 text-slate-400" aria-hidden="true"></i>Close the day</span></a>@endif
            </nav>
        </x-ui.section>
    </div>

    <x-ui.section title="Sales, last 7 days" :description="'This month so far: '.$money($monthSales)" icon="fa-chart-bar">
        <x-slot:actions><x-ui.button class="ui-button-sm" :href="route('cashier.sales-records')">All records</x-ui.button></x-slot:actions>
        <div class="p-4"><div wire:ignore class="relative h-[260px]"><canvas id="cashierSalesChart" role="img" aria-label="Sales for the last 7 days"></canvas></div></div>
    </x-ui.section>

{{-- Chart.js --}}
<script>
document.addEventListener('livewire:init', function () {
    const ctx = document.getElementById('cashierSalesChart');
    if (!ctx) return;

    const labels  = @json($chartLabels);
    const totals  = @json($chartData);
    const context = ctx.getContext('2d');

    const gradient = context.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, 'rgba(8,126,131,0.28)');
    gradient.addColorStop(1, 'rgba(8,126,131,0.02)');

    new Chart(context, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Sales ({{ currency() }})',
                data: totals,
                backgroundColor: gradient,
                borderColor: '#087e83',
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: { usePointStyle: true, padding: 16, font: { size: 12, weight: '600' } }
                },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: function (c) {
                            return ' {{ currency() }} ' + c.parsed.y.toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,.05)', borderDash: [4,4] },
                    ticks: {
                        callback: function (v) { return '{{ currency() }} ' + v.toLocaleString(); },
                        font: { size: 11 }
                    }
                }
            }
        }
    });
});
</script>

</div>
