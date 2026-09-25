<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Clearance Receipt</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { size: 80mm 297mm; margin: 0; }
        html, body {
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans Mono", "Courier New", monospace;
            font-size: 10px;
            line-height: 1.35;
            margin: 0;
            width: 80mm;
        }
        body { padding: 4mm; }
        .center { text-align: center; }
        .receipt-logo { max-height: 16mm; max-width: 34mm; object-fit: contain; margin-bottom: 3mm; }
        .clinic-name { font-size: 15px; font-weight: 900; letter-spacing: .2px; margin-bottom: 2px; text-transform: uppercase; }
        .small { font-size: 9px; }
        .divider { border-top: 1px dashed #000; margin: 6px 0; }
        .strong-divider { border-top: 2px solid #000; margin: 6px 0; }
        .section { margin: 7px 0; }
        .label { font-weight: 700; text-transform: uppercase; font-size: 9px; }
        .doc-title { font-size: 12px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase; margin: 6px 0 2px; }
        .row-line { display: flex; justify-content: space-between; margin: 3px 0; font-size: 10px; }
        .paid-badge { font-size: 11px; font-weight: 900; letter-spacing: 1px; }
        .paid { color: #166534; }
        .unpaid { color: #991b1b; }
        .footer { font-size: 9px; margin-top: 10px; text-align: center; }
        @media print {
            @page { size: 80mm 297mm; margin: 0; }
            body { padding: 4mm; }
        }
    </style>
</head>
<body>
    <div class="center">
        @if(!empty($clinicSettings) && $clinicSettings->logoDataUri())
            <img src="{{ $clinicSettings->logoDataUri() }}" class="receipt-logo" alt="Clinic Logo">
        @endif
        <div class="clinic-name">{{ $clinicSettings->clinic_name ?? 'EYE CLINIC' }}</div>
        @if(!empty($clinicSettings->clinic_address))
            <div class="small">{{ $clinicSettings->clinic_address }}</div>
        @endif
        @if(!empty($clinicSettings->clinic_contact))
            <div class="small">Tel: {{ $clinicSettings->clinic_contact }}</div>
        @endif
        <div class="divider"></div>
        <div class="doc-title">Clearance Receipt</div>
        <div class="small">
            <strong>REF: {{ $clearance->sale?->transaction_id ?? 'CLR-' . str_pad($clearance->id, 6, '0', STR_PAD_LEFT) }}</strong>
        </div>
        <div class="small">{{ $clearance->created_at->format('M d, Y h:i A') }}</div>
    </div>

    <div class="strong-divider"></div>

    <div class="section">
        <div class="label">Patient</div>
        <div style="font-size:11px; font-weight:700;">{{ $clearance->patient->name ?? 'N/A' }}</div>
        <div class="small">ID: {{ $clearance->patient->pxnumber ?? 'N/A' }}</div>
        <div class="small">Contact: {{ $clearance->patient->contact ?? 'N/A' }}</div>
    </div>

    <div class="divider"></div>

    <div class="section">
        <div class="label">Service</div>
        @if($clearance->service)
            <div class="row-line">
                <span>{{ $clearance->service->name }}</span>
                <span>{{ currency() }} {{ number_format($clearance->service->selling_price ?? 0, 2) }}</span>
            </div>
        @else
            <div style="font-size:9px; color:#666;">No specific service recorded</div>
        @endif
    </div>

    <div class="strong-divider"></div>

    <div class="row-line" style="font-size:12px; font-weight:900;">
        <span>PAYMENT STATUS</span>
        <span class="paid-badge {{ $clearance->payment_status === 'Paid' ? 'paid' : 'unpaid' }}">
            {{ strtoupper($clearance->payment_status) }}
        </span>
    </div>

    <div class="divider"></div>

    <div class="footer">
        <div>Cleared by: {{ $clearance->user->name ?? 'Staff' }}</div>
        <div>Date: {{ $clearance->clearance_date }}</div>
        <div class="divider"></div>
        <div>Thank you!</div>
    </div>

    <script>
        window.addEventListener('load', function () {
            var images = Array.prototype.slice.call(document.images || []);
            var imageReady = images.map(function (img) {
                return img.decode ? img.decode().catch(function () {}) : Promise.resolve();
            });

            Promise.all(imageReady).then(function () {
                setTimeout(function () {
                    window.focus();
                    window.print();
                }, 300);
            });
        });
    </script>
</body>
</html>
