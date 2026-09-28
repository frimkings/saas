<section class="pa-section">
<style>.pa-addon-input{box-sizing:border-box;background:#07101f;border:1px solid #273750;color:#edf5ff;border-radius:7px;padding:6px 8px}.pa-addon-input::placeholder{color:#6f86a6}</style>
    <h4>Add-ons</h4>
    <p class="pa-muted" style="margin-top:0">Extra features for this clinic on top of its plan, billed each month. Adding one charges the rest of the current period now; cancelling keeps it working to the end of the paid period.</p>

    @if($requests->isNotEmpty())
        <div class="pa-card" style="border-color:#f59e0b;margin-bottom:12px">
            <b>Waiting for your answer</b>
            <table class="pa-table pa-table-sm" style="margin-top:8px">
                <thead><tr><th>Add-on</th><th>Asked by</th><th>Price a month</th><th></th></tr></thead>
                <tbody>
                    @foreach($requests as $request)
                        <tr wire:key="request-{{ $request->id }}">
                            <td><b>{{ $request->name() }}</b>@if($request->message)<div class="pa-muted">“{{ $request->message }}”</div>@endif</td>
                            <td>{{ $request->requester?->name ?? '—' }}<div class="pa-muted">{{ $request->created_at->diffForHumans() }}</div></td>
                            <td><input class="pa-addon-input" type="number" step="0.01" min="0" style="width:100px" wire:model="approvePrice.{{ $request->id }}" placeholder="{{ number_format($catalogue[$request->feature]['price'] ?? 0, 2) }}" aria-label="Monthly price for {{ $request->name() }}">
                                @error("approvePrice.$request->id")<small class="pa-err">{{ $message }}</small>@enderror</td>
                            <td style="white-space:nowrap">
                                <button type="button" class="pa-btn pa-btn-sm" wire:click="approve({{ $request->id }})" wire:loading.attr="disabled">Approve</button>
                                <input class="pa-addon-input" type="text" style="width:130px" wire:model="rejectNote.{{ $request->id }}" placeholder="Reason (optional)" aria-label="Reason for declining">
                                <button type="button" class="pa-btn alt pa-btn-sm" wire:click="reject({{ $request->id }})">Decline</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <table class="pa-table pa-table-sm">
        <thead><tr><th>Feature</th><th>Status</th><th>Price a month</th><th></th></tr></thead>
        <tbody>
            @foreach($catalogue as $feature => $offer)
                @php($addon = $active->firstWhere('feature', $feature))
                <tr wire:key="addon-{{ $feature }}">
                    <td><b>{{ $offer['name'] }}</b></td>
                    @if($included->has($feature))
                        <td><span class="pa-badge">Included in plan</span></td><td class="pa-muted">—</td><td></td>
                    @elseif($addon)
                        <td>
                            <span class="pa-badge green">Add-on</span>
                            <div class="pa-muted">Since {{ $addon->starts_at->format('j M Y') }}@if($addon->ends_at) · ends {{ $addon->ends_at->format('j M Y') }}@endif</div>
                        </td>
                        <td>{{ (float) $addon->monthly_price > 0 ? $addon->currency . ' ' . number_format((float) $addon->monthly_price, 2) : 'Free' }}</td>
                        <td style="white-space:nowrap">
                            @unless($addon->ends_at)
                                <input class="pa-addon-input" type="number" step="0.01" min="0" style="width:90px" wire:model="newPrice.{{ $addon->id }}" placeholder="New price" aria-label="New monthly price for {{ $offer['name'] }}">
                                <button type="button" class="pa-btn alt pa-btn-sm" wire:click="changePrice({{ $addon->id }})">Change price</button>
                                <button type="button" class="pa-btn alt pa-btn-sm" wire:click="cancel({{ $addon->id }})" wire:confirm="Cancel {{ $offer['name'] }}? It keeps working until the end of the paid period.">Cancel</button>
                            @endunless
                            @error("newPrice.$addon->id")<small class="pa-err">{{ $message }}</small>@enderror
                        </td>
                    @else
                        @php($typed = $price[$feature] ?? null)
                        @php($charge = is_numeric($typed ?? $offer['price']) ? $prorata((float) ($typed ?? $offer['price'])) : null)
                        <td class="pa-muted">Not added</td>
                        <td><input class="pa-addon-input" type="number" step="0.01" min="0" style="width:100px" wire:model.live.debounce.400ms="price.{{ $feature }}" placeholder="{{ number_format($offer['price'], 2) }}" aria-label="Monthly price for {{ $offer['name'] }}"></td>
                        <td>
                            <button type="button" class="pa-btn pa-btn-sm" wire:click="add('{{ $feature }}')" wire:loading.attr="disabled">Add</button>
                            <div class="pa-muted" style="font-size:12px">{{ $charge ? 'Charges ' . $offer['currency'] . ' ' . number_format($charge[0], 2) . " now ({$charge[1]} of {$charge[2]} days)" : 'Nothing charged now' }}</div>
                            @error("price.$feature")<small class="pa-err">{{ $message }}</small>@enderror
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
    <label class="pa-field" style="margin-top:10px"><span>Reason (optional, kept with the next add-on you add)</span><input class="pa-addon-input" type="text" wire:model="reason" placeholder="e.g. Asked by phone, 3 months free"></label>
</section>
