@php use App\Livewire\Platform\OpticalLinksComponent as L; @endphp
<x-platform.page active="links" title="Optical Links" subtitle="Clinics that send spectacle jobs to an optical centre on Nexus: who is linked, the agreed terms, what is owed, and payments in dispute. Pause or end a link here when it needs stepping in.">
    <style>
        .ol-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:16px}.ol-bar .ui-field{width:auto;min-width:200px}
        .ol-num{text-align:right!important;white-space:nowrap}.ol-name{background:none;border:0;padding:0;color:#e8eef8;font:inherit;font-weight:700;cursor:pointer;text-align:left}.ol-name:hover{color:var(--blue)}
        .ol-open td{background:#0b1526}.ol-sub{display:block;font-size:11px;color:var(--muted)}
        .ui-tiles.ol-5{grid-template-columns:repeat(5,1fr)}@media(max-width:900px){.ui-tiles.ol-5{grid-template-columns:1fr 1fr}}
        .ol-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:900px){.ol-grid{grid-template-columns:1fr}}
        .ol-dl{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin:0}.ol-dl dt{color:var(--muted)}.ol-dl dd{margin:0}
    </style>

    <x-ui.flash />

    <div class="ui-tiles ui-tiles--auto ol-5" style="margin-bottom:16px" data-tour="ol-totals">
        <div class="ui-tile"><span class="ui-tile__label">Linked</span><b class="ui-tile__value">{{ $totals['live'] }}</b><small class="ui-tile__hint">Taking jobs now</small></div>
        <div class="ui-tile"><span class="ui-tile__label">Paused</span><b class="ui-tile__value">{{ $totals['paused'] }}</b><small class="ui-tile__hint">By a clinic or by you</small></div>
        <div class="ui-tile"><span class="ui-tile__label">Owed by clinics</span><b class="ui-tile__value">{{ currency() }} {{ number_format($totals['owed'], 2) }}</b><small class="ui-tile__hint">On jobs started, less confirmed payments</small></div>
        <div class="ui-tile"><span class="ui-tile__label">At credit limit</span><b class="ui-tile__value">{{ $totals['over'] }}</b><small class="ui-tile__hint">Can't send new jobs</small></div>
        <div class="ui-tile"><span class="ui-tile__label">Disputed payments</span><b class="ui-tile__value {{ $totals['disputed'] ? 'fail' : '' }}">{{ $totals['disputed'] }}</b><small class="ui-tile__hint">Optical centre says not received</small></div>
    </div>

    <div class="ol-bar" data-tour="ol-filter">
        <label class="ui-field"><span>Show</span>
            <select class="ui-input" wire:model.live="filter">@foreach(L::FILTERS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
        </label>
        <label class="ui-field"><span>Search</span><input class="ui-input" type="search" wire:model.live.debounce.300ms="search" placeholder="Clinic or optical centre"></label>
    </div>

    <div class="ui-card" data-tour="ol-list">
        <div class="ui-card-head"><div><h2>Links</h2><p>Click a link for its jobs, payments and actions.</p></div></div>
        <div class="ui-table-wrap ui-table-wrap--framed">
            <table class="ui-table">
                <thead><tr><th>Optical centre → clinic</th><th>Status</th><th>Terms</th><th class="ol-num">Jobs</th><th class="ol-num">Billed</th><th class="ol-num">Owed</th><th class="ol-num">Payments</th><th class="ol-num">Last activity</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    @php $link = $row['link']; @endphp
                    <tr wire:key="ol-{{ $link->id }}" class="{{ $openId === $link->id ? 'ol-open' : '' }}">
                        <td><button type="button" class="ol-name" wire:click="open({{ $link->id }})" aria-expanded="{{ $openId === $link->id ? 'true' : 'false' }}">{{ $link->clinic?->name ?? 'Optical centre #'.$link->clinic_id }} → {{ $link->linkedClinic?->name ?? 'Clinic #'.$link->linked_clinic_id }}</button>
                            <span class="ol-sub">Since {{ $link->linked_at?->format('j M Y') ?? '—' }}</span></td>
                        <td><span class="ui-badge {{ $link->link_status === 'active' ? 'ui-badge--good' : ($link->link_status === 'paused' ? 'ui-badge--warning' : 'ui-badge--neutral') }}">{{ \App\Models\OpticalPartnerClinic::LINK_STATUSES[$link->link_status] ?? $link->link_status }}</span>
                            @if($link->link_paused_by_platform)<span class="ol-sub">By you{{ $link->link_status_reason ? ': '.$link->link_status_reason : '' }}</span>@elseif($link->link_status === 'paused')<span class="ol-sub">By {{ (int) $link->link_paused_by_clinic_id === (int) $link->clinic_id ? 'the optical centre' : 'the clinic' }}</span>@endif
                            @if($link->link_status === 'ended' && $link->link_status_reason)<span class="ol-sub">{{ $link->link_status_reason }}</span>@endif</td>
                        <td><span class="ol-sub">Trade {{ $link->tradeSummary() }}</span><span class="ol-sub">Credit {{ $link->credit_limit !== null ? currency().' '.number_format($link->credit_limit, 2) : 'no limit' }}</span><span class="ol-sub">Auto-approve {{ $link->auto_approve_limit !== null ? 'to '.currency().' '.number_format($link->auto_approve_limit, 2) : 'off' }}</span></td>
                        <td class="ol-num">{{ $row['jobs'] }}<span class="ol-sub">{{ $row['open'] }} open</span></td>
                        <td class="ol-num">{{ number_format($row['billed'], 2) }}<span class="ol-sub">{{ number_format($row['paid'], 2) }} paid</span></td>
                        <td class="ol-num {{ $row['over'] ? 'fail' : '' }}">{{ number_format($row['owed'], 2) }}@if($row['over'])<span class="ol-sub fail">At credit limit</span>@endif</td>
                        <td class="ol-num">@if($row['disputed'])<span class="fail">{{ $row['disputed'] }} disputed</span>@endif @if($row['waiting'])<span class="ol-sub">{{ $row['waiting'] }} to confirm</span>@endif @if(! $row['disputed'] && ! $row['waiting'])—@endif</td>
                        <td class="ol-num">{{ $row['last'] ? \Carbon\Carbon::parse($row['last'])->format('j M Y') : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ui-empty">No links {{ $filter === 'all' ? 'yet' : 'match' }}.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($open)
        @php $link = $open['link']; @endphp
        <div class="ui-card" data-tour="ol-detail">
            <div class="ui-card-head"><div><h2>{{ $link->clinic?->name }} → {{ $link->linkedClinic?->name }}</h2><p>Partner record "{{ $link->name }}" at the optical centre. Amounts in {{ currency() }}.</p></div>
                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="open(null)">Close</button></div>
            <div class="ol-grid" style="padding:0 16px 16px">
                <div>
                    <h3 style="margin:0 0 8px;font-size:14px">Latest jobs</h3>
                    <div class="ui-table-wrap ui-table-wrap--framed">
                        <table class="ui-table">
                            <thead><tr><th>Sent</th><th>Patient</th><th>Status</th><th class="ol-num">Total</th><th class="ol-num">Paid</th></tr></thead>
                            <tbody>
                            @forelse($openRequests as $request)
                                <tr wire:key="olr-{{ $request->id }}"><td>{{ $request->created_at->format('j M Y') }}</td><td>{{ $request->patient_name }}@if($request->isRemake())<span class="ol-sub">Remake</span>@endif</td>
                                    <td>{{ $request->progressLabel() }}@if($request->auto_approved)<span class="ol-sub">Auto-approved</span>@endif</td>
                                    <td class="ol-num">{{ $request->order_total !== null ? number_format((float) $request->order_total, 2) : ($request->quote_total !== null ? number_format((float) $request->quote_total, 2) : '—') }}</td>
                                    <td class="ol-num">{{ $request->order_paid !== null ? number_format((float) $request->order_paid, 2) : '—' }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="ui-empty">No jobs sent yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <h3 style="margin:16px 0 8px;font-size:14px">Latest payments from the clinic</h3>
                    <div class="ui-table-wrap ui-table-wrap--framed">
                        <table class="ui-table">
                            <thead><tr><th>Recorded</th><th>Voucher</th><th>Status</th><th class="ol-num">Amount</th></tr></thead>
                            <tbody>
                            @forelse($openPayments as $payment)
                                <tr wire:key="olp-{{ $payment->id }}"><td>{{ $payment->created_at->format('j M Y') }}</td><td>{{ $payment->voucher_number }}<span class="ol-sub">{{ $payment->methodLabel() }}</span></td>
                                    <td class="{{ $payment->status === 'disputed' ? 'fail' : '' }}">{{ ucfirst($payment->status) }}@if($payment->dispute_reason)<span class="ol-sub">{{ $payment->dispute_reason }}</span>@endif</td>
                                    <td class="ol-num">{{ number_format((float) $payment->amount, 2) }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="ui-empty">No payments recorded through the link.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <h3 style="margin:0 0 8px;font-size:14px">Terms and balance</h3>
                    <dl class="ol-dl">
                        <dt>Trade discount</dt><dd>{{ $link->tradeSummary() }}</dd>
                        <dt>Credit limit</dt><dd>{{ $link->credit_limit !== null ? number_format($link->credit_limit, 2) : 'No limit' }}</dd>
                        <dt>Owed now</dt><dd class="{{ $open['over'] ? 'fail' : '' }}">{{ number_format($open['owed'], 2) }}</dd>
                        <dt>Clinic auto-approves</dt><dd>{{ $link->auto_approve_limit !== null ? 'Quotes up to '.number_format($link->auto_approve_limit, 2) : 'Off' }}</dd>
                        <dt>Terms changed</dt><dd>{{ $link->terms_updated_at?->format('j M Y') ?? 'Never' }}</dd>
                    </dl>
                    @if($link->hasLink())
                        <h3 style="margin:16px 0 8px;font-size:14px" data-tour="ol-actions">Step in</h3>
                        <p class="ui-hint" style="margin:0 0 8px">A pause you make stops new jobs, and only you can lift it. Ending the link stops it for good; jobs already sent stay on both sides. Both clinics see your reason.</p>
                        <label class="ui-field" style="width:100%"><span>Reason</span><textarea class="ui-input" rows="2" wire:model="reason" placeholder="e.g. Payment dispute under review by support"></textarea></label>
                        @error('reason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                            @if($link->link_status === 'active')
                                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="setStatus('paused')">Pause link</button>
                            @elseif($link->link_paused_by_platform)
                                <button type="button" class="ui-button ui-button-primary ui-button-sm" wire:click="setStatus('active')">Resume link</button>
                            @else
                                <span class="ui-hint">Paused by one of the clinics; they resume it.</span>
                            @endif
                            <button type="button" class="ui-button ui-button-danger ui-button-sm" wire:click="setStatus('ended')" wire:confirm="End the link between {{ $link->clinic?->name }} and {{ $link->linkedClinic?->name }}? The clinic can't send new jobs, and a new invite is needed to link again.">End link</button>
                        </div>
                    @else
                        <p class="ui-hint" style="margin-top:16px">This link has ended{{ $link->link_status_reason ? ': '.$link->link_status_reason : '' }}.</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
</x-platform.page>
