<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $order->order_id }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }

        body {
            width: 80mm;
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            line-height: 1.2;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }

        .receipt {
            width: 100%;
            padding: 8px;
        }

        /* Compact Header */
        .header {
            text-align: center;
            margin-bottom: 8px;
            padding: 8px;
            background: #000;
            color: #fff;
        }

        .header h1 {
            font-size: 18px;
            font-weight: 900;
            margin-bottom: 3px;
            letter-spacing: 2px;
        }

        .clinic-name {
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 3px;
        }

        .clinic-logo {
            max-width: 34mm;
            max-height: 16mm;
            object-fit: contain;
            margin-bottom: 3px;
        }

        .clinic-details {
            font-size: 8px;
            line-height: 1.3;
        }

        /* Compact Info */
        .receipt-info {
            margin-bottom: 6px;
            font-size: 9px;
            border-bottom: 1px dashed #000;
            padding-bottom: 6px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
        }

        .info-label {
            font-weight: 700;
        }

        .info-value {
            font-weight: 900;
        }

        /* Patient - Minimal */
        .patient-section {
            font-size: 9px;
            margin-bottom: 6px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #000;
        }

        .patient-name {
            font-weight: 900;
            font-size: 10px;
            margin-bottom: 2px;
        }

        /* Items - Compact */
        .items {
            margin: 6px 0;
            font-size: 9px;
        }

        .item {
            display: flex;
            justify-content: space-between;
            margin: 4px 0;
            padding: 4px 0;
            border-bottom: 1px solid #eee;
        }

        .item-name {
            font-weight: 700;
            flex: 1;
        }

        .item-price {
            font-weight: 900;
            min-width: 50px;
            text-align: right;
        }

        /* Payment - Minimal */
        @if($received > 0)
        .payment-box {
            text-align: center;
            padding: 6px;
            margin: 6px 0;
            background: #000;
            color: #fff;
        }

        .payment-box .label {
            font-size: 8px;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .payment-box .amount {
            font-size: 20px;
            font-weight: 900;
        }
        @endif

        /* Summary - Compact */
        .summary {
            margin: 6px 0;
            font-size: 10px;
        }

        .sum-row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
            padding: 2px 0;
        }

        .sum-row.total {
            font-size: 13px;
            font-weight: 900;
            margin-top: 4px;
            padding-top: 4px;
            border-top: 2px solid #000;
        }

        .sum-row.balance {
            font-weight: 900;
            background: #000;
            color: #fff;
            padding: 4px 6px;
        }

        /* Status - Minimal */
        .status {
            text-align: center;
            margin: 6px 0;
            padding: 5px;
            border: 2px solid #000;
            font-weight: 900;
            font-size: 11px;
        }

        .status.paid {
            background: #000;
            color: #fff;
        }

        /* Footer - Minimal */
        .footer {
            text-align: center;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #000;
            font-size: 8px;
        }

        .thank {
            font-weight: 900;
            font-size: 11px;
            margin-bottom: 4px;
        }

        /* Print */
        @media print {
            body { width: 80mm; }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Header -->
        <div class="header">
            <h1>RECEIPT</h1>
            @if(!empty($appSettings) && $appSettings->logoDataUri())
                <img src="{{ $appSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
            @endif
            <div class="clinic-name">{{ $appSettings->clinic_name }}</div>
            <div class="clinic-details">{{ $appSettings->clinic_contact }}</div>
        </div>

        <!-- Receipt Info -->
        <div class="receipt-info">
            <div class="info-row">
                <span class="info-label">Receipt:</span>
                <span class="info-value">{{ $order->order_id }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Date:</span>
                <span class="info-value">{{ now()->format('d/m/Y H:i') }}</span>
            </div>
        </div>

        <!-- Patient -->
        <div class="patient-section">
            <div class="patient-name">{{ $order->refraction->consultation->patient->name }}</div>
            @if($order->refraction->consultation->patient->phone)
            <div>{{ $order->refraction->consultation->patient->phone }}</div>
            @endif
        </div>

        <!-- Items -->
        <div class="items">
            <div class="item">
                <span class="item-name">Frame: {{ $order->frame_model_number }}</span>
            </div>
            <div class="item">
                <span class="item-name">Lens Type: {{ $order->refraction->lensType }}</span>
            </div>
            <div class="item" style="border-bottom: 2px solid #000; margin-top: 6px; padding-top: 6px;">
                <span class="item-name" style="font-size: 10px;">Total Cost of Spectacles</span>
                <span class="item-price" style="font-size: 11px;">{{ currency() }} {{ number_format($order->frame_price + $order->lens_price, 2) }}</span>
            </div>
        </div>

        <!-- Payment Received -->
        @if($received > 0)
        <div class="payment-box">
            <div class="label">PAYMENT RECEIVED</div>
            <div class="amount">{{ currency() }} {{ number_format($received, 2) }}</div>
        </div>
        @endif

        <!-- Summary -->
        @php $balance = ($order->frame_price + $order->lens_price) - $order->paid_amount; @endphp
        <div class="summary">
            <div class="sum-row total">
                <span>TOTAL</span>
                <span>{{ currency() }} {{ number_format($order->frame_price + $order->lens_price, 2) }}</span>
            </div>
            <div class="sum-row">
                <span>Paid</span>
                <span>{{ currency() }} {{ number_format($order->paid_amount, 2) }}</span>
            </div>
            @if($balance > 0)
            <div class="sum-row balance">
                <span>BALANCE DUE</span>
                <span>{{ currency() }} {{ number_format($balance, 2) }}</span>
            </div>
            @endif
        </div>

        <!-- Status -->
        <div class="status {{ $balance <= 0 ? 'paid' : '' }}">
            @if($balance <= 0)
                ✓ FULLY PAID
            @elseif($order->paid_amount > 0)
                PARTIAL PAYMENT
            @else
                UNPAID
            @endif
        </div>

        <!-- Pickup -->
        <div style="text-align: center; margin: 6px 0; font-size: 9px; font-weight: 700;">
            Pickup: {{ \Carbon\Carbon::parse($order->pickUpDate)->format('d M Y') }}
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="thank">★ THANK YOU ★</div>
            <p>{{ now()->format('d/m/Y H:i:s') }}</p>
        </div>
    </div>
</body>
</html>
