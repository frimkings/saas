<section class="pa-section">
<style>.pa-size-input{box-sizing:border-box;background:#07101f;border:1px solid #273750;color:#edf5ff;border-radius:7px;padding:6px 8px}.pa-size-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}</style>
    <h4>Attendance by staff size</h4>
    <p class="pa-muted" style="margin-top:0">Attendance priced from the attendance price list: a package and the staff it pays for, which is also how many staff the clinic can follow. Starting charges the rest of the period; a change works now and costs the new price from the next renewal. The price stays as it is when the list changes, until you reprice it. From {{ number_format($specialFrom) }} staff, type the price you agreed.</p>

    @if($current)
        <div class="pa-card" style="margin-bottom:10px">
            <b>{{ $current->name() }}</b> · {{ $current->currency }} {{ number_format((float) $current->monthly_price, 2) }} a month
            @if($current->custom_price)<span class="pa-badge">Agreed price</span>@endif
            <div class="pa-muted">Since {{ $current->starts_at->format('j M Y') }}@if($current->ends_at) · stops {{ $current->ends_at->format('j M Y') }}@endif
                @if($currentList && ! $currentList['special'] && abs($currentList['monthly'] - (float) $current->monthly_price) > 0.004)
                    · today's list price is {{ $current->currency }} {{ number_format($currentList['monthly'], 2) }}
                @endif
                @if($oldPacks) · plus {{ number_format($oldPacks) }} staff from packs bought earlier @endif
            </div>
        </div>
    @endif

    @if(! $current || ! $current->ends_at)
        <div class="pa-size-row">
            <label class="pa-field"><span>Package</span>
                <select class="pa-size-input" wire:model.live="package" aria-label="Attendance package">
                    @foreach($packages as $key => $p)<option value="{{ $key }}">{{ $p['name'] }}</option>@endforeach
                </select>
            </label>
            <label class="pa-field"><span>Staff</span><input class="pa-size-input" type="number" min="1" style="width:100px" wire:model.live.debounce.400ms="staff" placeholder="e.g. 80" aria-label="Number of staff"></label>
            <label class="pa-field"><span>Price a month (empty = list)</span><input class="pa-size-input" type="number" min="0" step="0.01" style="width:130px" wire:model.live.debounce.400ms="price"
                placeholder="{{ $list && ! $list['special'] ? number_format($list['monthly'], 2) : 'Agreed price' }}" aria-label="Monthly price"></label>
            <label class="pa-field" style="flex:1;min-width:180px"><span>Reason (optional)</span><input class="pa-size-input" type="text" wire:model="reason" placeholder="e.g. Signed for 360 staff"></label>
        </div>
        <div class="pa-muted" style="margin:6px 0">
            @if($staff !== '' && $list)
                @if($list['special'])
                    {{ number_format((int) $staff) }} staff is special pricing: type the agreed monthly price; the staff limit will be {{ number_format((int) $staff) }}.
                @else
                    List price for {{ $packages[$package]['name'] }} up to {{ number_format($list['size']) }} staff: {{ number_format($list['monthly'], 2) }} a month.
                @endif
                @if($charge) Starting today charges {{ number_format($charge[0], 2) }} now ({{ $charge[1] }} of {{ $charge[2] }} days). @endif
            @endif
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button type="button" class="pa-btn pa-btn-sm" wire:click="save" wire:loading.attr="disabled">{{ $current ? 'Change' : 'Start attendance by size' }}</button>
            @if($current)
                @if($currentList && ! $currentList['special'] && abs($currentList['monthly'] - (float) $current->monthly_price) > 0.004)
                    <button type="button" class="pa-btn alt pa-btn-sm" wire:click="reprice" wire:confirm="Charge today's list price from the next renewal?">Reprice to today's list</button>
                @endif
                <button type="button" class="pa-btn alt pa-btn-sm" wire:click="stop" wire:confirm="Stop attendance by size? It keeps working until the end of the paid period.">Stop</button>
            @endif
        </div>
    @endif
    @error('size')<small class="pa-err">{{ $message }}</small>@enderror
</section>
