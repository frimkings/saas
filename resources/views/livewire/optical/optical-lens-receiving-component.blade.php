<div class="clinic-ui ui-page space-y-6"
    x-data="{ saved: false, dirty() { const grid = $wire.bulkQuantities || {}; return Object.values(grid).some(row => Object.values(row || {}).some(q => Number(q) > 0)) || !! $wire.excelFile || Number($wire.quantity) > 0 } }"
    x-init="window.addEventListener('beforeunload', e => { if (! saved && dirty()) { e.preventDefault(); e.returnValue = '' } })"
    x-on:lens-receipt-saved.window="saved = true">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="{{ route('optical.stock') }}" class="text-xs text-teal-800 underline">← Stock management</a>
            <h1 class="text-xl font-bold text-slate-900">Receive lenses</h1>
            <p class="ui-muted text-xs">Enter a supplier lens order by power grid or Excel order sheet. Stock is recorded only when you confirm the receipt.</p>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="ui-panel p-5">
            @include('livewire.optical.partials.shared-lens-receipt')
        </div>

        @error('importReceipt')<p class="ui-panel p-3 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
        @if($duplicateImport)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm" role="alert">
                <p>These worksheet quantities were already received as import #{{ $duplicateImport->id }} on {{ $duplicateImport->created_at->format('d M Y H:i') }} ({{ $duplicateImport->reference ?: 'no invoice reference' }}).</p>
                <a href="{{ route('optical.stock', ['viewImport' => $duplicateImport->id]) }}" target="_blank" rel="noopener" class="underline">View original receipt (opens in a new tab)</a>
                <p class="mt-2">For a separate delivery, enter a different invoice reference and explain why the quantities repeat.</p>
                <label class="block mt-2">Repeat delivery reason<textarea wire:model="repeatDeliveryReason" maxlength="1000" class="ui-input w-full" rows="2"></textarea></label>
                @error('repeatDeliveryReason')<p class="text-red-700">{{ $message }}</p>@enderror
            </div>
        @endif

        <div class="ui-panel p-5"><label class="block text-xs font-semibold mb-1">Notes</label><textarea wire:model="notes" rows="2" maxlength="2000" class="ui-input w-full"></textarea>@error('notes')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>

        @php $totals = $this->receiptTotals(); @endphp
        {{-- Worked out in the browser while typing (lensReceipt); the server recalculates on save. --}}
        <div class="lens-receipt-footer" x-data="lensReceipt(@js(\App\Support\LensDesign::EYE_SPECIFIC))">
            <div class="text-sm">
                <strong x-text="totals().pieces.toLocaleString('en-US') + ' lenses'">{{ number_format($totals['pieces']) }} lenses</strong> <span class="text-slate-500" x-show="totals().pieces && totals().pieces % 2 === 0" x-text="'(' + (totals().pieces / 2).toLocaleString('en-US') + ' pairs)'">@if($totals['pieces'] && $totals['pieces'] % 2 === 0)({{ number_format($totals['pieces'] / 2) }} pairs)@endif</span>
                · Total cost <strong>{{ currency() }} <span x-text="money(totals().cost)">{{ number_format($totals['cost'], 2) }}</span></strong>
                @if($errors->any())<span class="ml-2 text-red-700" role="alert">Fix the highlighted errors above before receiving.</span>@endif
            </div>
            <div class="flex gap-2">
                <a href="{{ route('optical.stock') }}" class="ui-button">Cancel</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="ui-button ui-button-primary">Confirm &amp; Receive Stock</button>
            </div>
        </div>
    </form>
</div>
