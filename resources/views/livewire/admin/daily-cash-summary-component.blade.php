@php
    $money = fn ($v) => number_format((float) $v, 2);
    $collected = (float) $payments->sum('total');
    $lineName = count($lineOptions) > 1 ? ($line === 'combined' ? 'Clinic & Optical' : $lineLabels[$line]) : null;
    $tile = 'ui-panel flex h-full flex-col gap-1 px-4 py-3';
    $tileLabel = 'flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $tileValue = 'text-2xl font-semibold tabular-nums text-slate-900';
    $tileHint = 'mt-auto text-xs text-slate-500';
@endphp
<div class="clinic-ui ui-page space-y-5 cash-summary-page">

    <div class="ui-heading">
        <div>
            <h1>Daily Cash Summary</h1>
            <p class="ui-muted">{{ $periodLabel }}@if($lineName) · {{ $lineName }}@endif</p>
        </div>
        <div class="no-print flex flex-wrap items-center gap-2">
            @if(count($lineOptions) > 1)
                <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm font-semibold" role="group" aria-label="Business line">
                    @foreach($lineOptions as $option)
                        <button type="button" wire:click="$set('line', '{{ $option }}')" aria-pressed="{{ $line === $option ? 'true' : 'false' }}"
                                class="px-3 py-2 {{ $line === $option ? 'bg-teal-700 text-white' : 'bg-white text-slate-700 hover:bg-slate-50' }} {{ ! $loop->first ? 'border-l border-slate-300' : '' }}">{{ $lineLabels[$option] }}</button>
                    @endforeach
                </div>
            @endif
            <div class="min-w-[12rem]"><x-date-range from="fromDate" to="toDate" presets="finance" /></div>
            <a href="{{ route('admin.day-close') }}" wire:navigate class="ui-button ui-button-secondary"><i class="fas fa-cash-register" aria-hidden="true"></i>Day close</a>
            <button type="button" class="ui-button ui-button-primary" wire:click="print"><i class="fas fa-print" aria-hidden="true"></i>Print</button>
        </div>
    </div>

    {{-- The four figures that matter first, all the same size. --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="{{ $tile }} border-l-4 !border-l-teal-600">
            <span class="{{ $tileLabel }}"><i class="fas fa-money-bill-wave text-teal-700" aria-hidden="true"></i>Collected</span>
            <span class="{{ $tileValue }}">{{ currency() }} {{ $money($amountPaid) }}</span>
            <span class="{{ $tileHint }}">Money taken in, all methods</span>
        </div>
        <div class="{{ $tile }}">
            <span class="{{ $tileLabel }}"><i class="fas fa-cash-register text-green-700" aria-hidden="true"></i>Gross sales</span>
            <span class="{{ $tileValue }}">{{ currency() }} {{ $money($grossSales) }}</span>
            <span class="{{ $tileHint }}">{{ $salesCount }} {{ \Illuminate\Support\Str::plural('transaction', $salesCount) }}</span>
        </div>
        <div class="{{ $tile }}">
            <span class="{{ $tileLabel }}"><i class="fas fa-balance-scale text-amber-600" aria-hidden="true"></i>Still owed</span>
            <span class="{{ $tileValue }} {{ (float) $outstandingCreated > 0 ? '!text-amber-700' : '' }}">{{ currency() }} {{ $money($outstandingCreated) }}</span>
            <span class="{{ $tileHint }}">Left unpaid on these sales</span>
        </div>
        <div class="{{ $tile }}">
            <span class="{{ $tileLabel }}"><i class="fas fa-undo text-red-700" aria-hidden="true"></i>Refunds</span>
            <span class="{{ $tileValue }} {{ (float) $refundsTotal > 0 ? '!text-red-700' : '' }}">{{ currency() }} {{ $money($refundsTotal) }}</span>
            <span class="{{ $tileHint }}">{{ $refundsCount }} {{ \Illuminate\Support\Str::plural('sale', $refundsCount) }} refunded</span>
        </div>
    </div>

    <div class="grid items-start gap-5 lg:grid-cols-[3fr_2fr]">
        <section class="ui-panel" aria-labelledby="breakdown-title">
            <div class="flex items-center justify-between gap-2 border-b border-slate-200 px-5 py-4">
                <h2 id="breakdown-title" class="text-base font-semibold text-slate-900"><i class="fas fa-table mr-2 text-teal-700" aria-hidden="true"></i>By payment method</h2>
                <span class="text-sm text-slate-500">{{ $payments->sum('count') }} {{ \Illuminate\Support\Str::plural('payment', (int) $payments->sum('count')) }}</span>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Method</th><th class="ui-number">Payments</th><th class="hidden w-2/5 sm:table-cell">Share</th><th class="ui-number">Amount</th></tr></thead>
                    <tbody>
                        @forelse($payments as $payment)
                            @php $share = $collected > 0 ? round((float) $payment->total / $collected * 100) : 0; @endphp
                            <tr>
                                <td class="font-semibold">
                                    <span class="mr-2 inline-block h-2.5 w-2.5 rounded-full align-middle" style="background: {{ \App\Support\PaymentMethods::color($payment->payment_method) }}" aria-hidden="true"></span>{{ \App\Support\PaymentMethods::label($payment->payment_method) }}
                                </td>
                                <td class="ui-number">{{ $payment->count }}</td>
                                <td class="hidden sm:table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-2 rounded-full" style="width: {{ $share }}%; background: {{ \App\Support\PaymentMethods::color($payment->payment_method) }}"></div></div>
                                        <span class="w-10 text-right text-xs tabular-nums text-slate-500">{{ $share }}%</span>
                                    </div>
                                </td>
                                <td class="ui-number font-semibold">{{ currency() }} {{ $money($payment->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-empty italic text-slate-500">No payments on these dates.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-50 font-semibold">
                            <td>Total collected</td><td class="ui-number">{{ $payments->sum('count') }}</td><td class="hidden sm:table-cell"></td>
                            <td class="ui-number">{{ currency() }} {{ $money($collected) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if($collectedByLine->isNotEmpty())
                <div class="grid gap-3 border-t border-slate-200 px-5 py-4 sm:grid-cols-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 sm:col-span-2">Collected by business</p>
                    @foreach(['clinic', 'optical'] as $businessLine)
                        <div class="flex items-baseline justify-between rounded-lg border border-slate-200 px-3 py-2">
                            <span class="text-sm font-semibold text-slate-700">{{ $lineLabels[$businessLine] }} <span class="font-normal text-slate-500">· {{ (int) ($collectedByLine[$businessLine]->count ?? 0) }}</span></span>
                            <span class="font-semibold tabular-nums">{{ currency() }} {{ $money($collectedByLine[$businessLine]->total ?? 0) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="ui-panel" aria-labelledby="chart-title">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 id="chart-title" class="text-base font-semibold text-slate-900"><i class="fas fa-chart-pie mr-2 text-teal-700" aria-hidden="true"></i>How payments came in</h2>
            </div>
            <div class="p-4">
                @if($payments->isNotEmpty())
                    <div wire:ignore class="relative h-[280px]"><canvas id="paymentDonut" aria-label="Payments by method" role="img"></canvas></div>
                @else
                    <div class="flex h-[280px] flex-col items-center justify-center text-slate-300">
                        <i class="fas fa-chart-pie fa-4x mb-3" aria-hidden="true"></i>
                        <span class="text-sm text-slate-500">No payments on these dates</span>
                    </div>
                @endif
            </div>
        </section>
    </div>

    @if($insurance)
        <section class="ui-panel" aria-labelledby="insurance-title">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 id="insurance-title" class="text-base font-semibold text-slate-900"><i class="fas fa-shield-alt mr-2 text-sky-700" aria-hidden="true"></i>Insurance</h2>
            </div>
            <div class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                <div class="px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Billed to insurers</p>
                    <p class="text-xl font-semibold tabular-nums text-slate-900">{{ currency() }} {{ $money($insurance['billed']) }}</p>
                    <p class="text-xs text-slate-500">Part of gross sales; the insurer pays later</p>
                </div>
                <div class="px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Received from insurers</p>
                    <p class="text-xl font-semibold tabular-nums text-green-700">{{ currency() }} {{ $money($insurance['received']) }}</p>
                    <p class="text-xs text-slate-500">Recorded under Insurer Payments, not in the drawer</p>
                </div>
                <div class="px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Shortfalls written off</p>
                    <p class="text-xl font-semibold tabular-nums {{ (float) $insurance['writtenOff'] > 0 ? 'text-red-700' : 'text-slate-900' }}">{{ currency() }} {{ $money($insurance['writtenOff']) }}</p>
                    <p class="text-xs text-slate-500">What insurers did not pay and the clinic absorbed</p>
                </div>
            </div>
        </section>
    @endif

    <script>
        window.addEventListener('print-page', function () { window.print(); });

        (function () {
            var paymentChart = null;

            function initPaymentChart(d) {
                var canvas = document.getElementById('paymentDonut');
                if (!canvas) return;
                if (!d.labels || !d.labels.length) return;

                var total = d.data.reduce(function (a, b) { return a + b; }, 0);

                if (paymentChart) { paymentChart.destroy(); paymentChart = null; }

                paymentChart = new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: d.labels,
                        datasets: [{
                            data: d.data,
                            backgroundColor: d.colors,
                            borderWidth: 3,
                            borderColor: '#fff',
                            hoverOffset: 8,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    usePointStyle: true,
                                    padding: 16,
                                    font: { size: 12 },
                                    generateLabels: function (chart) {
                                        var ds = chart.data.datasets[0];
                                        return chart.data.labels.map(function (label, i) {
                                            var val = ds.data[i].toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
                                callbacks: {
                                    label: function (ctx) {
                                        var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                                        return ' ' + ctx.label + ': {{ currency() }} ' + ctx.parsed.toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' (' + pct + '%)';
                                    },
                                },
                            },
                        },
                    },
                });
            }

            // Initialize on first page load from server-rendered data
            initPaymentChart(@json($chartPayload));

            // Re-draw when date changes (Livewire AJAX re-render)
            window.addEventListener('update-payment-chart', function (e) {
                initPaymentChart(e.detail);
            });
        })();
    </script>
    <style>
        @media print {
            .no-print, .main-sidebar, .main-header, .content-header { display:none !important; }
            .content-wrapper, .content, .container-fluid { margin:0 !important; padding:0 !important; }
            .card { box-shadow:none !important; border:0 !important; }
        }
    </style>
</div>
