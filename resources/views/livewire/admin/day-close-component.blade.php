@php
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $chip = 'inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-semibold';
    $money = fn ($v) => number_format((float) $v, 2);
    $methodName = fn ($key) => $figures[$key]['label'] ?? \App\Support\PaymentMethods::label($key);
    $hasBlockers = $blockers['voids']->isNotEmpty() || $blockers['corrections']->isNotEmpty();

    // Each method as shown: what was expected (as it stood at the close, for a closed day), what was counted.
    $rows = collect($figures)->map(function ($f, $key) use ($closed, $close, $counted) {
        $snap = $closed ? ($close->methods[$key] ?? null) : null;
        $row = [
            'label' => $f['label'],
            'opening' => (float) ($snap['opening'] ?? $f['opening']),
            'in' => (float) ($snap['in'] ?? $f['in']),
            'out' => (float) ($snap['out'] ?? $f['out']),
            'expected' => (float) ($closed ? ($snap['expected'] ?? $f['expected']) : $f['expected']),
            'counted' => $closed ? ($snap['counted'] ?? null) : (($counted[$key] ?? '') === '' ? null : (float) $counted[$key]),
        ];
        $row['diff'] = $row['counted'] === null ? null : round($row['counted'] - $row['expected'], 2);
        // Cash is always counted; another method is listed when money moved through it or it was counted.
        $row['main'] = $key === 'cash' || $row['counted'] !== null || abs($row['opening']) + abs($row['in']) + abs($row['out']) >= 0.005;
        return $row;
    });
    $quiet = $rows->where('main', false);
    $cash = $rows->get('cash');
    $usedMethods = collect($cashiers)->flatMap(fn ($p) => array_keys($p['methods']))->unique();
    $diffText = fn ($d) => $d === null ? '—' : (abs($d) < 0.005 ? 'Balanced' : ($d > 0 ? 'Over ' : 'Short ').currency().' '.number_format(abs($d), 2));
    $diffTone = fn ($d) => $d === null ? 'bg-slate-100 text-slate-500' : (abs($d) < 0.005 ? 'bg-green-100 text-green-800' : ($d > 0 ? 'bg-sky-100 text-sky-800' : 'bg-red-100 text-red-800'));
    $step = 'flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-700 text-xs font-semibold text-white';
@endphp
<div class="clinic-ui ui-page space-y-5">
<x-ui.flash />

    <div class="ui-heading" data-tour="day-close-heading">
        <div>
            <h1>Day close</h1>
            <p class="ui-muted">{{ $day->format('l, d M Y') }}{{ $branch ? ' · '.$branch->name : '' }}
                @if($closed)<span class="{{ $chip }} ml-1 bg-green-100 text-green-800"><i class="fas fa-lock mr-1" aria-hidden="true"></i>Closed</span>
                @else<span class="{{ $chip }} ml-1 bg-amber-100 text-amber-800">Open</span>@endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <label for="close-date" class="sr-only">Day</label>
            <input id="close-date" type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="ui-input !w-44">
            @if(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']))
                <a href="{{ route('admin.cash-book') }}" wire:navigate class="ui-button ui-button-secondary"><i class="fas fa-book" aria-hidden="true"></i>Cash book</a>
            @endif
        </div>
    </div>

    @if($closed)
        <section class="ui-panel !border-green-300 !bg-green-50 px-5 py-4" aria-label="Day closed">
            <p class="text-sm font-semibold text-green-900">Closed by {{ $close->closer?->name ?? 'someone' }} at {{ $close->closed_at?->format('H:i') }}@if($close->notes) — “{{ $close->notes }}”@endif</p>
            @if($drift->isNotEmpty())
                <p class="mt-2 rounded-md bg-amber-100 px-3 py-2 text-sm text-amber-900" role="status">
                    <i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>
                    Money moved on this day after it was closed:
                    @foreach($drift as $key => $d){{ $methodName($key) }} {{ $d > 0 ? '+' : '−' }}{{ currency() }} {{ $money(abs($d)) }}@if(!$loop->last), @endif @endforeach.
                    Reopen and close the day again to count it.
                </p>
            @endif
            @if($isOwner)
                <form wire:submit="reopen" class="mt-3 flex flex-wrap items-end gap-2">
                    <div class="min-w-[16rem] flex-1">
                        <label for="reopen-reason" class="{{ $label }}">Reopen the day (owner)</label>
                        <input id="reopen-reason" type="text" wire:model="reopenReason" class="ui-input" placeholder="Why it needs reopening" maxlength="500">
                        @error('reopenReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="ui-button ui-button-secondary"><i class="fas fa-lock-open" aria-hidden="true"></i>Reopen</button>
                </form>
            @endif
        </section>
    @elseif($close)
        <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">Reopened by {{ $close->reopener?->name ?? 'the owner' }} on {{ $close->reopened_at?->format('d M Y H:i') }}: {{ $close->reopen_reason }}</p>
    @endif

    @if($hasBlockers)
        <section class="ui-panel !border-red-300 !bg-red-50 px-5 py-4" aria-label="Waiting for a decision" data-tour="day-close-blockers">
            <h2 class="text-sm font-semibold text-red-900"><i class="fas fa-hand-paper mr-1" aria-hidden="true"></i>Decide these before closing</h2>
            <ul class="mt-2 space-y-1 text-sm text-red-900">
                @foreach($blockers['voids'] as $void)
                    <li>Void of sale <span class="font-mono">{{ $void->sale?->transaction_id }}</span> is {{ $void->status === 'approved' ? 'approved but not yet processed' : 'waiting for the owner' }}.
                        <a href="{{ route('admin.refund-approvals') }}" class="font-semibold underline">Open voids & refunds</a></li>
                @endforeach
                @if($blockers['corrections']->isNotEmpty())
                    <li>{{ $blockers['corrections']->count() }} payment {{ \Illuminate\Support\Str::plural('correction', $blockers['corrections']->count()) }} waiting for the owner — see <a href="#corrections" class="font-semibold underline">Payment corrections</a> below.</li>
                @endif
            </ul>
        </section>
    @endif

    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        {{-- 1. The count --}}
        <section class="ui-panel" aria-labelledby="count-title" data-tour="day-close-count" x-data="{ more: false }">
            <div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4">
                <span class="{{ $step }}">1</span>
                <div>
                    <h2 id="count-title" class="text-base font-semibold text-slate-900">{{ $closed ? 'What was counted' : 'Count the till' }}</h2>
                    <p class="text-xs text-slate-500">Expected is the start of the day, plus money in, less money out. Count the cash drawer; check the MoMo wallet or card statement too if you can.</p>
                </div>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Method</th><th class="ui-number">Expected</th><th class="ui-number">Counted</th><th class="text-right">Difference</th></tr></thead>
                    <tbody>
                        @foreach($rows as $key => $row)
                            <tr wire:key="method-{{ $key }}" @unless($row['main']) x-show="more" x-cloak @endunless class="[&>td]:!align-middle">
                                <td class="font-semibold">{{ $row['label'] }}</td>
                                <td class="ui-number">
                                    <span class="block font-semibold">{{ $money($row['expected']) }}</span>
                                    <span class="block text-xs font-normal text-slate-500">{{ $money($row['opening']) }} <span class="text-green-700">+ {{ $money($row['in']) }}</span> <span class="text-red-700">− {{ $money($row['out']) }}</span></span>
                                </td>
                                <td class="ui-number">
                                    @if($closed)
                                        {{ $row['counted'] === null ? '—' : $money($row['counted']) }}
                                    @else
                                        <label for="count-{{ $key }}" class="sr-only">Counted {{ $row['label'] }}</label>
                                        <input id="count-{{ $key }}" type="number" step="0.01" min="0" inputmode="decimal" wire:model.live.debounce.400ms="counted.{{ $key }}"
                                               class="ui-input !h-9 !w-24 sm:!w-32 text-right tabular-nums {{ $key === 'cash' ? 'border-teal-400' : '' }}" placeholder="{{ $key === 'cash' ? 'Count cash' : 'Optional' }}">
                                    @endif
                                </td>
                                <td class="text-right"><span class="{{ $chip }} {{ $diffTone($row['diff']) }}">{{ $diffText($row['diff']) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($quiet->isNotEmpty())
                <div class="border-t border-slate-200 px-5 py-2 text-sm">
                    <button type="button" x-on:click="more = ! more" class="font-semibold text-teal-700 hover:underline">
                        <span x-show="! more">Show {{ $quiet->count() }} other {{ \Illuminate\Support\Str::plural('method', $quiet->count()) }} with no money on this day</span>
                        <span x-show="more" x-cloak>Hide methods with no money</span>
                    </button>
                </div>
            @endif
        </section>

        {{-- The drawer at a glance, and closing. --}}
        <aside class="space-y-5">
            <section class="ui-panel" aria-labelledby="drawer-title" data-tour="day-close-submit">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 id="drawer-title" class="text-base font-semibold text-slate-900"><i class="fas fa-cash-register mr-2 text-teal-700" aria-hidden="true"></i>Cash drawer</h2>
                </div>
                <dl class="divide-y divide-slate-100 px-5 text-sm">
                    <div class="flex items-baseline justify-between py-2"><dt class="text-slate-500">Expected</dt><dd class="text-lg font-semibold tabular-nums">{{ currency() }} {{ $money($cash['expected'] ?? 0) }}</dd></div>
                    <div class="flex items-baseline justify-between py-2"><dt class="text-slate-500">Counted</dt><dd class="font-semibold tabular-nums">{{ ($cash['counted'] ?? null) === null ? '—' : currency().' '.$money($cash['counted']) }}</dd></div>
                    <div class="flex items-baseline justify-between py-2"><dt class="text-slate-500">Difference</dt><dd><span class="{{ $chip }} {{ $diffTone($cash['diff'] ?? null) }}">{{ $diffText($cash['diff'] ?? null) }}</span></dd></div>
                    @if($closed)
                        <div class="flex items-baseline justify-between py-2"><dt class="text-slate-500">Banked / handed over</dt><dd class="font-semibold tabular-nums">{{ currency() }} {{ $money($close->banked) }}</dd></div>
                    @else
                        <div class="flex items-baseline justify-between py-2"><dt class="text-slate-500">Float kept in drawer</dt><dd class="tabular-nums">{{ currency() }} {{ $money($float) }}</dd></div>
                    @endif
                </dl>
                @unless($closed)
                    <form wire:submit="close" class="space-y-3 border-t border-slate-200 px-5 py-4">
                        <div>
                            <label for="banked" class="{{ $label }}">Cash banked / handed over now</label>
                            <input id="banked" type="number" step="0.01" min="0" inputmode="decimal" wire:model="banked" class="ui-input" placeholder="{{ $suggestedBank !== null ? $money($suggestedBank) : '0.00' }}">
                            <p class="mt-1 text-xs text-slate-500">
                                @if($suggestedBank !== null)
                                    Suggested {{ currency() }} {{ $money($suggestedBank) }}, leaving the float.
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
                        @error('close')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        @error('counted.*')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        <button type="submit" class="ui-button ui-button-primary w-full justify-center" @disabled($hasBlockers)><i class="fas fa-lock" aria-hidden="true"></i>Close the day</button>
                        @if($hasBlockers)<p class="text-xs text-red-700">Decide the waiting requests first.</p>@endif
                    </form>
                @endunless
            </section>

            <section class="ui-panel" aria-labelledby="recent-title">
                <div class="border-b border-slate-200 px-5 py-3"><h2 id="recent-title" class="text-sm font-semibold text-slate-900">Recent days</h2></div>
                @forelse($recent as $row)
                    <button type="button" wire:click="$set('date', '{{ $row->business_date->toDateString() }}')"
                            class="flex w-full items-center justify-between gap-2 border-b border-slate-100 px-5 py-2 text-left text-sm last:border-0 hover:bg-slate-50 {{ $row->business_date->isSameDay($day) ? 'bg-teal-50' : '' }}">
                        <span class="font-semibold text-slate-700">{{ $row->business_date->format('D d M') }}</span>
                        <span class="flex items-center gap-2">
                            <span class="tabular-nums {{ abs((float) $row->variance) < 0.005 ? 'text-green-700' : 'text-red-700' }}">{{ abs((float) $row->variance) < 0.005 ? 'Balanced' : $money($row->variance) }}</span>
                            @if($row->isClosed())<i class="fas fa-lock text-xs text-green-700" title="Closed" aria-label="Closed"></i>@else<i class="fas fa-lock-open text-xs text-amber-600" title="Reopened" aria-label="Reopened"></i>@endif
                        </span>
                    </button>
                @empty
                    <p class="px-5 py-3 text-sm text-slate-500">No days closed yet.</p>
                @endforelse
            </section>
        </aside>
    </div>

    {{-- 2. Who took what --}}
    <section class="ui-panel" aria-labelledby="cashiers-title" data-tour="day-close-cashiers">
        <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4">
            <span class="{{ $step }}">2</span>
            <h2 id="cashiers-title" class="text-base font-semibold text-slate-900">Who took what</h2>
        </div>
        @if(count($cashiers))
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Cashier</th>@foreach($usedMethods as $key)<th class="ui-number">{{ $methodName($key) }}</th>@endforeach<th class="ui-number">Refunded</th><th class="ui-number">Net</th></tr></thead>
                    <tbody>
                        @foreach($cashiers as $person)
                            <tr>
                                <td class="font-semibold">{{ $person['name'] }}</td>
                                @foreach($usedMethods as $key)<td class="ui-number">{{ isset($person['methods'][$key]) ? $money($person['methods'][$key]) : '—' }}</td>@endforeach
                                <td class="ui-number {{ $person['refunded'] > 0 ? 'text-red-700' : 'text-slate-400' }}">{{ $person['refunded'] > 0 ? $money($person['refunded']) : '—' }}</td>
                                <td class="ui-number font-semibold">{{ $money($person['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="px-5 py-4 text-sm text-slate-500">No money taken on this day.</p>
        @endif
    </section>

    {{-- 3. Payments, and putting a wrong method right --}}
    <section class="ui-panel" aria-labelledby="payments-title" data-tour="day-close-payments">
        <div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4">
            <span class="{{ $step }}">3</span>
            <div>
                <h2 id="payments-title" class="text-base font-semibold text-slate-900">Payments taken <span class="font-normal text-slate-500">({{ $payments->count() }})</span></h2>
                <p class="text-xs text-slate-500">Recorded under the wrong method? Use <b>Wrong method?</b>; the owner approves it. To cancel a sale entered by mistake, request a void from Sales Records.</p>
            </div>
        </div>
        @if($payments->isNotEmpty())
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Time</th><th>Sale</th><th>Customer</th><th>Taken by</th><th>Method</th><th class="ui-number">Amount</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach($payments as $payment)
                            <tr wire:key="payment-{{ $payment->id }}">
                                <td class="tabular-nums">{{ $payment->created_at->format('H:i') }}</td>
                                <td class="font-mono text-xs">{{ $payment->sale?->transaction_id }}</td>
                                <td>{{ $payment->sale?->patient?->name ?? $payment->sale?->customer_name }}</td>
                                <td class="text-xs text-slate-500">{{ $payment->collectedBy?->name }}</td>
                                <td>{{ \App\Support\PaymentMethods::label($payment->payment_method) }}</td>
                                <td class="ui-number font-semibold">{{ $money($payment->amount) }}</td>
                                <td class="text-right">
                                    @if($pendingByPayment->has($payment->id))
                                        <span class="{{ $chip }} bg-amber-100 text-amber-800">Correction pending</span>
                                    @elseif(!$closed)
                                        <button type="button" wire:click="startCorrection({{ $payment->id }})" class="ui-button ui-button-secondary !py-1 text-xs">Wrong method?</button>
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
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="px-5 py-4 text-sm text-slate-500">No payments on this day.</p>
        @endif
    </section>

    @if($corrections->isNotEmpty())
        <section id="corrections" class="ui-panel" aria-labelledby="corrections-title" data-tour="day-close-corrections">
            <div class="border-b border-slate-200 px-5 py-4"><h2 id="corrections-title" class="text-base font-semibold text-slate-900">Payment corrections</h2></div>
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
</div>
