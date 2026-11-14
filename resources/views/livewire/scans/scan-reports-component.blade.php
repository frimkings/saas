@php $m = fn ($n) => number_format((float) $n, 2); $t = $report['totals']; @endphp
<div class="space-y-5">
    <section class="ui-panel bg-white" style="padding:14px 16px" data-tour="scan-report-range">
        <div class="flex flex-wrap items-end gap-3">
            <label class="ds-field"><span>From</span><input type="date" wire:model.live="from" class="ui-input" max="{{ now()->toDateString() }}"></label>
            <label class="ds-field"><span>To</span><input type="date" wire:model.live="to" class="ui-input" max="{{ now()->toDateString() }}"></label>
            <div class="flex flex-wrap gap-1.5">
                @foreach($presets as $key => $label)<button type="button" class="oo-btn" wire:click="preset('{{ $key }}')">{{ $label }}</button>@endforeach
            </div>
            <a class="oo-btn primary" style="margin-left:auto" href="{{ route('frontdesk.scans.report', ['from' => $rangeFrom->toDateString(), 'to' => $rangeTo->toDateString()]) }}">Download for Excel</a>
        </div>
        <p class="ds-hint" style="margin:8px 0 0">{{ $rangeFrom->format('d M Y') }} to {{ $rangeTo->format('d M Y') }}. Orders count on the day they were registered; cancelled orders are left out.</p>
    </section>

    <div class="ds-tiles">
        <div class="ds-tile"><span>Orders</span><b>{{ $t['orders'] }}</b><small class="ds-hint">{{ $t['patients'] }} {{ \Illuminate\Support\Str::plural('patient', $t['patients']) }} · {{ $t['scans'] }} {{ \Illuminate\Support\Str::plural('scan', $t['scans']) }}</small></div>
        <div class="ds-tile"><span>Billed</span><b>{{ currency() }} {{ $m($t['billed']) }}</b><small class="ds-hint">Paid {{ $m($t['paid']) }}</small></div>
        <div class="ds-tile"><span>Commission earned</span><b>{{ currency() }} {{ $m($t['commission']) }}</b></div>
        <div class="ds-tile"><span>Kept by the clinic</span><b>{{ currency() }} {{ $m($t['paid'] - $t['commission']) }}</b><small class="ds-hint">Paid less commission</small></div>
    </div>

    <section class="ui-panel bg-white" data-tour="scan-report-partners">
        <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">Referrals by partner clinic</h2></div>
        <div class="overflow-x-auto">
            <table class="oo-table w-full" style="min-width:760px">
                <thead><tr><th>Partner clinic</th><th class="oo-num">Orders</th><th class="oo-num">Patients</th><th class="oo-num">Scans</th><th class="oo-num">Billed</th><th class="oo-num">Paid</th><th class="oo-num">Commission</th></tr></thead>
                <tbody>
                    @forelse($report['partners'] as $p)
                        <tr style="cursor:default"><td><b>{{ $p['name'] }}</b>@if($p['code']) <span class="oo-id">{{ $p['code'] }}</span>@endif</td>
                            <td class="oo-num">{{ $p['orders'] }}</td><td class="oo-num">{{ $p['patients'] }}</td><td class="oo-num">{{ $p['scans'] }}</td>
                            <td class="oo-num">{{ $m($p['billed']) }}</td><td class="oo-num">{{ $m($p['paid']) }}</td><td class="oo-num">{{ $m($p['commission']) }}</td></tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">No referrals from partner clinics in these dates.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="ui-panel bg-white">
            <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">Where patients came from</h2></div>
            <div class="overflow-x-auto">
                <table class="oo-table w-full">
                    <thead><tr><th>Source</th><th class="oo-num">Orders</th><th class="oo-num">Billed</th><th class="oo-num">Commission</th></tr></thead>
                    <tbody>
                        @foreach($report['sources'] as $s)
                            <tr style="cursor:default"><td>{{ $s['label'] }}</td><td class="oo-num">{{ $s['orders'] }}</td><td class="oo-num">{{ $m($s['billed']) }}</td><td class="oo-num">{{ $m($s['commission']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ui-panel bg-white">
            <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">Turnaround</h2></div>
            <dl class="oo-dl" style="padding:14px 16px;margin:0">
                @php $avg = fn ($h) => $h === null ? '—' : ($h < 1 ? 'Under an hour on average' : $h.' hours on average'); @endphp
                <dt>Paid to reported</dt><dd>{{ $avg($report['turnaround']['to_report']) }} <span class="ui-muted text-xs">({{ $report['turnaround']['reported'] }} reported)</span></dd>
                <dt>Reported to sent</dt><dd>{{ $avg($report['turnaround']['to_send']) }} <span class="ui-muted text-xs">({{ $report['turnaround']['sent'] }} sent to a referrer)</span></dd>
            </dl>
        </section>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="ui-panel bg-white">
            <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">By scan</h2></div>
            <div class="overflow-x-auto">
                <table class="oo-table w-full">
                    <thead><tr><th>Scan <span class="ui-muted text-xs">(billed)</span></th><th class="oo-num">Scans</th><th class="oo-num">Billed</th><th class="oo-num" title="From partner clinics / our doctors / self-referred">Partner · Ours · Self</th></tr></thead>
                    <tbody>
                        @forelse($report['scans'] as $s)
                            <tr style="cursor:default"><td>{{ $s['name'] }}</td><td class="oo-num">{{ $s['count'] }}</td><td class="oo-num">{{ $m($s['amount']) }}</td><td class="oo-num">{{ $s['partner'] }} · {{ $s['internal'] }} · {{ $s['self'] }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="ui-empty"><p class="ui-muted text-sm">No scans in these dates.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ui-panel bg-white">
            <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">Top referring doctors</h2></div>
            <div class="overflow-x-auto">
                <table class="oo-table w-full">
                    <thead><tr><th>Doctor</th><th class="oo-num">Orders</th><th class="oo-num">Billed</th></tr></thead>
                    <tbody>
                        @forelse($report['doctors'] as $d)
                            <tr style="cursor:default"><td><b>{{ $d['name'] }}</b><span class="oo-sub">{{ $d['where'] }}</span></td><td class="oo-num">{{ $d['orders'] }}</td><td class="oo-num">{{ $m($d['billed']) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="ui-empty"><p class="ui-muted text-sm">No orders name a referring doctor in these dates.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="ui-panel bg-white">
        <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">Gone quiet</h2>
            <p class="ds-hint m-0">Partner clinics with no referral in the last {{ $dormantDays }} days. A call may bring them back.</p></div>
        <div class="overflow-x-auto">
            <table class="oo-table w-full">
                <thead><tr><th>Partner clinic</th><th>Phone</th><th>Last referral</th></tr></thead>
                <tbody>
                    @forelse($report['dormant'] as $d)
                        <tr style="cursor:default"><td><b>{{ $d['name'] }}</b> <span class="oo-id">{{ $d['code'] }}</span></td><td>{{ $d['phone'] ?: '—' }}</td><td>{{ $d['last'] ? $d['last']->format('d M Y') : 'Never' }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="ui-empty"><p class="ui-muted text-sm">Every partner clinic has referred someone recently.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
