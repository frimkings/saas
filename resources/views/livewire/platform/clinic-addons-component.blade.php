<section class="pa-section">
    <h4>Add-ons</h4>
    <p class="ui-muted" style="margin-top:0">Extra features for this clinic on top of its plan, billed each month. Adding one charges the rest of the current period now; cancelling keeps it working to the end of the paid period.</p>

    @if($requests->isNotEmpty())
        <div class="ui-card" style="border-color:#f59e0b;margin-bottom:12px">
            <b>Waiting for your answer</b>
            <table class="ui-table ui-table-sm" style="margin-top:8px">
                <thead><tr><th>Add-on</th><th>Asked by</th><th>Price a month</th><th></th></tr></thead>
                <tbody>
                    @foreach($requests as $request)
                        <tr wire:key="request-{{ $request->id }}">
                            <td><b>{{ $request->name() }}</b>@if($request->message)<div class="ui-muted">“{{ $request->message }}”</div>@endif</td>
                            <td>{{ $request->requester?->name ?? '—' }}<div class="ui-muted">{{ $request->created_at->diffForHumans() }}</div></td>
                            <td><input class="ui-input ui-input-sm" type="number" step="0.01" min="0" style="width:100px" wire:model="approvePrice.{{ $request->id }}" placeholder="{{ number_format($catalogue[$request->feature]['price'] ?? 0, 2) }}" aria-label="Monthly price for {{ $request->name() }}">
                                @error("approvePrice.$request->id")<small class="ui-error">{{ $message }}</small>@enderror</td>
                            <td style="white-space:nowrap">
                                <button type="button" class="ui-button ui-button-primary ui-button-sm" wire:click="approve({{ $request->id }})" wire:loading.attr="disabled">Approve</button>
                                <input class="ui-input ui-input-sm" type="text" style="width:130px" wire:model="rejectNote.{{ $request->id }}" placeholder="Reason (optional)" aria-label="Reason for declining">
                                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="reject({{ $request->id }})">Decline</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <table class="ui-table ui-table-sm">
        <thead><tr><th>Feature</th><th>Status</th><th>Price a month</th><th></th></tr></thead>
        <tbody>
            @foreach($catalogue as $feature => $offer)
                @php($addon = $active->firstWhere('feature', $feature))
                @continue(! $addon && ! $included->has($feature) && in_array($feature, \App\Services\ClinicAddonService::NOT_SOLD, true))
                <tr wire:key="addon-{{ $feature }}">
                    <td><b>{{ $offer['name'] }}</b></td>
                    @if($included->has($feature))
                        <td><span class="ui-badge">Included in plan</span></td><td class="ui-muted">—</td><td></td>
                    @elseif($addon)
                        <td>
                            <span class="ui-badge ui-badge--good">Add-on</span>
                            <div class="ui-muted">Since {{ $addon->starts_at->format('j M Y') }}@if($addon->ends_at) · ends {{ $addon->ends_at->format('j M Y') }}@endif</div>
                        </td>
                        <td>{{ (float) $addon->monthly_price > 0 ? $addon->currency . ' ' . number_format((float) $addon->monthly_price, 2) : 'Free' }}</td>
                        <td style="white-space:nowrap">
                            @unless($addon->ends_at)
                                <input class="ui-input ui-input-sm" type="number" step="0.01" min="0" style="width:90px" wire:model="newPrice.{{ $addon->id }}" placeholder="New price" aria-label="New monthly price for {{ $offer['name'] }}">
                                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="changePrice({{ $addon->id }})">Change price</button>
                                @if($offer['pack_size'])
                                    <div style="margin-top:4px"><span class="ui-muted">{{ $addon->quantity }} {{ $addon->quantity === 1 ? 'pack' : 'packs' }}, {{ $addon->units }} {{ \App\Support\PlanProduct::packUnit($feature) }}</span>
                                        <input class="ui-input ui-input-sm" type="number" min="1" style="width:70px" wire:model="newPacks.{{ $addon->id }}" placeholder="Packs" aria-label="Number of packs">
                                        <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="changePacks({{ $addon->id }})">Change packs</button>
                                        @error("newPacks.$addon->id")<small class="ui-error">{{ $message }}</small>@enderror</div>
                                @endif
                                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="cancel({{ $addon->id }})" wire:confirm="Cancel {{ $offer['name'] }}? It keeps working until the end of the paid period.">Cancel</button>
                            @endunless
                            @error("newPrice.$addon->id")<small class="ui-error">{{ $message }}</small>@enderror
                        </td>
                    @else
                        @php($typed = $price[$feature] ?? null)
                        @php($charge = is_numeric($typed ?? $offer['price']) ? $prorata((float) ($typed ?? $offer['price'])) : null)
                        <td class="ui-muted">Not added</td>
                        <td><input class="ui-input ui-input-sm" type="number" step="0.01" min="0" style="width:100px" wire:model.live.debounce.400ms="price.{{ $feature }}" placeholder="{{ number_format($offer['price'], 2) }}" aria-label="Monthly price for {{ $offer['name'] }}"></td>
                        <td>
                            @if($offer['pack_size'])<input class="ui-input ui-input-sm" type="number" min="1" style="width:60px" wire:model="packs.{{ $feature }}" placeholder="1" aria-label="Packs"> <span class="ui-muted">× {{ $offer['pack_size'] }} {{ \App\Support\PlanProduct::packUnit($feature) }}</span>@endif
                            <button type="button" class="ui-button ui-button-primary ui-button-sm" wire:click="add('{{ $feature }}')" wire:loading.attr="disabled">Add</button>
                            <div class="ui-muted" style="font-size:12px">{{ $charge ? 'Charges ' . $offer['currency'] . ' ' . number_format($charge[0], 2) . " now ({$charge[1]} of {$charge[2]} days)" : 'Nothing charged now' }}</div>
                            @error("price.$feature")<small class="ui-error">{{ $message }}</small>@enderror
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
    <label class="ui-field" style="margin-top:10px"><span>Reason (optional, kept with the next add-on you add)</span><input class="ui-input ui-input-sm" type="text" wire:model="reason" placeholder="e.g. Asked by phone, 3 months free"></label>
</section>
