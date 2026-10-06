@php
    use App\Livewire\Platform\ClinicUsageComponent as U;
    [$from, $to] = $range;
    $maxDay = max(1, (int) $days->max('requests'));
    $sortHead = fn (string $col, string $label) => '<button type="button" wire:click="sortBy(\''.$col.'\')" class="cu-sort'.($sort === $col ? ' on' : '').'">'.e($label).($sort === $col ? ($col === 'name' ? ' ↑' : ' ↓') : '').'</button>';
@endphp
<x-platform.page active="usage" title="Clinic Usage" subtitle="How much each clinic uses the online system: page views and actions, data sent, server and database time, and rows stored. Use it to see which clinics cost the most to host before setting usage-based prices.">
    <style>
        .cu-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:16px}.cu-bar .pp-field{width:auto;min-width:170px}
        .cu-sort{background:none;border:0;padding:0;color:inherit;font:inherit;text-transform:inherit;letter-spacing:inherit;cursor:pointer}.cu-sort.on{color:var(--blue)}
        .cu-num{text-align:right!important;white-space:nowrap}.cu-share{display:flex;align-items:center;gap:8px;justify-content:flex-end}
        .cu-meter{width:70px;height:6px;border-radius:3px;background:#1b2940;overflow:hidden}.cu-meter i{display:block;height:100%;background:var(--blue)}
        .cu-name{background:none;border:0;padding:0;color:#e8eef8;font:inherit;font-weight:700;cursor:pointer;text-align:left}.cu-name:hover{color:var(--blue)}
        .cu-open td{background:#0b1526}
        .cu-day{display:block;height:8px;border-radius:3px;background:var(--blue);min-width:2px}
        .pp-stats.cu-4{grid-template-columns:repeat(4,1fr)}@media(max-width:900px){.pp-stats.cu-4{grid-template-columns:1fr 1fr}}
    </style>

    @unless($metering)
        <div class="pp-warn">Usage metering is off on this installation (it is on by default only when tenancy is enabled). Set <code>USAGE_METERING=true</code> to start counting.</div>
    @endunless

    <div class="cu-bar">
        <label class="pp-field"><span>Period</span>
            <select wire:model.live="period">
                @foreach(U::PERIODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="pp-field"><span>Hosting bill for this period (optional)</span>
            <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="hostingCost" placeholder="e.g. 40.00">
        </label>
        <span class="pp-hint">{{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}. The bill is split by each clinic's share of server time; it isn't saved.</span>
    </div>

    <div class="pp-stats cu-4" style="margin-bottom:16px">
        <div class="pp-stat"><span>Requests</span><b>{{ number_format($totals['requests']) }}</b><small>Page views and actions</small></div>
        <div class="pp-stat"><span>Data sent</span><b>{{ U::bytes($totals['bytes_out']) }}</b><small>Before compression</small></div>
        <div class="pp-stat"><span>Server time</span><b>{{ U::duration($totals['server_ms']) }}</b><small>Time spent answering requests</small></div>
        <div class="pp-stat"><span>Active clinics</span><b>{{ $totals['active'] }}</b><small>Used the system in this period</small></div>
    </div>

    <div class="pp-card">
        <div class="pp-card-head"><div><h2>By clinic</h2><p>Click a clinic for its day-by-day usage. Click a column heading to sort.</p></div></div>
        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead><tr>
                    <th>{!! $sortHead('name', 'Clinic') !!}</th>
                    <th class="cu-num">{!! $sortHead('requests', 'Requests') !!}</th>
                    <th class="cu-num">{!! $sortHead('bytes_out', 'Data sent') !!}</th>
                    <th class="cu-num">{!! $sortHead('server_ms', 'Server time') !!}</th>
                    <th class="cu-num">{!! $sortHead('db_ms', 'Database time') !!}</th>
                    <th class="cu-num">Share</th>
                    @if($hostingCost !== '' && is_numeric($hostingCost))<th class="cu-num">Cost share</th>@endif
                    <th class="cu-num">{!! $sortHead('stored_rows', 'Rows stored') !!}</th>
                    <th class="cu-num">Active days</th>
                </tr></thead>
                <tbody>
                @forelse($clinics as $c)
                    <tr wire:key="cu-{{ $c['id'] }}" class="{{ $clinicId === $c['id'] ? 'cu-open' : '' }}">
                        <td><button type="button" class="cu-name" wire:click="showClinic({{ $c['id'] }})" aria-expanded="{{ $clinicId === $c['id'] ? 'true' : 'false' }}">{{ $c['name'] }}</button>
                            @if($c['status'] !== 'active')<span class="pp-badge grey">{{ ucfirst($c['status']) }}</span>@endif</td>
                        <td class="cu-num">{{ number_format($c['requests']) }}<small>{{ number_format($c['page_views']) }} pages · {{ number_format($c['actions']) }} actions</small></td>
                        <td class="cu-num">{{ U::bytes($c['bytes_out']) }}</td>
                        <td class="cu-num">{{ U::duration($c['server_ms']) }}</td>
                        <td class="cu-num">{{ U::duration($c['db_ms']) }}<small>{{ number_format($c['db_queries']) }} queries</small></td>
                        <td class="cu-num"><span class="cu-share"><span class="cu-meter"><i style="width:{{ round($c['share'] * 100, 1) }}%"></i></span>{{ number_format($c['share'] * 100, 1) }}%</span></td>
                        @if($c['cost'] !== null)<td class="cu-num">{{ number_format($c['cost'], 2) }}</td>@endif
                        <td class="cu-num">{{ $c['stored_rows'] === null ? '—' : number_format($c['stored_rows']) }}</td>
                        <td class="cu-num">{{ $c['active_days'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="pp-empty">No clinics yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($openClinic)
        <div class="pp-card">
            <div class="pp-card-head"><div><h2>{{ $openClinic['name'] }}: day by day</h2><p>Days with no use are left out.</p></div>
                <button type="button" class="pp-btn alt sm" wire:click="showClinic(null)">Close</button></div>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead><tr><th>Date</th><th style="width:30%">Requests</th><th class="cu-num">Data sent</th><th class="cu-num">Server time</th><th class="cu-num">Database time</th><th class="cu-num">Rows stored</th></tr></thead>
                    <tbody>
                    @forelse($days as $d)
                        <tr wire:key="cud-{{ $d->date }}">
                            <td>{{ \Carbon\Carbon::parse($d->date)->format('D j M') }}</td>
                            <td><span class="cu-day" style="width:{{ round($d->requests / $maxDay * 100) }}%"></span><small>{{ number_format($d->requests) }} ({{ number_format($d->page_views) }} pages · {{ number_format($d->actions) }} actions)</small></td>
                            <td class="cu-num">{{ U::bytes($d->bytes_out) }}</td>
                            <td class="cu-num">{{ U::duration($d->server_ms) }}</td>
                            <td class="cu-num">{{ U::duration($d->db_ms) }}</td>
                            <td class="cu-num">{{ $d->stored_rows === null ? '—' : number_format($d->stored_rows) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="pp-empty">No usage recorded in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-platform.page>
