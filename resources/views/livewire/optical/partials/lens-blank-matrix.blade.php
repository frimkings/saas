<div class="space-y-4 min-w-0" wire:poll.15s>
    <section class="ui-panel p-5 space-y-4" aria-label="Lens blank stock filters">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 class="font-semibold text-slate-900">Lens Blank Power Grid</h2>
                <p class="ui-muted mt-1">Branch stock in individual lenses. Select the lens specifications to view available quantities.</p>
            </div>
            @hasanyrole('Manager|Super Admin')
                <button type="button" wire:click="openIntake" class="ui-button ui-button-primary shrink-0">+ Receive Lens Blanks</button>
            @endhasanyrole
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <label class="block text-xs font-semibold">Lens design<select wire:model.live="matrixDesign" class="ui-input mt-1"><option>Single Vision</option><option>Bifocal</option><option>Progressive</option></select></label>
            @if(\App\Support\LensDesign::isEyeSpecific($matrixDesign))
                <label class="block text-xs font-semibold">Eye<select wire:model.live="matrixEye" class="ui-input mt-1">@foreach(\App\Support\LensDesign::EYES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            @endif
            <label class="block text-xs font-semibold">Manufacturer / product range<select wire:model.live="matrixRange" class="ui-input mt-1">@forelse($lensRanges as $range)<option value="{{ $range }}">{{ $range ?: 'Unspecified legacy range' }}</option>@empty<option value="">No received lens ranges</option>@endforelse</select></label>
            <label class="block text-xs font-semibold">Diameter (mm)<input type="number" wire:model.live="matrixDiameter" min="0" max="100" class="ui-input mt-1"><span class="text-slate-500">0 = legacy unspecified stock</span></label>

            <label class="block min-w-32 text-xs font-semibold text-slate-700">Refractive index
                <select wire:model.live="matrixIndex" class="ui-input mt-1"><option value="1.50">1.50</option><option value="1.60">1.60</option><option value="1.56">1.56</option><option value="1.61">1.61</option><option value="1.67">1.67</option><option value="1.74">1.74</option></select>
            </label>
            <label class="block min-w-48 text-xs font-semibold text-slate-700">Coating
                <select wire:model.live="matrixCoating" class="ui-input mt-1"><option value="AR">Clear AR</option><option value="Photo AR">Photo AR</option><option value="Photo Gray">Photo Gray</option><option value="Photochromic">Photochromic</option><option value="Blue AR">Blue AR</option><option value="BlueCut">Blue cut</option><option value="Transitions">Transitions</option><option value="HC">Hard coat</option></select>
            </label>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600 pb-2" aria-label="Stock legend">
                <span><span class="inline-block size-2.5 rounded-sm bg-emerald-500 mr-1"></span>Above reorder level</span>
                <span><span class="inline-block size-2.5 rounded-sm bg-yellow-400 mr-1"></span>Low (default: 1–10 lenses / up to 5 pairs)</span>
                <span><span class="inline-block size-2.5 rounded-sm bg-red-400 mr-1"></span>Out (0)</span>
            </div>
        </div>
    </section>

    <div class="flex flex-wrap justify-between gap-3 items-center text-sm"><strong>{{ number_format($matrixTotal) }} lenses available in this branch</strong><label class="flex gap-2 items-center"><input type="checkbox" wire:model.live="matrixStockedOnly">Show tracked sphere rows (including zero stock)</label><label class="flex gap-2 items-center"><input type="checkbox" wire:model.live="matrixFullPowers">Show full power range</label><button type="button" wire:click="$refresh" class="ui-button">Refresh stock</button></div>
    @if($matrixTotal === 0 || $matrixSpheres->isEmpty())
        <div class="ui-panel p-4 text-sm space-y-2 bg-amber-50/50 border-amber-200" role="status">
            <div class="font-semibold text-amber-900 flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-600 shrink-0 min-w-5 inline-block" style="width: 20px; height: 20px;" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                No stock found matching: {{ $matrixRange ?: 'Default Range' }} ({{ $matrixDesign }}, {{ $matrixIndex }}, {{ $matrixCoating }}, {{ $matrixDiameter }}mm)
            </div>
            <ul class="list-disc list-inside text-slate-700 space-y-1 text-xs">
                <li><strong>Did you just import stock from Excel?</strong> Ensure you clicked <span class="font-semibold text-slate-900">"Confirm & Receive Stock"</span> at the bottom of the intake form after applying the Excel preview.</li>
                <li><strong>Check filter settings:</strong> Match the 5 filter controls above (Design, Range, Index, Coating, Diameter) to the exact parameters used during receipt.</li>
                <li><strong>Toggle row visibility:</strong> Untick <span class="font-semibold">"Show tracked sphere rows (including zero stock)"</span> to view all power grid cells.</li>
            </ul>
        </div>
    @endif
    @if(session()->has('success'))<div class="ui-panel p-3 text-teal-800" role="status">{{ session('success') }}</div>@endif

    <section class="ui-panel p-4 space-y-3" aria-label="Lens replenishment alerts">
        <h3 class="font-bold">Replenishment alerts for this lens range</h3>
        <p class="text-sm"><strong>{{ $replenishmentRows->where('quantity', 0)->count() }}</strong> zero-stock powers · <strong>{{ $replenishmentRows->where('low', true)->where('quantity', '>', 0)->count() }}</strong> low-stock powers · Suggested order: <strong>{{ $replenishmentRows->sum('pairs') }} pairs</strong></p>
        <p class="text-xs text-slate-600">Default reorder threshold: 5 pairs (10 lenses). Target: 10 pairs. Custom thresholds control the yellow cells. Only previously stocked powers are included in orders; untracked empty cells remain red.</p>
        @hasanyrole('Manager|Super Admin')
        <div class="flex gap-3"><button type="button" wire:click="editReorderLevels" class="ui-button">Set reorder levels per power</button><button type="button" wire:click="downloadReplenishment" class="ui-button">Download replenishment order (.xlsx)</button><button type="button" wire:click="orderFromReorderList" class="ui-button ui-button-primary">Create supplier order</button></div>
        @endhasanyrole
        @error('replenishment')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
        @if($showReorderSettings)
            <form wire:submit="saveReorderLevels" class="space-y-3">
                <div class="overflow-auto max-h-96"><table class="ui-table w-full"><thead><tr><th>SPH</th><th>{{ $matrixDesign === 'Single Vision' ? 'CYL' : 'ADD' }}</th><th>On hand (lenses)</th><th>Reorder at (pairs)</th><th>Target (pairs)</th><th>Order (pairs)</th></tr></thead><tbody>
                @foreach($replenishmentRows as $row)
                    <tr wire:key="reorder-{{ $row['id'] }}"><td>{{ $row['sphere'] }}</td><td>{{ $row['power'] }}</td><td>{{ $row['quantity'] }}</td><td><input type="number" min="0" max="49999" step="1" wire:model="reorderPairs.{{ $row['id'] }}" class="ui-input w-24" aria-label="Reorder pairs for {{ $row['sphere'] }} / {{ $row['power'] }}">@error('reorderPairs.'.$row['id'])<span class="text-red-700">{{ $message }}</span>@enderror</td><td><input type="number" min="1" max="50000" step="1" wire:model="targetPairs.{{ $row['id'] }}" class="ui-input w-24" aria-label="Target pairs for {{ $row['sphere'] }} / {{ $row['power'] }}">@error('targetPairs.'.$row['id'])<span class="text-red-700">{{ $message }}</span>@enderror</td><td>{{ $row['pairs'] }}</td></tr>
                @endforeach
                </tbody></table></div>
                <p class="text-xs">Order quantities reflect saved settings. Save changes before downloading.</p>
                <button type="submit" class="ui-button ui-button-primary">Save reorder levels</button><button type="button" wire:click="$set('showReorderSettings', false)" class="ui-button">Cancel</button>
            </form>
        @endif
    </section>

    <div class="ui-panel min-w-0 overflow-auto max-h-[min(65vh,650px)]" role="region" aria-label="Lens blank power grid" tabindex="0">
        <table class="border-collapse text-xs font-mono text-center min-w-max w-full">
            <caption class="sr-only">Available lens blank quantities for index {{ $matrixIndex }} and {{ $matrixCoating }} coating</caption>
            <thead class="sticky top-0 z-20"><tr>
                <th scope="col" class="sticky left-0 z-30 bg-slate-900 text-white px-3 py-2 whitespace-nowrap border border-slate-700">SPH / {{ $matrixDesign === 'Single Vision' ? 'CYL' : 'ADD (+)' }}</th>
                @foreach($matrixColumns as $cyl)
                    <th scope="col" class="bg-slate-900 text-white px-3 py-2 whitespace-nowrap border border-slate-700">{{ number_format($cyl, 2) }}</th>
                @endforeach
                <th scope="col" class="bg-slate-900 text-white px-3 py-2">Total</th>
            </tr></thead>
            <tbody>
            @foreach($matrixSpheres as $sph)
                <tr>
                    <th scope="row" class="sticky left-0 z-10 bg-slate-900 text-white px-3 py-2 whitespace-nowrap border border-slate-700">{{ $sph > 0 ? '+' : '' }}{{ number_format($sph, 2) }}</th>
                    @foreach($matrixColumns as $cyl)
                        @php $quantity = (int) ($lensBlankStock[number_format($sph, 2).'|'.number_format($cyl, 2)] ?? 0); $level = $replenishmentRows->first(fn ($r) => (float) $r['sphere'] === (float) $sph && (float) $r['power'] === (float) $cyl); $threshold = ($level['reorder'] ?? 5) * 2; @endphp
                        <td class="px-3 py-2 border border-white/70 tabular-nums {{ $quantity > $threshold ? 'bg-emerald-50 text-emerald-800' : ($quantity > 0 ? 'bg-yellow-100 text-yellow-900' : 'bg-red-100 text-red-800') }}">@hasanyrole('Manager|Super Admin')<button type="button" wire:click="openIntake('{{ number_format($sph,2) }}','{{ number_format($cyl,2) }}')" title="Receive this power" class="w-full underline decoration-dotted">{{ $quantity }}</button>@else{{ $quantity }}@endhasanyrole</td>
                    @endforeach
                    <td class="font-bold px-3 py-2">{{ $matrixRowTotals[number_format($sph,2)] ?? 0 }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr class="font-bold bg-slate-100"><th class="px-3 py-2">Total</th>@foreach($matrixColumns as $cyl)<td class="px-3 py-2">{{ $matrixColumnTotals[number_format($cyl,2)] ?? 0 }}</td>@endforeach<td class="px-3 py-2">{{ $matrixTotal }}</td></tr></tfoot>
        </table>
    </div>

</div>
