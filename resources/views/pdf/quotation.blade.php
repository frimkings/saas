<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Proforma Invoice {{ $q->quotation_number }}</title>
<style>
@page { size: A4 portrait; margin: 12mm 14mm 14mm 14mm; }

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 9.5pt; color: #1a1a1a;
}

/* Letterhead */
.letterhead { text-align:center; border-bottom:2px solid #003087; padding-bottom:6px; margin-bottom:7px; }
.lh-logo    { max-height:52px; max-width:120px; object-fit:contain; display:block; margin:0 auto 3px; }
.lh-org     { font-size:14pt; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#003087; line-height:1.1; }
.lh-contact { font-size:8pt; color:#555; margin-top:2px; }

/* Title bar */
.title-bar { text-align:center; background:#003087; color:#fff; padding:5px 0; font-size:11pt; font-weight:bold; letter-spacing:2px; text-transform:uppercase; margin-bottom:3px; }
.doc-meta   { text-align:center; font-size:9pt; color:#444; margin-bottom:9px; }

/* Status */
.badge { font-size:8pt; font-weight:bold; text-transform:uppercase; }
.badge-draft    { color:#475569; }
.badge-sent     { color:#1d4ed8; }
.badge-accepted { color:#15803d; }
.badge-expired  { color:#92400e; }
.badge-cancelled{ color:#991b1b; }

/* Info row */
.info-section { width:100%; border-collapse:collapse; margin-bottom:9px; }
.info-section td { vertical-align:top; padding:0; }
.info-label  { font-size:7pt; font-weight:bold; text-transform:uppercase; color:#888; letter-spacing:.3px; margin-bottom:2px; }
.info-value  { font-size:10pt; }
.info-strong { font-weight:bold; }

/* Items table */
.items { width:100%; border-collapse:collapse; margin-bottom:9px; }
.items thead tr { background:#003087; color:#fff; }
.items th { padding:4px 5px; font-size:7.5pt; text-transform:uppercase; }
.items td { padding:4px 5px; font-size:9pt; border-bottom:1px solid #e5e7eb; }
.items tr:nth-child(even) td { background:#f8fafc; }
.tr { text-align:right; }
.tc { text-align:center; }

/* Totals */
.totals { text-align:right; margin-bottom:9px; }
.totals table { display:inline-table; border-collapse:collapse; }
.totals td { padding:2px 7px; font-size:9.5pt; }
.total-row td { border-top:2px solid #003087; font-weight:bold; font-size:11pt; color:#003087; }

/* Notes */
.notes-label { font-size:7pt; font-weight:bold; text-transform:uppercase; color:#888; margin-bottom:2px; }
.notes-box   { border:1px solid #ccc; padding:5px 7px; font-size:9pt; color:#374151; }

/* Signatures */
.sigs { width:100%; border-collapse:collapse; margin-top:20px; }
.sigs td { width:50%; text-align:center; padding:0 15px; }
.sig-line { border-top:1px solid #555; padding-top:3px; font-size:8.5pt; color:#555; }

/* Footer */
.footer { text-align:center; border-top:1px solid #ccc; padding-top:4px; margin-top:10px; font-size:7.5pt; color:#999; }
</style>
</head>
<body>

    {{-- Letterhead --}}
    <div class="letterhead">
        @if($setting->logoDataUri())
            <img src="{{ $setting->logoDataUri() }}" class="lh-logo" alt="Logo">
        @endif
        <div class="lh-org">{{ $setting->clinic_name }}</div>
        <div class="lh-contact">
            {{ $setting->clinic_address }}
            @if($setting->clinic_contact) &nbsp;|&nbsp; Tel: {{ $setting->clinic_contact }} @endif
            @if($setting->clinic_email)   &nbsp;|&nbsp; {{ $setting->clinic_email }} @endif
        </div>
    </div>

    {{-- Title --}}
    <div class="title-bar">Pro Forma Invoice</div>
    <div class="doc-meta">
        <strong>{{ $q->quotation_number }}</strong>
        &nbsp;&bull;&nbsp;
        <span class="badge badge-{{ $q->status }}">{{ ucfirst($q->status) }}</span>
    </div>

    {{-- Info --}}
    <table class="info-section">
        <tr>
            <td style="width:52%;">
                <div class="info-label">Prepared For</div>
                <div class="info-value info-strong">{{ $q->patient_name }}</div>
                @if($q->patient_phone)
                    <div style="font-size:8.5pt; color:#555; margin-top:1px;">{{ $q->patient_phone }}</div>
                @endif
            </td>
            <td style="width:48%; text-align:right;">
                <div class="info-label">Issue Date</div>
                <div class="info-value">{{ $q->issue_date->format('d M Y') }}</div>
                <div class="info-label" style="margin-top:5px;">Valid Until</div>
                <div class="info-value">{{ $q->valid_until->format('d M Y') }}</div>
                <div class="info-label" style="margin-top:5px;">Prepared By</div>
                <div class="info-value">{{ $q->creator->name ?? $setting->clinic_name }}</div>
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <table class="items">
        <thead>
            <tr>
                <th class="tc" style="width:20px;">#</th>
                <th>Description</th>
                <th class="tr" style="width:50px;">Qty</th>
                <th class="tr" style="width:90px;">Unit Price</th>
                <th class="tr" style="width:90px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($q->items as $i => $item)
                <tr>
                    <td class="tc">{{ $i + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="tr">{{ number_format($item->quantity, 2) }}</td>
                    <td class="tr">{{ currency() }} {{ number_format($item->unit_price, 2) }}</td>
                    <td class="tr">{{ currency() }} {{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals">
        <table>
            <tr><td>Subtotal</td><td class="tr">{{ currency() }} {{ number_format($q->subtotal, 2) }}</td></tr>
            @if($q->discount_amount > 0)
                <tr><td>Discount</td><td class="tr" style="color:#dc2626;">- {{ currency() }} {{ number_format($q->discount_amount, 2) }}</td></tr>
            @endif
            <tr class="total-row"><td>TOTAL</td><td class="tr">{{ currency() }} {{ number_format($q->total_amount, 2) }}</td></tr>
        </table>
    </div>

    {{-- Notes --}}
    @if($q->notes)
        <div style="margin-bottom:9px;">
            <div class="notes-label">Notes &amp; Terms</div>
            <div class="notes-box">{{ $q->notes }}</div>
        </div>
    @endif

    {{-- Signatures --}}
    <table class="sigs">
        <tr>
            <td>
                <div style="font-size:9.5pt; font-weight:bold;">{{ $q->creator->name ?? $setting->clinic_name }}</div>
                <div style="font-size:8pt; color:#555; margin-top:1px;">{{ $setting->clinic_name }}</div>
            </td>
            <td><div style="height:26px;"></div><div class="sig-line">Authorised Signature &amp; Stamp</div></td>
        </tr>
    </table>

    {{-- Footer --}}
    <div class="footer">
        {{ $setting->clinic_name }}
        @if($setting->clinic_address) &nbsp;|&nbsp; {{ $setting->clinic_address }} @endif
        &nbsp;|&nbsp; This pro forma invoice is valid until {{ $q->valid_until->format('d M Y') }}.
    </div>

</body>
</html>
