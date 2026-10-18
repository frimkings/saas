@php
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $chip = 'inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-semibold';
    $money = fn ($v) => number_format((float) $v, 2);
    $methodName = fn ($key) => $figures[$key]['label'] ?? \App\Support\PaymentMethods::label($key);
    $hasBlockers = $blockers['voids']->isNotEmpty() || $blockers['corrections']->isNotEmpty();
@endphp
<div class="clinic-ui ui-page space-y-5">
<x-ui.flash />

    <div class="ui-heading" data-tour="day-close-heading">
        <div>
            <h1>Day close</h1>
            <p class="ui-muted">Count the till, put the day's mistakes right, and close {{ $branch ? $branch->name.' for ' : '' }}the day.</p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div>
                <label for="close-date" class="{{ $label }}">Day</label>
                <input id="close-date" type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="ui-input">
            </div>
            <a href="{{ route('admin.cash-book') }}" wire:navigate class="ui-button ui-button-secondary">Cash book</a>
        </div>
    </div>

    @if($closed)
        <section class="ui-panel border-green-300 bg-green-50 p-5" aria-label="Day closed">
            <p class="text-sm font-semibold text-green-900"><i class="fas fa-lock mr-1" aria-hidden="true"></i>
                {{ $day->format('l, d M Y') }} was closed by {{ $close->closer?->name ?? 'someone' }} at {{ $close->closed_at?->format('H:i') }}.
                Difference {{ currency() }} {{ $money($close->variance) }} · banked {{ currency() }} {{ $money($close->banked) }}.</p>
            @if($close->notes)<p class="mt-1 text-sm text-green-900">“{{ $close->notes }}”</p>@endif
            @if($drift->isNotEmpty())
                <p class="mt-2 rounded-md bg-amber-100 px-3 py-2 text-sm text-amber-900" role="status">
                    <i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>
                    Money moved on this day after it was closed:
                    @foreach($drift as $key => $d){{ $methodName($key) }} {{ $d > 0 ? '+' : '−' }}{{ currency() }} {{ $money(abs($d)) }}@if(!$loop->last), @endif @endforeach.
                    It is on the cash book; reopen and close the day again to count it.
                </p>
            @endif
            @if($isOwner)
                <form wire:submit="reopen" class="mt-3 flex flex-wrap items-end gap-2">
                    <div class="min-w-[16rem] flex-1">
                        <label for="reopen-reason" class="{{ $label }}">Reopen the day (owner)</label>
                        <input id="reopen-reason" type="text" wire:model="reopenReason" class="ui-input" placeholder="Why it needs reopening" maxlength="500">
                        @error('reopenReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="ui-button ui-button-secondary"><i class="fas fa-lock-open mr-1" aria-hidden="true"></i>Reopen</button>
                </form>
            @endif
        </section>
    @elseif($close)
        <p class="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-900">Reopened by {{ $close->reopener?->name ?? 'the owner' }} on {{ $close->reopened_at?->format('d M Y H:i') }}: {{ $close->reopen_reason }}</p>
    @endif

    @if($hasBlockers)
        <section class="ui-panel border-red-300 bg-red-50 p-5" aria-label="Waiting for a decision" data-tour="day-close-blockers">
            <h2 class="text-base font-semibold text-red-900">Decide these before closing</h2>
            <ul class="mt-2 space-y-1 text-sm text-red-900">
                @foreach($blockers['voids'] as $void)
                    <li><i class="fas fa-ban mr-1" aria-hidden="true"></i>Void of sale <span class="font-mono">{{ $void->sale?->transaction_id }}</span> is {{ $void->status === 'approved' ? 'approved but not yet processed' : 'waiting for the owner' }}.
                        <a href="{{ route('admin.refund-approvals') }}" class="font-semibold underline">Open voids & refunds</a></li>
                @endforeach
                @foreach($blockers['corrections'] as $correction)
                    <li><i class="fas fa-exchange-alt mr-1" aria-hidden="true"></i>A payment correction is waiting for the owner (below).</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="ui-panel" aria-label="Count the till" data-tour="day-close-count">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">{{ $closed ? 'What was counted' : 'Count the till' }}</h2>
            <p class="text-xs text-slate-500">Expected = start of the day + money in − money out (refunds, expenses, banking). The cash drawer must be counted; other accounts are optional — check the MoMo wallet or card statement if you can.</p>
        </div>
        <form wire:submit="close">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Method</th><th class="ui-number">Start of day</th><th class="ui-number">In</th><th class="ui-number">Out</th><th class="ui-number">Expected</th><th class="ui-number">Counted</th><th class="ui-number">Difference</th></tr></thead>
                    <tbody>
                        @foreach($figures as $key => $f)
                            @php
                                $snap = $closed ? ($close->methods[$key] ?? null) : null;
                                $expected = $closed ? (float) ($snap['expected'] ?? $f['expected']) : $f['expected'];
                                $count = $closed ? ($snap['counted'] ?? null) : (($counted[$key] ?? '') === '' ? null : (float) $counted[$key]);
                                $diff = $count === null ? null : round($count - $expected, 2);
                            @endphp
                            <tr wire:key="method-{{ $key }}">
                                <td class="font-semibold">{{ $f['label'] }}</td>
                                <td class="ui-number">{{ $money($snap['opening'] ?? $f['opening']) }}</td>
                                <td class="ui-number text-green-700">{{ $money($snap['in'] ?? $f['in']) }}</td>
                                <td class="ui-number text-red-700">{{ $money($snap['out'] ?? $f['out']) }}</td>
                                <td class="ui-number font-semibold">{{ $money($expected) }}</td>
                                <td class="ui-number">
                                    @if($closed)
                                        {{ $count === null ? '—' : $money($count) }}
                                    @else
                                        <label for="count-{{ $key }}" class="sr-only">Counted {{ $f['label'] }}</label>
                                        <input id="count-{{ $key }}" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="counted.{{ $key }}" class="ui-input !w-32 text-right" placeholder="{{ $key === 'cash' ? 'Required' : 'Optional' }}">
                                    @endif
                                </td>
                                <td class="ui-number font-semibold {{ $diff === null ? 'text-slate-400' : (abs($diff) < 0.005 ? 'text-green-700' : ($diff > 0 ? 'text-sky-700' : 'text-red-700')) }}">
                                    {{ $diff === null ? '—' : (abs($diff) < 0.005 ? 'Balanced' : ($diff > 0 ? 'Over ' : 'Short ').$money(abs($diff))) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @unless($closed)
                <div class="grid gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-[1fr_2fr_auto]">
                    <div>
                        <label for="banked" class="{{ $label }}">Cash banked / handed over now</label>
                        <input id="banked" type="number" step="0.01" min="0" wire:model="banked" class="ui-input" placeholder="{{ $suggestedBank !== null ? $money($suggestedBank) : '0.00' }}">
                        <p class="mt-1 text-xs text-slate-500">
                            @if($suggestedBank !== null)
                                Suggested: {{ currency() }} {{ $money($suggestedBank) }}, leaving the {{ currency() }} {{ $money($float) }} float.
                                <button type="button" wire:click="$set('banked', '{{ number_format($suggestedBank, 2, '.', '') }}')" class="font-semibold text-teal-700 underline">Use it</button>
                            @else
                                Count the cash to see what to bank.
                            @endif
                        </p>
                        @error('banked')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="close-notes" class="{{ $label }}">Notes</label>
                        <input id="close-notes" type="text" wire:model="notes" class="ui-input" maxlength="1000" placeholder="Explain any difference">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="ui-button ui-button-primary w-full" @disabled($hasBlockers)><i class="fas fa-lock mr-1" aria-hidden="true"></i>Close the day</button>
                    </div>
                    @error('close')<p class="ui-error sm:col-span-3" role="alert">{{ $message }}</p>@enderror
                    @error('counted.*')<p class="ui-error sm:col-span-3" role="alert">{{ $message }}</p>@enderror
                </div>
            @endunless
        </form>
    </section>

    <section class="ui-panel" aria-label="Collections by cashier" data-tour="day-close-cashiers">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-base font-semibold text-slate-900">By cashier</h2></div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Cashier</th>@foreach($figures as $key => $f)<th class="ui-number">{{ $f['label'] }}</th>@endforeach<th class="ui-number">Refunded</th><th class="ui-number">Net</th></tr></thead>
                <tbody>
                    @forelse($cashiers as $person)
                        <tr>
                            <td class="font-semibold">{{ $person['name'] }}</td>
                            @foreach($figures as $key => $f)<td class="ui-number">{{ isset($person['methods'][$key]) ? $money($person['methods'][$key]) : '' }}</td>@endforeach
                            <td class="ui-number text-red-700">{{ $person['refunded'] > 0 ? $money($person['refunded']) : '' }}</td>
                            <td class="ui-number font-semibold">{{ $money($person['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($figures) + 3 }}" class="ui-empty italic text-slate-500">No money taken on this day.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-panel" aria-label="Payments on this day" data-tour="day-close-payments">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">Payments taken</h2>
            <p class="text-xs text-slate-500">Recorded under the wrong method? Ask for a correction; the owner approves it. To cancel a sale entered by mistake, request a void from Sales Records.</p>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Time</th><th>Sale</th><th>Customer</th><th>Taken by</th><th>Method</th><th class="ui-number">Amount</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse($payments as $payment)
                        <tr wire:key="payment-{{ $payment->id }}">
                            <td>{{ $payment->created_at->format('H:i') }}</td>
                            <td class="font-mono text-xs">{{ $payment->sale?->transaction_id }}</td>
                            <td>{{ $payment->sale?->patient?->name ?? $payment->sale?->customer_name }}</td>
                            <td class="text-xs text-slate-500">{{ $payment->collectedBy?->name }}</td>
                            <td>{{ \App\Support\PaymentMethods::label($payment->payment_method) }}</td>
                            <td class="ui-number font-semibold">{{ $money($payment->amount) }}</td>
                            <td class="text-right">
                                @if($pendingByPayment->has($payment->id))
                                    <span class="{{ $chip }} bg-amber-100 text-amber-800">Correction pending</span>
                                @elseif(!$closed)
                                    <button type="button" wire:click="startCorrection({{ $payment->id }})" class="ui-button ui-button-secondary">Wrong method?</button>
                                @endif
                            </td>
                        </tr>
                        @if($correctingPaymentId === $payment->id)
                            <tr wire:key="correct-{{ $payment->id }}"><td colspan="7" class="bg-slate-50">
                                <form wire:submit="requestCorrection" class="grid gap-2 sm:grid-cols-[1fr_2fr_auto_auto] sm:items-end">
                                    <div>
                                        <label for="correct-to" class="{{ $label }}">Right method</label>
                                        <select id="correct-to" wire:model="correctTo" class="ui-input">
                                            <option value="">Choose…</option>
                                            @foreach($methodOptions as $key => $name)
                                                @if($key !== $payment->payment_method)<option value="{{ $key }}">{{ $name }}</option>@endif
                                            @endforeach
                                        </select>
                                        @error('correctTo')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label for="correct-reason" class="{{ $label }}">What went wrong</label>
                                        <input id="correct-reason" type="text" wire:model="correctReason" class="ui-input" maxlength="500" placeholder="e.g. Patient paid by MoMo, entered as cash">
                                        @error('correctReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <button type="submit" class="ui-button ui-button-primary">Send to owner</button>
                                    <button type="button" wire:click="$set('correctingPaymentId', null)" class="ui-button ui-button-secondary">Cancel</button>
                                </form>
                            </td></tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="ui-empty italic text-slate-500">No payments on this day.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($corrections->isNotEmpty())
        <section class="ui-panel" aria-label="Payment corrections" data-tour="day-close-corrections">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-base font-semibold text-slate-900">Payment corrections</h2></div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Sale</th><th>Change</th><th class="ui-number">Amount</th><th>Why</th><th>Asked by</th><th>Status</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach($corrections as $correction)
                            <tr wire:key="correction-{{ $correction->id }}">
                                <td class="font-mono text-xs">{{ $correction->sale?->transaction_id }}</td>
                                <td>{{ \App\Support\PaymentMethods::label($correction->from_method) }} → <b>{{ \App\Support\PaymentMethods::label($correction->to_method) }}</b></td>
                                <td class="ui-number">{{ $money($correction->amount) }}</td>
                                <td class="text-sm">{{ $correction->reason }}@if($correction->rejection_reason)<span class="block text-xs text-red-700">Rejected: {{ $correction->rejection_reason }}</span>@endif</td>
                                <td class="text-xs text-slate-500">{{ $correction->requester?->name }}</td>
                                <td>
                                    @if($correction->status === 'pending')<span class="{{ $chip }} bg-amber-100 text-amber-800">Waiting for owner</span>
                                    @elseif($correction->status === 'approved')<span class="{{ $chip }} bg-green-100 text-green-800">Approved{{ $correction->decider ? ' by '.$correction->decider->name : '' }}</span>
                                    @else<span class="{{ $chip }} bg-red-100 text-red-800">Rejected</span>@endif
                                </td>
                                <td class="text-right">
                                    @if($isOwner && $correction->status === 'pending' && !$closed)
                                        <div class="flex justify-end gap-1">
                                            <button type="button" wire:click="approveCorrection({{ $correction->id }})" class="ui-button ui-button-primary">Approve</button>
                                            <button type="button" wire:click="$set('rejectingCorrectionId', {{ $correction->id }})" class="ui-button ui-button-danger">Reject</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            @if($rejectingCorrectionId === $correction->id)
                                <tr wire:key="reject-{{ $correction->id }}"><td colspan="7" class="bg-red-50">
                                    <form wire:submit="rejectCorrection" class="flex flex-wrap items-end gap-2">
                                        <div class="min-w-[16rem] flex-1">
                                            <label for="reject-reason" class="{{ $label }}">Reason for rejecting</label>
                                            <input id="reject-reason" type="text" wire:model="rejectReason" class="ui-input" maxlength="500">
                                            @error('rejectReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                        </div>
                                        <button type="submit" class="ui-button ui-button-danger">Reject</button>
                                        <button type="button" wire:click="$set('rejectingCorrectionId', null)" class="ui-button ui-button-secondary">Back</button>
                                    </form>
                                </td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="ui-panel" aria-label="Recent day closes">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-base font-semibold text-slate-900">Recent days</h2></div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Day</th><th>Status</th><th>Closed by</th><th class="ui-number">Difference</th><th class="ui-number">Banked</th></tr></thead>
                <tbody>
                    @forelse($recent as $row)
                        <tr>
                            <td><button type="button" wire:click="$set('date', '{{ $row->business_date->toDateString() }}')" class="font-semibold text-teal-700 hover:underline">{{ $row->business_date->format('D d M Y') }}</button></td>
                            <td>@if($row->isClosed())<span class="{{ $chip }} bg-green-100 text-green-800">Closed</span>@else<span class="{{ $chip }} bg-amber-100 text-amber-800">Reopened</span>@endif</td>
                            <td class="text-sm">{{ $row->closer?->name }}</td>
                            <td class="ui-number {{ abs((float) $row->variance) < 0.005 ? 'text-green-700' : 'text-red-700' }}">{{ $money($row->variance) }}</td>
                            <td class="ui-number">{{ $money($row->banked) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-empty italic text-slate-500">No days closed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
