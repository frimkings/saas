<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 18px; margin: 0 0 4px; text-align: center; text-transform: uppercase; }
        h2 { font-size: 15px; margin: 12px 0 4px; text-align: center; }
        .muted { color: #6b7280; font-size: 11px; }
        .report-header { text-align: center; border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 12px; }
        .clinic-logo { max-height: 58px; max-width: 130px; object-fit: contain; margin-bottom: 6px; }
        .status { text-align: center; font-weight: bold; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 5px 7px; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; text-align: left; text-transform: uppercase; font-size: 10px; }
        .amount { text-align: right; white-space: nowrap; }
        .heading td { background: #eef2f7; font-weight: bold; text-transform: uppercase; }
        .total td { font-weight: bold; border-top: 2px solid #9ca3af; }
        .final td { background: #ecfdf3; font-weight: bold; border-top: 2px solid #16a34a; }
        .line-note { color: #6b7280; font-size: 10px; margin-top: 2px; }
        .notes { margin-top: 12px; color: #6b7280; font-size: 10px; }
        .preview-actions { margin-bottom: 12px; text-align: right; }
        .preview-actions button { padding: 6px 10px; }
        @media print { .preview-actions { display: none; } }
    </style>
</head>
<body>
    @if(!empty($preview))
        <div class="preview-actions"><button type="button" onclick="window.print()">Print</button></div>
    @endif
    <div class="report-header">
        @if($clinicSettings->logoDataUri())
            <img src="{{ $clinicSettings->logoDataUri() }}" class="clinic-logo" alt="Logo">
        @endif
        <h1>{{ $clinicSettings->clinic_name }}</h1>
        <div class="muted">{{ $clinicSettings->clinic_address }}</div>
        @if($clinicSettings->clinic_contact || $clinicSettings->clinic_email)
            <div class="muted">
                @if($clinicSettings->clinic_contact)Tel: {{ $clinicSettings->clinic_contact }}@endif
                @if($clinicSettings->clinic_email){{ $clinicSettings->clinic_contact ? ' | ' : '' }}Email: {{ $clinicSettings->clinic_email }}@endif
            </div>
        @endif
        <h2>{{ $title }}</h2>
        <div class="muted">{{ \Carbon\Carbon::parse($from)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($to)->format('M d, Y') }}</div>
        <div class="muted">Generated {{ $generated_at->format('M d, Y h:i A') }} by {{ $generated_by }}</div>
        @if(!empty($status))<div class="status">{{ $status }}</div>@endif
    </div>

    <table>
        <thead><tr><th>Description</th><th class="amount">Amount ({{ currency() }})</th></tr></thead>
        <tbody>
            @foreach($rows as $row)
                <tr class="{{ in_array($row['type'], ['heading', 'total', 'final'], true) ? $row['type'] : '' }}">
                    <td>{{ $row['label'] }}@if(!empty($row['note']))<div class="line-note">{{ $row['note'] }}</div>@endif</td>
                    <td class="amount">{{ isset($row['amount']) ? (($row['amount'] < 0 ? '− ' : '').number_format(abs($row['amount']), 2)) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(!empty($notes))
        <div class="notes">@foreach($notes as $note)<p>{{ $note }}</p>@endforeach</div>
    @endif
</body>
</html>
