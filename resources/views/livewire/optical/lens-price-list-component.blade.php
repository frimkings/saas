<div class="ui-panel p-5 space-y-4">
    <div>
        <h2 class="font-bold text-slate-900">Lens price list</h2>
        <p class="text-xs text-slate-500">Set one selling price per pair for each lens range, with higher prices for strong powers. One lens (half a pair) sells at half the pair price. Saving reprices every power in the range; orders and quotes already created keep their prices.</p>
    </div>
    @if(session()->has('lens_price_message'))<p class="text-sm text-teal-700" role="status">{{ session('lens_price_message') }}</p>@endif

    <div class="ui-table-wrap"><table class="ui-table w-full">
        <thead><tr><th>Lens range</th><th>Design</th><th>Index / coating</th><th class="ui-number">Powers</th><th class="ui-number">In stock</th><th class="ui-number">Price per pair</th><th>Exceptions</th>@if($canManage)<th>Action</th>@endif</tr></thead>
        <tbody>
        @forelse($ranges as $range)
            <tr>
                <td class="font-semibold">{{ $range['specs']['range'] ?: '—' }}</td>
                <td>{{ $range['specs']['design'] }}</td>
                <td>{{ $range['specs']['index'] }} · {{ $range['specs']['coating'] }}@if($range['specs']['diameter']) · {{ $range['specs']['diameter'] }} mm @endif</td>
                <td class="ui-number">{{ $range['powers'] }}</td>
                <td class="ui-number">{{ $range['stock'] }}</td>
                <td class="ui-number">
                    @if($range['list']){{ currency() }} {{ number_format((float) $range['list']->pair_price, 2) }}
                    @else<span class="text-slate-500" title="Prices set per power during stock receipts">Not set ({{ currency() }} {{ number_format($range['min'], 2) }}@if($range['max'] != $range['min'])–{{ number_format($range['max'], 2) }}@endif)</span>@endif
                </td>
                <td>{{ $range['list'] && $range['list']->rules->isNotEmpty() ? $range['list']->rules->count() : '—' }}</td>
                @if($canManage)<td><button type="button" class="ui-button text-xs" wire:click="edit('{{ $range['key'] }}')">{{ $range['list'] ? 'Edit' : 'Set price' }}</button></td>@endif
            </tr>
        @empty
            <tr><td colspan="8" class="text-slate-500">No stock lens ranges yet. Receive lens stock first, then set its price here.</td></tr>
        @endforelse
        </tbody>
    </table></div>

    @if($editing)
        @php $powerLabel = $editing['specs']['design'] === 'Single Vision' ? 'CYL' : 'ADD'; @endphp
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { editingKey: null })" aria-hidden="true"></div>
        <aside class="oo-drawer" style="width:min(560px,100vw)" role="dialog" aria-modal="true" aria-labelledby="lens-price-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { editingKey: null })">
            <header class="oo-drawer-head">
                <h2 id="lens-price-title">{{ $editing['specs']['range'] }} · {{ $editing['specs']['design'] }} {{ $editing['specs']['index'] }} {{ $editing['specs']['coating'] }}</h2>
                <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { editingKey: null })" aria-label="Close">&times;</button>
            </header>
            <form wire:submit="save" id="lens-price-form" class="oo-drawer-body space-y-4">
                <label class="ct-field"><span>Price per pair ({{ currency() }}) *</span>
                    <input autocomplete="off" type="number" min="0" step="0.01" wire:model.live.debounce.300ms="pairPrice" class="ui-input">
                    @if(is_numeric($pairPrice))<small class="text-slate-500">One lens: {{ currency() }} {{ number_format((float) $pairPrice / 2, 2) }}</small>@endif
                    @error('pairPrice')<small style="color:#b91c1c">{{ $message }}</small>@enderror
                </label>

                <fieldset class="space-y-2">
                    <legend class="text-xs font-semibold">Higher prices for strong powers</legend>
                    <p class="text-xs text-slate-500">Applies when the lens is at or beyond these powers, ignoring the sign (e.g. SPH 4.00 covers +4.00 and −4.00). Fill SPH, {{ $powerLabel }} or both. If several match, the highest price is used.</p>
                    @foreach($rules as $i => $rule)
                        <div class="grid grid-cols-7 gap-2 items-end" wire:key="lens-price-rule-{{ $i }}">
                            <label class="text-xs col-span-2">SPH from ±<input autocomplete="off" type="number" min="0" max="15" step="0.25" wire:model="rules.{{ $i }}.min_sphere" class="ui-input w-full" placeholder="any"></label>
                            <label class="text-xs col-span-2">{{ $powerLabel }} from ±<input autocomplete="off" type="number" min="0" max="6" step="0.25" wire:model="rules.{{ $i }}.min_power" class="ui-input w-full" placeholder="any"></label>
                            <label class="text-xs col-span-2">Pair price<input autocomplete="off" type="number" min="0" step="0.01" wire:model="rules.{{ $i }}.pair_price" class="ui-input w-full"></label>
                            <button type="button" class="ui-button text-xs" wire:click="removeRule({{ $i }})" aria-label="Remove exception {{ $i + 1 }}">✕</button>
                            @foreach(['min_sphere', 'min_power', 'pair_price'] as $field)
                                @error("rules.$i.$field")<small class="col-span-7" style="color:#b91c1c">{{ $message }}</small>@enderror
                            @endforeach
                        </div>
                    @endforeach
                    <button type="button" class="ui-button text-xs" wire:click="addRule">+ Add exception</button>
                </fieldset>

                <p class="ui-muted text-xs" style="margin:0">Saving updates all {{ $editing['powers'] }} powers in this range. Future stock receipts use this list instead of a typed selling price. Orders and quotes already created keep their prices; a quote can be repriced when it is converted.</p>
            </form>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" x-on:click="dismissLocal($el, $wire, { editingKey: null })">Cancel</button>
                <button type="submit" form="lens-price-form" class="oo-btn primary" wire:loading.attr="disabled">Save and apply</button>
            </footer>
        </aside>
    @endif
</div>
