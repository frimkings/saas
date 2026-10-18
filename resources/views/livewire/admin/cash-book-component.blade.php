@php
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<div class="clinic-ui ui-page space-y-5">
<x-ui.flash />

    <div class="ui-heading" data-tour="cash-book-heading">
        <div>
            <h1>Cash book</h1>
            <p class="ui-muted">Money in and out of each till and account{{ $branch ? ' at '.$branch->name : '' }}, with a running balance.</p>
        </div>
        <a href="{{ route('admin.day-close') }}" class="ui-button ui-button-primary" wire:navigate><i class="fas fa-cash-register mr-1" aria-hidden="true"></i>Close the day</a>
    </div>

    @if(!$started)
        <section class="ui-panel border-amber-300 bg-amber-50 p-5" aria-label="Start the cash book" data-tour="cash-book-start">
            <h2 class="text-base font-semibold text-amber-900">Start the cash book</h2>
            <p class="mt-1 text-sm text-amber-900">Count what is in the cash drawer (and, if you like, the MoMo wallet and other accounts) and enter it here. Balances are worked out from that day on; until then each period starts from zero.</p>
            @if($isOwner)
                <form wire:submit="startBook" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="start-date" class="{{ $label }}">Start date</label>
                        <input id="start-date" type="date" wire:model="startDate" max="{{ today()->toDateString() }}" class="ui-input">
                        @error('startDate')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    @foreach($methods as $key => $name)
                        <div>
                            <label for="opening-{{ $key }}" class="{{ $label }}">{{ $name }}{{ $key === 'cash' ? ' (required)' : '' }}</label>
                            <input id="opening-{{ $key }}" type="number" step="0.01" min="0" wire:model="openingCounts.{{ $key }}" class="ui-input" placeholder="{{ $key === 'cash' ? '0.00' : 'Leave blank to skip' }}">
                            @error('openingCounts.'.$key)<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div class="flex items-end"><button type="submit" class="ui-button ui-button-primary w-full">Start the book</button></div>
                </form>
            @else
                <p class="mt-2 text-sm font-semibold text-amber-900">Ask the owner to enter the opening count.</p>
            @endif
        </section>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-tour="cash-book-balances">
        @foreach($methods as $key => $name)
            <button type="button" wire:click="selectMethod('{{ $key }}')" aria-pressed="{{ $method === $key ? 'true' : 'false' }}"
                    class="ui-panel px-4 py-3 text-left transition {{ $method === $key ? 'ring-2 ring-teal-600' : 'hover:border-teal-300' }}">
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $name }}</span>
                <span class="block text-xl font-semibold tabular-nums {{ ($balances[$key] ?? 0) < 0 ? 'text-red-700' : 'text-slate-900' }}">{{ currency() }} {{ $money($balances[$key] ?? 0) }}</span>
                <span class="text-xs text-slate-500">balance now{{ $key === 'cash' && $branch && (float) $branch->till_float > 0 ? ' · float '.currency().' '.$money($branch->till_float) : '' }}</span>
            </button>
        @endforeach
    </div>

    <section class="ui-panel" aria-label="{{ $methods[$method] ?? $method }} book">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">{{ $methods[$method] ?? $method }}</h2>
                <p class="text-xs text-slate-500">Opening {{ currency() }} {{ $money($opening) }} · in {{ currency() }} {{ $money($totalIn) }} · out {{ currency() }} {{ $money($totalOut) }} · closing <b class="text-slate-800">{{ currency() }} {{ $money($closing) }}</b></p>
            </div>
            <div>
                <span class="{{ $label }}">Period</span>
                <x-date-range from="fromDate" to="toDate" presets="finance" />
            </div>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Date</th><th>What</th><th>Ref</th><th>By</th><th class="ui-number">In</th><th class="ui-number">Out</th><th class="ui-number">Balance</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @foreach($entries as $entry)
                        <tr class="{{ $entry['kind'] === 'opening' ? 'bg-slate-50 italic' : '' }} {{ $entry['cancelled'] ? 'text-slate-400 line-through' : '' }}">
                            <td class="whitespace-nowrap">{{ $entry['date']->format('d M Y') }}@if(in_array($entry['kind'], ['payment', 'refund'], true))<span class="block text-xs text-slate-500">{{ $entry['date']->format('H:i') }}</span>@endif</td>
                            <td>{{ $entry['label'] }}</td>
                            <td class="font-mono text-xs">{{ $entry['reference'] }}</td>
                            <td class="text-xs text-slate-500">{{ $entry['by'] }}</td>
                            <td class="ui-number text-green-700">{{ $entry['in'] > 0 ? $money($entry['in']) : '' }}</td>
                            <td class="ui-number text-red-700">{{ $entry['out'] > 0 ? $money($entry['out']) : '' }}</td>
                            <td class="ui-number font-semibold">{{ $money($entry['balance']) }}</td>
                            <td class="text-right">
                                @if($entry['movement_id'] && !$entry['cancelled'])
                                    <button type="button" wire:click="askCancel({{ $entry['movement_id'] }})" class="ui-button ui-button-secondary" title="Cancel this entry" aria-label="Cancel this entry"><i class="fas fa-ban" aria-hidden="true"></i></button>
                                @endif
                            </td>
                        </tr>
                        @if($cancellingId && $entry['movement_id'] === $cancellingId)
                            <tr><td colspan="8" class="bg-amber-50">
                                <form wire:submit="cancelMovement" class="flex flex-wrap items-end gap-2">
                                    <div class="min-w-[16rem] flex-1">
                                        <label for="cancel-reason" class="{{ $label }}">Why is this entry wrong?</label>
                                        <input id="cancel-reason" type="text" wire:model="cancelReason" class="ui-input" maxlength="500">
                                        @error('cancelReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <button type="submit" class="ui-button ui-button-danger">Cancel entry</button>
                                    <button type="button" wire:click="$set('cancellingId', null)" class="ui-button ui-button-secondary">Keep it</button>
                                </form>
                            </td></tr>
                        @endif
                    @endforeach
                    @if($entries->count() === 1)
                        <tr><td colspan="8" class="ui-empty italic text-slate-500">Nothing moved through {{ $methods[$method] ?? $method }} in this period.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-[2fr_1fr]">
        <section class="ui-panel p-5" aria-label="Record money moved" data-tour="cash-book-record">
            <h2 class="text-base font-semibold text-slate-900">Record money moved by hand</h2>
            <p class="mt-1 text-xs text-slate-500">Payments, refunds and expenses come onto the book by themselves. Record here money banked, handed to the owner, withdrawn from the wallet, or added to the drawer.</p>
            <form wire:submit="recordMovement" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="move-type" class="{{ $label }}">What happened</label>
                    <select id="move-type" wire:model="moveType" class="ui-input">
                        <option value="{{ \App\Models\CashMovement::BANKED }}">Banked / handed over (out)</option>
                        <option value="{{ \App\Models\CashMovement::ADDED }}">Money added (in)</option>
                    </select>
                </div>
                <div>
                    <label for="move-amount" class="{{ $label }}">Amount ({{ $methods[$method] ?? $method }})</label>
                    <input id="move-amount" type="number" step="0.01" min="0.01" wire:model="moveAmount" class="ui-input">
                    @error('moveAmount')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="move-date" class="{{ $label }}">Date</label>
                    <input id="move-date" type="date" wire:model="moveDate" max="{{ today()->toDateString() }}" class="ui-input">
                    @error('moveDate')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="move-ref" class="{{ $label }}">Reference</label>
                    <input id="move-ref" type="text" wire:model="moveReference" class="ui-input" placeholder="Deposit slip, MoMo ID…" maxlength="255">
                </div>
                <div class="sm:col-span-2 lg:col-span-1">
                    <label for="move-notes" class="{{ $label }}">Notes</label>
                    <input id="move-notes" type="text" wire:model="moveNotes" class="ui-input" maxlength="500">
                </div>
                <div class="flex items-end"><button type="submit" class="ui-button ui-button-primary w-full">Record</button></div>
            </form>
        </section>

        <section class="ui-panel p-5" aria-label="Till float">
            <h2 class="text-base font-semibold text-slate-900">Till float</h2>
            <p class="mt-1 text-xs text-slate-500">The change kept in the cash drawer overnight. At day close, anything above it is suggested for banking. Set 0 to bank everything.</p>
            @if($isOwner)
                <form wire:submit="saveFloat" class="mt-3 flex items-end gap-2">
                    <div class="flex-1">
                        <label for="till-float" class="{{ $label }}">Float ({{ currency() }})</label>
                        <input id="till-float" type="number" step="0.01" min="0" wire:model="floatAmount" class="ui-input">
                        @error('floatAmount')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="ui-button ui-button-secondary">Save</button>
                </form>
            @else
                <p class="mt-3 text-lg font-semibold">{{ currency() }} {{ $money($branch?->till_float ?? 0) }}</p>
            @endif
        </section>
    </div>
</div>
