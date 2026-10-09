@php
    // Form · Treatment · Manufacturer, then index and diameter, in the shop's own names.
    $rangeLabel = fn ($specs) => implode(' · ', array_filter([
        ! empty($specs['form']) ? \App\Support\Optical\LensOptions::label('form', $specs['form']) : null,
        \App\Support\Optical\LensOptions::label('treatment', $specs['coating']),
        $specs['range'] !== '' ? \App\Support\Optical\LensOptions::label('manufacturer', $specs['range']) : 'Unspecified manufacturer',
        $specs['index'], $specs['diameter'] ? $specs['diameter'].' mm' : 'diameter not recorded',
    ]));
    $pairsText = fn ($pairs, $extra, $eyeSpecific) => number_format($pairs).' '.\Illuminate\Support\Str::plural('pair', $pairs).($extra ? ' + '.$extra.' '.($eyeSpecific ? 'unpaired' : 'single').' '.\Illuminate\Support\Str::plural('lens', $extra) : '');
    $currentRange = $stockedRanges->firstWhere('key', $matrixRangeKey);
    $isManager = auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
    // Single vision switches Pairs/Lenses in the browser: both are rendered and CSS shows one.
    // Progressive/bifocal Pairs/Right/Left stays on the server: it sets the eye the reorder alerts cover.
    $browserViews = ! $matrixEyeSpecific;
    $eyeViews = ['pairs' => 'Pairs (R + L)', 'R' => 'Right', 'L' => 'Left'];
    $receiveUrl = $isManager ? route('optical.stock', [
        'receive' => 'lens', 'lensRange' => $matrixRange, 'lensDesign' => $matrixDesign, 'lensIndex' => $matrixIndex,
        'lensCoating' => $matrixCoating, 'lensDiameter' => $matrixDiameter ?: 65,
        'lensEye' => $matrixEyeSpecific ? ($matrixView === 'pairs' ? 'B' : $matrixEye) : '',
    ]) : '';
    $tone = fn ($value, $extra, $threshold) => $value > $threshold ? 'g' : (($value > 0 || ($extra && ! $matrixEyeSpecific)) ? 'y' : 'r');
    $both = fn ($pairsHtml, $lensesHtml) => $browserViews ? '<span class="v-p">'.$pairsHtml.'</span><span class="v-l">'.$lensesHtml.'</span>' : $pairsHtml;
    $extraBadge = fn ($cell) => $cell['extra'] ? '<span class="lg-extra'.($matrixEyeSpecific ? ' lg-extra-eye' : '').'">+'.$cell['extra'].($cell['extraEye'] ? ' '.$cell['extraEye'] : '').'</span>' : '';
    $inPairs = $matrixView === 'pairs';
@endphp
<div class="space-y-4 min-w-0 lg-grid" x-data="lensGrid(@js($browserViews ? ($matrixView === 'lenses' ? 'lenses' : 'pairs') : 'pairs'))"
     data-grid="{{ json_encode($selectionGrid) }}" data-receive-url="{{ $receiveUrl }}"
     :class="{ 'lg-selecting': selecting, 'v-lenses': view === 'lenses' }"
     x-on:lens-grid-reset.window="clear()" x-on:lens-select-toggle.window="toggleMode()"
     x-on:visibilitychange.document="refreshIfStale()">
    <style>
        .lg-grid .lg-c{padding:.5rem .75rem;border:1px solid rgb(255 255 255 / .7);white-space:nowrap;font-variant-numeric:tabular-nums}
        .lg-grid:not(.v-lenses) .lg-c[data-tp=g],.lg-grid.v-lenses .lg-c[data-tl=g]{background:#ecfdf5;color:#065f46}
        .lg-grid:not(.v-lenses) .lg-c[data-tp=y],.lg-grid.v-lenses .lg-c[data-tl=y]{background:#fef9c3;color:#713f12}
        .lg-grid:not(.v-lenses) .lg-c[data-tp=r],.lg-grid.v-lenses .lg-c[data-tl=r]{background:#fee2e2;color:#991b1b}
        .lg-grid.v-lenses .v-p,.lg-grid:not(.v-lenses) .v-l{display:none}
        .lg-grid .lg-b{width:100%;text-decoration:underline dotted;text-underline-offset:2px}
        .lg-grid .lg-sel{outline:2px solid #0f766e;outline-offset:-2px;box-shadow:inset 0 0 0 2px #fff}
        .lg-grid .lg-sel .lg-b::before{content:'✓ '}
        .lg-grid .lg-new.lg-sel .lg-b::after{content:' new'}
        .lg-grid.lg-selecting .lg-new:not(.lg-sel) .lg-b{opacity:.55;text-decoration:none;border-bottom:1px dashed currentColor}
        .lg-grid .lg-line{cursor:default}
        .lg-grid.lg-selecting .lg-line{cursor:pointer;text-decoration:underline dotted}
        .lg-grid .lg-extra{margin-left:.25rem;border-radius:.25rem;padding:0 .25rem;font-size:10px;font-weight:600;color:#fff;background:#334155}
        .lg-grid .lg-extra-eye{background:#7c3aed}
        .lg-grid .lg-order{font-size:10px;line-height:1;font-weight:600;color:#1d4ed8}
    </style>

    {{-- Toolbar: range, view and stock summary in one row so the grid starts near the top. --}}
    <section class="ui-panel px-4 py-3" aria-label="Lens stock range">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
            <select wire:model.live="matrixRangeKey" class="ui-input" style="min-width:18rem;flex:1 1 22rem;max-width:40rem" aria-label="Lens range"
                    title="One lens range at this branch. Lenses are bought in pairs; a single vision pair can be split, a progressive or bifocal pair is one right and one left lens.">
                @if($stockedRanges->isEmpty())<option value="">No lens stock received yet</option>@endif
                @unless($currentRange || $stockedRanges->isEmpty())<option value="{{ $matrixRangeKey }}">{{ $matrixRange ?: 'Unspecified range' }} · {{ $matrixDesign }} (no stock)</option>@endunless
                @foreach($stockedRanges->groupBy('specs.design') as $design => $ranges)
                    <optgroup label="{{ \App\Support\Optical\LensOptions::label('design', $design) }}">
                        @foreach($ranges as $range)
                            <option value="{{ $range['key'] }}">{{ $rangeLabel($range['specs']) }} — {{ $pairsText($range['pairs'], $range['extra'], \App\Support\LensDesign::isEyeSpecific($design)) }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>

            <div class="inline-flex rounded-lg border border-slate-300 overflow-hidden" role="group" aria-label="Quantity view">
                @if($browserViews)
                    @foreach(['pairs' => 'Pairs', 'lenses' => 'Lenses'] as $value => $label)
                        <button type="button" x-on:click="view = '{{ $value }}'" :aria-pressed="view === '{{ $value }}'"
                            :class="view === '{{ $value }}' ? 'bg-teal-700 text-white' : 'bg-white text-slate-700'" class="px-3 py-1.5 text-sm" @unless($loop->first) style="border-left:1px solid #cbd5e1" @endunless>{{ $label }}</button>
                    @endforeach
                @else
                    @foreach($eyeViews as $value => $label)
                        <button type="button" wire:click="$set('matrixView', '{{ $value }}')" aria-pressed="{{ $matrixView === $value ? 'true' : 'false' }}"
                            class="px-3 py-1.5 text-sm {{ $matrixView === $value ? 'bg-teal-700 text-white' : 'bg-white text-slate-700' }}" @unless($loop->first) style="border-left:1px solid #cbd5e1" @endunless>{{ $label }}</button>
                    @endforeach
                @endif
            </div>

            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <label class="flex gap-1.5 items-center"><input type="checkbox" wire:model.live="matrixStockedOnly">Only rows with stock</label>
                <label class="flex gap-1.5 items-center"><input type="checkbox" wire:model.live="matrixFullPowers">Full power range</label>
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-x-3 gap-y-2">
                <div class="text-right leading-tight">
                    <strong class="text-sm">
                        @if($browserViews)
                            {!! $both(e($pairsText($matrixViewTotals['pairs']['all']['value'], $matrixViewTotals['pairs']['all']['extra'], false)), e(number_format($matrixTotal).' lenses')) !!}
                        @elseif($matrixView === 'pairs'){{ $pairsText($matrixCellTotal['value'], $matrixCellTotal['extra'], true) }}
                        @else{{ number_format($matrixTotal) }} {{ $matrixView === 'R' ? 'right' : 'left' }} lenses
                        @endif
                    </strong>
                    <span class="block text-[11px] text-slate-500">
                        {{ $matrixDesign }} · {{ $matrixEyeSpecific ? 'ADD' : 'CYL' }} columns ·
                        @if($matrixPrice){{ currency() }} {{ number_format((float) $matrixPrice->pair_price, 2) }}/pair{{ $matrixPrice->rules->isNotEmpty() ? '*' : '' }}@else<button type="button" wire:click="setTab('lens-prices')" class="underline">set price</button>@endif
                        · <span wire:loading.remove wire:target="$refresh">updated {{ $matrixRefreshedAt->format('H:i') }}</span><span wire:loading wire:target="$refresh">refreshing…</span>
                    </span>
                </div>
                <button type="button" wire:click="$refresh" x-on:click="_refreshedAt = Date.now()" class="ui-button" style="padding:6px 10px" title="Refresh stock" aria-label="Refresh stock">⟳</button>
                @if($isManager)
                    <div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                        <button type="button" x-ref="receive" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="menu" class="ui-button ui-button-primary">Receive ▾</button>
                        <template x-teleport="body">
                            <div x-show="open" x-cloak x-anchor.bottom-end.offset.4="$refs.receive" x-on:click.outside="if (! $refs.receive.contains($event.target)) open = false" role="menu"
                                 class="clinic-ui z-[1050] w-72 rounded-lg border border-slate-200 bg-white p-1 text-left text-xs shadow-lg">
                                <a role="menuitem" href="{{ $receiveUrl }}&lensSphere=0.00&lensPower={{ $matrixDesign === 'Single Vision' ? '0.00' : '1.00' }}" class="block rounded px-3 py-2 hover:bg-slate-50"><b>Receive lens blanks</b><span class="block text-slate-500">A few powers for this range</span></a>
                                <a role="menuitem" href="{{ route('optical.stock.receive-lenses', array_filter(['lensRange' => $matrixRange, 'lensDesign' => $matrixDesign, 'lensIndex' => $matrixIndex, 'lensCoating' => $matrixCoating, 'lensDiameter' => $matrixDiameter ?: null])) }}" class="block rounded px-3 py-2 hover:bg-slate-50"><b>Receive a lens order</b><span class="block text-slate-500">Power grid or Excel order sheet</span></a>
                                @foreach($missingDesigns as $design)
                                    <a role="menuitem" href="{{ route('optical.stock', ['receive' => 'lens', 'lensDesign' => $design, 'lensPower' => $design === 'Single Vision' ? '0.00' : '1.00', 'lensEye' => \App\Support\LensDesign::isEyeSpecific($design) ? 'B' : '']) }}" class="block rounded px-3 py-2 hover:bg-slate-50">Receive {{ strtolower($design) }} lenses <span class="text-slate-500">(none in stock yet)</span></a>
                                @endforeach
                            </div>
                        </template>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @if($stockedRanges->isEmpty())
        <div class="ui-panel p-4 text-sm bg-amber-50/50 border-amber-200" role="status">No lens stock has been received at this branch yet. Use <strong>Receive ▾</strong> to add stock; it will then appear here.</div>
    @elseif($matrixCellTotal['value'] + $matrixCellTotal['extra'] === 0 || $matrixSpheres->isEmpty())
        <div class="ui-panel p-4 text-sm space-y-1 bg-amber-50/50 border-amber-200" role="status">
            <p class="font-semibold text-amber-900">No {{ $matrixView === 'R' ? 'right-eye ' : ($matrixView === 'L' ? 'left-eye ' : '') }}stock in this range at this branch.</p>
            <p class="text-xs text-slate-700">Choose another range above{{ $matrixStockedOnly ? ', or untick "Only rows with stock" to see every power' : '' }}. Just imported from Excel? Make sure the receipt was confirmed with <strong>Confirm &amp; Receive Stock</strong>.</p>
        </div>
    @endif

    {{-- One alert line for reordering; amber or red only when powers are low or out. --}}
    @php
        $outCount = $replenishmentRows->where('quantity', 0)->count();
        $lowCount = $replenishmentRows->where('low', true)->where('quantity', '>', 0)->count();
        $suggested = $replenishmentRows->sum('pairs');
        $alertTone = $outCount ? 'border-red-200 bg-red-50 text-red-900' : ($lowCount ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900');
    @endphp
    @if($stockedRanges->isNotEmpty())
        <section class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-4 py-2 text-sm {{ $alertTone }}" aria-label="Lens replenishment alerts">
            <span class="flex-1 min-w-0">
                @if($outCount || $lowCount)
                    <span aria-hidden="true">⚠</span> <strong>{{ $lowCount }}</strong> {{ \Illuminate\Support\Str::plural('power', $lowCount) }} low · <strong>{{ $outCount }}</strong> out · suggested order <strong>{{ number_format($suggested) }} pairs</strong>
                @else
                    <span aria-hidden="true">✓</span> Every stocked power is above its reorder level.
                @endif
                <span class="ml-1 cursor-help text-xs opacity-70" tabindex="0" title="Default: reorder at 5 pairs (10 lenses), topping up to 10 pairs. Custom levels per power set the yellow cells. Create supplier order covers powers stocked before; pick any power by hand with Select powers.">ⓘ</span>
            </span>
            @if($isManager)
                <button type="button" x-on:click="toggleMode()" :class="selecting ? 'ui-button-primary' : 'bg-white'" :aria-pressed="selecting" class="ui-button" style="padding:5px 12px" x-text="selecting ? 'Done selecting' : 'Select powers'">Select powers</button>
                <div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                    <button type="button" x-ref="reorder" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="menu" class="ui-button" style="padding:5px 12px;background:#fff">Reorder ▾</button>
                    <template x-teleport="body">
                        <div x-show="open" x-cloak x-anchor.bottom-end.offset.4="$refs.reorder" x-on:click.outside="if (! $refs.reorder.contains($event.target)) open = false" role="menu"
                             class="clinic-ui z-[1050] w-72 rounded-lg border border-slate-200 bg-white p-1 text-left text-xs shadow-lg">
                            <button type="button" role="menuitem" wire:click="orderFromReorderList" x-on:click="open = false" class="block w-full rounded px-3 py-2 text-left hover:bg-slate-50"><b>Create supplier order</b><span class="block text-slate-500">Every low and out power, topped up to target</span></button>
                            <button type="button" role="menuitem" wire:click="downloadReplenishment" x-on:click="open = false" class="block w-full rounded px-3 py-2 text-left hover:bg-slate-50">Download replenishment order (.xlsx)</button>
                            <button type="button" role="menuitem" wire:click="editReorderLevels" x-on:click="open = false" class="block w-full rounded px-3 py-2 text-left hover:bg-slate-50">Set reorder levels per power</button>
                        </div>
                    </template>
                </div>
            @endif
            @error('replenishment')<p role="alert" class="w-full text-red-700">{{ $message }}</p>@enderror
        </section>
    @endif

    @if($showReorderSettings)
        {{-- Cancel hides the form at once; the server hears of it with the next request. --}}
        <section class="ui-panel p-4 space-y-3" aria-label="Reorder levels per power" x-data="{ cancel() { this.$root.hidden = true; this.$wire.$set('showReorderSettings', false, false) } }">
            <form wire:submit="saveReorderLevels" class="space-y-3">
                <div class="overflow-auto max-h-96"><table class="ui-table w-full"><thead><tr><th>SPH</th><th>{{ $matrixDesign === 'Single Vision' ? 'CYL' : 'ADD' }}</th><th>On hand (lenses)</th><th>Reorder at (pairs)</th><th>Target (pairs)</th><th>Order (pairs)</th></tr></thead><tbody>
                @foreach($replenishmentRows as $row)
                    <tr wire:key="reorder-{{ $row['id'] }}"><td>{{ $row['sphere'] }}</td><td>{{ $row['power'] }}</td><td>{{ $row['quantity'] }}</td><td><input autocomplete="off" type="number" min="0" max="49999" step="1" wire:model="reorderPairs.{{ $row['id'] }}" class="ui-input w-24" aria-label="Reorder pairs for {{ $row['sphere'] }} / {{ $row['power'] }}">@error('reorderPairs.'.$row['id'])<span class="text-red-700">{{ $message }}</span>@enderror</td><td><input autocomplete="off" type="number" min="1" max="50000" step="1" wire:model="targetPairs.{{ $row['id'] }}" class="ui-input w-24" aria-label="Target pairs for {{ $row['sphere'] }} / {{ $row['power'] }}">@error('targetPairs.'.$row['id'])<span class="text-red-700">{{ $message }}</span>@enderror</td><td>{{ $row['pairs'] }}</td></tr>
                @endforeach
                </tbody></table></div>
                <p class="text-xs">Order quantities reflect saved settings. Save changes before downloading.</p>
                <button type="submit" class="ui-button ui-button-primary">Save reorder levels</button><button type="button" x-on:click="cancel()" class="ui-button">Cancel</button>
            </form>
        </section>
    @endif

    @if($isManager)
        <div x-show="selecting" x-cloak class="flex flex-wrap items-center gap-2 text-xs" role="group" aria-label="Select powers to order">
            <button type="button" x-on:click="byStock('out')" class="ui-button" style="padding:5px 10px">All out of stock</button>
            <button type="button" x-on:click="byStock('low')" class="ui-button" style="padding:5px 10px">All low</button>
            <button type="button" x-on:click="clear()" class="ui-button" style="padding:5px 10px">Clear</button>
            <label class="flex items-center gap-2"><input type="checkbox" x-model="includeOnOrder"> Include powers already on order</label>
            <span class="text-slate-600">Click powers, Shift-click for a block, or click an SPH row or {{ $matrixEyeSpecific ? 'ADD' : 'CYL' }} heading for a whole line of stocked powers. Faded cells were never stocked: click one to order it as a new power.{{ $matrixEyeSpecific ? ' Each pair orders one right and one left lens.' : '' }}</span>
        </div>
    @endif

    @php
        $cells = (array) $selectionGrid['cells'];
        $pairsCells = $browserViews ? $matrixViews['pairs'] : $matrixCells;
        $lensCells = $browserViews ? $matrixViews['lenses'] : $matrixCells;
        $pairsTotals = $matrixViewTotals[$browserViews ? 'pairs' : $matrixView];
        $lensTotals = $browserViews ? $matrixViewTotals['lenses'] : $pairsTotals;
        $cellTitle = function ($cell) use ($inPairs, $matrixEyeSpecific) {
            if ($matrixEyeSpecific && ! $inPairs) return $cell['value'].' '.\Illuminate\Support\Str::plural('lens', $cell['value']);
            if (! $matrixEyeSpecific) return $cell['value'].' '.\Illuminate\Support\Str::plural('pair', $cell['value']).($cell['extra'] ? ' + 1 single lens (sellable as half a pair)' : '').' = '.($cell['value'] * 2 + $cell['extra']).' lenses';
            $r = $cell['value'] + ($cell['extraEye'] === 'R' ? $cell['extra'] : 0);
            $l = $cell['value'] + ($cell['extraEye'] === 'L' ? $cell['extra'] : 0);
            return $cell['value'].' complete '.\Illuminate\Support\Str::plural('pair', $cell['value'])." ({$r} right, {$l} left)".($cell['extra'] ? '. '.$cell['extra'].' unpaired '.($cell['extraEye'] === 'R' ? 'right' : 'left').' '.\Illuminate\Support\Str::plural('lens', $cell['extra']).': usable only with a special-order lens for the other eye' : '');
        };
        $totalCell = fn ($pairsTotal, $lensTotal) => $both(
            e($pairsTotal['value']).($pairsTotal['extra'] ? '<span class="ml-1 font-semibold text-slate-500" style="font-size:10px">+'.e($pairsTotal['extra']).'</span>' : ''),
            e($lensTotal['value']),
        );
        $empty = ['value' => 0, 'extra' => 0, 'extraEye' => null];
    @endphp

    <div class="ui-panel min-w-0 overflow-auto" style="max-height:max(360px, calc(100dvh - 280px))" role="region" aria-label="Lens blank power grid" tabindex="0">
        <table class="border-collapse text-xs font-mono text-center min-w-max w-full">
            <caption class="sr-only">Stock for {{ $matrixRange ?: 'unspecified range' }}, {{ $matrixDesign }}, index {{ $matrixIndex }}, {{ $matrixCoating }}</caption>
            <thead class="sticky top-0 z-20"><tr>
                <th scope="col" class="sticky left-0 z-30 bg-slate-900 text-white px-3 py-2 whitespace-nowrap border border-slate-700">SPH / {{ $matrixDesign === 'Single Vision' ? 'CYL' : 'ADD (+)' }}</th>
                @foreach($matrixColumns as $cyl)
                    <th scope="col" class="bg-slate-900 text-white px-3 py-2 whitespace-nowrap border border-slate-700">@if($isManager)<span class="lg-line" x-on:click="selecting && line('power', '{{ number_format($cyl, 2) }}')" :title="selecting ? 'Select or clear this whole column' : ''">{{ number_format($cyl, 2) }}</span>@else{{ number_format($cyl, 2) }}@endif</th>
                @endforeach
                <th scope="col" class="bg-slate-900 text-white px-3 py-2">Total</th>
            </tr></thead>
            <tbody>
            @foreach($matrixSpheres as $sph)
                @php $sphKey = number_format($sph, 2); @endphp
                <tr>
                    <th scope="row" class="sticky left-0 z-10 bg-slate-900 text-white px-3 py-2 whitespace-nowrap border border-slate-700">@if($isManager)<span class="lg-line" x-on:click="selecting && line('sphere', '{{ $sphKey }}')" :title="selecting ? 'Select or clear this whole row' : ''">{{ $sph > 0 ? '+' : '' }}{{ $sphKey }}</span>@else{{ $sph > 0 ? '+' : '' }}{{ $sphKey }}@endif</th>
                    @foreach($matrixColumns as $cyl)
                        @php
                            $cellKey = $sphKey.'|'.number_format($cyl, 2);
                            $pairsCell = $pairsCells[$cellKey] ?? $empty;
                            $lensCell = $lensCells[$cellKey] ?? $empty;
                            $reorder = $cells[$cellKey][3] ?? 5;
                            $onOrder = $cells[$cellKey][4] ?? 0;
                            $stocked = isset($cells[$cellKey]);
                            $tp = $tone($pairsCell['value'], $pairsCell['extra'], $reorder * ($browserViews || $inPairs ? 1 : 2));
                            $tl = $browserViews ? $tone($lensCell['value'], 0, $reorder * 2) : $tp;
                            $content = $both(e($pairsCell['value']).$extraBadge($pairsCell), e($lensCell['value']));
                        @endphp
                        <td class="lg-c{{ $stocked ? '' : ' lg-new' }}" data-tp="{{ $tp }}" data-tl="{{ $tl }}" title="{{ $cellTitle($pairsCell) }}{{ $onOrder ? ' · '.$onOrder.' pairs on order' : '' }}"
                            @if($isManager) :class="isSel('{{ $cellKey }}') && 'lg-sel'" @endif>
                            @if($isManager)<button type="button" class="lg-b" x-on:click="pick('{{ $cellKey }}', $event.shiftKey)">{!! $content !!}</button>@else{!! $content !!}@endif
                            @if($onOrder)<div class="lg-order">⏱{{ $onOrder }}</div>@endif
                        </td>
                    @endforeach
                    <td class="font-bold px-3 py-2 whitespace-nowrap">{!! $totalCell($pairsTotals['rows'][$sphKey] ?? $empty, $lensTotals['rows'][$sphKey] ?? $empty) !!}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr class="font-bold bg-slate-100">
                <th class="px-3 py-2">Total</th>
                @foreach($matrixColumns as $cyl)
                    <td class="px-3 py-2 whitespace-nowrap">{!! $totalCell($pairsTotals['columns'][number_format($cyl, 2)] ?? $empty, $lensTotals['columns'][number_format($cyl, 2)] ?? $empty) !!}</td>
                @endforeach
                <td class="px-3 py-2 whitespace-nowrap">{!! $totalCell($pairsTotals['all'], $lensTotals['all']) !!}</td>
            </tr></tfoot>
        </table>
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="Stock legend" style="margin-top:-6px">
        <span><span class="inline-block size-2.5 rounded-sm bg-emerald-500 mr-1"></span>Above reorder level</span>
        <span><span class="inline-block size-2.5 rounded-sm bg-yellow-400 mr-1"></span>Low (default: up to 5 pairs)</span>
        <span><span class="inline-block size-2.5 rounded-sm bg-red-400 mr-1"></span>Out</span>
        @if(! $matrixEyeSpecific)<span class="v-p"><span class="lg-extra">+1</span> one single lens, sellable as half a pair</span>@endif
        @if($inPairs && $matrixEyeSpecific)<span><span class="lg-extra lg-extra-eye">+2 R</span> unpaired lenses (right and left counts differ)</span>@endif
        @if($matrixOnOrder)<span><span class="lg-order">⏱10</span> pairs on order (sent, not yet delivered)</span>@endif
    </div>

    @if($isManager)
        {{-- Worked out in the browser; the server is asked only to build the order or the sheet. --}}
        <div x-show="selecting && count() > 0" x-cloak class="ui-panel p-3 flex flex-wrap items-center gap-3" style="position:sticky;bottom:0;z-index:40;box-shadow:0 -6px 18px rgba(15,23,42,.12)" role="region" aria-label="Selected powers">
            <strong class="text-sm" aria-live="polite"><span x-text="count()"></span> <span x-text="count() === 1 ? 'power' : 'powers'"></span> selected · <span x-text="pairsTotal().toLocaleString()"></span> pairs · est. {{ currency() }} <span x-text="money(estimate())"></span></strong>
            <label class="flex items-center gap-2 text-sm">Pairs per power <input autocomplete="off" type="number" min="1" max="5000" x-model.number="pairsEach" class="ui-input" style="width:5rem"></label>
            <div class="flex gap-2 ml-auto">
                <button type="button" x-on:click="$wire.downloadSelectionSheet(keys(), pairsEach)" class="ui-button">Download order sheet (.xlsx)</button>
                <button type="button" x-on:click="$wire.createOrderFromSelection(keys(), pairsEach)" wire:loading.attr="disabled" wire:target="createOrderFromSelection" class="ui-button ui-button-primary">Create purchase order</button>
            </div>
            @error('orderPairsEach')<p class="w-full text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
            @error('selectedCells')<p class="w-full text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
            <p class="w-full text-xs text-slate-500">Estimated from the last purchase cost; a new power at the range's typical cost. You can change quantities, costs and the supplier on the purchase order before sending it.</p>
        </div>
    @endif
</div>
