<div data-livewire-root>
<div class="content p-3" style="background:#f0f2f5; min-height:100vh;">
<div class="container-fluid">

    {{-- Page Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold" style="color:#2c3e50;">
                <i class="fas fa-cash-register mr-2 text-success"></i>Cashier Dashboard
            </h3>
            <small class="text-muted text-uppercase font-weight-bold" style="letter-spacing:.05em;">
                Overview &mdash; {{ now()->format('l, F d Y') }}
            </small>
        </div>
        <a href="{{ route('cashier.seller-desk') }}" class="btn btn-success btn-sm">
            <i class="fas fa-cash-register mr-1"></i>Open POS
        </a>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 1: Top KPI Cards                                              --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100">
                <div class="kpi-card__icon"><i class="fas fa-coins fa-lg"></i></div>
                <div class="kpi-card__label">Today's Sales</div>
                <div class="kpi-card__value kpi-card__value--green">{{ currency() }} {{ number_format($todaySales, 2) }}</div>
                <a href="{{ route('cashier.sales-records') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#28a745;">
                    Sales Records <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-receipt fa-lg"></i></div>
                <div class="kpi-card__label">Transactions Today</div>
                <div class="kpi-card__value">{{ number_format($transactionsToday) }}</div>
                <a href="{{ route('cashier.sales-records') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#007bff;">
                    More Info <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--yellow h-100">
                <div class="kpi-card__icon"><i class="fas fa-balance-scale fa-lg"></i></div>
                <div class="kpi-card__label">Outstanding Balances</div>
                <div class="kpi-card__value kpi-card__value--yellow">
                    {{ number_format($outstandingCount) }}
                    <small class="text-muted" style="font-size:.65rem; font-weight:500;">partial payments</small>
                </div>
                <a href="{{ route('cashier.outstanding-balances') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#e0a800;">
                    Collect Balances <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100">
                <div class="kpi-card__icon"><i class="fas fa-user-clock fa-lg"></i></div>
                <div class="kpi-card__label">Awaiting Clearance</div>
                <div class="kpi-card__value kpi-card__value--orange">{{ number_format($queueSize) }}</div>
                <a href="{{ route('secretary.patient-clearance') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#fd7e14;">
                    View Queue <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 2: Secondary Stats                                             --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100">
                <div class="kpi-card__icon"><i class="fas fa-chart-line fa-lg"></i></div>
                <div class="kpi-card__label">This Month's Sales</div>
                <div class="kpi-card__value kpi-card__value--green">{{ currency() }} {{ number_format($monthSales, 2) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('F Y') }}</small>
                <a href="{{ route('cashier.sales-records') }}"
                   class="small font-weight-bold mt-1 d-block" style="color:#28a745;">
                    Sales Records <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--teal h-100">
                <div class="kpi-card__icon"><i class="fas fa-check-circle fa-lg"></i></div>
                <div class="kpi-card__label">Fully Paid Today</div>
                <div class="kpi-card__value kpi-card__value--teal">{{ number_format($paidToday) }}</div>
                <small class="text-muted mt-1 d-block">complete transactions</small>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--purple h-100">
                <div class="kpi-card__icon"><i class="fas fa-hourglass-half fa-lg"></i></div>
                <div class="kpi-card__label">Partial Payments Today</div>
                <div class="kpi-card__value kpi-card__value--purple">
                    {{ number_format($partialToday) }}
                    @if($partialToday > 0)
                        <span class="badge badge-warning ml-1" style="font-size:.55rem; vertical-align:middle;">Pending</span>
                    @endif
                </div>
                <a href="{{ route('cashier.outstanding-balances') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#6f42c1;">
                    Collect Balances <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 3: Sales Bar Chart (past 7 days)                               --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 font-weight-bold text-dark">
                            <i class="fas fa-chart-bar text-success mr-2"></i>Sales &mdash; Last 7 Days
                        </h6>
                        <small class="text-muted">Daily collected revenue ({{ currency() }})</small>
                    </div>
                    <a href="{{ route('cashier.sales-records') }}" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-external-link-alt mr-1"></i>All Records
                    </a>
                </div>
                <div class="card-body">
                    <div wire:ignore style="position:relative; height:260px;">
                        <canvas id="cashierSalesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 4: Quick Actions + Today's Queue                               --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row">

        {{-- Quick Access --}}
        <div class="col-xl-4 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-bolt text-warning mr-2"></i>Quick Access
                    </h6>
                </div>
                <div class="card-body d-flex flex-column justify-content-center gap-2" style="gap:.75rem;">
                    <a href="{{ route('cashier.seller-desk') }}"
                       class="btn btn-success btn-block py-3"
                       style="font-size:1.05rem; font-weight:700; border-radius:10px;">
                        <i class="fas fa-cash-register fa-lg mr-2"></i>Point of Sale (POS)
                    </a>
                    <a href="{{ route('cashier.outstanding-balances') }}"
                       class="btn btn-outline-warning btn-block py-2"
                       style="font-weight:600; border-radius:10px;">
                        <i class="fas fa-balance-scale mr-2"></i>Outstanding Balances
                        @if($outstandingCount > 0)
                            <span class="badge badge-warning ml-1">{{ $outstandingCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('cashier.sales-records') }}"
                       class="btn btn-outline-secondary btn-block py-2"
                       style="font-weight:600; border-radius:10px;">
                        <i class="fas fa-list mr-2"></i>Sales Records
                    </a>
                    <a href="{{ route('refunds.logs') }}"
                       class="btn btn-outline-secondary btn-block py-2"
                       style="font-weight:600; border-radius:10px;">
                        <i class="fas fa-undo mr-2"></i>Refund Logs
                    </a>
                </div>
            </div>
        </div>

        {{-- Today's Queue --}}
        <div class="col-xl-8 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-user-clock text-orange mr-2" style="color:#fd7e14;"></i>
                        Awaiting Clearance Today
                    </h6>
                    <a href="{{ route('secretary.patient-clearance') }}" class="btn btn-sm btn-outline-secondary">
                        View All
                    </a>
                </div>
                <div class="card-body p-0">
                    @if($todayQueue->isEmpty())
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                            <p class="mb-0">No patients awaiting clearance.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" style="font-size:.85rem;">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Patient</th>
                                        <th>PX No.</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($todayQueue as $i => $row)
                                        <tr>
                                            <td class="text-muted">{{ $i + 1 }}</td>
                                            <td class="font-weight-bold">{{ $row->patient->name ?? '—' }}</td>
                                            <td><span class="badge badge-light border">{{ $row->patient->pxnumber ?? '—' }}</span></td>
                                            <td>
                                                <span class="badge badge-warning">Awaiting</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

    </div>

</div>{{-- /container-fluid --}}
</div>{{-- /content --}}

{{-- Chart.js --}}
<script>
document.addEventListener('livewire:init', function () {
    const ctx = document.getElementById('cashierSalesChart');
    if (!ctx) return;

    const labels  = @json($chartLabels);
    const totals  = @json($chartData);
    const context = ctx.getContext('2d');

    const gradient = context.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, 'rgba(40,167,69,0.28)');
    gradient.addColorStop(1, 'rgba(40,167,69,0.02)');

    new Chart(context, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Sales ({{ currency() }})',
                data: totals,
                backgroundColor: gradient,
                borderColor: '#28a745',
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

<style>
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
.kpi-card__icon { font-size: 1.1rem; margin-bottom: .4rem; }
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
.kpi-card__value { font-size: 1.1rem; font-weight: 800; color: #212529; line-height: 1.2; }
.kpi-card__value--green  { color: #28a745; }
.kpi-card__value--orange { color: #fd7e14; }
.kpi-card__value--teal   { color: #20c997; }
.kpi-card__value--purple { color: #6f42c1; }
.kpi-card__value--yellow { color: #e0a800; }
</style>
</div>
