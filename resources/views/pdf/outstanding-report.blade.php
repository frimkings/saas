<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .clinic-logo { max-height: 58px; max-width: 130px; object-fit: contain; margin-bottom: 6px; }
        .clinic-name { font-size: 18px; font-weight: bold; text-transform: uppercase; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .text-right { text-align: right; }
        .footer { margin-top: 30px; font-weight: bold; text-align: right; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        @if(!empty($appSettings) && $appSettings->logoDataUri())
            <img src="{{ $appSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
        @endif
        <div class="clinic-name">{{ $appSettings->clinic_name ?? 'OPTICAL CLINIC' }}</div>
        <div class="muted">
            {{ $appSettings->clinic_address ?? '' }}
            @if(!empty($appSettings->clinic_contact)) | Tel: {{ $appSettings->clinic_contact }} @endif
            @if(!empty($appSettings->clinic_email)) | Email: {{ $appSettings->clinic_email }} @endif
        </div>
        <h2>OUTSTANDING BALANCES</h2>
        <p>Report Generated: {{ $generated_at->format('d M, Y H:i A') }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Patient Name</th>
                <th>Phone</th>
                <th>Order ID</th>
                <th>Status</th>
                <th class="text-right">Total Bill</th>
                <th class="text-right">Total Paid</th>
                <th class="text-right">Balance Due</th>
            </tr>
        </thead>
        <tbody>
            @php $totalDue = 0; @endphp
            @foreach($debts as $order)
                @php 
                    $bill = $order->total;
                    $due = $bill - $order->paid_amount;
                    $totalDue += $due;
                @endphp
                <tr>
                    <td>{{ $order->display_customer_name }}</td>
                    <td>{{ $order->display_customer_phone ?? '—' }}</td>
                    <td>{{ $order->order_id }}</td>
                    <td>{{ $order->status }}</td>
                    <td class="text-right">{{ currency() }} {{ number_format($bill, 2) }}</td>
                    <td class="text-right">{{ currency() }} {{ number_format($order->paid_amount, 2) }}</td>
                    <td class="text-right" style="color: red; font-weight: bold;">{{ currency() }} {{ number_format($due, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        TOTAL ACCOUNTS RECEIVABLE: {{ currency() }} {{ number_format($totalDue, 2) }}
    </div>
</body>
</html>
