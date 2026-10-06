@php
    $tiles = [
        ['New patients today', number_format($patientsRegisteredToday), 'Registered today', 'fa-user-plus', 'text-blue-600', route('secretary.patients'), 'All patients'],
        ['Appointments today', number_format($appointmentsToday), 'Scheduled and active', 'fa-calendar-check', 'text-orange-500', route('secretary.appointments'), 'View schedule'],
        ['Clearances today', number_format($clearancesToday), 'Patients processed today', 'fa-clipboard-check', 'text-green-600', route('secretary.patient-clearance'), 'Clearance desk'],
        ["Today's sales", currency().' '.number_format($todaySales, 2), now()->format('D, d M Y'), 'fa-cash-register', 'text-teal-600', route('cashier.sales-records'), 'Sales records'],
        ['Registered patients', number_format($totalPatients), 'All time', 'fa-users', 'text-blue-600', route('secretary.patients'), 'Patient registry'],
        ['Outstanding balances', number_format($outstandingBalances), 'Part-paid sales', 'fa-balance-scale', 'text-amber-500', route('cashier.outstanding-balances'), 'View balances'],
        ['Awaiting doctor', number_format($awaitingDoctor), 'Not yet seen today', 'fa-user-clock', 'text-orange-500', route('secretary.patient-clearance'), 'Clearance desk'],
        ['Spectacle renewals due', number_format($renewalsDue), number_format($spectaclesReady).' ready for pickup', 'fa-glasses', 'text-teal-600', route('secretary.spectacles'), 'Spectacles'],
    ];
    $apptBadge = fn ($status) => match ($status ?? 'scheduled') {
        'completed' => 'bg-green-50 text-green-700',
        'cancelled' => 'bg-red-50 text-red-700',
        default => 'bg-blue-50 text-blue-700',
    };
@endphp
<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading">
        <div>
            <h1>Reception</h1>
            <p class="ui-muted">Welcome, {{ auth()->user()->name }} &middot; {{ now()->format('l, j F Y') }}</p>
        </div>
        <div class="ui-actions">
            <a href="{{ route('secretary.appointments') }}" class="ui-button ui-button-secondary"><i class="fas fa-calendar-alt" aria-hidden="true"></i>Appointments</a>
            <a href="{{ route('cashier.seller-desk') }}" class="ui-button ui-button-primary"><i class="fas fa-cash-register" aria-hidden="true"></i>Open POS</a>
        </div>
    </div>

    {{-- What to act on today: appointments, spectacles due or late, glasses not collected --}}
    @livewire('attention-panel-component', ['line' => 'clinic', 'compact' => true], key('attention-clinic'))

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($tiles as [$label, $value, $note, $icon, $colour, $url, $linkLabel])
            <div class="ui-panel flex flex-col p-4">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <i class="fas {{ $icon }} {{ $colour }}" aria-hidden="true"></i>
                </div>
                <p class="ui-value">{{ $value }}</p>
                <p class="ui-muted">{{ $note }}</p>
                <a href="{{ $url }}" class="mt-2 text-xs font-semibold text-teal-700 no-underline hover:underline">{{ $linkLabel }} &rarr;</a>
            </div>
        @endforeach
    </div>

    <section class="ui-panel">
        <div class="ui-panel-heading">
            <div>
                <h2>New patient registrations, last 7 days</h2>
                <p class="ui-muted">Patients registered each day</p>
            </div>
            <a href="{{ route('secretary.patients') }}" class="ui-button ui-button-secondary">All patients</a>
        </div>
        <div class="p-4">
            <div wire:ignore class="relative h-60">
                <canvas id="secretaryPatientsChart" aria-label="New patients per day for the last 7 days" role="img"></canvas>
            </div>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="ui-panel flex flex-col">
            <div class="ui-panel-heading">
                <h2>Today's clearance queue</h2>
                @if($awaitingDoctor > 0)<span class="ui-badge ui-badge-waiting">{{ $awaitingDoctor }} awaiting</span>@endif
            </div>
            <div class="ui-table-wrap flex-1">
                <table class="ui-table">
                    <thead><tr><th>Patient</th><th>Folder no.</th><th>Contact</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($todayQueue as $clearance)
                            <tr>
                                <td class="font-semibold">{{ $clearance->patient->name ?? '—' }}</td>
                                <td class="font-mono text-xs">{{ $clearance->patient->pxnumber ?? '—' }}</td>
                                <td class="text-slate-500">{{ $clearance->patient->contact ?? '—' }}</td>
                                <td>
                                    @if($clearance->doctor_status)
                                        <span class="ui-badge">Seen</span>
                                    @else
                                        <span class="ui-badge ui-badge-waiting">Waiting</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-empty text-slate-500">No clearances recorded today.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 p-3 text-right">
                <a href="{{ route('secretary.patient-clearance') }}" class="ui-button ui-button-secondary">Open clearance desk</a>
            </div>
        </section>

        <section class="ui-panel flex flex-col">
            <div class="ui-panel-heading">
                <h2>Today's appointments</h2>
                @if($appointmentsToday > 0)<span class="ui-badge">{{ $appointmentsToday }}</span>@endif
            </div>
            <div class="ui-table-wrap flex-1">
                <table class="ui-table">
                    <thead><tr><th>Patient</th><th>Folder no.</th><th>Time</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($upcomingAppointments as $appt)
                            <tr>
                                <td class="font-semibold">{{ $appt->patient->name ?? $appt->title ?? '—' }}</td>
                                <td class="font-mono text-xs">{{ $appt->patient->pxnumber ?? '—' }}</td>
                                <td class="whitespace-nowrap text-slate-500">{{ optional($appt->scheduled_at)->format('h:i A') ?? '—' }}</td>
                                <td><span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold {{ $apptBadge($appt->status) }}">{{ ucfirst($appt->status ?? 'Scheduled') }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-empty text-slate-500">No appointments scheduled for today.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 p-3 text-right">
                <a href="{{ route('secretary.appointments') }}" class="ui-button ui-button-secondary">Manage appointments</a>
            </div>
        </section>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Patient registry', 'Register new patients and manage records.', route('secretary.patients'), 'Open patients', 'fa-user-plus'],
            ['Appointments', 'Schedule and track patient appointments.', route('secretary.appointments'), 'Open schedule', 'fa-calendar-check'],
            ['Point of sale', 'Process drug and product sales.', route('cashier.seller-desk'), 'Open POS', 'fa-shopping-cart'],
            ['Drugs & spectacles', 'Manage spectacle orders and drug dispensing.', route('secretary.spectacles'), 'Open dispensary', 'fa-capsules'],
        ] as [$title, $text, $url, $action, $icon])
            <div class="ui-panel flex flex-col justify-between gap-3 p-4">
                <div>
                    <h2><i class="fas {{ $icon }} mr-2 text-teal-600" aria-hidden="true"></i>{{ $title }}</h2>
                    <p class="ui-muted mt-1">{{ $text }}</p>
                </div>
                <a href="{{ $url }}" class="ui-button ui-button-secondary">{{ $action }}</a>
            </div>
        @endforeach
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('secretaryPatientsChart');
            if (!canvas || !window.Chart) return;

            new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: @json($chartLabels),
                    datasets: [{
                        label: 'New patients',
                        data: @json($chartData),
                        backgroundColor: 'rgba(8, 126, 131, 0.18)',
                        borderColor: '#087e83',
                        borderWidth: 2,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: (ctx) => ' ' + ctx.parsed.y + ' patient(s)' } }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: 'rgba(15, 23, 42, .06)' }, ticks: { precision: 0, font: { size: 11 } } }
                    }
                }
            });
        });
    </script>
</div>
