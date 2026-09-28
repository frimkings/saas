<div class="pa-card" style="margin-top:16px">
<style>.pa-addon-input{box-sizing:border-box;background:#07101f;border:1px solid #273750;color:#edf5ff;border-radius:7px;padding:6px 8px}.pa-addon-input::placeholder{color:#6f86a6}</style>
    <h3 class="pa-section-title">Add-on prices</h3>
    <p class="pa-muted" style="margin-top:0">What each extra feature costs a clinic per month when added on top of its plan (yearly clinics pay twelve months). Tick "Offer to clinics" to list it on clinics' Subscription page so they can ask for it. You can add any extra to a clinic from its Plan tab, offered or not, at this price or a custom one.</p>
    <div class="pa-table-wrap">
        <table class="pa-table pa-table-sm">
            <thead><tr><th>Feature</th><th>Price a month</th><th>Offer to clinics</th><th></th></tr></thead>
            <tbody>
                @foreach($extras as $feature => [$name, $only])
                    <tr wire:key="addon-price-{{ $feature }}">
                        <td><b>{{ $name }}</b>@if($only === 'clinic') <span class="pa-muted">(clinic)</span>@endif</td>
                        <td>
                            <input class="pa-addon-input" type="number" step="0.01" min="0" style="width:110px" wire:model="rows.{{ $feature }}.price" aria-label="Monthly price for {{ $name }}">
                            @error("rows.$feature.price")<small class="pa-err">{{ $message }}</small>@enderror
                        </td>
                        <td><label class="pa-check" style="margin:0"><input type="checkbox" wire:model="rows.{{ $feature }}.offered"> Offer</label></td>
                        <td><button type="button" class="pa-btn alt pa-btn-sm" wire:click="save('{{ $feature }}')" wire:loading.attr="disabled" wire:target="save('{{ $feature }}')">Save</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
