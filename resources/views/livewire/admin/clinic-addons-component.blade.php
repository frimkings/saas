<div class="card mt-3">
    <div class="card-header"><h3 class="card-title">Add-ons</h3></div>
    <div class="card-body">
        <p class="text-muted small mb-3">Extra features on top of your plan, billed each month with your subscription. When one is added, the rest of the current period is charged straight away.</p>

        @if($active->isNotEmpty())
            <h6 class="font-weight-bold">Your add-ons</h6>
            <ul class="list-unstyled mb-3">
                @foreach($active as $addon)
                    <li class="d-flex flex-wrap justify-content-between border-bottom py-2">
                        <span><i class="fas fa-check-circle text-success mr-1"></i> <b>{{ $addon->name() }}</b>
                            <span class="text-muted small">since {{ $addon->starts_at->format('j M Y') }}@if($addon->ends_at) · ends {{ $addon->ends_at->format('j M Y') }}@endif</span></span>
                        <span>{{ (float) $addon->monthly_price > 0 ? $addon->currency . ' ' . number_format((float) $addon->monthly_price, 2) . ' a month' : 'Free' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if($offers->isNotEmpty())
            <h6 class="font-weight-bold">Available to add</h6>
            <ul class="list-unstyled mb-0">
                @foreach($offers as $feature => $offer)
                    <li class="border-bottom py-2" wire:key="offer-{{ $feature }}">
                        <div class="d-flex flex-wrap justify-content-between align-items-center" style="gap:.5rem">
                            <span><b>{{ $offer['name'] }}</b> <span class="text-muted">{{ $offer['currency'] }} {{ number_format($offer['price'], 2) }} a month</span></span>
                            @if(in_array($feature, $pending, true))
                                <span class="badge badge-warning">Requested · waiting for approval</span>
                            @elseif($requesting !== $feature)
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="ask('{{ $feature }}')">Request</button>
                            @endif
                        </div>
                        @if($requesting === $feature)
                            <div class="mt-2">
                                <textarea class="form-control form-control-sm" rows="2" wire:model="message" placeholder="Anything we should know? (optional)" aria-label="Message with the request"></textarea>
                                @error('message')<small class="text-danger">{{ $message }}</small>@enderror
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="send" wire:loading.attr="disabled">Send request</button>
                                    <button type="button" class="btn btn-sm btn-link" wire:click="$set('requesting', null)">Cancel</button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif($active->isEmpty())
            <p class="text-muted mb-0">No add-ons are available right now. Contact us if you need a feature your plan doesn't include.</p>
        @endif
    </div>
</div>
