<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Account Ledger – {{ $patient->name }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
@page { size: A4 portrait; margin: 0; }
html, body {
    width: 210mm; height: 297mm;
    font-family: Arial, sans-serif;
    font-size: 9.5pt; color: #1a1a1a; background: #fff;
}
.page { position: relative; width: 210mm; min-height: 297mm; padding: 12mm 14mm 14mm 14mm; background: #fff; }

.letterhead { display:table; width:100%; padding-bottom:7px; margin-bottom:9px; border-bottom:2px solid #0e7490; }
.lh-left  { display:table-cell; vertical-align:middle; width:65px; }
.lh-right { display:table-cell; vertical-align:middle; padding-left:10px; }
.lh-logo  { max-height:50px; max-width:60px; object-fit:contain; }
.lh-name  { font-size:13pt; font-weight:bold; text-transform:uppercase; color:#0e7490; letter-spacing:1px; }
.lh-sub   { font-size:8pt; color:#555; margin-top:1px; }

.doc-title { margin-bottom:10px; }
.doc-title h2 { font-size:16pt; font-weight:bold; color:#0e7490; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px; }

.patient-box { border:1px solid #cffafe; background:#f0fdff; border-radius:4px; padding:8px 10px; margin-bottom:10px; display:table; width:100%; }
.pb-left  { display:table-cell; vertical-align:middle; width:60%; }
.pb-right { display:table-cell; vertical-align:middle; text-align:right; font-size:8.5pt; }

.summary-row { display:table; width:100%; margin-bottom:10px; }
.summary-cell { display:table-cell; width:33%; text-align:center; padding:6px; border-radius:4px; font-size:9pt; }
.sum-charge  { background:#fef2f2; border:1px solid #fecaca; }
.sum-payment { background:#f0fdf4; border:1px solid #bbf7d0; }
.sum-balance { background:#fffbeb; border:1px solid #fde68a; }

.ledger-table { width:100%; border-collapse:collapse; margin-top:4px; }
.ledger-table thead tr { background:#0e7490; color:#fff; }
.ledger-table th { padding:5px 6px; font-size:8pt; text-transform:uppercase; letter-spacing:.3px; }
.ledger-table td { padding:4px 6px; font-size:9pt; border-bottom:1px solid #e5e7eb; vertical-align:middle; }
.ledger-table tbody tr.row-charge  { }
.ledger-table tbody tr.row-payment { background:#f0fdf4; }
.ledger-table tbody tr.row-refund  { background:#ecfeff; }
.ledger-table tfoot td { background:#f8f9fa; font-weight:bold; padding:5px 6px; border-top:2px solid #0e7490; }
.text-right { text-align:right; }
.text-danger { color:#dc2626; }
.text-success { color:#15803d; }
.text-warning { color:#92400e; }

.footer { position:fixed; bottom:10mm; left:14mm; right:14mm; text-align:center; font-size:7.5pt; color:#9ca3af; border-top:1px solid #e5e7eb; padding-top:4px; }
</style>
</head>
<body>
<div class="page">

    <div class="letterhead">
        <div class="lh-left">
            @if($setting->logoDataUri())
                <img src="{{ $setting->logoDataUri() }}" class="lh-logo" alt="">
            @endif
        </div>
        <div class="lh-right">
            <div class="lh-name">{{ $setting->clinic_name }}</div>
            <div class="lh-sub">
                {{ $setting->clinic_address }}
                @if($setting->clinic_phone) &nbsp;&bull;&nbsp; {{ $setting->clinic_phone }} @endif
            </div>
        </div>
    </div>

    <div class="doc-title">
        <h2>Patient Account Ledger</h2>
        <div style="font-size:8.5pt; color:#6b7280;">
            Generated: {{ now()->format('d F Y H:i') }}
            @if($fromDate || $toDate)
                &nbsp;&bull;&nbsp; Period:
                {{ $fromDate ? \Carbon\Carbon::parse($fromDate)->format('d M Y') : 'Start' }}
                –
                {{ $toDate ? \Carbon\Carbon::parse($toDate)->format('d M Y') : 'Now' }}
            @endif
        </div>
    </div>

    <div class="patient-box">
        <div class="pb-left">
            <strong style="font-size:12pt;">{{ $patient->name }}</strong>
            <span style="color:#6b7280; font-size:8.5pt; margin-left:8px;">{{ $patient->pxnumber }}</span>
            @if($patient->contact)
                <div style="font-size:8.5pt; color:#555; margin-top:2px;">{{ $patient->contact }}</div>
            @endif
        </div>
        <div class="pb-right">
            @if($patient->gender) {{ ucfirst($patient->gender) }} @endif
            @if($patient->dob) &nbsp;&bull;&nbsp; DOB: {{ \Carbon\Carbon::parse($patient->dob)->format('d M Y') }} @endif
        </div>
    </div>

    <div class="summary-row">
        <div class="summary-cell sum-charge">
            <div style="font-size:7.5pt; font-weight:bold; text-transform:uppercase; color:#991b1b;">Total Charges</div>
            <div style="font-size:12pt; font-weight:bold; color:#dc2626;">{{ currency() }} {{ number_format($summary['total_charges'], 2) }}</div>
        </div>
        <div class="summary-cell sum-payment" style="margin: 0 4px;">
            <div style="font-size:7.5pt; font-weight:bold; text-transform:uppercase; color:#15803d;">Total Paid</div>
            <div style="font-size:12pt; font-weight:bold; color:#15803d;">{{ currency() }} {{ number_format($summary['total_payments'], 2) }}</div>
        </div>
        <div class="summary-cell sum-balance">
            <div style="font-size:7.5pt; font-weight:bold; text-transform:uppercase; color:#92400e;">Outstanding Balance</div>
            <div style="font-size:12pt; font-weight:bold; color:{{ $summary['balance'] > 0 ? '#d97706' : '#15803d' }};">
                {{ currency() }} {{ number_format($summary['balance'], 2) }}
            </div>
        </div>
    </div>

    <table class="ledger-table">
        <thead>
            <tr>
                <th style="width:15%">Date</th>
                <th>Description</th>
                <th style="width:10%">Ref</th>
                <th class="text-right" style="width:13%">Charge</th>
                <th class="text-right" style="width:13%">Payment</th>
                <th class="text-right" style="width:14%">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
                <tr class="row-{{ $entry['type'] }}">
                    <td>{{ \Carbon\Carbon::parse($entry['date'])->format('d M Y') }}</td>
                    <td>{{ $entry['label'] }}</td>
                    <td style="font-size:8pt; color:#6b7280;">{{ $entry['reference'] }}</td>
                    <td class="text-right {{ $entry['debit'] > 0 ? 'text-danger' : '' }}">
                        {{ $entry['debit'] > 0 ? currency() . ' ' . number_format($entry['debit'], 2) : '—' }}
                    </td>
                    <td class="text-right {{ $entry['credit'] > 0 ? 'text-success' : '' }}">
                        {{ $entry['credit'] > 0 ? currency() . ' ' . number_format($entry['credit'], 2) : '—' }}
                    </td>
                    <td class="text-right {{ $entry['balance'] > 0 ? 'text-warning' : 'text-success' }}">
                        {{ currency() }} {{ number_format($entry['balance'], 2) }}
                    </td>
                </tr>
            @endforeach
            @if($entries->isEmpty())
                <tr><td colspan="6" style="text-align:center; padding:12px; color:#9ca3af;">No transactions found.</td></tr>
            @endif
        </tbody>
        @if($entries->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;">Totals</td>
                    <td class="text-right text-danger">{{ currency() }} {{ number_format($summary['total_charges'], 2) }}</td>
                    <td class="text-right text-success">{{ currency() }} {{ number_format($summary['total_payments'], 2) }}</td>
                    <td class="text-right {{ $summary['balance'] > 0 ? 'text-warning' : 'text-success' }}">
                        {{ currency() }} {{ number_format($summary['balance'], 2) }}
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

</div>

<div class="footer">
    {{ $setting->clinic_name }}
    @if($setting->clinic_address) &nbsp;|&nbsp; {{ $setting->clinic_address }} @endif
    &nbsp;|&nbsp; Confidential — For internal use only
</div>
</body>
</html>
