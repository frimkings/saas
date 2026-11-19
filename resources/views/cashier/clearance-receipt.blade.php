<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Clearance Receipt</title>
    @include('cashier.partials.thermal-styles')
</head>
<body>
    <div class="center">
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
        <div style="font-size:14px; font-weight:900;">{{ $clearance->patient->name ?? 'N/A' }}</div>
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
            <div class="small">—</div>
        @endif
    </div>

    <div class="strong-divider"></div>

    <div class="status-box">Payment: {{ strtoupper($clearance->payment_status) }}</div>

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
