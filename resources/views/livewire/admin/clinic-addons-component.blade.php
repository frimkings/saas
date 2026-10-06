<div class="card overflow-hidden rounded-xl border border-slate-200 bg-white mt-4">
    <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2"><h3 class="font-semibold">Add-ons</h3></div>
    <div class="card-body p-4">
        <p class="text-slate-500 text-sm mb-4">Extra features on top of your plan, billed each month with your subscription. When one is added, the rest of the current period is charged straight away.</p>

        @if($active->isNotEmpty())
            <h6 class="font-semibold">Your add-ons</h6>
            <ul class="list-none pl-0 mb-4">
                @foreach($active as $addon)
                    <li class="flex flex-wrap justify-between border-b border-slate-200 py-2">
                        <span><i class="fas fa-check-circle text-green-700 mr-1"></i> <b>{{ $addon->name() }}</b>
                            <span class="text-slate-500 text-sm">since {{ $addon->starts_at->format('j M Y') }}@if($addon->ends_at) · ends {{ $addon->ends_at->format('j M Y') }}@endif</span></span>
                        <span>{{ (float) $addon->monthly_price > 0 ? $addon->currency . ' ' . number_format((float) $addon->monthly_price, 2) . ' a month' : 'Free' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if($offers->isNotEmpty())
            <h6 class="font-semibold">Available to add</h6>
            <ul class="list-none pl-0 mb-0">
                @foreach($offers as $feature => $offer)
                    <li class="border-b border-slate-200 py-2" wire:key="offer-{{ $feature }}">
                        <div class="flex flex-wrap justify-between items-center" style="gap:.5rem">
                            <span><b>{{ $offer['name'] }}</b> <span class="text-slate-500">{{ $offer['currency'] }} {{ number_format($offer['price'], 2) }} a month</span></span>
                            @if(in_array($feature, $pending, true))
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">Requested · waiting for approval</span>
                            @elseif($requesting !== $feature)
                                <button type="button" class="btn ui-button ui-button-sm ui-button-secondary" wire:click="ask('{{ $feature }}')">Request</button>
                            @endif
                        </div>
                        @if($requesting === $feature)
                            <div class="mt-2">
                                <textarea class="form-control ui-input ui-input-sm" rows="2" wire:model="message" placeholder="Anything we should know? (optional)" aria-label="Message with the request"></textarea>
                                @error('message')<small class="text-red-700">{{ $message }}</small>@enderror
                                <div class="mt-2">
                                    <button type="button" class="btn ui-button ui-button-sm ui-button-primary" wire:click="send" wire:loading.attr="disabled">Send request</button>
                                    <button type="button" class="btn ui-button ui-button-sm ui-button-link" wire:click="$set('requesting', null)">Cancel</button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif($active->isEmpty())
            <p class="text-slate-500 mb-0">No add-ons are available right now. Contact us if you need a feature your plan doesn't include.</p>
        @endif
    </div>
</div>
