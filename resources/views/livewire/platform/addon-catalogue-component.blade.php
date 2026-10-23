<div class="ui-card" style="margin-top:16px">
    <h3 class="pa-section-title">Add-on prices</h3>
    <p class="ui-muted" style="margin-top:0">What each extra feature costs a clinic per month when added on top of its plan (yearly clinics pay twelve months). Tick "Offer to clinics" to list it on clinics' Subscription page so they can ask for it. You can add any extra to a clinic from its Plan tab, offered or not, at this price or a custom one.</p>
    <div class="ui-table-wrap ui-table-wrap--framed">
        <table class="ui-table ui-table-sm">
            <thead><tr><th>Feature</th><th>Price a month</th><th>Offer to clinics</th><th></th></tr></thead>
            <tbody>
                @foreach($extras as $feature => [$name, $only])
                    <tr wire:key="addon-price-{{ $feature }}">
                        <td><b>{{ $name }}</b>@if($only === 'clinic') <span class="ui-muted">(clinic)</span>@endif</td>
                        <td>
                            <input class="ui-input ui-input-sm" type="number" step="0.01" min="0" style="width:110px" wire:model="rows.{{ $feature }}.price" aria-label="Monthly price for {{ $name }}">
                            @if(in_array($feature, \App\Support\PlanProduct::ADDON_ONLY, true))
                                <span class="ui-muted">per pack of</span>
                                <input class="ui-input ui-input-sm" type="number" min="1" style="width:80px" wire:model="rows.{{ $feature }}.pack" aria-label="{{ ucfirst(\App\Support\PlanProduct::packUnit($feature)) }} in a pack"> <span class="ui-muted">{{ \App\Support\PlanProduct::packUnit($feature) }}</span>
                                @error("rows.$feature.pack")<small class="ui-error">{{ $message }}</small>@enderror
                            @endif
                            @error("rows.$feature.price")<small class="ui-error">{{ $message }}</small>@enderror
                        </td>
                        <td><label class="ui-check" style="margin:0"><input type="checkbox" wire:model="rows.{{ $feature }}.offered"> Offer</label></td>
                        <td><button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="save('{{ $feature }}')" wire:loading.attr="disabled" wire:target="save('{{ $feature }}')">Save</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
