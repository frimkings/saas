@php
    $money = fn ($v) => currency().' '.number_format((float) $v, 2);
    $group = 'mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $reportsLink = $line === 'optical' ? route('optical.reports') : route('admin.reports');
    $roleTone = fn ($role) => match ($role) {
        'Super Admin' => 'bg-red-100 text-red-800', 'Manager' => 'bg-amber-100 text-amber-800', 'Doctor' => 'bg-sky-100 text-sky-800',
        'Cashier' => 'bg-green-100 text-green-800', 'Secretary' => 'bg-teal-100 text-teal-800', default => 'bg-slate-100 text-slate-700',
    };
@endphp
<div class="clinic-ui ui-page space-y-6">

    <x-ui.page-header title="Business Overview"
        :description="now()->format('l, d F Y').(count($lineOptions) > 1 ? ' · Money figures: '.($line === 'combined' ? 'Clinic & Optical' : $lineLabels[$line]) : '')">
        @if(count($lineOptions) > 1)
            <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm font-semibold" role="group" aria-label="Business line">
                @foreach($lineOptions as $option)
                    <a href="{{ route('admin.dashboard', ['line' => $option]) }}" @if($line === $option) aria-current="page" @endif
                       class="px-3 py-2 no-underline hover:no-underline {{ $line === $option ? 'bg-teal-700 text-white' : 'bg-white text-slate-700 hover:bg-slate-50' }} {{ ! $loop->first ? 'border-l border-slate-300' : '' }}">{{ $lineLabels[$option] }}</a>
                @endforeach
            </div>
        @endif
        <x-guide-tour page="clinic.dashboard" :context="['owner' => $owner]" />
        <x-ui.button :href="$reportsLink"><i class="fas fa-chart-bar" aria-hidden="true"></i>Full reports</x-ui.button>
    </x-ui.page-header>

    @if($setup)
        <x-setup-checklist :setup="$setup" />
    @endif

    @if($pendingDiscounts > 0)
        <a href="{{ route('admin.discount-approvals') }}" class="ui-panel ui-panel--warning flex items-center justify-between gap-3 px-5 py-3 text-sm text-amber-900 no-underline hover:no-underline">
            <span><i class="fas fa-tags mr-2" aria-hidden="true"></i><b>{{ $pendingDiscounts }}</b> {{ \Illuminate\Support\Str::plural('discount request', $pendingDiscounts) }} waiting for approval</span>
            <span class="font-semibold underline">Review</span>
        </a>
    @endif

    <section aria-label="Today">
        <h2 class="{{ $group }}">Today</h2>
        <div class="ui-tiles" data-tour="clinic-kpis">
            <x-ui.stat-tile label="Sales" :value="$money($todayRevenue)" hint="Collected today" icon="fa-cash-register" tone="accent" :href="$todayLink" />
            <x-ui.stat-tile label="Consultations" :value="number_format($consultationsToday)" hint="Patients seen by a doctor" icon="fa-stethoscope" />
            <x-ui.stat-tile label="Appointments" :value="number_format($todayAppointments)" hint="Booked for today" icon="fa-calendar-check" :href="route('secretary.appointments')" />
            <x-ui.stat-tile label="Prescriptions to dispense" :value="number_format($pendingPrescriptions)" :tone="$pendingPrescriptions > 0 ? 'warning' : 'neutral'"
                :hint="$pendingPrescriptions > 0 ? 'Waiting at the pharmacy counter' : 'Nothing waiting'" icon="fa-prescription-bottle-alt" />
        </div>
    </section>

    {{-- What staff need to act on today, including expiring stock --}}
    @livewire('attention-panel-component', ['line' => 'clinic', 'compact' => true], key('attention-clinic'))

    <section aria-label="This month">
        <h2 class="{{ $group }}">{{ now()->format('F Y') }}</h2>
        <div class="ui-tiles">
            <x-ui.stat-tile label="Revenue" :value="$money($monthRevenue)" :hint="$statementLink[0]" icon="fa-chart-line" :href="$statementLink[1]" />
            <x-ui.stat-tile label="Expenses" :value="$money($monthExpenses)" hint="Expense tracker" icon="fa-file-invoice-dollar" :href="$expensesLink" />
            <x-ui.stat-tile label="Outstanding balances" :value="number_format($outstandingCount)" :tone="$outstandingCount > 0 ? 'warning' : 'neutral'"
                hint="Sales part paid" icon="fa-balance-scale" :href="$outstandingLink" />
            <x-ui.stat-tile label="New patients" :value="number_format($newPatientsMonth)" :hint="number_format($totalPatients).' registered in all'" icon="fa-user-plus" :href="route('doctor.all-records')" />
        </div>
    </section>

    <x-ui.section title="Revenue, last 7 days" :description="'Money collected each day ('.currency().')'" icon="fa-chart-bar">
        <x-slot:actions><x-ui.button class="ui-button-sm" :href="$reportsLink">Full report</x-ui.button></x-slot:actions>
        <div class="p-4"><div wire:ignore class="relative h-[260px]"><canvas id="dashboardRevenueChart" role="img" aria-label="Revenue for the last 7 days"></canvas></div></div>
    </x-ui.section>

    <section aria-label="Stock">
        <h2 class="{{ $group }}">Stock · {{ number_format($productsInStock) }} {{ \Illuminate\Support\Str::plural('product', $productsInStock) }} in stock</h2>
        <div class="ui-tiles">
            <x-ui.stat-tile label="Low stock" :value="number_format($lowStockCount)" hint="10 units or fewer" icon="fa-exclamation-triangle" :tone="$lowStockCount > 0 ? 'warning' : 'neutral'" :href="route('admin.inventory-alerts')" />
            <x-ui.stat-tile label="Out of stock" :value="number_format($outOfStockCount)" hint="None left" icon="fa-times-circle" :tone="$outOfStockCount > 0 ? 'danger' : 'neutral'" :href="route('admin.inventory-alerts')" />
            <x-ui.stat-tile label="Expiring soon" :value="number_format($expiringSoonCount)" hint="Within 90 days" icon="fa-clock" :tone="$expiringSoonCount > 0 ? 'warning' : 'neutral'" :href="route('admin.inventory-alerts')" />
            <x-ui.stat-tile label="Expired" :value="number_format($expiredCount)" hint="Past the expiry date" icon="fa-ban" :tone="$expiredCount > 0 ? 'danger' : 'neutral'" :href="route('admin.inventory-alerts')" />
        </div>
    </section>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <x-ui.section title="Top 5 selling products" :description="now()->format('F Y')" icon="fa-trophy">
            <x-slot:actions><x-ui.button class="ui-button-sm" :href="$reportsLink">Sales report</x-ui.button></x-slot:actions>
            @if(count($topProducts))
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead><tr><th class="w-10">#</th><th>Product</th><th class="ui-number">Sold</th><th class="ui-number">Revenue</th></tr></thead>
                        <tbody>
                            @foreach($topProducts as $i => $item)
                                <tr>
                                    <td><span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold {{ $i === 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600' }}">{{ $i + 1 }}</span></td>
                                    <td class="font-semibold">{{ $item['name'] }}@if($line === 'combined')<span class="font-normal text-slate-500"> · {{ $lineLabels[$item['line']] }}</span>@endif</td>
                                    <td class="ui-number">{{ number_format($item['total_qty']) }}</td>
                                    <td class="ui-number font-semibold">{{ $money($item['total_revenue']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="ui-section-empty">No sales recorded this month.</p>
            @endif
        </x-ui.section>

        <x-ui.section title="Recent sign-ins" :description="number_format($totalActiveUsers).' active '.\Illuminate\Support\Str::plural('user', $totalActiveUsers)" icon="fa-shield-alt">
            <x-slot:actions>
                <x-ui.menu label="Staff">
                    <a href="{{ route('admin.users') }}" class="ui-menu-item" role="menuitem"><i class="fas fa-users" aria-hidden="true"></i>Manage users</a>
                    <a href="{{ route('admin.login-history') }}" class="ui-menu-item" role="menuitem"><i class="fas fa-history" aria-hidden="true"></i>Login history</a>
                    <a href="{{ route('admin.audit-trail') }}" class="ui-menu-item" role="menuitem"><i class="fas fa-clipboard-list" aria-hidden="true"></i>Audit trail</a>
                </x-ui.menu>
            </x-slot:actions>
            @if(count($recentLogins))
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead><tr><th>User</th><th>Role</th><th>When</th><th class="hidden sm:table-cell">IP</th></tr></thead>
                        <tbody>
                            @foreach($recentLogins as $log)
                                @php $roleName = $log->user?->roles->first()?->name ?? 'Staff'; @endphp
                                <tr>
                                    <td class="font-semibold">{{ $log->user->name ?? 'Unknown' }}</td>
                                    <td><span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold {{ $roleTone($roleName) }}">{{ $roleName }}</span></td>
                                    <td class="whitespace-nowrap text-slate-500">{{ optional($log->login_at)->diffForHumans() ?? '—' }}</td>
                                    <td class="hidden font-mono text-xs text-slate-500 sm:table-cell">{{ $log->ip_address }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="ui-section-empty">No sign-ins recorded yet.</p>
            @endif
        </x-ui.section>
    </div>

{{-- Chart.js — fires after Livewire mounts the DOM --}}
<script>
document.addEventListener('livewire:init', function () {
    const ctx = document.getElementById('dashboardRevenueChart');
    if (!ctx) return;

    const labels   = @json($revenueChartLabels);
    const revenues = @json($revenueChartData);

    const context  = ctx.getContext('2d');
    const gradient = context.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, 'rgba(8,126,131,0.28)');
    gradient.addColorStop(1, 'rgba(8,126,131,0.02)');

    new Chart(context, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue ({{ currency() }})',
                data: revenues,
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

</div>
