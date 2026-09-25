<div class="content p-3" style="background:#f0f2f5; min-height:100vh;">
<div class="container-fluid">

    {{-- Page Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold" style="color:#2c3e50;">
                <i class="fas fa-tachometer-alt mr-2 text-primary"></i>Admin Dashboard
            </h3>
            <small class="text-muted text-uppercase font-weight-bold" style="letter-spacing:.05em;">
                Overview &mdash; {{ now()->format('l, F d Y') }}
                @if(count($lineOptions) > 1) &middot; Money figures: {{ $line === 'combined' ? 'Clinic & Optical' : $lineLabels[$line] }}@endif
            </small>
            @if(count($lineOptions) > 1)
                <div class="btn-group btn-group-sm d-flex mt-2" role="group" aria-label="Business line">
                    @foreach($lineOptions as $option)
                        <a href="{{ route('admin.dashboard', ['line' => $option]) }}" class="btn {{ $line === $option ? 'btn-primary' : 'btn-outline-primary' }}" @if($line === $option) aria-current="page" @endif>{{ $lineLabels[$option] }}</a>
                    @endforeach
                </div>
            @endif
        </div>
        <a href="{{ $line === 'optical' ? route('optical.reports') : route('admin.reports') }}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-chart-bar mr-1"></i>Full Reports
        </a>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 1: Top KPI Cards                                              --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-users fa-lg"></i></div>
                <div class="kpi-card__label">Total Patients</div>
                <div class="kpi-card__value">{{ number_format($totalPatients) }}</div>
                <a href="{{ route('doctor.all-records') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#007bff;">
                    More Info <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100">
                <div class="kpi-card__icon"><i class="fas fa-cash-register fa-lg"></i></div>
                <div class="kpi-card__label">Today's Sales</div>
                <div class="kpi-card__value kpi-card__value--green">{{ currency() }} {{ number_format($todayRevenue, 2) }}</div>
                <a href="{{ $todayLink }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#28a745;">
                    More Info <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100">
                <div class="kpi-card__icon"><i class="fas fa-calendar-check fa-lg"></i></div>
                <div class="kpi-card__label">Today's Appointments</div>
                <div class="kpi-card__value kpi-card__value--orange">{{ number_format($todayAppointments) }}</div>
                <a href="{{ route('secretary.appointments') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#fd7e14;">
                    More Info <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--teal h-100">
                <div class="kpi-card__icon"><i class="fas fa-boxes fa-lg"></i></div>
                <div class="kpi-card__label">Products In Stock</div>
                <div class="kpi-card__value kpi-card__value--teal">{{ number_format($productsInStock) }}</div>
                <a href="{{ route('admin.product') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#20c997;">
                    More Info <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 2: Financial Cards                                             --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100">
                <div class="kpi-card__icon"><i class="fas fa-chart-line fa-lg"></i></div>
                <div class="kpi-card__label">This Month's Revenue</div>
                <div class="kpi-card__value kpi-card__value--green">{{ currency() }} {{ number_format($monthRevenue, 2) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('F Y') }}</small>
                <a href="{{ $statementLink[1] }}"
                   class="small font-weight-bold mt-1 d-block" style="color:#28a745;">
                    {{ $statementLink[0] }} <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--red h-100">
                <div class="kpi-card__icon"><i class="fas fa-file-invoice-dollar fa-lg"></i></div>
                <div class="kpi-card__label">Expenses This Month</div>
                <div class="kpi-card__value kpi-card__value--red">{{ currency() }} {{ number_format($monthExpenses, 2) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('F Y') }}</small>
                <a href="{{ $expensesLink }}"
                   class="small font-weight-bold mt-1 d-block" style="color:#dc3545;">
                    Expense Tracker <i class="fas fa-arrow-circle-right ml-1"></i>
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
                <a href="{{ $outstandingLink }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#e0a800;">
                    More Info <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--purple h-100">
                <div class="kpi-card__icon"><i class="fas fa-tags fa-lg"></i></div>
                <div class="kpi-card__label">Pending Discount Approvals</div>
                <div class="kpi-card__value kpi-card__value--purple">
                    {{ number_format($pendingDiscounts) }}
                    @if($pendingDiscounts > 0)
                        <span class="badge badge-danger ml-1" style="font-size:.55rem; vertical-align:middle;">Action Needed</span>
                    @endif
                </div>
                <a href="{{ route('admin.discount-approvals') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#6f42c1;">
                    More Info <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 3: Revenue Bar Chart (past 7 days)                            --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 font-weight-bold text-dark">
                            <i class="fas fa-chart-bar text-primary mr-2"></i>Revenue &mdash; Last 7 Days
                        </h6>
                        <small class="text-muted">Daily collected revenue ({{ currency() }})</small>
                    </div>
                    <a href="{{ $line === 'optical' ? route('optical.reports') : route('admin.reports') }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-external-link-alt mr-1"></i>Full Report
                    </a>
                </div>
                <div class="card-body">
                    <div wire:ignore style="position:relative; height:260px;">
                        <canvas id="dashboardRevenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 4: Clinic Stats                                                --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-user-plus fa-lg"></i></div>
                <div class="kpi-card__label">New Patients This Month</div>
                <div class="kpi-card__value">{{ number_format($newPatientsMonth) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('F Y') }}</small>
                <a href="{{ route('doctor.all-records') }}"
                   class="small font-weight-bold mt-1 d-block" style="color:#007bff;">
                    View Records <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--teal h-100">
                <div class="kpi-card__icon"><i class="fas fa-stethoscope fa-lg"></i></div>
                <div class="kpi-card__label">Consultations Today</div>
                <div class="kpi-card__value kpi-card__value--teal">{{ number_format($consultationsToday) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('D, d M Y') }}</small>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100">
                <div class="kpi-card__icon"><i class="fas fa-prescription-bottle-alt fa-lg"></i></div>
                <div class="kpi-card__label">Pending Prescriptions</div>
                <div class="kpi-card__value kpi-card__value--orange">
                    {{ number_format($pendingPrescriptions) }}
                    @if($pendingPrescriptions > 0)
                        <span class="badge badge-warning ml-1" style="font-size:.55rem; vertical-align:middle;">Awaiting Dispensing</span>
                    @endif
                </div>
                <small class="text-muted mt-1 d-block">Prescriptions not yet dispensed</small>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 5: Inventory Alert Info-Boxes                                  --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-3 col-md-6 mb-3">
            <a href="{{ route('admin.inventory-alerts') }}" class="d-block text-decoration-none">
                <div class="info-box shadow-sm" style="border-radius:12px; margin-bottom:0;">
                    <span class="info-box-icon bg-warning" style="border-radius:12px 0 0 12px;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text font-weight-bold text-dark">Low Stock</span>
                        <span class="info-box-number">{{ number_format($lowStockCount) }}</span>
                        <small class="text-muted">&le; 10 units remaining</small>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <a href="{{ route('admin.inventory-alerts') }}" class="d-block text-decoration-none">
                <div class="info-box shadow-sm" style="border-radius:12px; margin-bottom:0;">
                    <span class="info-box-icon bg-danger" style="border-radius:12px 0 0 12px;">
                        <i class="fas fa-times-circle"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text font-weight-bold text-dark">Out of Stock</span>
                        <span class="info-box-number">{{ number_format($outOfStockCount) }}</span>
                        <small class="text-muted">Zero quantity</small>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <a href="{{ route('admin.inventory-alerts') }}" class="d-block text-decoration-none">
                <div class="info-box shadow-sm" style="border-radius:12px; margin-bottom:0;">
                    <span class="info-box-icon bg-info" style="border-radius:12px 0 0 12px;">
                        <i class="fas fa-clock"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text font-weight-bold text-dark">Expiring Soon</span>
                        <span class="info-box-number">{{ number_format($expiringSoonCount) }}</span>
                        <small class="text-muted">Within 90 days</small>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <a href="{{ route('admin.inventory-alerts') }}" class="d-block text-decoration-none">
                <div class="info-box shadow-sm" style="border-radius:12px; margin-bottom:0;">
                    <span class="info-box-icon bg-dark" style="border-radius:12px 0 0 12px;">
                        <i class="fas fa-skull-crossbones"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text font-weight-bold text-dark">Expired Products</span>
                        <span class="info-box-number">{{ number_format($expiredCount) }}</span>
                        <small class="text-muted">Past expiry date</small>
                    </div>
                </div>
            </a>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 6: Top Products + Recent Logins                                --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        {{-- Top 5 Selling Products This Month --}}
        <div class="col-xl-7 col-lg-6 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-trophy text-warning mr-2"></i>Top 5 Selling Products
                        <small class="text-muted font-weight-normal ml-1">&mdash; {{ now()->format('F Y') }}</small>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>Product</th>
                                    <th class="text-center">Qty Sold</th>
                                    <th class="text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($topProducts as $i => $item)
                                    <tr>
                                        <td>
                                            <span class="rank-badge rank-badge--{{ $i + 1 <= 3 ? ($i + 1) : '' }}">
                                                {{ $i + 1 }}
                                            </span>
                                        </td>
                                        <td class="font-weight-bold">
                                            {{ $item['name'] }}
                                            @if($line === 'combined')<small class="text-muted font-weight-normal">· {{ $lineLabels[$item['line']] }}</small>@endif
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-light border">{{ number_format($item['total_qty']) }}</span>
                                        </td>
                                        <td class="text-right font-weight-bold text-success">
                                            {{ currency() }} {{ number_format($item['total_revenue'], 2) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="fas fa-box-open fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                                            No sales recorded this month.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-top text-right">
                    <a href="{{ $line === 'optical' ? route('optical.reports') : route('admin.reports') }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-chart-bar mr-1"></i>Full Sales Report
                    </a>
                </div>
            </div>
        </div>

        {{-- Recent Login Activity --}}
        <div class="col-xl-5 col-lg-6 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-shield-alt text-info mr-2"></i>Recent Login Activity
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>When</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentLogins as $log)
                                    @php
                                        $roleName = $log->user?->roles->first()?->name ?? 'Staff';
                                        $roleBadge = match($roleName) {
                                            'Super Admin' => 'danger',
                                            'Manager'     => 'warning',
                                            'Doctor'      => 'primary',
                                            'Cashier'     => 'success',
                                            'Secretary'   => 'info',
                                            default       => 'secondary',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="font-weight-bold" style="font-size:.85rem;">
                                            {{ $log->user->name ?? 'Unknown' }}
                                        </td>
                                        <td>
                                            <span class="badge badge-{{ $roleBadge }}">{{ $roleName }}</span>
                                        </td>
                                        <td class="small text-muted">
                                            {{ optional($log->login_at)->diffForHumans() ?? '—' }}
                                        </td>
                                        <td>
                                            <code style="font-size:.72rem;">{{ $log->ip_address }}</code>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="fas fa-user-clock fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                                            No login records found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-top text-right">
                    <a href="{{ route('admin.login-history') }}" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-history mr-1"></i>Full Login History
                    </a>
                </div>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 7: Bottom Quick-Access Cards                                   --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-4">

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-user-shield fa-lg"></i></div>
                <div class="kpi-card__label">Total Active Users</div>
                <div class="kpi-card__value">{{ number_format($totalActiveUsers) }}</div>
                <a href="{{ route('admin.users') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#007bff;">
                    Manage Users <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="kpi-card__icon"><i class="fas fa-file-invoice-dollar fa-lg"></i></div>
                    <div class="kpi-card__label">Financial Reports</div>
                    <p class="small text-muted mt-1 mb-0">View revenue, expenses and profit statements.</p>
                </div>
                <a href="{{ route('admin.income-statement') }}" class="btn btn-success btn-sm mt-3">
                    <i class="fas fa-external-link-alt mr-1"></i>Income Statement
                </a>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="kpi-card__icon"><i class="fas fa-clipboard-list fa-lg"></i></div>
                    <div class="kpi-card__label">System Audit</div>
                    <p class="small text-muted mt-1 mb-0">Track all system events and user activity.</p>
                </div>
                <a href="{{ route('admin.audit-trail') }}" class="btn btn-warning btn-sm mt-3">
                    <i class="fas fa-external-link-alt mr-1"></i>Audit Trail
                </a>
            </div>
        </div>

    </div>

</div>{{-- /container-fluid --}}

{{-- Chart.js — fires after Livewire mounts the DOM --}}
<script>
document.addEventListener('livewire:init', function () {
    const ctx = document.getElementById('dashboardRevenueChart');
    if (!ctx) return;

    const labels   = @json($revenueChartLabels);
    const revenues = @json($revenueChartData);

    const context  = ctx.getContext('2d');
    const gradient = context.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, 'rgba(40,167,69,0.28)');
    gradient.addColorStop(1, 'rgba(40,167,69,0.02)');

    new Chart(context, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue ({{ currency() }})',
                data: revenues,
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
                        label: function (ctx) {
                            return ' {{ currency() }} ' + ctx.parsed.y.toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,.05)', borderDash: [4, 4] },
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
/* KPI Cards — same styles as reports-component.blade.php */
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
.kpi-card--red::before    { background: #dc3545; }
.kpi-card__icon { font-size: 1.1rem; margin-bottom: .4rem; color: #adb5bd; }
.kpi-card--blue .kpi-card__icon   { color: #007bff; }
.kpi-card--green .kpi-card__icon  { color: #28a745; }
.kpi-card--orange .kpi-card__icon { color: #fd7e14; }
.kpi-card--teal .kpi-card__icon   { color: #20c997; }
.kpi-card--purple .kpi-card__icon { color: #6f42c1; }
.kpi-card--yellow .kpi-card__icon { color: #ffc107; }
.kpi-card--red .kpi-card__icon    { color: #dc3545; }
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
.kpi-card__value--red    { color: #dc3545; }

/* Rank badges for top products table */
.rank-badge {
    width: 26px; height: 26px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .75rem;
    font-weight: 700;
    background: #e9ecef;
    color: #495057;
}
.rank-badge--1 { background: #ffd700; color: #856404; }
.rank-badge--2 { background: #c0c0c0; color: #495057; }
.rank-badge--3 { background: #cd7f32; color: #fff;    }
</style>

</div>{{-- /content --}}
