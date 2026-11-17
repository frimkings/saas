<div class="clinic-ui ui-page space-y-5">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Owed to Optical Centres</h1>
            <p class="ui-muted text-sm">What linked optical centres have billed your clinic for spectacle jobs, what you have paid, and payments they still have to confirm. A payment you record here goes into your expenses at once.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-guide-tour page="clinic.optical-owed" />
            <x-ui.button :href="route('secretary.optical-sent')" wire:navigate>Sent to Optical</x-ui.button>
            <x-ui.button :href="route('secretary.optical-centres')" wire:navigate>Optical centres</x-ui.button>
        </div>
    </div>

    <x-ui.flash />

    @forelse($summary as $row)
        @php $link = $row['link']; $receipts = $unbooked[$link->id] ?? collect(); @endphp
        <section class="ui-panel bg-white" wire:key="owed-{{ $link->id }}" @if($loop->first) data-tour="owed-account" @endif>
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 p-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">{{ $link->clinic?->name ?? $link->name }}</h2>
                    <p class="ui-muted text-xs">{{ \App\Models\OpticalPartnerClinic::LINK_STATUSES[$link->link_status] ?? 'Unlinked' }}@if($link->billing_terms) · {{ $link->billing_terms === 'on_account' ? 'On account' : 'Pay on order' }}@endif</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button wire:click="toggleHistory({{ $link->id }})">{{ $historyPartnerId === $link->id ? 'Hide history' : 'Account history' }}</x-ui.button>
                    @if($row['to_pay'] > 0)<x-ui.button variant="primary" wire:click="openPay({{ $link->id }})" data-tour="owed-pay">Record payment</x-ui.button>@endif
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4 md:grid-cols-4">
                <div><div class="text-xs font-semibold uppercase text-slate-500">Billed</div><div class="text-lg font-bold tabular-nums">{{ currency() }} {{ number_format($row['billed'], 2) }}</div></div>
                <div><div class="text-xs font-semibold uppercase text-slate-500">Paid</div><div class="text-lg font-bold tabular-nums">{{ currency() }} {{ number_format($row['paid'], 2) }}</div></div>
                <div><div class="text-xs font-semibold uppercase text-slate-500">Waiting for them to confirm</div><div class="text-lg font-bold tabular-nums {{ $row['waiting'] > 0 ? 'text-amber-700' : '' }}">{{ currency() }} {{ number_format($row['waiting'], 2) }}</div></div>
                <div><div class="text-xs font-semibold uppercase text-slate-500">Still to pay</div><div class="text-lg font-bold tabular-nums {{ $row['to_pay'] > 0 ? 'text-red-700' : 'text-green-700' }}">{{ currency() }} {{ number_format($row['to_pay'], 2) }}</div></div>
            </div>
            @if(array_sum($row['aging']) > 0)
                <div class="flex flex-wrap gap-4 px-4 pb-3 text-xs text-slate-600">
                    @foreach(\App\Services\OpticalPartnerAccountService::AGING as $key => $label)<span>{{ $label }}: <b class="tabular-nums">{{ number_format($row['aging'][$key], 2) }}</b></span>@endforeach
                </div>
            @endif
            @if($row['open']->isNotEmpty())
                <div class="ui-table-wrap border-t border-slate-200">
                    <table class="ui-table w-full text-sm">
                        <thead><tr><th>Job</th><th>Patient</th><th>Since</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Owed</th></tr></thead>
                        <tbody>
                            @foreach($row['open'] as $job)
                                <tr wire:key="owed-job-{{ $job->id }}">
                                    <td>{{ $job->order_number }}<div class="text-xs text-slate-500">{{ $job->order_status }}</div></td>
                                    <td>{{ $job->patient_name }}<div class="text-xs text-slate-500">{{ $job->sender_reference }}</div></td>
                                    <td>{{ ($job->approved_at ?? $job->created_at)->format('d M Y') }}</td>
                                    <td class="text-right tabular-nums">{{ number_format((float) $job->order_total, 2) }}</td>
                                    <td class="text-right tabular-nums">{{ number_format((float) $job->order_paid, 2) }}</td>
                                    <td class="text-right tabular-nums font-semibold">{{ number_format($job->balance(), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($receipts->isNotEmpty())
                <div class="border-t border-slate-200 p-4">
                    <h3 class="text-sm font-bold text-slate-900">Paid but not in your expenses yet</h3>
                    <p class="ui-muted mb-2 text-xs">The optical centre recorded these payments from you directly (cash at their counter, for example). Add them so your expenses and cash book match.</p>
                    @foreach($receipts as $receipt)
                        <div class="flex flex-wrap items-center justify-between gap-2 py-1 text-sm" wire:key="rcpt-{{ $receipt['payment']->id }}">
                            <span>{{ $receipt['payment']->created_at->format('d M Y') }} · {{ $receipt['payment']->receipt_number }} · {{ \App\Models\OpticalPartnerPayment::METHODS[$receipt['payment']->payment_method] ?? $receipt['payment']->payment_method }} · <b class="tabular-nums">{{ currency() }} {{ number_format($receipt['amount'], 2) }}</b></span>
                            <x-ui.button wire:click="bookReceipt({{ $link->id }}, {{ $receipt['payment']->id }})">Add to expenses</x-ui.button>
                        </div>
                    @endforeach
                </div>
            @endif
            @if($historyPartnerId === $link->id)
                <div class="ui-table-wrap border-t border-slate-200">
                    <table class="ui-table w-full text-sm">
                        <thead><tr><th>Date</th><th>Details</th><th class="text-right">Charged</th><th class="text-right">Paid</th><th class="text-right">Balance</th></tr></thead>
                        <tbody>
                            @forelse($history as $entry)
                                <tr><td>{{ $entry['date']->format('d M Y') }}</td><td>{{ $entry['description'] }}</td>
                                    <td class="text-right tabular-nums">{{ $entry['charge'] > 0 ? number_format($entry['charge'], 2) : '' }}</td>
                                    <td class="text-right tabular-nums">{{ $entry['payment'] > 0 ? number_format($entry['payment'], 2) : '' }}</td>
                                    <td class="text-right tabular-nums font-semibold">{{ number_format($entry['balance'], 2) }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="ui-empty"><p class="ui-muted text-sm">No jobs or payments yet.</p></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="ui-muted px-4 py-2 text-xs">Payments waiting for the optical centre to confirm are not in this history yet.</p>
                </div>
            @endif
        </section>
    @empty
        <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">Your clinic isn't linked to an optical centre yet, so nothing is owed. Link one under Optical centres.</div>
    @endforelse

    <section class="ui-panel bg-white" data-tour="owed-payments">
        <div class="border-b border-slate-200 p-4"><h2 class="text-base font-bold text-slate-900">Payments you recorded</h2></div>
        <div class="ui-table-wrap">
            <table class="ui-table w-full text-sm">
                <thead><tr><th>Date</th><th>Optical centre</th><th>Voucher</th><th>Method</th><th class="text-right">Amount</th><th>Status</th><th class="text-right"></th></tr></thead>
                <tbody>
                    @forelse($payments as $payment)
                        <tr wire:key="olp-{{ $payment->id }}">
                            <td>{{ $payment->created_at->format('d M Y') }}<div class="text-xs text-slate-500">{{ $payment->recorder?->name }}</div></td>
                            <td>{{ $payment->opticalClinic?->name }}</td>
                            <td>{{ $payment->voucher_number }}@if($payment->reference && $payment->reference !== $payment->voucher_number)<div class="text-xs text-slate-500">ref {{ $payment->reference }}</div>@endif</td>
                            <td>{{ $payment->methodLabel() }}</td>
                            <td class="text-right tabular-nums">{{ number_format((float) $payment->amount, 2) }}</td>
                            <td><span class="inline-flex rounded px-2 py-0.5 text-xs font-semibold {{ match($payment->status) { 'pending' => 'bg-amber-100 text-amber-900', 'confirmed' => 'bg-green-100 text-green-800', 'disputed' => 'bg-red-100 text-red-800', default => 'bg-slate-100 text-slate-700' } }}">{{ \App\Models\OpticalLinkPayment::STATUSES[$payment->status] ?? $payment->status }}</span>
                                @if($payment->status === 'disputed')<div class="mt-1 text-xs text-red-700">{{ $payment->dispute_reason }}</div>@endif</td>
                            <td class="text-right whitespace-nowrap">@if(in_array($payment->status, \App\Models\OpticalLinkPayment::WAITING, true))<x-ui.button variant="danger" wire:click="withdraw({{ $payment->id }})" wire:confirm="Withdraw this payment? Its expense is removed from your books.">Withdraw</x-ui.button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty"><p class="ui-muted text-sm">No payments recorded yet.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($payRow)
        <x-ui.modal :title="'Record a payment to '.($payRow['link']->clinic?->name ?? 'the optical centre')" state="showPay">
            <form wire:submit="recordPayment" class="space-y-4 p-4">
                <p class="text-sm text-slate-600">You still owe <b>{{ currency() }} {{ number_format($payRow['to_pay'], 2) }}</b>. The payment is added to your expenses now ("{{ \App\Models\OpticalLinkPayment::EXPENSE_CATEGORY }}"); the optical centre confirms it once the money arrives.</p>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block text-sm"><span class="mb-1 block font-semibold text-slate-700">Amount ({{ currency() }})</span><input type="number" step="0.01" min="0" wire:model="payAmount" class="ui-input" required>@error('payAmount')<p class="ui-error">{{ $message }}</p>@enderror</label>
                    <label class="block text-sm"><span class="mb-1 block font-semibold text-slate-700">Paid by</span><select wire:model="payMethod" class="ui-input">@foreach($methods as $key => $label)<option value="{{ $key }}">{{ $label }}{{ $key === 'cash' ? ' (from the till)' : '' }}</option>@endforeach</select>@error('payMethod')<p class="ui-error">{{ $message }}</p>@enderror</label>
                    <label class="block text-sm"><span class="mb-1 block font-semibold text-slate-700">Reference</span><input type="text" wire:model="payReference" maxlength="100" class="ui-input" placeholder="MoMo or bank reference"></label>
                    <label class="block text-sm"><span class="mb-1 block font-semibold text-slate-700">Notes</span><input type="text" wire:model="payNotes" maxlength="1000" class="ui-input"></label>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="splitByJob"> Say which jobs this pays (otherwise the oldest jobs are paid first)</label>
                @if($splitByJob)
                    <div class="space-y-1">
                        @foreach($payJobs as $row)
                            <label class="flex items-center justify-between gap-3 text-sm" wire:key="alloc-{{ $row['job']->id }}">
                                <span>{{ $row['job']->order_number }} · {{ $row['job']->patient_name }} <span class="text-xs text-slate-500">owes {{ number_format($row['left'], 2) }}</span></span>
                                <input type="number" step="0.01" min="0" max="{{ $row['left'] }}" wire:model="allocations.{{ $row['job']->id }}" class="ui-input !w-32 text-right" aria-label="Amount for {{ $row['job']->order_number }}">
                            </label>
                        @endforeach
                        @error('allocations')<p class="ui-error">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div class="flex justify-end gap-2">
                    <x-ui.button wire:click="closePay">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="recordPayment">Record payment</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
