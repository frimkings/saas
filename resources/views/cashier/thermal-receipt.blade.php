<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ ($sale->bill_status ?? 'finalized') === 'open' ? 'Provisional Bill' : 'Final Receipt' }} - {{ $sale->transaction_id }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        @page {
            size: 80mm 297mm;
            margin: 0;
        }

        html,
        body {
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans Mono", "Courier New", monospace;
            font-size: 10px;
            line-height: 1.35;
            margin: 0;
            width: 80mm;
        }

        body {
            padding: 4mm;
        }

        .center {
            text-align: center;
        }

        .receipt-logo {
            max-height: 16mm;
            max-width: 34mm;
            object-fit: contain;
            margin-bottom: 3mm;
        }

        .clinic-name {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: .2px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .small {
            font-size: 9px;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }

        .strong-divider {
            border-top: 2px solid #000;
            margin: 6px 0;
        }

        .section {
            margin: 7px 0;
        }

        .label {
            font-weight: 700;
            text-transform: uppercase;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        th,
        td {
            font-size: 9px;
            padding: 3px 1px;
            vertical-align: top;
        }

        th {
            border-bottom: 1px solid #000;
            font-weight: 700;
            text-align: left;
        }

        .col-item {
            width: 47%;
        }

        .col-qty {
            text-align: center;
            width: 11%;
        }

        .col-price,
        .col-total {
            text-align: right;
            width: 21%;
        }

        .money-row {
            clear: both;
            font-size: 10px;
            margin: 3px 0;
            overflow: hidden;
            width: 100%;
        }

        .money-row .left {
            float: left;
            width: 48%;
        }

        .money-row .right {
            float: right;
            text-align: right;
            width: 52%;
        }

        .grand-total {
            font-size: 13px;
            font-weight: 900;
            margin-top: 5px;
        }

        .muted {
            color: #555;
        }

        .footer {
            font-size: 9px;
            margin-top: 10px;
            text-align: center;
        }

        @media print {
            @page {
                size: 80mm 297mm;
                margin: 0;
            }

            body {
                padding: 4mm;
            }
        }

        /* ── Refund watermark ────────────────────────────── */
        .refund-watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 36px;
            font-weight: 900;
            color: rgba(220, 38, 38, 0.18);
            letter-spacing: 4px;
            text-transform: uppercase;
            white-space: nowrap;
            pointer-events: none;
            z-index: 9999;
            user-select: none;
            border: 5px solid rgba(220, 38, 38, 0.18);
            padding: 6px 14px;
        }

        .refund-notice {
            border: 2px solid #dc2626;
            color: #dc2626;
            text-align: center;
            font-weight: 900;
            font-size: 11px;
            padding: 5px;
            margin: 8px 0 4px;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
@php
    $currency = currency() . ' ';
    $grossAmount = $sale->items->sum('subtotal');
    $discountAmount = (float) ($sale->discount_amount ?? 0);
    $amountPaid = (float) ($sale->amount_paid ?? 0);
    $changeAmount = (float) ($change ?? 0);
    $balanceAmount = max(0, (float) $sale->total_amount - $amountPaid);
@endphp

@if($sale->is_refunded)
    <div class="refund-watermark">REFUNDED</div>
@endif

    <div class="center">
        @if(!empty($clinicSettings) && $clinicSettings->logoDataUri())
            <img src="{{ $clinicSettings->logoDataUri() }}" class="receipt-logo" alt="Clinic Logo">
        @endif

        <div class="clinic-name">{{ $clinicSettings->clinic_name ?? 'PHARMACY POS' }}</div>
        @if(!empty($clinicSettings->clinic_address))
            <div class="small">{{ $clinicSettings->clinic_address }}</div>
        @endif
        @if(!empty($clinicSettings->clinic_contact))
            <div class="small">Tel: {{ $clinicSettings->clinic_contact }}</div>
        @endif
        @if(!empty($clinicSettings->clinic_email))
            <div class="small">{{ $clinicSettings->clinic_email }}</div>
        @endif

        <div class="divider"></div>
        <div class="small"><strong>TXN: {{ $sale->transaction_id }}</strong></div>
        <div class="small">{{ $sale->created_at->format('M d, Y h:i A') }}</div>
        @if(($sale->bill_status ?? 'finalized') === 'open')
            <div class="small"><strong>PROVISIONAL OPEN BILL — NOT A FINAL RECEIPT</strong></div>
            <div class="small">Version {{ $sale->bill_version ?? 1 }}@if($sale->expires_at) · Expires {{ $sale->expires_at->format('M d, Y h:i A') }}@endif</div>
        @else
            <div class="small"><strong>FINAL RECEIPT · VERSION {{ $sale->bill_version ?? 1 }}</strong></div>
        @endif
    </div>

    @if($sale->is_refunded)
    <div class="refund-notice">
        &#9888; THIS SALE HAS BEEN REFUNDED &#9888;
        @if($sale->refunded_at)
        <div style="font-size:8px; font-weight:400; margin-top:2px;">
            Refunded on {{ \Carbon\Carbon::parse($sale->refunded_at)->format('M d, Y h:i A') }}
        </div>
        @endif
    </div>
    @endif

    @if($sale->patient)
        <div class="section">
            <div class="label">Patient</div>
            <div>{{ $sale->patient->name }}</div>
            <div class="small">Contact: {{ $sale->patient->contact ?? 'N/A' }}</div>
            <div class="small">ID: {{ $sale->patient->pxnumber ?? 'N/A' }}</div>
        </div>
        <div class="divider"></div>
    @else
        <div class="section">
            <div class="label">Customer</div>
            <div>{{ $sale->customer_display_name }}</div>
            @if($sale->customer_phone)<div class="small">Contact: {{ $sale->customer_phone }}</div>@endif
        </div>
        <div class="divider"></div>
    @endif

    <table>
        <thead>
            <tr>
                <th class="col-item">ITEM</th>
                <th class="col-qty">QTY</th>
                <th class="col-price">PRICE</th>
                <th class="col-total">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td class="col-item">{{ \Illuminate\Support\Str::limit($item->display_product_name, 19) }}</td>
                    <td class="col-qty">{{ $item->dispensed_quantity }}</td>
                    <td class="col-price">{{ $currency }}{{ number_format($item->selling_price, 2) }}</td>
                    <td class="col-total">{{ $currency }}{{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="strong-divider"></div>

    @if($discountAmount > 0)
        <div class="money-row muted">
            <span class="left">SUBTOTAL</span>
            <span class="right">{{ $currency }}{{ number_format($grossAmount, 2) }}</span>
        </div>
        <div class="money-row">
            <span class="left">
                DISCOUNT
                @if($sale->discount_type === 'percentage')
                    ({{ number_format((float) $sale->discount_value, 0) }}%)
                @endif
            </span>
            <span class="right">-{{ $currency }}{{ number_format($discountAmount, 2) }}</span>
        </div>
        @if($sale->approvedBy)
            <div class="small muted">Approved by: {{ $sale->approvedBy->name }}</div>
        @endif
    @endif

    <div class="money-row grand-total">
        <span class="left">TOTAL</span>
        <span class="right">{{ $currency }}{{ number_format($sale->total_amount, 2) }}</span>
    </div>

    @if($sale->paymentTransactions->isNotEmpty())
        @foreach($sale->paymentTransactions as $payment)
            <div class="money-row">
                <span class="left">PAID ({{ strtoupper($payment->payment_method) }})</span>
                <span class="right">{{ $currency }}{{ number_format($payment->amount, 2) }}</span>
            </div>
        @endforeach

        @if($sale->paymentTransactions->count() > 1)
            <div class="money-row">
                <span class="left">TOTAL PAID</span>
                <span class="right">{{ $currency }}{{ number_format($amountPaid, 2) }}</span>
            </div>
        @endif
    @elseif($amountPaid > 0)
        <div class="money-row">
            <span class="left">PAID</span>
            <span class="right">{{ $currency }}{{ number_format($amountPaid, 2) }}</span>
        </div>
    @endif

    @if($changeAmount > 0)
        <div class="money-row">
            <span class="left">CHANGE</span>
            <span class="right">{{ $currency }}{{ number_format($changeAmount, 2) }}</span>
        </div>
    @endif

    @if($balanceAmount > 0)
        <div class="money-row">
            <span class="left">BALANCE DUE</span>
            <span class="right">{{ $currency }}{{ number_format($balanceAmount, 2) }}</span>
        </div>
    @endif

    <div class="divider"></div>

    @if(($sale->business_line ?? null) === 'optical')
        @php
            $opticalOrder = \App\Models\LensOrder::where('sale_id', $sale->id)->first();
            $opticalSettings = \App\Models\OpticalSetting::first();
        @endphp
        @if($opticalOrder && $opticalOrder->work_type === 'prescription')
            <div class="footer">
                @if($opticalOrder->warranty_expires_at)
                    <div>Lens warranty until {{ $opticalOrder->warranty_expires_at->format('d M Y') }}</div>
                @elseif((int) ($opticalSettings?->warranty_months ?? 0) > 0)
                    <div>Lens warranty: {{ (int) $opticalSettings->warranty_months }} months from collection</div>
                @endif
                @if($opticalSettings?->optical_disclaimer)<div>{{ $opticalSettings->optical_disclaimer }}</div>@endif
            </div>
            <div class="divider"></div>
        @endif
    @endif

    <div class="footer">
        <div>Thank you for your business!</div>
        <div>Please keep this receipt for your records.</div>
        <div class="divider"></div>
        <div>Served by: {{ $sale->user->name ?? 'Staff' }}</div>
        <div>{{ now()->format('M d, Y h:i A') }}</div>
    </div>
</body>
</html>
