<div class="{{ $optical ? 'ui-panel p-6 space-y-4 max-w-2xl' : 'p-6' }}" data-tour="discount-limits">
    <div class="{{ $optical ? 'space-y-4' : 'rounded-lg border border-slate-200 bg-white p-4 shadow-sm' }}" style="max-width:720px">
        <h5 class="{{ $optical ? 'text-sm font-semibold text-slate-900' : 'font-semibold mb-1' }}"><i class="fas fa-percent {{ $optical ? '' : 'mr-1 text-teal-700' }}"></i> Discount limits</h5>
        <p class="{{ $optical ? 'ui-muted text-xs' : 'text-sm text-slate-500 mb-4' }}">
            The most each role may take off a sale without approval, as a percent of what can be discounted.
            Above it, the clinic till sends the discount to a Manager or the Owner to approve, and the optical till
            asks for a Manager or the Owner. You (the Owner) have no limit. Someone with two roles gets the higher limit.
            Nobody can approve their own request except the Owner.
        </p>

        <form wire:submit.prevent="save" class="ui-form space-y-4">
            <div class="overflow-x-auto">
                <table class="ui-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left">Role</th>
                            @foreach($lines as $label)<th class="text-left">{{ $label }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($roles as $role => $label)
                            <tr>
                                <td class="font-semibold">{{ $label }}</td>
                                @foreach($lines as $line => $lineLabel)
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <input type="number" min="0" max="100" step="1" class="ui-input !w-24"
                                                   aria-label="{{ $label }}, {{ $lineLabel }}, percent"
                                                   wire:model="limits.{{ $role }}.{{ $line }}">
                                            <span class="text-slate-500">%</span>
                                        </div>
                                        @error("limits.{$role}.{$line}")<small class="text-xs text-red-600">{{ $message }}</small>@enderror
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ui-muted text-xs">0% = every discount needs approval. 100% = any discount.</p>
            <button type="submit" class="ui-button ui-button-primary" wire:loading.attr="disabled" wire:target="save">
                <i class="fas fa-save"></i> Save discount limits
            </button>
        </form>
    </div>
    @if(! $optical && array_key_exists(\App\Support\Access\DiscountLimits::CLINICAL, $lines))
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm mt-4" style="max-width:720px" data-tour="part-payment-setting">
            <h5 class="font-semibold mb-1"><i class="fas fa-clock mr-1 text-teal-700"></i> Part payment at the clinic till</h5>
            <p class="text-sm text-slate-500 mb-3">
                Frames and lenses can always be part-paid. Turn this on to also let a registered patient pay part of a bill for
                drugs and services (for example a test). The rest goes to Outstanding Balances until it is collected.
            </p>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" class="mt-1" wire:model="partPaymentAllItems">
                <span><b>Allow part payment on drugs and services</b><br><span class="text-slate-500">Off: drugs and services must be paid in full before checkout.</span></span>
            </label>
            <button type="button" class="ui-button ui-button-primary mt-3" wire:click="savePartPayment" wire:loading.attr="disabled" wire:target="savePartPayment">
                <i class="fas fa-save"></i> Save
            </button>
        </div>
    @endif
</div>
