<section class="ui-panel p-4 space-y-3" aria-label="Lens import history">
    <h2 class="font-bold text-lg">Lens import history</h2>
    <p class="text-xs text-slate-600">Receipts recorded since import tracking was enabled. Quantities are individual lenses unless labelled pairs.</p>
    <input autocomplete="off" type="search" wire:model.live.debounce.300ms="importSearch" class="ui-input w-full" placeholder="Search worksheet, file, supplier or invoice" aria-label="Search imports">
    <div class="ui-table-wrap"><table class="ui-table w-full">
        <thead><tr><th>Receipt / invoice</th><th>File / worksheet</th><th>Supplier</th><th>Lenses</th><th>Status</th><th>Date / user</th><th>Actions</th></tr></thead>
        <tbody>@forelse($imports as $import)
            <tr wire:key="lens-import-{{ $import->id }}">
                <td>#{{ $import->id }}<div>{{ $import->reference }}</div></td>
                <td>{{ $import->worksheet }}<div class="text-xs text-slate-500">{{ $import->filename }}</div></td>
                <td>{{ $import->supplier }}</td><td>{{ number_format($import->pieces) }}</td>
                <td>{{ $import->active_lines === 0 ? 'Reversed' : ($import->active_lines < $import->receipt_lines ? 'Partially reversed' : 'Received') }}</td>
                <td>{{ $import->created_at->format('d M Y H:i') }}<div>{{ $import->user?->name }}</div></td>
                <td><button type="button" wire:click="viewImport({{ $import->id }})" class="underline">View receipt</button>
                    @hasanyrole('Manager|Super Admin')@if($import->active_lines > 0)
                        <button type="button" wire:click="reverseImport({{ $import->id }})" wire:confirm="Reverse all remaining stock receipts in this import? All lines must have enough stock. This records offsetting ledger entries." class="text-red-700 underline ml-2">Reverse import</button>
                    @endif@endhasanyrole
                </td>
            </tr>
        @empty<tr><td colspan="7">No tracked imports found.</td></tr>@endforelse</tbody>
    </table></div>
    {{ $imports->links() }}
    @if($importDetail)
        <div class="border-t pt-4 space-y-2" data-sheet>
            <div class="flex justify-between"><h3 class="font-bold">Import #{{ $importDetail->id }} — {{ $importDetail->worksheet }}</h3><button type="button" x-on:click="dismissCall($el, $wire, 'closeImport')" class="underline">Close details</button></div>
            <p>{{ $importDetail->supplier }} · Invoice: {{ $importDetail->reference ?: 'Not supplied' }} · Batch: {{ $importDetail->batch_number ?: 'Not supplied' }}</p>
            <p>{{ implode(' · ', $importDetail->specifications) }}</p>
            <p>Worksheet: {{ number_format($importDetail->source_quantity) }} {{ $importDetail->source_unit }}. Received: {{ number_format($importDetail->pieces) }} individual lenses.</p>
            <p>Receipt cost: GHS {{ number_format($importDetail->movements->sum(fn ($m) => $m->quantity_change * (float) $m->unit_cost), 2) }}</p>
            @if($importDetail->repeat_reason)<p>Repeat delivery reason: {{ $importDetail->repeat_reason }}</p>@endif
            <div class="ui-table-wrap"><table class="ui-table w-full"><thead><tr><th>Lens / SKU</th><th>Lenses</th><th>Cost / lens</th><th>Price / lens</th><th>Status</th></tr></thead><tbody>
            @foreach($importDetail->movements as $line)
                <tr><td>{{ $line->product?->name }}<div class="text-xs">{{ $line->product?->sku }}</div></td><td>{{ $line->quantity_change }}</td><td>GHS {{ $line->unit_cost }}</td><td>GHS {{ $line->unit_price }}</td><td>{{ $line->reversedBy ? 'Reversed' : 'Received' }}</td></tr>
            @endforeach
            </tbody></table></div>
        </div>
    @endif
</section>
