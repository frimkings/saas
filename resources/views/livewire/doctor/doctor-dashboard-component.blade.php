<div class="content p-3" style="background:#f0f2f5; min-height:100vh;">
<div class="container-fluid">

    {{-- Page Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold" style="color:#2c3e50;">
                <i class="fas fa-stethoscope mr-2 text-primary"></i>Doctor Dashboard
            </h3>
            <small class="text-muted text-uppercase font-weight-bold" style="letter-spacing:.05em;">
                Welcome, {{ auth()->user()->name }} &mdash; {{ now()->format('l, F d Y') }}
            </small>
        </div>
        <a href="{{ route('doctor.patient-awaiting') }}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-users mr-1"></i>Patient Queue
        </a>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 1: Top KPI Cards                                              --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--orange h-100">
                <div class="kpi-card__icon"><i class="fas fa-user-clock fa-lg"></i></div>
                <div class="kpi-card__label">Awaiting Consultation</div>
                <div class="kpi-card__value kpi-card__value--orange">{{ number_format($awaitingToday) }}</div>
                <small class="text-muted mt-1 d-block">Patients in queue today</small>
                <a href="{{ route('doctor.patient-awaiting') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#fd7e14;">
                    View Queue <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--teal h-100">
                <div class="kpi-card__icon"><i class="fas fa-stethoscope fa-lg"></i></div>
                <div class="kpi-card__label">Consultations Today</div>
                <div class="kpi-card__value kpi-card__value--teal">{{ number_format($consultationsToday) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('D, d M Y') }}</small>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-clipboard-list fa-lg"></i></div>
                <div class="kpi-card__label">Consultations This Month</div>
                <div class="kpi-card__value">{{ number_format($consultationsMonth) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('F Y') }}</small>
                <a href="{{ route('doctor.all-records') }}"
                   class="small font-weight-bold mt-1 d-block" style="color:#007bff;">
                    All Records <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="kpi-card kpi-card--purple h-100">
                <div class="kpi-card__icon"><i class="fas fa-users fa-lg"></i></div>
                <div class="kpi-card__label">Total Registered Patients</div>
                <div class="kpi-card__value kpi-card__value--purple">{{ number_format($totalPatients) }}</div>
                <a href="{{ route('doctor.all-records') }}"
                   class="small font-weight-bold mt-2 d-block" style="color:#6f42c1;">
                    View Records <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- ROW 2: Activity Cards                                              --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--green h-100">
                <div class="kpi-card__icon"><i class="fas fa-check-circle fa-lg"></i></div>
                <div class="kpi-card__label">Patients Seen Today</div>
                <div class="kpi-card__value kpi-card__value--green">{{ number_format($seenToday) }}</div>
                <small class="text-muted mt-1 d-block">Consultations completed today</small>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--yellow h-100">
                <div class="kpi-card__icon"><i class="fas fa-prescription-bottle-alt fa-lg"></i></div>
                <div class="kpi-card__label">Pending Prescriptions</div>
                <div class="kpi-card__value kpi-card__value--yellow">
                    {{ number_format($pendingPrescriptions) }}
                    @if($pendingPrescriptions > 0)
                        <span class="badge badge-warning ml-1" style="font-size:.55rem; vertical-align:middle;">Awaiting Dispensing</span>
                    @endif
                </div>
                <small class="text-muted mt-1 d-block">Prescriptions not yet dispensed</small>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-3">
            <div class="kpi-card kpi-card--blue h-100">
                <div class="kpi-card__icon"><i class="fas fa-paper-plane fa-lg"></i></div>
                <div class="kpi-card__label">Referrals This Month</div>
                <div class="kpi-card__value">{{ number_format($referralsMonth) }}</div>
                <small class="text-muted mt-1 d-block">{{ now()->format('F Y') }}</small>
                <a href="{{ route('doctor.referrals') }}"
                   class="small font-weight-bold mt-1 d-block" style="color:#007bff;">
                    View Referrals <i class="fas fa-arrow-circle-right ml-1"></i>
                </a>
            </div>
        </div>

    </div>

  

    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 font-weight-bold text-dark">
                            <i class="fas fa-chart-bar text-primary mr-2"></i>My Consultations &mdash; Last 7 Days
                        </h6>
                        <small class="text-muted">Daily consultation count</small>
                    </div>
                    <a href="{{ route('doctor.all-records') }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-external-link-alt mr-1"></i>All Records
                    </a>
                </div>
                <div class="card-body">
                    <div wire:ignore style="position:relative; height:240px;">
                        <canvas id="doctorConsultationChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

   
    <div class="row mb-4">

        
        <div class="col-xl-7 col-lg-6 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-history text-primary mr-2"></i>Recent Consultations
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Patient</th>
                                    <th>Folder No.</th>
                                    <th>Chief Complaint</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentConsultations as $consultation)
                                    <tr>
                                        <td class="font-weight-bold" style="font-size:.85rem;">
                                            {{ $consultation->patient->name ?? '—' }}
                                        </td>
                                        <td>
                                            <code style="font-size:.78rem;">{{ $consultation->patient->pxnumber ?? '—' }}</code>
                                        </td>
                                        <td class="text-muted" style="font-size:.82rem; max-width:160px;">
                                            <span title="{{ $consultation->chiefComplaint }}">
                                                {{ \Illuminate\Support\Str::limit($consultation->chiefComplaint, 40) ?: '—' }}
                                            </span>
                                        </td>
                                        <td class="small text-muted">
                                            {{ $consultation->created_at->format('d M Y') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="fas fa-stethoscope fa-2x d-block mb-2" style="color:#dee2e6;"></i>
                                            No consultations recorded yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-top text-right">
                    <a href="{{ route('doctor.all-records') }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-list mr-1"></i>View All Records
                    </a>
                </div>
            </div>
        </div>

        {{-- Today's Patient Queue --}}
        <div class="col-xl-5 col-lg-6 mb-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-user-clock text-warning mr-2"></i>Today's Queue
                        @if($awaitingToday > 0)
                            <span class="badge badge-warning ml-1">{{ $awaitingToday }} waiting</span>
                        @endif
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:.83rem;">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>Patient</th>
                                    <th>Folder No.</th>
                                    <th>Waiting</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($todayQueue as $i => $clearance)
                                    @php
                                        $mins = $clearance->created_at->diffInMinutes(now());
                                    @endphp
                                    <tr @if($mins >= 30) style="background:#fff8e1;" @endif>
                                        <td class="text-muted">{{ $i + 1 }}</td>
                                        <td class="font-weight-bold">
                                            {{ $clearance->patient->name ?? '—' }}
                                        </td>
                                        <td>
                                            <code style="font-size:.78rem;">{{ $clearance->patient->pxnumber ?? '—' }}</code>
                                        </td>
                                        <td>
                                            @if($mins >= 60)
                                                <span class="badge badge-danger">{{ floor($mins/60) }}h {{ $mins%60 }}m</span>
                                            @elseif($mins >= 30)
                                                <span class="badge badge-warning">{{ $mins }}m</span>
                                            @else
                                                <span class="badge badge-light text-muted">{{ $mins }}m</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="fas fa-check-circle fa-2x d-block mb-2" style="color:#28a745; opacity:.4;"></i>
                                            No patients awaiting consultation.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-top text-right">
                    <a href="{{ route('doctor.patient-awaiting') }}" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-users mr-1"></i>Open Queue
                    </a>
                </div>
            </div>
        </div>

    </div>

</div>{{-- /container-fluid --}}

{{-- Chart.js --}}
<script>
document.addEventListener('livewire:init', function () {
    const ctx = document.getElementById('doctorConsultationChart');
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
                label: 'Consultations',
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
                            return ' ' + ctx.parsed.y + ' consultation(s)';
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
                    stepSize: 1,
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
