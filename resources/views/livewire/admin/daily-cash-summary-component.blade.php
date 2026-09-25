<div class="content p-3">
    <div class="container-fluid cash-summary-page">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div>
                <h3 class="mb-0 text-primary font-weight-bold">Daily Cash Summary</h3>
                <small class="text-muted text-uppercase font-weight-bold">End-of-day reconciliation</small>
                @if(count($lineOptions) > 1)
                    <div class="btn-group btn-group-sm d-flex mt-2" role="group" aria-label="Business line">
                        @foreach($lineOptions as $option)
                            <button type="button" wire:click="$set('line', '{{ $option }}')" class="btn {{ $line === $option ? 'btn-primary' : 'btn-outline-primary' }}" aria-pressed="{{ $line === $option ? 'true' : 'false' }}">{{ $lineLabels[$option] }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="d-flex" style="gap:.5rem;">
                <input type="date" class="form-control" wire:model.live="reportDate">
                <button class="btn btn-primary" wire:click="print"><i class="fas fa-print mr-1"></i>Print</button>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="text-center mb-3">
                    <h4 class="font-weight-bold mb-1">Daily Cash Summary @if(count($lineOptions) > 1)<small class="text-muted">· {{ $line === 'combined' ? 'Clinic & Optical' : $lineLabels[$line] }}</small>@endif</h4>
                    <div class="text-muted">{{ \Carbon\Carbon::parse($reportDate)->format('M d, Y') }}</div>
                </div>
                <div class="row">
                    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-primary"><i class="fas fa-receipt"></i></span><div class="info-box-content"><span class="info-box-text">Transactions</span><span class="info-box-number">{{ $salesCount }}</span></div></div></div>
                    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-cash-register"></i></span><div class="info-box-content"><span class="info-box-text">Gross Sales</span><span class="info-box-number">{{ currency() }} {{ number_format($grossSales, 2) }}</span></div></div></div>
                    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-money-bill"></i></span><div class="info-box-content"><span class="info-box-text">Collected</span><span class="info-box-number">{{ currency() }} {{ number_format($amountPaid, 2) }}</span></div></div></div>
                    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-balance-scale"></i></span><div class="info-box-content"><span class="info-box-text">Outstanding</span><span class="info-box-number">{{ currency() }} {{ number_format($outstandingCreated, 2) }}</span></div></div></div>
                </div>

                <div class="row mt-2">
                    {{-- Left: Payment Breakdown Table --}}
                    <div class="col-md-7">
                        <h6 class="font-weight-bold mb-3"><i class="fas fa-table mr-2 text-primary"></i>Payment Breakdown</h6>
                        <table class="table table-bordered mb-0">
                            <thead class="thead-light">
                                <tr><th>Payment Method</th><th class="text-center">Entries</th><th class="text-right">Amount</th></tr>
                            </thead>
                            <tbody>
                                @forelse($payments as $payment)
                                    @php
                                        $pLabels = ['cash' => 'Cash', 'card' => 'Card', 'momo' => 'Mobile Money', 'code' => 'Hubtel Wallet'];
                                    @endphp
                                    <tr>
                                        <td class="font-weight-bold">{{ $pLabels[$payment->payment_method] ?? strtoupper($payment->payment_method) }}</td>
                                        <td class="text-center">{{ $payment->count }}</td>
                                        <td class="text-right">{{ currency() }} {{ number_format($payment->total, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted">No payments recorded.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td>Total Collected</td><td></td>
                                    <td class="text-right">{{ currency() }} {{ number_format($payments->sum('total'), 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                        @if($collectedByLine->isNotEmpty())
                            <table class="table table-sm table-bordered mt-3 mb-0">
                                <caption class="sr-only">Collected by business</caption>
                                <thead class="thead-light"><tr><th>Collected by business</th><th class="text-center">Entries</th><th class="text-right">Amount</th></tr></thead>
                                <tbody>
                                    @foreach(['clinic', 'optical'] as $businessLine)
                                        <tr>
                                            <td>{{ $lineLabels[$businessLine] }}</td>
                                            <td class="text-center">{{ (int) ($collectedByLine[$businessLine]->count ?? 0) }}</td>
                                            <td class="text-right">{{ currency() }} {{ number_format((float) ($collectedByLine[$businessLine]->total ?? 0), 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        <div class="mt-3 text-muted small">
                            <strong>Refunds:</strong> {{ $refundsCount }} transaction(s) — {{ currency() }} {{ number_format($refundsTotal, 2) }}
                        </div>
                    </div>

                    {{-- Right: Donut Chart --}}
                    <div class="col-md-5">
                        <h6 class="font-weight-bold mb-3"><i class="fas fa-chart-pie mr-2 text-primary"></i>How We Receive Payments</h6>
                        @if($payments->isNotEmpty())
                            <div wire:ignore style="position:relative; height:260px;">
                                <canvas id="paymentDonut"></canvas>
                            </div>
                        @else
                            <div class="d-flex flex-column align-items-center justify-content-center" style="height:260px; color:#ced4da;">
                                <i class="fas fa-chart-pie fa-4x mb-3"></i>
                                <span class="small text-muted">No payment data for this date</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>


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
