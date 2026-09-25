<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sales Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; }
        .report-header { text-align: center; border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 12px; }
        .clinic-logo { max-height: 58px; max-width: 130px; object-fit: contain; margin-bottom: 6px; }
        .clinic-name { font-size: 18px; font-weight: bold; text-transform: uppercase; }
        .muted { color: #666; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px; font-size: 12px; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>
    <div class="report-header">
        @if(!empty($clinicSettings) && $clinicSettings->logoDataUri())
            <img src="{{ $clinicSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
        @endif
        <div class="clinic-name">{{ $clinicSettings->clinic_name ?? 'Eye Clinic' }}</div>
        <div class="muted">
            {{ $clinicSettings->clinic_address ?? '' }}
            @if(!empty($clinicSettings->clinic_contact)) | Tel: {{ $clinicSettings->clinic_contact }} @endif
            @if(!empty($clinicSettings->clinic_email)) | Email: {{ $clinicSettings->clinic_email }} @endif
        </div>
        <h3>Sales Report: {{ $from }} - {{ $to }}</h3>
        <p class="muted">Generated: {{ $generated_at }}</p>
    </div>

    <table>
        <thead>
            <tr><th>ID</th><th>Date</th><th>Total</th><th>Items</th></tr>
        </thead>
        <tbody>
            @foreach($sales as $sale)
                <tr>
                    <td>{{ $sale->id }}</td>
                    <td>{{ $sale->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ number_format($sale->total_amount, 2) }}</td>
                    <td>
                        <ul>
                        @foreach($sale->items as $it)
                            <li>{{ $it->product->name ?? 'Deleted' }} - {{ $it->quantity }} x {{ number_format($it->selling_price, 2) }}</li>
                        @endforeach
                        </ul>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
