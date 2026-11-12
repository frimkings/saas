<div class="space-y-5">
    <x-ui.flash />

    <div class="ds-tiles" data-tour="scan-commission-tiles">
        <div class="ds-tile"><span>Owed to referrers</span><b style="color:{{ $tiles['owed'] > 0 ? '#b91c1c' : 'inherit' }}">{{ currency() }} {{ number_format($tiles['owed'], 2) }}</b><small class="ds-hint">{{ $tiles['payees'] }} {{ \Illuminate\Support\Str::plural('referrer', $tiles['payees']) }}</small></div>
        <div class="ds-tile"><span>Earned this month</span><b>{{ currency() }} {{ number_format($tiles['earnedMonth'], 2) }}</b></div>
        <div class="ds-tile"><span>Paid this month</span><b>{{ currency() }} {{ number_format($tiles['paidMonth'], 2) }}</b></div>
        <div class="ds-tile"><span>How it works</span><small class="ds-hint" style="display:block;margin-top:4px">Earned when a referred patient's bill is fully paid. A refund takes it back.</small></div>
    </div>

    <section class="ui-panel bg-white" data-tour="scan-commission-owed">
        <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">Owed now</h2></div>
        <div class="overflow-x-auto">
            <table class="oo-table w-full" style="min-width:720px">
                <colgroup><col style="width:auto"><col style="width:150px"><col style="width:110px"><col style="width:140px"><col style="width:140px"><col style="width:100px"></colgroup>
                <thead><tr><th>Referrer</th><th>Type</th><th>Lines</th><th>Oldest</th><th class="oo-num">Owed</th><th></th></tr></thead>
                <tbody>
                    @forelse($owed as $row)
                        <tr wire:key="owed-{{ $row['type'] }}-{{ $row['id'] }}" wire:click="openPayee('{{ $row['type'] }}', {{ $row['id'] }})">
                            <td><b>{{ $row['name'] }}</b>@if($row['code']) <span class="oo-id">{{ $row['code'] }}</span>@endif</td>
                            <td><span class="oo-badge {{ $row['type'] === 'partner' ? 'oo-b-teal' : 'oo-b-purple' }}">{{ $row['type'] === 'partner' ? 'Partner clinic' : 'Our doctor' }}</span></td>
                            <td>{{ $row['count'] }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($row['oldest'])->format('d M Y') }}</td>
                            <td class="oo-num"><b style="color:{{ $row['amount'] > 0 ? '#b91c1c' : 'inherit' }}">{{ currency() }} {{ number_format($row['amount'], 2) }}</b></td>
                            <td><div class="flex justify-end"><button type="button" class="oo-btn" wire:click.stop="openPayee('{{ $row['type'] }}', {{ $row['id'] }})">{{ $row['amount'] > 0 ? 'Pay' : 'View' }}</button></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">Nothing owed. Commission appears here when a referred patient's scans are paid in full.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-panel bg-white">
        <div style="padding:14px 16px;border-bottom:1px solid var(--clinic-line)"><h2 class="text-sm font-bold m-0">Recent payouts</h2></div>
        <div class="overflow-x-auto">
            <table class="oo-table w-full" style="min-width:760px">
                <colgroup><col style="width:140px"><col style="width:auto"><col style="width:120px"><col style="width:150px"><col style="width:150px"><col style="width:130px"></colgroup>
                <thead><tr><th>Voucher</th><th>Paid to</th><th>Lines</th><th>Method</th><th>Paid</th><th class="oo-num">Amount</th></tr></thead>
                <tbody>
                    @forelse($payouts as $payout)
                        <tr wire:key="payout-{{ $payout->id }}" style="cursor:default">
                            <td><span class="oo-id">{{ $payout->voucher_number }}</span><span class="oo-sub">{{ $payout->route === 'payroll' ? 'Through payroll' : 'Voucher' }}</span></td>
                            <td><b>{{ $payout->payeeName() }}</b>@if($payout->reference)<span class="oo-sub">Ref {{ $payout->reference }}</span>@endif</td>
                            <td>{{ $payout->commissions_count }}<span class="oo-sub">up to {{ $payout->period_to?->format('d M') }}</span></td>
                            <td>{{ $methods[$payout->payment_method] ?? ($payout->payment_method ?: '—') }}</td>
                            <td>{{ $payout->paid_at->format('d M Y, H:i') }}<span class="oo-sub">{{ $payout->payer?->name }}</span></td>
                            <td class="oo-num"><b>{{ currency() }} {{ number_format($payout->amount, 2) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">No payouts yet.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($payeeType && $payeeId)
        <div class="oo-overlay" wire:click="closePayee" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="sc-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.closePayee()" wire:key="sc-drawer-{{ $payeeType }}-{{ $payeeId }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="sc-title">{{ $payee['name'] ?? 'Referrer' }}</h2>
                    <div class="text-xs ui-muted">{{ $payeeType === 'partner' ? 'Partner clinic' : 'Our doctor' }}@if($payee['code'] ?? null) · <span class="oo-id">{{ $payee['code'] }}</span>@endif</div>
                </div>
                <button type="button" class="oo-x" wire:click="closePayee" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                <section class="oo-section">
                    <h3>Unpaid lines</h3>
                    @if($lines->isEmpty())
                        <p class="ui-muted text-sm m-0">Nothing unpaid.</p>
                    @else
                        <table class="oo-lines">
                            <thead><tr><th style="width:90px">Earned</th><th>Scan</th><th style="width:110px" class="oo-num">Paid bill</th><th style="width:90px">Rate</th><th style="width:100px" class="oo-num">Commission</th></tr></thead>
                            <tbody>
                                @foreach($lines as $line)
                                    <tr wire:key="scl-{{ $line->id }}">
                                        <td>{{ $line->earned_on->format('d M') }}</td>
                                        <td>{{ $line->item?->scan_name }}{{ $line->item?->eye ? ' ('.$line->item->eye.')' : '' }}<span class="oo-sub">{{ $line->order?->order_number }} · {{ $line->order?->patient?->name }}</span>
                                            @if($line->kind === 'correction')<span class="oo-badge oo-b-amber">Correction: refunded after payout</span>@endif</td>
                                        <td class="oo-num">{{ number_format($line->base_amount, 2) }}</td>
                                        <td>{{ \App\Models\DiagnosticScan::describeCommission($line->rate_type, (float) $line->rate_value) }}</td>
                                        <td class="oo-num"><b style="color:{{ $line->amount < 0 ? '#b91c1c' : 'inherit' }}">{{ number_format($line->amount, 2) }}</b></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>

                @if($lines->isNotEmpty())
                    <section class="oo-section">
                        <h3>Pay out</h3>
                        <form wire:submit="pay" wire:confirm="Pay {{ $payee['name'] ?? 'this referrer' }} {{ currency() }} {{ number_format($payable, 2) }}?" id="sc-pay-form" class="ds-2">
                            <label class="ds-field"><span>Pay what was earned up to</span><input type="date" wire:model.live="upTo" class="ui-input" max="{{ now()->toDateString() }}">@error('upTo')<small class="ds-err">{{ $message }}</small>@enderror</label>
                            <label class="ds-field"><span>Paid by</span><select wire:model="payMethod" class="ui-input">@foreach($methods as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>@error('payMethod')<small class="ds-err">{{ $message }}</small>@enderror</label>
                            <label class="ds-field"><span>Reference</span><input type="text" wire:model="payReference" class="ui-input" placeholder="Transfer or MoMo reference">@error('payReference')<small class="ds-err">{{ $message }}</small>@enderror</label>
                            <label class="ds-field"><span>Notes</span><input type="text" wire:model="payNotes" class="ui-input"></label>
                        </form>
                        <div class="oo-money mt-2.5" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                            <div><span>To pay now</span><b style="color:{{ $payable > 0 ? '#065f46' : '#b91c1c' }}">{{ currency() }} {{ number_format($payable, 2) }}</b></div>
                            <div><span>Owed in all</span><b>{{ currency() }} {{ number_format((float) $lines->sum('amount'), 2) }}</b></div>
                        </div>
                        @error('payAmount')<div class="oo-note red mt-2.5">{{ $message }}</div>@enderror
                        <p class="ds-hint" style="margin:8px 0 0">Recorded as a "{{ \App\Services\Scans\ScanCommissionService::EXPENSE_CATEGORY }}" expense{{ $payMethod === 'cash' ? ', taken from the till' : '' }}.@if($payeeType === 'staff') Doctors can also be paid with their salary once payroll settles commission.@endif</p>
                    </section>
                @endif
            </div>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" wire:click="closePayee">Close</button>
                @if($lines->isNotEmpty())
                    {{-- The confirm sits on the form: on a button outside it, Livewire skips it. --}}
                    <button type="submit" form="sc-pay-form" class="oo-btn primary" @disabled($payable <= 0) wire:loading.attr="disabled" wire:target="pay">Pay {{ currency() }} {{ number_format($payable, 2) }}</button>
                @endif
            </footer>
        </aside>
    @endif
</div>
