@php
    $tiles = [
        ['Awaiting consultation', $awaitingToday, 'Patients in queue today', 'fa-user-clock', 'text-orange-500', route('doctor.patient-awaiting'), 'View queue'],
        ['Consultations today', $consultationsToday, now()->format('D, d M Y'), 'fa-stethoscope', 'text-teal-600', null, null],
        ['Consultations this month', $consultationsMonth, now()->format('F Y'), 'fa-clipboard-list', 'text-blue-600', route('doctor.all-records'), 'All records'],
        ['Registered patients', $totalPatients, 'All time', 'fa-users', 'text-violet-600', route('doctor.all-records'), 'View records'],
        ['Patients seen today', $seenToday, 'Consultations completed today', 'fa-check-circle', 'text-green-600', null, null],
        ['Pending prescriptions', $pendingPrescriptions, 'Not yet dispensed', 'fa-prescription-bottle-alt', 'text-amber-500', null, null],
        ['Referrals this month', $referralsMonth, now()->format('F Y'), 'fa-paper-plane', 'text-blue-600', route('doctor.referrals'), 'View referrals'],
    ];
    $chip = 'inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-semibold';
@endphp
<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading">
        <div>
            <h1>Doctor dashboard</h1>
            <p class="ui-muted">Welcome, {{ auth()->user()->name }} &middot; {{ now()->format('l, j F Y') }}</p>
        </div>
        <div class="ui-actions">
            <a href="{{ route('doctor.patient-awaiting') }}" class="ui-button ui-button-primary"><i class="fas fa-users" aria-hidden="true"></i>Patient queue</a>
        </div>
    </div>

    {{-- What to act on today, including expiring stock --}}
    @livewire('attention-panel-component', ['line' => 'clinic', 'compact' => true], key('attention-clinic'))

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($tiles as [$label, $value, $note, $icon, $colour, $url, $linkLabel])
            <div class="ui-panel flex flex-col p-4">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <i class="fas {{ $icon }} {{ $colour }}" aria-hidden="true"></i>
                </div>
                <p class="ui-value">{{ number_format($value) }}</p>
                <p class="ui-muted">{{ $note }}</p>
                @if($url)<a href="{{ $url }}" class="mt-2 text-xs font-semibold text-teal-700 no-underline hover:underline">{{ $linkLabel }} &rarr;</a>@endif
            </div>
        @endforeach
    </div>

    <section class="ui-panel">
        <div class="ui-panel-heading">
            <div>
                <h2>My consultations, last 7 days</h2>
                <p class="ui-muted">Consultations each day</p>
            </div>
            <a href="{{ route('doctor.all-records') }}" class="ui-button ui-button-secondary">All records</a>
        </div>
        <div class="p-4">
            <div wire:ignore class="relative h-60">
                <canvas id="doctorConsultationChart" role="img" aria-label="Consultations per day for the last 7 days"></canvas>
            </div>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[7fr_5fr]">
        <section class="ui-panel flex flex-col">
            <div class="ui-panel-heading"><h2>Recent consultations</h2></div>
            <div class="ui-table-wrap flex-1">
                <table class="ui-table">
                    <thead><tr><th>Patient</th><th>Folder no.</th><th>Chief complaint</th><th>Date</th></tr></thead>
                    <tbody>
                        @forelse($recentConsultations as $consultation)
                            <tr>
                                <td class="font-semibold">{{ $consultation->patient->name ?? '—' }}</td>
                                <td class="font-mono text-xs">{{ $consultation->patient->pxnumber ?? '—' }}</td>
                                <td class="max-w-[16rem] text-slate-500" title="{{ $consultation->chiefComplaint }}">{{ \Illuminate\Support\Str::limit($consultation->chiefComplaint, 40) ?: '—' }}</td>
                                <td class="whitespace-nowrap text-xs text-slate-500">{{ $consultation->created_at->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-empty text-slate-500"><i class="fas fa-stethoscope mb-2 text-3xl text-slate-300" aria-hidden="true"></i>No consultations recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 p-3 text-right">
                <a href="{{ route('doctor.all-records') }}" class="ui-button ui-button-secondary">View all records</a>
            </div>
        </section>

        <section class="ui-panel flex flex-col">
            <div class="ui-panel-heading">
                <h2>Today's queue</h2>
                @if($awaitingToday > 0)<span class="ui-badge ui-badge-waiting">{{ $awaitingToday }} waiting</span>@endif
            </div>
            <div class="ui-table-wrap flex-1">
                <table class="ui-table">
                    <thead><tr><th>#</th><th>Patient</th><th>Folder no.</th><th>Waiting</th></tr></thead>
                    <tbody>
                        @forelse($todayQueue as $i => $clearance)
                            @php $mins = $clearance->created_at->diffInMinutes(now()); @endphp
                            <tr @class(['bg-amber-50' => $mins >= 30])>
                                <td class="text-slate-500">{{ $i + 1 }}</td>
                                <td class="font-semibold">{{ $clearance->patient->name ?? '—' }}</td>
                                <td class="font-mono text-xs">{{ $clearance->patient->pxnumber ?? '—' }}</td>
                                <td>
                                    @if($mins >= 60)
                                        <span class="{{ $chip }} bg-red-100 text-red-800">{{ floor($mins / 60) }}h {{ $mins % 60 }}m</span>
                                    @elseif($mins >= 30)
                                        <span class="{{ $chip }} bg-amber-100 text-amber-800">{{ $mins }}m</span>
                                    @else
                                        <span class="{{ $chip }} bg-slate-100 text-slate-600">{{ $mins }}m</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-empty text-slate-500"><i class="fas fa-check-circle mb-2 text-3xl text-green-400" aria-hidden="true"></i>No patients awaiting consultation.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 p-3 text-right">
                <a href="{{ route('doctor.patient-awaiting') }}" class="ui-button ui-button-secondary">Open queue</a>
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('doctorConsultationChart');
            if (!canvas || !window.Chart) return;

            new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: @json($chartLabels),
                    datasets: [{
                        label: 'Consultations',
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
                        tooltip: { callbacks: { label: (ctx) => ' ' + ctx.parsed.y + ' consultation(s)' } }
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
