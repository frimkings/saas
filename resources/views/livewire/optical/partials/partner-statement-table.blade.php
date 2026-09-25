<table class="w-full text-sm statement">
    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
        <tr><th class="p-2 text-left">Date</th><th class="p-2 text-left">Details</th><th class="p-2 text-right">Charged</th><th class="p-2 text-right">Paid</th><th class="p-2 text-right">Balance</th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        <tr><td class="p-2 text-xs">{{ $fromDate->format('d M Y') }}</td><td class="p-2 font-semibold">Opening balance</td><td class="p-2"></td><td class="p-2"></td><td class="p-2 text-right font-mono font-semibold">{{ number_format($statement['opening'], 2) }}</td></tr>
        @forelse($statement['lines'] as $line)
            <tr>
                <td class="p-2 text-xs whitespace-nowrap">{{ $line['date']->format('d M Y') }}</td>
                <td class="p-2 text-xs">{{ $line['description'] }}</td>
                <td class="p-2 text-right font-mono">{{ $line['type'] === 'charge' ? number_format($line['amount'], 2) : '' }}</td>
                <td class="p-2 text-right font-mono text-emerald-700">{{ $line['type'] === 'payment' ? number_format($line['amount'], 2) : '' }}</td>
                <td class="p-2 text-right font-mono">{{ number_format($line['balance'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="p-4 text-center text-xs text-slate-500">No jobs or payments in this period.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr class="border-t-2 border-slate-300"><th class="p-2 text-left" colspan="2">Closing balance {{ $toDate->format('d M Y') }}</th><th class="p-2 text-right font-mono">{{ number_format($statement['charges'], 2) }}</th><th class="p-2 text-right font-mono">{{ number_format($statement['payments'], 2) }}</th><th class="p-2 text-right font-mono">{{ currency() }} {{ number_format($statement['closing'], 2) }}</th></tr>
    </tfoot>
</table>
