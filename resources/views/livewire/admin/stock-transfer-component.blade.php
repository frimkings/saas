@php
    $tone = fn ($status) => match ($status) {
        'requested' => 'bg-amber-100 text-amber-800', 'draft' => 'bg-slate-100 text-slate-700', 'in_transit' => 'bg-sky-100 text-sky-800',
        'received' => 'bg-green-100 text-green-800', default => 'bg-red-50 text-red-700',
    };
    $itemsList = function ($transfer) {
        return $transfer->items->map(function ($item) {
            $line = e($item->product?->name).' × '.$item->quantity;
            if ($item->received_quantity !== null && $item->received_quantity < $item->quantity) {
                $line .= ' <span class="font-semibold text-red-700">(received '.$item->received_quantity.', short '.($item->quantity - $item->received_quantity).')</span>';
            }
            return $line;
        })->implode('<br>');
    };
@endphp
<div class="clinic-ui ui-page space-y-4">
    <x-ui.page-header title="Stock Transfers" description="Send stock to another branch, or ask one for it. Stock leaves when it is sent and arrives when the other branch receives it." />

    @if($branches->isEmpty())
        {{-- One branch: nothing to send stock to yet. --}}
        <div class="ui-panel p-5 text-sm text-slate-600">Transfers move stock between your branches. Add a second branch under <a href="{{ route('admin.branches') }}" class="font-semibold text-teal-700 underline">Branches</a> to send stock to it.</div>
    @else
        <section class="ui-panel bg-white p-4" data-tour="transfer-new">
            <div class="mb-3 inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm font-semibold" role="group" aria-label="Send or ask">
                <button type="button" wire:click="setMode('send')" aria-pressed="{{ $mode === 'send' ? 'true' : 'false' }}" class="px-3 py-2 {{ $mode === 'send' ? 'bg-teal-700 text-white' : 'bg-white text-slate-700' }}">Send stock</button>
                <button type="button" wire:click="setMode('request')" aria-pressed="{{ $mode === 'request' ? 'true' : 'false' }}" class="border-l border-slate-300 px-3 py-2 {{ $mode === 'request' ? 'bg-teal-700 text-white' : 'bg-white text-slate-700' }}">Ask another branch</button>
            </div>
            <form wire:submit="createTransfer" class="space-y-3">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="text-sm"><span class="block font-semibold">{{ $mode === 'request' ? 'Ask' : 'Send to' }}</span>
                        <select wire:model="otherBranchId" class="ui-input !w-56" aria-label="{{ $mode === 'request' ? 'Branch to ask' : 'Destination branch' }}"><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                    <label class="flex-1 text-sm"><span class="block font-semibold">Notes</span><input wire:model="notes" class="ui-input" placeholder="{{ $mode === 'request' ? 'e.g. running out of Timolol' : 'Optional' }}"></label>
                </div>
                @error('otherBranchId')<p class="ui-error !mb-0" role="alert">{{ $message }}</p>@enderror
                <div class="space-y-2">
                    @foreach($lines as $index => $line)
                        <div class="flex flex-wrap items-center gap-2" wire:key="transfer-line-{{ $index }}">
                            <select wire:model="lines.{{ $index }}.product_id" class="ui-input !w-80" aria-label="Product {{ $index + 1 }}"><option value="">Select product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select>
                            <input type="number" min="1" wire:model="lines.{{ $index }}.quantity" class="ui-input !w-24" aria-label="Quantity {{ $index + 1 }}">
                            @if(count($lines) > 1)<button type="button" class="ui-button ui-button-secondary !min-h-9" wire:click="removeLine({{ $index }})" aria-label="Remove line {{ $index + 1 }}">&times;</button>@endif
                        </div>
                    @endforeach
                </div>
                @error('lines')<p class="ui-error !mb-0" role="alert">{{ $message }}</p>@enderror
                @error('lines.*.quantity')<p class="ui-error !mb-0" role="alert">Every quantity must be 1 or more.</p>@enderror
                <div class="flex gap-2">
                    <button type="button" class="ui-button ui-button-secondary" wire:click="addLine">+ Another product</button>
                    <button type="submit" class="ui-button ui-button-primary">{{ $mode === 'request' ? 'Send request' : 'Create draft' }}</button>
                </div>
            </form>
        </section>
    @endif

    @if($askedOfUs->isNotEmpty())
        <section class="ui-panel border-amber-300 bg-amber-50/40" data-tour="transfer-asked">
            <div class="ui-section-heading"><strong>Asked of this branch</strong></div>
            <div class="ui-table-wrap"><table class="ui-table ui-table-sm mb-0">
                <thead><tr><th>Reference</th><th>For</th><th>Items</th><th>Notes</th><th></th></tr></thead>
                <tbody>
                    @foreach($askedOfUs as $transfer)
                        <tr wire:key="asked-{{ $transfer->id }}">
                            <td>{{ $transfer->transfer_number }}<span class="block text-xs text-slate-500">{{ $transfer->created_at->format('d M Y') }}</span></td>
                            <td>{{ $transfer->destinationBranch?->name }}</td>
                            <td>{!! $itemsList($transfer) !!}</td>
                            <td class="text-xs">{{ $transfer->notes }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if($declineId === $transfer->id)
                                    <div class="flex items-center gap-1"><input wire:model="declineReason" class="ui-input !w-56 text-sm" placeholder="Why not?" aria-label="Why you decline"><button type="button" class="ui-button ui-button-danger !min-h-8" wire:click="declineTransfer">Decline</button></div>
                                    @error('declineReason')<p class="ui-error !mb-0 text-xs" role="alert">{{ $message }}</p>@enderror
                                @else
                                    <button type="button" class="ui-button ui-button-primary !min-h-8" wire:click="dispatchTransfer({{ $transfer->id }})" wire:confirm="Send this stock to {{ $transfer->destinationBranch?->name }}? It leaves this branch's stock now.">Send it</button>
                                    <button type="button" class="ui-button ui-button-secondary !min-h-8" wire:click="startDecline({{ $transfer->id }})">Decline…</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="ui-panel" data-tour="transfer-outgoing"><div class="ui-section-heading"><strong>Going out</strong></div><div class="ui-table-wrap"><table class="ui-table ui-table-sm mb-0">
            <thead><tr><th>Reference</th><th>To</th><th>Items</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($outgoing as $transfer)
                    <tr wire:key="out-{{ $transfer->id }}">
                        <td>{{ $transfer->transfer_number }}</td><td>{{ $transfer->destinationBranch?->name }}</td><td>{!! $itemsList($transfer) !!}</td>
                        <td><span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $tone($transfer->status) }}">{{ $statuses[$transfer->status] ?? $transfer->status }}</span></td>
                        <td class="whitespace-nowrap text-right">@if($transfer->status === 'draft')<button wire:click="dispatchTransfer({{ $transfer->id }})" class="ui-button ui-button-sm ui-button-secondary">Dispatch</button> <button wire:click="cancelTransfer({{ $transfer->id }})" wire:confirm="Cancel this draft? Nothing has moved." class="ui-button ui-button-sm ui-button-secondary">Cancel</button>@endif</td>
                    </tr>
                @empty<tr><td colspan="5" class="text-center text-slate-500">No outgoing transfers.</td></tr>@endforelse
            </tbody>
        </table></div></section>

        <section class="ui-panel" data-tour="transfer-incoming"><div class="ui-section-heading"><strong>Coming in</strong></div><div class="ui-table-wrap"><table class="ui-table ui-table-sm mb-0">
            <thead><tr><th>Reference</th><th>From</th><th>Items</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($incoming as $transfer)
                    <tr wire:key="in-{{ $transfer->id }}">
                        <td>{{ $transfer->transfer_number }}</td><td>{{ $transfer->sourceBranch?->name }}</td>
                        <td>
                            @if($receiveId === $transfer->id)
                                @foreach($transfer->items as $item)
                                    <label class="flex items-center gap-2 text-sm">{{ $item->product?->name }} (sent {{ $item->quantity }}): <input type="number" min="0" max="{{ $item->quantity }}" wire:model="received.{{ $item->id }}" class="ui-input !w-20 text-sm" aria-label="Received of {{ $item->product?->name }}"></label>
                                @endforeach
                                @error('received')<p class="ui-error !mb-0 text-xs" role="alert">{{ $message }}</p>@enderror
                            @else
                                {!! $itemsList($transfer) !!}
                            @endif
                            @if($transfer->status === 'declined' && $transfer->response_note)<span class="block text-xs text-red-700">Declined: {{ $transfer->response_note }}</span>@endif
                        </td>
                        <td><span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $tone($transfer->status) }}">{{ $statuses[$transfer->status] ?? $transfer->status }}</span></td>
                        <td class="whitespace-nowrap text-right">
                            @if($transfer->status === 'in_transit')
                                @if($receiveId === $transfer->id)<button wire:click="receiveTransfer" class="ui-button ui-button-sm ui-button-primary">Confirm received</button>
                                @else<button wire:click="startReceive({{ $transfer->id }})" class="ui-button ui-button-sm ui-button-primary">Receive…</button>@endif
                            @elseif($transfer->status === 'requested')
                                <button wire:click="cancelTransfer({{ $transfer->id }})" wire:confirm="Withdraw this request?" class="ui-button ui-button-sm ui-button-secondary">Withdraw</button>
                            @endif
                        </td>
                    </tr>
                @empty<tr><td colspan="5" class="text-center text-slate-500">No incoming transfers.</td></tr>@endforelse
            </tbody>
        </table></div></section>
    </div>
</div>
