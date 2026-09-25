<table class="w-full text-xs">
    <tbody>
        @foreach($pricing['lines'] as $line)
            <tr><td class="py-0.5 pr-3">{{ $line['label'] }}</td><td class="py-0.5 text-right font-mono whitespace-nowrap">{{ currency() }} {{ number_format($line['amount'], 2) }}</td></tr>
        @endforeach
        <tr class="border-t border-slate-200"><td class="pt-1 pr-3 font-semibold">Subtotal</td><td class="pt-1 text-right font-mono whitespace-nowrap">{{ currency() }} {{ number_format($pricing['subtotal'], 2) }}</td></tr>
        @if($pricing['discount'] > 0)
            <tr><td class="py-0.5 pr-3">Discount</td><td class="py-0.5 text-right font-mono whitespace-nowrap text-red-600">− {{ currency() }} {{ number_format($pricing['discount'], 2) }}</td></tr>
        @endif
        <tr class="border-t border-slate-300"><td class="pt-1 pr-3 font-bold">Total</td><td class="pt-1 text-right font-mono font-bold text-teal-800 whitespace-nowrap">{{ currency() }} {{ number_format($pricing['total'], 2) }}</td></tr>
    </tbody>
</table>
