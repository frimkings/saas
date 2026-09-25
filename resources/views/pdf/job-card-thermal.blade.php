<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
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
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            background: #fff;
            padding: 8px;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 8px;
            padding: 8px 6px;
            background: #000;
            color: #fff;
        }

        .clinic-logo {
            max-width: 34mm;
            max-height: 14mm;
            object-fit: contain;
            margin-bottom: 3px;
        }

        .clinic-name {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: 2px;
            margin-bottom: 2px;
        }

        .job-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }

        .order-id {
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.5px;
        }

        /* Patient Info */
        .patient-info {
            font-size: 11px;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #000;
        }

        .info-row {
            margin: 3px 0;
        }

        .info-row .label {
            font-weight: 700;
            display: inline;
        }

        .info-row .value {
            font-weight: 900;
            display: inline;
            margin-left: 2px;
        }

        /* Section Headers */
        .section-title {
            background: #000;
            color: #fff;
            padding: 4px 6px;
            font-size: 11px;
            font-weight: 900;
            text-align: center;
            margin: 8px 0 5px 0;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* Refraction Table */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 4px;
        }

        th, td {
            border: 1px solid #000;
            padding: 4px 3px;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #000;
            color: #fff;
            font-weight: 900;
            font-size: 10px;
        }

        .eye-label {
            font-weight: 900;
            font-size: 11px;
        }

        .rx-value {
            font-weight: 700;
        }

        /* Specs */
        .specs-grid {
            font-size: 11px;
            margin-bottom: 4px;
        }

        .spec-row {
            padding: 4px 0;
            border-bottom: 1px solid #ccc;
        }

        .spec-row:last-child {
            border-bottom: none;
        }

        .spec-label {
            font-weight: 700;
        }

        .spec-value {
            font-weight: 900;
        }

        /* Notes Box */
        .notes-box {
            border: 1px solid #000;
            padding: 6px;
            font-size: 11px;
            min-height: 40px;
            margin-bottom: 8px;
            background: #fff;
        }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px dashed #000;
            font-size: 10px;
            font-style: italic;
        }

        @media print {
            body {
                width: 80mm;
                padding: 8px;
            }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="header">
        @if(!empty($appSettings) && $appSettings->logoDataUri())
            <img src="{{ $appSettings->logoDataUri() }}" class="clinic-logo" alt="Logo">
        @endif
        <div class="clinic-name">{{ strtoupper($appSettings->clinic_name ?? 'OPTICAL CLINIC') }}</div>
        <div class="job-title">LAB JOB ORDER</div>
        <div class="order-id">{{ $order->order_id }}</div>
    </div>

    <!-- Patient Info -->
    <div class="patient-info">
        <div class="info-row">
            <span class="label">Patient:</span>
            <span class="value">{{ strtoupper($order->refraction->consultation->patient->name ?? '—') }}</span>
        </div>
        <div class="info-row">
            <span class="label">Phone:</span>
            <span class="value">{{ $order->refraction->consultation->patient->contact ?? '—' }}</span>
        </div>
        <div class="info-row">
            <span class="label">Order Date:</span>
            <span class="value">{{ $order->created_at->format('d M, Y') }}</span>
        </div>
        <div class="info-row">
            <span class="label">Pickup Date:</span>
            <span class="value">{{ \Carbon\Carbon::parse($order->pickUpDate)->format('d M, Y') }}</span>
        </div>
    </div>

    <!-- Prescription -->
    <div class="section-title">Prescription (Rx)</div>
    <table>
        <thead>
            <tr>
                <th style="width:12%">EYE</th>
                <th style="width:46%">Prescription</th>
                <th style="width:22%">VA</th>
                <th style="width:20%">ADD</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="eye-label">OD</td>
                <td class="rx-value">{{ $order->refraction->refractionOD }}</td>
                <td>{{ $order->refraction->refractionOD_distance_va ?? '—' }}</td>
                <td>{{ $order->refraction->refractionOD_ADD ?? '--' }}</td>
            </tr>
            <tr>
                <td class="eye-label">OS</td>
                <td class="rx-value">{{ $order->refraction->refractionOS }}</td>
                <td>{{ $order->refraction->refractionOS_distance_va ?? '—' }}</td>
                <td>{{ $order->refraction->refractionOS_ADD ?? '--' }}</td>
            </tr>
        </tbody>
    </table>

    <!-- Specifications -->
    <div class="section-title">Specifications</div>
    <div class="specs-grid">
        <div class="spec-row">
            <span class="spec-label">PD (mm): </span>
            <span class="spec-value">{{ $order->refraction->pd ?? 'N/S' }}</span>
        </div>
        <div class="spec-row">
            <span class="spec-label">Lens Type: </span>
            <span class="spec-value">{{ $order->refraction->lensType ?? 'Standard' }}</span>
        </div>
        <div class="spec-row">
            <span class="spec-label">Frame: </span>
            @php
                $frameName  = null;
                $frameBatch = null;
                // 0. Patient supplied the frame
                if ($order->own_frame) {
                    $frameName = strtoupper($order->frame_model_number ?: "Patient's own frame");
                }
                // 1. Linked frame product
                if (!$frameName && $order->frameProduct) {
                    $frameName  = $order->frameProduct->name;
                    $frameBatch = $order->frameProduct->batch_number;
                }
                // 2. Frame from POS sale items
                if (!$frameName) {
                    $saleItems = $order->refraction?->consultation?->sale?->items ?? collect();
                    $frameItem = $saleItems->first(fn($i) =>
                        str_contains(strtolower((string) optional(optional($i->product)->category)->name), 'frame')
                    );
                    if ($frameItem) {
                        $frameName  = $frameItem->product?->name;
                        $frameBatch = $frameItem->product?->batch_number;
                    }
                }
                // 3. Manually entered (skip placeholder)
                if (!$frameName) {
                    $raw = trim($order->frame_model_number ?? '');
                    $frameName = ($raw && strtolower($raw) !== 'to be assigned') ? $raw : '—';
                }
            @endphp
            <span class="spec-value">{{ $frameName }}{{ $frameBatch ? ' (Batch: ' . $frameBatch . ')' : '' }}</span>
        </div>
        <div class="spec-row">
            <span class="spec-label">Dispensed By: </span>
            <span class="spec-value">{{ strtoupper($order->user?->name ?? '—') }}</span>
        </div>
    </div>

    <!-- Lab Notes -->
    <div class="section-title">Lab Notes</div>
    <div class="notes-box">
        @if($order->refraction->refractionnotes)
            {{ $order->refraction->refractionnotes }}
        @endif
    </div>

    <!-- Footer -->
    <div class="footer">
        @if($order->own_frame)
            <strong>PATIENT'S OWN FRAME - GLAZED AT OWNER'S RISK</strong><br>
        @endif
        <strong>VERIFY ALL MEASUREMENTS BEFORE SURFACING</strong><br>
        Printed: {{ now()->format('d/m/Y H:i') }}
    </div>

</body>
</html>
