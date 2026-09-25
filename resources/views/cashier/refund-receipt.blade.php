<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $refund->refund_number ?? 'Refund Receipt' }}</title>
    <style>
        body { width: 72mm; margin: 0 auto; padding: 4mm; font: 11px/1.35 Arial, sans-serif; color: #111; }
        .center { text-align: center; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .rule { border-top: 1px dashed #333; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 0; text-align: left; vertical-align: top; }
        .right { text-align: right; }
        .total { font-size: 14px; font-weight: bold; }
        .muted { color: #555; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="center">
        <strong>{{ $clinicSettings->clinic_name ?? 'Eye Clinic' }}</strong><br>
        <span class="muted">{{ $clinicSettings->clinic_address ?? '' }}</span>
        <h3>{{ strtoupper($refund->request_type ?? 'refund') }} RECEIPT</h3>
    </div>

    <div class="row"><span>Refund No.</span><strong>{{ $refund->refund_number }}</strong></div>
    <div class="row"><span>Original TXN</span><span>{{ $refund->sale?->transaction_id }}</span></div>
    <div class="row"><span>Date</span><span>{{ $refund->processed_at?->format('M d, Y H:i') }}</span></div>
    <div class="row"><span>Customer</span><span>{{ $refund->sale?->customer_display_name }}</span></div>

    <div class="rule"></div>
    <table>
        <thead>
            <tr><th>Item</th><th class="right">Qty</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            @foreach($refund->sale?->items ?? [] as $item)
                <tr>
                    <td>{{ $item->display_product_name }}</td>
                    <td class="right">{{ $item->dispensed_quantity }}</td>
                    <td class="right">{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rule"></div>
    <div class="row total">
        <span>REFUNDED</span>
        <span>{{ currency() }} {{ number_format((float) $refund->refunded_amount, 2) }}</span>
    </div>
    <div class="row"><span>Reason code</span><span>{{ \App\Models\RefundLog::REASON_CODES[$refund->reason_code] ?? ucfirst((string) $refund->reason_code) }}</span></div>
    <p><strong>Reason:</strong> {{ $refund->reason }}</p>

    <div class="rule"></div>
    <div class="row"><span>Approved by</span><span>{{ $refund->approvedBy?->name ?? 'N/A' }}</span></div>
    <div class="row"><span>Processed by</span><span>{{ $refund->processedBy?->name ?? 'N/A' }}</span></div>
    <p class="center muted">References the original payment; it is not a new sale receipt.</p>

    <div class="center no-print">
        <button onclick="window.print()">Print</button>
        <a href="{{ route('refunds.receipt.pdf', $refund) }}">Download PDF</a>
    </div>
</body>
</html>
