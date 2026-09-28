@php $fullPage = $fullPage ?? false; @endphp
{{-- Totals, row sums and the override list are worked out in the browser (lensReceipt, resources/js/live-totals.js); the server recalculates on save. --}}
<div class="space-y-4" x-data="lensReceipt(@js(\App\Support\LensDesign::EYE_SPECIFIC))">
    @if($fullPage)<h2 class="lens-receipt-step">1. Lens range</h2>@endif
    <p class="text-xs text-slate-600">Receive individual stock lenses supplied to optical shops. Choose a saved range or enter a new manufacturer / range name.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label class="text-xs">Manufacturer / lens range *<input autocomplete="off" list="lens-ranges" wire:model.live.blur="lensRange" maxlength="100" class="ui-input w-full" placeholder="Select or add a lens range"><datalist id="lens-ranges">@foreach($lensRanges as $range)<option value="{{ $range }}">@endforeach</datalist></label>
        <label class="text-xs">Design *<select wire:model.live="lensDesign" class="ui-input w-full"><option>Single Vision</option><option>Bifocal</option><option>Progressive</option></select></label>
        <label class="text-xs">Index *<select wire:model.live="lensIndex" class="ui-input w-full">@foreach(['1.50','1.56','1.60','1.61','1.67','1.74'] as $index)<option>{{ $index }}</option>@endforeach</select></label>
        <label class="text-xs">Treatment *<select wire:model.live="lensCoating" class="ui-input w-full"><option value="AR">Clear AR</option><option>Photo AR</option><option>Photo Gray</option><option value="Photochromic">Photochromic</option><option value="Blue AR">Blue AR</option><option value="BlueCut">Blue cut</option><option>Transitions</option><option value="HC">Hard coat</option></select></label>
        <label class="text-xs">Diameter (mm) *<input autocomplete="off" wire:model.live.blur="lensDiameter" type="number" min="40" max="100" class="ui-input w-full"></label>
        <label class="text-xs">Entry mode<select wire:model.live="entryMode" class="ui-input w-full"><option value="single">Single power</option><option value="bulk">{{ $fullPage ? 'Bulk grid / Excel import' : 'Bulk grid / Excel import (opens full page)' }}</option></select></label>
        @if(\App\Support\LensDesign::isEyeSpecific($lensDesign))
            <fieldset class="text-xs sm:col-span-2">
                <legend class="mb-1">Eye * <span class="text-slate-500">{{ strtolower($lensDesign) }} lenses are made for one eye. A pair is one right and one left lens.</span></legend>
                <div class="flex flex-wrap gap-2">
                    @foreach(['B' => 'Both eyes (pairs)'] + \App\Support\LensDesign::EYES as $value => $label)
                        <label class="flex items-center gap-2 rounded-lg border px-3 py-2 cursor-pointer {{ $lensEye === $value ? 'border-teal-600 bg-teal-50' : 'border-slate-200' }}"><input type="radio" wire:model.live="lensEye" value="{{ $value }}"> {{ $label }}</label>
                    @endforeach
                </div>
                @error('lensEye')<p class="mt-1 text-red-600">{{ $message }}</p>@enderror
            </fieldset>
        @endif
    </div>
    @if($entryMode === 'single')
    <details class="text-xs"><summary class="cursor-pointer text-teal-800">Link an existing SKU (optional)</summary>
    <p class="mt-2">Use this for lenses already recorded as products, to keep their existing balance and history.</p>
    <input autocomplete="off" type="search" wire:model.live.debounce.250ms="productSearch" class="ui-input w-full" placeholder="Search existing SKU">
    @if($productId)<p>Selected: {{ $selectedProduct?->name }}</p><button type="button" wire:click="$set('productId', null)" class="underline">Clear selection</button>@else
    @foreach($productMatches as $match)<button type="button" wire:click="selectProduct({{ $match->id }})" class="block p-2 text-teal-800">{{ $match->sku }} / {{ $match->name }}</button>@endforeach
    @endif</details>
    <div class="grid grid-cols-3 gap-3">
        <label class="text-xs">SPH *<input autocomplete="off" type="number" step="0.25" min="-15" max="15" wire:model.live.blur="lensSphere" class="ui-input w-full"></label>
        <label class="text-xs">{{ $lensDesign === 'Single Vision' ? 'CYL' : 'ADD (+)' }} *<input autocomplete="off" type="number" step="0.25" min="{{ $lensDesign === 'Single Vision' ? -6 : 0.25 }}" max="{{ $lensDesign === 'Single Vision' ? 0 : 4 }}" wire:model.live.blur="lensPower" class="ui-input w-full"></label>
        <label class="text-xs">{{ $lensEye === 'B' ? 'Pairs (R + L)' : 'Pieces' }} *<input autocomplete="off" type="number" min="1" step="1" wire:model="quantity" class="ui-input w-full"></label>
    </div>
    @else
        @if($fullPage)<h2 class="lens-receipt-step">2. Quantities</h2>@endif
        <section class="rounded-lg border border-teal-200 p-3 space-y-3" aria-label="Import lens order from Excel">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">Import Excel order sheet</h3>
                <button type="button" wire:click="downloadLensTemplate" class="ui-button text-xs font-normal flex items-center gap-1">📥 Download Lens Order Template</button>
            </div>
            <p class="text-xs text-slate-600">Upload .xlsx (up to 5 MB). Import one worksheet per lens range. Positive and negative sections are read together. Use SPH rows and {{ $lensDesign === 'Single Vision' ? 'CYL' : 'ADD' }} columns. Blank quantities are ignored; totals are excluded. Enter prices below after importing.</p>
            <input type="file" wire:model="excelFile" accept=".xlsx" class="text-xs w-full" aria-label="Excel workbook">
            <span wire:loading wire:target="excelFile,previewExcel,applyExcel" class="text-xs">Reading workbook...</span>
            @if($excelSheets)
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <label class="text-xs">Layout<select wire:model.live="excelLayout" class="ui-input w-full"><option value="template">Lens order grid (auto-detect)</option><option value="manual">Other single-grid sheet</option></select></label>
                    <label class="text-xs">Workbook quantities *<select wire:model.live="excelUnit" class="ui-input w-full"><option value="">Choose pairs or pieces</option><option value="pairs">Pairs (2 lenses each)</option><option value="pieces">Individual pieces</option></select></label>
                    <label class="text-xs">Worksheet<select wire:model.live="excelSheet" class="ui-input w-full">@foreach($excelSheets as $i=>$name)<option value="{{ $i }}">{{ $name }}</option>@endforeach</select></label>
                    @if($excelLayout === 'manual')
                    <label class="text-xs">Power heading row<input autocomplete="off" type="number" min="1" wire:model.live="excelHeaderRow" class="ui-input w-full"></label>
                    <label class="text-xs">SPH column (A=1, B=2)<input autocomplete="off" type="number" min="1" wire:model.live="excelSphereColumn" class="ui-input w-full"></label>
                    @endif
                </div>
                <button type="button" wire:click="previewExcel" wire:loading.attr="disabled" class="ui-button">Preview import</button>
            @endif
            @if($excelFile)<button type="button" wire:click="cancelExcel" class="ui-button">Cancel import</button>@endif
            @error('excelFile')<p class="rounded border border-red-300 bg-red-50 p-2 text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
            @if($excelPreview)
                <p class="text-sm font-semibold">{{ $excelPreview['sheet'] }}: {{ $excelPreview['sourceTotal'] }} {{ $excelUnit }} = {{ $excelPreview['total'] }} pieces</p>
                @if(!empty($excelPreview['sectionTotals']))<p class="text-xs">Section quantities: {{ implode(' + ', $excelPreview['sectionTotals']) }} {{ $excelUnit }}. Prices below are per individual lens.</p>@endif
                @if(!empty($excelPreview['warnings']))
                    <div class="rounded border border-amber-300 bg-amber-50 p-3 text-xs" role="alert">@foreach($excelPreview['warnings'] as $warning)<p>{{ $warning }}</p>@endforeach
                    <label class="flex gap-2 mt-2"><input type="checkbox" wire:model="excelWarningsAccepted">I reviewed the quantity units and differences above.</label></div>
                @endif
                <p class="text-xs">@if(is_numeric($unitCost))Estimated receipt cost at GHS {{ number_format((float)$unitCost,2) }} per lens: GHS {{ number_format($excelPreview['total'] * (float)$unitCost,2) }}@else Enter unit cost below to calculate receipt cost.@endif</p>
                <div class="overflow-auto" style="max-height: {{ $fullPage ? '45vh' : '12rem' }}"><table class="ui-table w-full"><thead><tr><th>Sheet row</th><th>SPH</th><th>{{ $lensDesign === 'Single Vision' ? 'CYL' : 'ADD' }}</th><th>{{ !empty($excelPreview['perEye']) ? 'Pairs (R + L)' : 'Pieces' }}</th></tr></thead><tbody>
                @foreach($excelPreview['lines'] as $line)<tr><td>{{ $line['row'] }}</td><td>{{ $line['sphere'] }}</td><td>{{ $line['power'] }}</td><td>{{ $line['quantity'] }}</td></tr>@endforeach
                </tbody></table></div>
                <p class="text-xs">Applying replaces the current bulk quantities and clears per-power price overrides. Stock is recorded only when you confirm the receipt.</p>
                <button type="button" wire:click="applyExcel" wire:loading.attr="disabled" class="ui-button ui-button-primary">Apply preview to bulk grid</button>
            @endif
        </section>
        @if($importedSummary)<p class="text-xs text-teal-800">Imported: {{ $importedSummary }}. {{ $lensEye === 'B' ? 'All grid quantities below are pairs: each adds one right and one left lens.' : 'All grid quantities below are individual pieces.' }}</p>@endif
        <label class="flex gap-2 text-xs"><input type="checkbox" wire:model.live="templateGrid">Use lens order grid layout</label>
        @if($templateGrid)
            <label class="block text-xs">Sphere section<select wire:model.live="bulkSection" class="ui-input"><option value="positive">Positive SPH (including plano)</option><option value="negative">Negative SPH</option></select></label>
            <p class="text-xs text-slate-500">Plano (+0.00 / -0.00) is combined in the positive section. Switch sections to review both sets of quantities.</p>
        @else
        <label class="block text-xs">First sphere to display / paste<input autocomplete="off" type="number" min="-15" max="15" step="0.25" wire:model.live="bulkStart" class="ui-input w-full"></label>
        <p class="text-xs text-slate-500">Rows increase by +0.25. Paste quantities only from Excel, starting at the first column shown. Changing the first sphere preserves quantities already entered.</p>
        <textarea wire:model="bulkPaste" rows="2" class="ui-input w-full" aria-label="Paste Excel quantities" placeholder="Paste a rectangular block of quantities"></textarea>
        <button type="button" wire:click="pasteGrid" class="ui-button">Apply pasted quantities</button>
        @endif
        @php $columns = $lensDesign === 'Single Vision' ? range(0,-6,-0.25) : range(0.25,4,0.25); $start = is_numeric($bulkStart) ? max(0,min(120,(int) round(((float)$bulkStart + 15)*4))) : 60; @endphp
        @php
            $displayColumns = $templateGrid ? ($lensDesign === 'Single Vision' ? array_slice($columns,0,9,true) : array_slice($columns,3,9,true)) : $columns;
            $displayRows = $templateGrid ? ($bulkSection === 'negative' ? range(59, 4) : range(60, 116)) : range($start,min(120,$start+16));
            foreach($bulkQuantities as $r=>$cells) foreach($cells as $c=>$qty) if ((int)$qty>0) {
                if (isset($columns[$c])) $displayColumns[$c] = $columns[$c];
                if ($templateGrid && (($bulkSection === 'negative' && $r<60) || ($bulkSection !== 'negative' && $r>=60)) && !in_array($r,$displayRows)) $displayRows[]=$r;
            }
            ksort($displayColumns);
            if ($templateGrid && $bulkSection === 'negative') rsort($displayRows); else sort($displayRows);
        @endphp
        <div class="overflow-auto border rounded-lg" style="max-height: {{ $fullPage ? '65vh' : '18rem' }}"><table class="text-xs border-collapse"><thead><tr><th class="p-2">SPH / {{ $lensDesign === 'Single Vision' ? 'CYL' : 'ADD (+)' }}</th>@foreach($displayColumns as $power)<th class="p-2">{{ number_format($power,2) }}</th>@endforeach<th>Total</th></tr></thead><tbody>
        @foreach($displayRows as $r)
            <tr wire:key="bulk-row-{{ $lensDesign }}-{{ $r }}"><th class="p-2">{{ sprintf('%+.2f',-15+$r*.25) }}</th>
            @foreach($displayColumns as $c=>$power)<td class="border p-1"><input autocomplete="off" type="number" min="0" max="100000" step="1" wire:model="bulkQuantities.{{ $r }}.{{ $c }}" class="ui-input" style="width:65px" aria-label="Sphere {{ -15+$r*.25 }} power {{ $power }}"></td>@endforeach
            <td class="p-2" x-text="rowTotal({{ $r }})">{{ collect($bulkQuantities[$r] ?? [])->sum(fn($q)=>is_numeric($q)?(int)$q:0) }}</td></tr>
        @endforeach
        </tbody></table></div>
    @endif
    @php $priceList = $this->rangePriceList(); @endphp
    @if($fullPage)<h2 class="lens-receipt-step">{{ $entryMode === 'bulk' ? '3' : '2' }}. Pricing</h2>@endif
    <div class="grid grid-cols-2 gap-3">
        <label class="text-xs">{{ $entryMode === 'bulk' ? 'Default unit cost' : 'Unit cost' }} (GHS / lens) *<input autocomplete="off" type="number" min="0" step="0.01" wire:model="unitCost" class="ui-input w-full"><span class="text-slate-500" x-show="$wire.unitCost !== '' && ! isNaN(parseFloat($wire.unitCost))" x-text="'= GHS ' + money(num($wire.unitCost) * 2) + ' per pair'"></span></label>
        @if($priceList)
        <p class="text-xs rounded border border-teal-200 bg-teal-50 p-2">Selling price comes from the lens price list: GHS {{ number_format((float) $priceList->pair_price, 2) }} per pair (GHS {{ number_format((float) $priceList->pair_price / 2, 2) }} per lens){{ $priceList->rules->isNotEmpty() ? ', with '.$priceList->rules->count().' power exception(s)' : '' }}. Change it in Lens Catalogue → Lens prices.</p>
        @else
        <label class="text-xs">Selling price (GHS / lens) *<input autocomplete="off" type="number" min="0" step="0.01" wire:model="unitPrice" class="ui-input w-full"></label>
        @endif
    </div>
    @if($entryMode === 'bulk')
    @if($fullPage)
    <section class="space-y-2" aria-label="Price overrides"><h3 class="text-sm font-semibold">Price overrides <span class="font-normal text-slate-500">(optional)</span></h3>
    <p class="text-xs text-slate-500">Leave a box empty to use the default cost and selling price above. Fill it only for powers priced differently.</p>
    <div class="overflow-auto border rounded-lg text-xs" style="max-height: 50vh">
    @else
    <details><summary class="text-xs cursor-pointer">Price overrides for entered powers</summary><div class="max-h-48 overflow-auto text-xs">
    @endif
    {{-- Listed in the browser from the grid, so typing a quantity needs no server call. Keyed on the price list and design, which change what is shown. --}}
    <div wire:key="price-overrides-{{ $priceList ? 'list' : 'own' }}-{{ $lensDesign }}">
    <p class="p-2 text-slate-500" x-show="entered().length === 0">No quantities in the grid yet. {{ $excelPreview ? 'Click "Apply preview to bulk grid" above, then' : 'Enter quantities in the grid, then' }} each received power is listed here for its own cost and price.</p>
    <div class="grid grid-cols-3 gap-2 p-2 font-semibold border-b" x-show="entered().length > 0" x-cloak><span>SPH / {{ $lensDesign === 'Single Vision' ? 'CYL' : 'ADD' }}</span><span>Unit cost (GHS / lens)</span>@unless($priceList)<span>Selling price (GHS / lens)</span>@endunless</div>
    <template x-for="cell in entered()" :key="cell.key">
        <div class="grid grid-cols-3 gap-2 py-1 px-2"><span><span x-text="cell.label"></span> <span class="text-slate-500" x-text="'× ' + cell.qty"></span></span><input autocomplete="off" type="number" min="0" step="0.01" :value="override('bulkCosts', cell.r, cell.c)" x-on:input="setOverride('bulkCosts', cell.r, cell.c, $event.target.value)" placeholder="Default cost" aria-label="Unit cost override" class="ui-input">@unless($priceList)<input autocomplete="off" type="number" min="0" step="0.01" :value="override('bulkPrices', cell.r, cell.c)" x-on:input="setOverride('bulkPrices', cell.r, cell.c, $event.target.value)" placeholder="Default price" aria-label="Selling price override" class="ui-input">@endunless</div>
    </template>
    </div>
    @if($fullPage)</div></section>@else</div></details>@endif
    @endif
    @unless($priceList)
    <label class="flex gap-2 text-xs"><input type="checkbox" wire:model="updateSellingPrice">Update existing selling prices for received powers</label>
    <p class="text-xs text-slate-500">New powers use the entered price. Existing prices remain unchanged unless selected above. For bulk entries, expand price overrides to set prices for individual powers. To price a whole range at once, set a price per pair in Lens Catalogue → Lens prices.</p>
    @endunless
    @unless($fullPage)
    @php $totals = $this->receiptTotals(); @endphp
    <div class="bg-teal-50 p-3 rounded text-sm"><span x-text="totals().pieces">{{ $totals['pieces'] }}</span> pieces / Total cost: GHS <span x-text="money(totals().cost)">{{ number_format($totals['cost'],2) }}</span></div>
    @else
    <h2 class="lens-receipt-step">{{ $entryMode === 'bulk' ? '4' : '3' }}. Supplier &amp; invoice</h2>
    @endunless
    <div class="grid grid-cols-2 gap-3">
        <label class="text-xs">Supplier *<input autocomplete="off" wire:model="supplier" maxlength="180" class="ui-input w-full"></label>
        <label class="text-xs">Batch / lot number<input autocomplete="off" wire:model="batchNumber" maxlength="100" class="ui-input w-full"></label>
        <label class="text-xs">Invoice / receipt reference<input autocomplete="off" wire:model="reference" maxlength="100" class="ui-input w-full"></label>
    </div>
    @if($errors->any())<ul class="text-xs text-red-700" role="alert">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
</div>
