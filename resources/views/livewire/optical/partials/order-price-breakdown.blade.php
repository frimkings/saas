{{-- Service quantities and the discount are counted in the browser as they are typed (orderPricing, resources/js/live-totals.js).
     Keyed on the server's figures, so any server change redraws it with fresh ones; saving recalculates everything. --}}
<table class="w-full text-xs" wire:key="price-breakdown-{{ md5(json_encode($pricing)) }}" x-data="orderPricing(@js($pricing))">
    <tbody>
        @foreach($pricing['lines'] as $i => $line)
            <tr><td class="py-0.5 pr-3" x-text="label(pricing.lines[{{ $i }}])">{{ $line['label'] }}</td><td class="py-0.5 text-right font-mono whitespace-nowrap">{{ currency() }} <span x-text="money(amount(pricing.lines[{{ $i }}]))">{{ number_format($line['amount'], 2) }}</span></td></tr>
        @endforeach
        <tr class="border-t border-slate-200"><td class="pt-1 pr-3 font-semibold">Subtotal</td><td class="pt-1 text-right font-mono whitespace-nowrap">{{ currency() }} <span x-text="money(subtotal())">{{ number_format($pricing['subtotal'], 2) }}</span></td></tr>
        <tr x-show="discount() > 0" @if($pricing['discount'] <= 0) x-cloak @endif><td class="py-0.5 pr-3">Discount</td><td class="py-0.5 text-right font-mono whitespace-nowrap text-red-600">− {{ currency() }} <span x-text="money(discount())">{{ number_format($pricing['discount'], 2) }}</span></td></tr>
        <tr class="border-t border-slate-300"><td class="pt-1 pr-3 font-bold">Total</td><td class="pt-1 text-right font-mono font-bold text-teal-800 whitespace-nowrap">{{ currency() }} <span x-text="money(total())">{{ number_format($pricing['total'], 2) }}</span></td></tr>
    </tbody>
</table>
