<div class="content p-3" style="background:#f0f2f5; min-height:100vh;">
<div class="container-fluid">

    {{-- Page Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold" style="color:#2c3e50;">
                <i class="fas fa-concierge-bell mr-2 text-primary"></i>Secretary / Cashier Dashboard
            </h3>
            <small class="text-muted text-uppercase font-weight-bold" style="letter-spacing:.05em;">
                Welcome, {{ auth()->user()->name }} &mdash; {{ now()->format('l, F d Y') }}
            </small>
        </div>
        <a href="{{ route('cashier.seller-desk') }}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-cash-register mr-1"></i>Open POS
        </a>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 1: Top KPI Cards                                              --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-user-plus fa-lg"></i></div>
                <div class="kpi-card__label">New Patients Today</div>
                <div class="kpi-card__value">{{ number_format($patientsRegisteredToday) }}</div>
                <small class="text-muted mt-1 d-block">Registered today</small>
                <a href="{{ route('secretary.patients') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#007bff;">
                    All Patients <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100">
                <div class="kpi-card__icon"><i class="fas fa-calendar-check fa-lg"></i></div>
                <div class="kpi-card__label">Appointments Today</div>
                <div class="kpi-card__value kpi-card__value--orange">{{ number_format($appointmentsToday) }}</div>
                <small class="text-muted mt-1 d-block">Scheduled &amp; active</small>
                <a href="{{ route('secretary.appointments') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#fd7e14;">
                    View Schedule <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100">
                <div class="kpi-card__icon"><i class="fas fa-clipboard-check fa-lg"></i></div>
                <div class="kpi-card__label">Clearances Today</div>
                <div class="kpi-card__value kpi-card__value--green">{{ number_format($clearancesToday) }}</div>
                <small class="text-muted mt-1 d-block">Patients processed today</small>
                <a href="{{ route('secretary.patient-clearance') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#28a745;">
                    Clearance Desk <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--teal h-100">
                <div class="kpi-card__icon"><i class="fas fa-cash-register fa-lg"></i></div>
                <div class="kpi-card__label">Today's Sales</div>
                <div class="kpi-card__value kpi-card__value--teal">{{ currency() }} {{ number_format($todaySales, 2) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('D, d M Y') }}</small>
                <a href="{{ route('cashier.sales-records') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#20c997;">
                    Sales Records <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 2: Secondary Stat Cards                                        --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-users fa-lg"></i></div>
                <div class="kpi-card__label">Total Registered Patients</div>
                <div class="kpi-card__value">{{ number_format($totalPatients) }}</div>
                <a href="{{ route('secretary.patients') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#007bff;">
                    Patient Registry <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--yellow h-100">
                <div class="kpi-card__icon"><i class="fas fa-balance-scale fa-lg"></i></div>
                <div class="kpi-card__label">Outstanding Balances</div>
                <div class="kpi-card__value kpi-card__value--yellow">
                    {{ number_format($outstandingBalances) }}
                    <small class="text-muted" style="font-size:.65rem; font-weight:500;">partial payments</small>
                </div>
                @if($outstandingBalances > 0)
                    <span class="badge badge-warning mt-1">Needs Attention</span>
                @endif
                <a href="{{ route('cashier.outstanding-balances') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#e0a800;">
                    View Balances <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100">
                <div class="kpi-card__icon"><i class="fas fa-user-clock fa-lg"></i></div>
                <div class="kpi-card__label">Awaiting Doctor</div>
                <div class="kpi-card__value kpi-card__value--orange">
                    {{ number_format($awaitingDoctor) }}
                    @if($awaitingDoctor > 0)
                        <span class="badge badge-warning ml-1" style="font-size:.55rem; vertical-align:middle;">In Queue</span>
                    @endif
                </div>
                <small class="text-muted mt-1 d-block">Patients not yet seen by doctor</small>
                <a href="{{ route('secretary.patient-clearance') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#fd7e14;">
                    Clearance Desk <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--teal h-100">
                <div class="kpi-card__icon"><i class="fas fa-glasses fa-lg"></i></div>
                <div class="kpi-card__label">Spectacle Renewals Due</div>
                <div class="kpi-card__value kpi-card__value--teal">
                    {{ number_format($renewalsDue) }}
                    @if($renewalsDue > 0)
                        <span class="badge badge-info ml-1" style="font-size:.55rem; vertical-align:middle;">Within 30 days</span>
                    @endif
                </div>
                <div class="kpi-card__value mt-1" style="font-size:.85rem; font-weight:600;">
                    <span class="text-success">{{ number_format($spectaclesReady) }}</span>
                    <small class="text-muted" style="font-size:.65rem; font-weight:500;">ready for pickup</small>
                </div>
                <a href="{{ route('secretary.spectacles') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#20c997;">
                    Spectacles <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 3: New Patients Chart (past 7 days)                           --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 font-weight-bold text-dark">
                            <i class="fas fa-chart-bar text-primary mr-2"></i>New Patient Registrations &mdash; Last 7 Days
                        </h6>
                        <small class="text-muted">Daily count of newly registered patients</small>
                    </div>
                    <a href="{{ route('secretary.patients') }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-external-link-alt mr-1"></i>All Patients
                    </a>
                </div>
                <div class="card-body">
                    <div wire:ignore style="position:relative; height:240px;">
                        <canvas id="secretaryPatientsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 4: Today's Clearance Queue + Today's Appointments             --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        {{-- Today's Clearance Queue --}}
        <div class="col-xl-6 col-lg-6 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-clipboard-list text-success mr-2"></i>Today's Clearance Queue
                        @if($awaitingDoctor > 0)
                            <span class="badge badge-warning ml-1">{{ $awaitingDoctor }} awaiting</span>
                        @endif
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Patient</th>
                                    <th>Folder No.</th>
                                    <th>Contact</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($todayQueue as $clearance)
                                    <tr>
                                        <td class="font-weight-bold" style="font-size:.85rem;">
                                            {{ $clearance->patient->name ?? '—' }}
                                        </td>
                                        <td>
                                            <code style="font-size:.78rem;">{{ $clearance->patient->pxnumber ?? '—' }}</code>
                                        </td>
                                        <td class="small text-muted">
                                            {{ $clearance->patient->contact ?? '—' }}
                                        </td>
                                        <td class="text-center">
                                            @if($clearance->doctor_status)
                                                <span class="badge badge-success">Seen</span>
                                            @else
                                                <span class="badge badge-warning">Waiting</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="fas fa-check-circle fa-2x d-block mb-2" style="color:#28a745; opacity:.4;"></i>
                                            No clearances recorded today.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-top text-right">
                    <a href="{{ route('secretary.patient-clearance') }}" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-clipboard-check mr-1"></i>Open Clearance Desk
                    </a>
                </div>
            </div>
        </div>

        {{-- Today's Appointments --}}
        <div class="col-xl-6 col-lg-6 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-calendar-alt text-primary mr-2"></i>Today's Appointments
                        @if($appointmentsToday > 0)
                            <span class="badge badge-primary ml-1">{{ $appointmentsToday }}</span>
                        @endif
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Patient</th>
                                    <th>Folder No.</th>
                                    <th>Time</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($upcomingAppointments as $appt)
                                    @php
                                        $statusBadge = match($appt->status ?? 'scheduled') {
                                            'completed'  => 'success',
                                            'cancelled'  => 'danger',
                                            default      => 'primary',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="font-weight-bold" style="font-size:.85rem;">
                                            {{ $appt->patient->name ?? $appt->title ?? '—' }}
                                        </td>
                                        <td>
                                            <code style="font-size:.78rem;">{{ $appt->patient->pxnumber ?? '—' }}</code>
                                        </td>
                                        <td class="small text-muted">
                                            {{ optional($appt->scheduled_at)->format('h:i A') ?? '—' }}
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-{{ $statusBadge }}">
                                                {{ ucfirst($appt->status ?? 'Scheduled') }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="fas fa-calendar-times fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                                            No appointments scheduled for today.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-top text-right">
                    <a href="{{ route('secretary.appointments') }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-calendar-alt mr-1"></i>Manage Appointments
                    </a>
                </div>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 5: Quick Access Links                                          --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-4">

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="kpi-card__icon"><i class="fas fa-user-plus fa-lg"></i></div>
                    <div class="kpi-card__label">Patient Registry</div>
                    <p class="small text-muted mt-1 mb-0">Register new patients and manage records.</p>
                </div>
                <a href="{{ route('secretary.patients') }}" class="btn btn-primary btn-sm mt-3">
                    <i class="fas fa-external-link-alt mr-1"></i>Open Patients
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="kpi-card__icon"><i class="fas fa-calendar-check fa-lg"></i></div>
                    <div class="kpi-card__label">Appointments</div>
                    <p class="small text-muted mt-1 mb-0">Schedule and track patient appointments.</p>
                </div>
                <a href="{{ route('secretary.appointments') }}" class="btn btn-warning btn-sm mt-3">
                    <i class="fas fa-external-link-alt mr-1"></i>Open Schedule
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--teal h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="kpi-card__icon"><i class="fas fa-shopping-cart fa-lg"></i></div>
                    <div class="kpi-card__label">Point of Sale</div>
                    <p class="small text-muted mt-1 mb-0">Process drug &amp; product sales transactions.</p>
                </div>
                <a href="{{ route('cashier.seller-desk') }}" class="btn btn-info btn-sm mt-3">
                    <i class="fas fa-external-link-alt mr-1"></i>Open POS
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="kpi-card__icon"><i class="fas fa-capsules fa-lg"></i></div>
                    <div class="kpi-card__label">Drugs &amp; Spectacles</div>
                    <p class="small text-muted mt-1 mb-0">Manage spectacle orders and drug dispensing.</p>
                </div>
                <a href="{{ route('secretary.spectacles') }}" class="btn btn-success btn-sm mt-3">
                    <i class="fas fa-external-link-alt mr-1"></i>Open Dispensary
                </a>
            </div>
        </div>

    </div>

</div>{{-- /container-fluid --}}

{{-- Chart.js --}}
<script>
document.addEventListener('livewire:init', function () {
    const ctx = document.getElementById('secretaryPatientsChart');
    if (!ctx) return;

    const labels = @json($chartLabels);
    const counts = @json($chartData);

    const context  = ctx.getContext('2d');
    const gradient = context.createLinearGradient(0, 0, 0, 240);
    gradient.addColorStop(0, 'rgba(0,123,255,0.25)');
    gradient.addColorStop(1, 'rgba(0,123,255,0.02)');

    new Chart(context, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'New Patients',
                data: counts,
                backgroundColor: gradient,
                borderColor: '#007bff',
                borderWidth: 2,
                borderRadius: 6,
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
                            return ' ' + ctx.parsed.y + ' patient(s)';
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
                        precision: 0,
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

</div>{{-- /content --}}
