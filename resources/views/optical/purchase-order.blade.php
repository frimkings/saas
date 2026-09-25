@php $settings = \App\Models\Setting::getSettings(); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $order->po_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        .right { text-align: right; }
        .muted { color: #555; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()">Print</button>
    <h1>Purchase order {{ $order->po_number }}</h1>
    <p class="muted">{{ $settings->clinic_name ?? '' }} · {{ $order->ordered_at?->format('d M Y') ?? 'Draft' }}</p>
    <p><strong>Supplier:</strong> {{ $order->supplier?->name }}@if($order->supplier?->contact_person) · {{ $order->supplier->contact_person }}@endif @if($order->supplier?->phone) · {{ $order->supplier->phone }}@endif</p>
    @if($order->expected_date)<p><strong>Needed by:</strong> {{ $order->expected_date->format('d M Y') }}</p>@endif
    @if($order->notes)<p><strong>Notes:</strong> {{ $order->notes }}</p>@endif
    <table>
        <thead><tr><th>Item</th><th class="right">Quantity</th><th class="right">Unit cost</th><th class="right">Total</th></tr></thead>
        <tbody>
            @foreach($order->lines as $line)
                <tr><td>{{ $line->description }}</td><td class="right">{{ $line->quantity_ordered }}</td><td class="right">{{ number_format((float) $line->unit_cost, 2) }}</td><td class="right">{{ number_format($line->quantity_ordered * (float) $line->unit_cost, 2) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="3" class="right">Total</th><th class="right">{{ number_format($order->totalCost(), 2) }}</th></tr></tfoot>
    </table>
</body>
</html>
