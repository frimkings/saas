{{-- Drug names from the previous software matched to the clinic's products (App\Livewire\Admin\LegacyMedicationNamesComponent). --}}
<div class="clinic-ui ui-page space-y-4">
    <style>
        .lm-filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:12px 16px;border-bottom:1px solid var(--clinic-line)}
        .lm-filters .ui-field{margin:0}.lm-filters .lm-search{flex:1;min-width:220px}
        .lm-pick{min-width:240px;max-width:340px}
    </style>

    <header class="ui-heading">
        <div>
            <p class="ui-muted">Clinic workspace / Inventory / Products</p>
            <h1>Previous software drug names</h1>
            <p class="ui-muted">Drugs prescribed in the clinic's previous software often have other names than your products. Match each name once and "Add again" in the Prescription tab works for every patient who was prescribed it. A product with exactly the same name is used without a match.</p>
        </div>
        <div class="ui-actions"><x-ui.button :href="route('admin.product')">Back to Products</x-ui.button></div>
    </header>

    <section class="ui-tiles" aria-label="Summary">
        <x-ui.stat-tile label="Drug names" :value="number_format($totalNames)" hint="As written in the previous software" />
        <x-ui.stat-tile label="Without a product" :value="number_format($unmatchedCount)" :tone="$unmatchedCount ? 'warning' : 'good'" :hint="$unmatchedCount ? 'Marked Not in product list in the Prescription tab' : 'Every name has a product'" />
    </section>

    <x-ui.panel style="padding:0">
        <h2 class="sr-only">Drug names</h2>
        <div class="lm-filters">
            <div class="lm-search"><x-ui.field label="Search" name="search" wire:model.live.debounce.300ms="search" placeholder="Drug name" /></div>
            <label class="inline-flex items-center gap-2 pb-2 text-sm"><input type="checkbox" wire:model.live="unmatchedOnly"> Only names without a product</label>
            <span class="ui-muted text-xs" wire:loading role="status">Updating…</span>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <caption class="sr-only">Drug names from the previous software and the product each one stands for.</caption>
                <thead><tr><th scope="col">Name in the previous software</th><th scope="col" class="ui-number">Prescribed</th><th scope="col">Last</th><th scope="col">Your product</th></tr></thead>
                <tbody wire:loading.class="opacity-50">
                @forelse($names as $row)
                    @php($productId = $resolved[$row->name_key] ?? null)
                    @php($isManual = isset($manual[$row->name_key]))
                    <tr wire:key="lm-{{ md5($row->name_key) }}">
                        <td class="font-semibold">{{ $row->item }}</td>
                        <td class="ui-number whitespace-nowrap">{{ number_format($row->times) }} <span class="block text-xs text-slate-500">{{ number_format($row->patients) }} {{ Str::plural('patient', $row->patients) }}</span></td>
                        <td class="whitespace-nowrap text-slate-600">{{ $row->last_on ? \Illuminate\Support\Carbon::parse($row->last_on)->format('d M Y') : '—' }}</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-2">
                                <select class="ui-input lm-pick" aria-label="Product for {{ $row->item }}" wire:change="match(@js($row->name_key), $event.target.value)">
                                    <option value="">— Not matched —</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" @selected($productId === $product->id)>{{ $product->name }}{{ $product->quantity > 0 ? '' : ' (out of stock)' }}</option>
                                    @endforeach
                                </select>
                                @if($productId && ! $isManual)<span class="text-xs text-slate-500">Same name</span>@endif
                                @if($isManual)<button type="button" class="text-xs text-slate-500 underline" wire:click="clear(@js($row->name_key))">Remove match</button>@endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="ui-empty">
                        @if($unmatchedOnly && trim($search) === '')
                            <h2>Every drug name has a product</h2><p class="ui-muted">Untick "Only names without a product" to see or change the matches.</p>
                        @else
                            <h2>No drug names found</h2><p class="ui-muted">Try another search.</p>
                        @endif
                    </div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($names->hasPages())
            <div class="ui-panel-heading" style="padding:10px 16px">
                <p class="ui-muted">{{ number_format($names->total()) }} {{ Str::plural('name', $names->total()) }}</p>
                <div class="ui-actions">
                    <x-ui.button wire:click="previousPage" :disabled="$names->onFirstPage()" aria-label="Previous page">Previous</x-ui.button>
                    <span class="ui-muted">Page {{ $names->currentPage() }} of {{ $names->lastPage() }}</span>
                    <x-ui.button wire:click="nextPage" :disabled="!$names->hasMorePages()" aria-label="Next page">Next</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.panel>
</div>
