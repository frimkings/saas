{{-- Sits inside the consultation form: every button is type="button" and Enter is held back, so nothing here saves the consultation. --}}
<div class="ui-panel mb-4" data-tour="consultation-scans">
    <div class="ui-section-heading">
        <h6 class="mb-0 font-semibold"><i class="fas fa-eye text-teal-700"></i> Diagnostic Scans</h6>
        <button type="button" wire:click="toggle" class="ui-button ui-button-sm {{ $open ? 'ui-button-secondary' : 'ui-button-primary' }}">
            <i class="fas {{ $open ? 'fa-times' : 'fa-plus' }}"></i> {{ $open ? 'Close' : 'Order scans' }}
        </button>
    </div>
    <div class="p-5 space-y-3">
        @if($open)
            <div class="rounded-md border border-slate-200 bg-slate-50 p-3 space-y-2">
                @forelse($scans as $scan)
                    <div class="flex flex-wrap items-center gap-2" wire:key="cs-scan-{{ $scan->id }}">
                        <label class="flex flex-1 items-center gap-2 text-sm text-slate-800" style="min-width:12rem">
                            <input type="checkbox" wire:model.live="chosen.{{ $scan->id }}.on">
                            <span><b>{{ $scan->name }}</b>@if($scan->code) <span class="text-slate-400">{{ $scan->code }}</span>@endif
                                <span class="block text-xs text-slate-500">{{ currency() }} {{ number_format((float) $scan->price, 2) }}{{ $scan->per_eye ? ' per eye' : '' }}</span></span>
                        </label>
                        @if($scan->per_eye && !empty($chosen[$scan->id]['on']))
                            <select wire:model.live="chosen.{{ $scan->id }}.eye" class="ui-input ui-input-sm" style="max-width:150px" aria-label="Eye for {{ $scan->name }}">
                                @foreach($eyes as $key => $label)<option value="{{ $key }}" @selected(($chosen[$scan->id]['eye'] ?? 'OU') === $key)>{{ $label }}</option>@endforeach
                            </select>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500 mb-0">No scans are set up yet. A manager adds them under Front Desk → Diagnostic Scans → Catalogue.</p>
                @endforelse
                @if($scans->isNotEmpty())
                    <input type="text" wire:model="note" maxlength="500" class="ui-input ui-input-sm w-full" placeholder="Note for the scan room (optional), e.g. suspected glaucoma"
                        aria-label="Note for the scan room" x-on:keydown.enter.prevent>
                    @error('items')<div class="text-red-700 text-sm" role="alert">{{ $message }}</div>@enderror
                    @error('note')<div class="text-red-700 text-sm" role="alert">{{ $message }}</div>@enderror
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <small class="text-slate-500">Goes to the front desk to be paid; you earn the commission once it is paid in full.</small>
                        <button type="button" wire:click="order" wire:loading.attr="disabled" wire:target="order" class="ui-button ui-button-sm ui-button-primary">
                            <i class="fas fa-paper-plane"></i> Send to front desk</button>
                    </div>
                @endif
            </div>
        @endif

        @error('withdraw')<div class="text-red-700 text-sm" role="alert">{{ $message }}</div>@enderror
        @forelse($orders as $order)
            @php $state = $order->status === 'cancelled' ? 'cancelled' : $order->paymentState(); @endphp
            <div class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 pb-2 last:border-0 last:pb-0" wire:key="cs-order-{{ $order->id }}">
                <div class="text-sm">
                    <div class="font-semibold text-slate-900">{{ $order->items->map->label()->implode(', ') }}</div>
                    <div class="text-xs text-slate-500">{{ $order->order_number }} · {{ $order->created_at->format('d M Y') }} · {{ $order->referrerLabel() }}</div>
                </div>
                <div class="flex items-center gap-2">
                    @if($order->status === 'cancelled')
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-600">Withdrawn</span>
                    @elseif($state === 'unbilled')
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">Waiting to pay</span>
                        @if($order->source === 'internal' && ((int) $order->ordered_by === auth()->id() || \App\Support\Access\Access::can('records.delete')))
                            <button type="button" wire:click="withdraw({{ $order->id }})" wire:confirm="Withdraw scan order {{ $order->order_number }}?" class="ui-button ui-button-sm ui-button-link text-red-700">Withdraw</button>
                        @endif
                    @else
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $state === 'paid' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $state === 'paid' ? 'Paid' : 'Part paid' }}</span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800">{{ $statuses[$order->status] ?? $order->status }}</span>
                    @endif
                </div>
            </div>
        @empty
            @unless($open)<p class="text-sm text-slate-500 mb-0">No scans ordered for this patient in the last 90 days.</p>@endunless
        @endforelse
    </div>
</div>
