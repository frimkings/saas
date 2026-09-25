<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase Order {{ $po->po_number }}</title>
<style>
@page { size: A4 portrait; margin: 12mm 14mm 14mm 14mm; }

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 9.5pt; color: #1a1a1a;
}

/* Letterhead */
.letterhead { text-align:center; border-bottom:2px solid #15803d; padding-bottom:6px; margin-bottom:7px; }
.lh-logo    { max-height:52px; max-width:120px; object-fit:contain; display:block; margin:0 auto 3px; }
.lh-org     { font-size:14pt; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#15803d; line-height:1.1; }
.lh-contact { font-size:8pt; color:#555; margin-top:2px; }

/* Title bar */
.title-bar  { text-align:center; background:#15803d; color:#fff; padding:5px 0; font-size:11pt; font-weight:bold; letter-spacing:2px; text-transform:uppercase; margin-bottom:3px; }
.doc-meta   { text-align:center; font-size:9pt; color:#444; margin-bottom:9px; }

/* Status */
.badge { font-size:8pt; font-weight:bold; text-transform:uppercase; }
.badge-draft    { color:#475569; }
.badge-ordered  { color:#1d4ed8; }
.badge-partial  { color:#92400e; }
.badge-received { color:#15803d; }
.badge-cancelled{ color:#991b1b; }

/* Two-column info section using a table */
.info-section { width:100%; border-collapse:collapse; margin-bottom:9px; }
.info-section td { vertical-align:top; width:50%; padding:0; }
.info-section td:first-child { padding-right:5mm; }
.info-section td:last-child  { padding-left:5mm; }
.info-label   { font-size:7pt; font-weight:bold; text-transform:uppercase; color:#888; letter-spacing:.3px; margin-bottom:2px; }
.info-box     { border:1px solid #ccc; padding:5px 7px; font-size:9pt; }
.detail-row   { width:100%; border-collapse:collapse; }
.detail-row td { padding:1px 0; font-size:8.5pt; }
.dl { color:#888; }
.dv { text-align:right; font-weight:bold; }

/* Items table */
.items { width:100%; border-collapse:collapse; margin-bottom:9px; }
.items thead tr { background:#15803d; color:#fff; }
.items th { padding:4px 5px; font-size:7.5pt; text-transform:uppercase; }
.items td { padding:4px 5px; font-size:9pt; border-bottom:1px solid #e5e7eb; }
.items tr:nth-child(even) td { background:#f0fdf4; }
.tr { text-align:right; }
.tc { text-align:center; }

/* Totals — right-aligned block */
.totals { text-align:right; margin-bottom:9px; }
.totals table { display:inline-table; border-collapse:collapse; }
.totals td { padding:2px 7px; font-size:9.5pt; }
.total-row td { border-top:2px solid #15803d; font-weight:bold; font-size:11pt; color:#15803d; }

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
    <div class="title-bar">Purchase Order</div>
    <div class="doc-meta">
        <strong>{{ $po->po_number }}</strong>
        &nbsp;&bull;&nbsp;
        <span class="badge badge-{{ $po->status }}">{{ ucfirst($po->status) }}</span>
    </div>

    {{-- Supplier + Order details --}}
    <table class="info-section">
        <tr>
            <td>
                <div class="info-label">Supplier</div>
                <div class="info-box">
                    @if($po->supplier)
                        <strong>{{ $po->supplier->name }}</strong>
                        @if($po->supplier->contact_person)
                            <br><span style="color:#555; font-size:8.5pt;">Attn: {{ $po->supplier->contact_person }}</span>
                        @endif
                        @if($po->supplier->phone)
                            <br><span style="color:#555; font-size:8.5pt;">{{ $po->supplier->phone }}</span>
                        @endif
                    @else
                        <em style="color:#9ca3af;">No supplier specified</em>
                    @endif
                </div>
            </td>
            <td>
                <div class="info-label">Order Details</div>
                <div class="info-box">
                    <table class="detail-row">
                        <tr><td class="dl">Order Date:</td><td class="dv">{{ $po->order_date->format('d M Y') }}</td></tr>
                        <tr><td class="dl">Expected Delivery:</td><td class="dv">{{ $po->expected_date?->format('d M Y') ?? '—' }}</td></tr>
                        <tr><td class="dl">Raised By:</td><td class="dv">{{ $po->creator->name ?? '—' }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <table class="items">
        <thead>
            <tr>
                <th class="tc" style="width:20px;">#</th>
                <th>Description</th>
                <th class="tr" style="width:65px;">Qty Ord.</th>
                <th class="tr" style="width:65px;">Qty Rec.</th>
                <th class="tr" style="width:80px;">Unit Cost</th>
                <th class="tr" style="width:80px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po->items as $i => $item)
                <tr>
                    <td class="tc">{{ $i + 1 }}</td>
                    <td>
                        {{ $item->description }}
                        @if($item->batch_number)
                            <br><span style="color:#888; font-size:7.5pt;">Batch: {{ $item->batch_number }}</span>
                        @endif
                    </td>
                    <td class="tr">{{ number_format($item->quantity_ordered, 2) }}</td>
                    <td class="tr">{{ number_format($item->quantity_received, 2) }}</td>
                    <td class="tr">{{ currency() }} {{ number_format($item->unit_cost, 2) }}</td>
                    <td class="tr">{{ currency() }} {{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals">
        <table>
            <tr class="total-row">
                <td>TOTAL</td>
                <td class="tr">{{ currency() }} {{ number_format($po->total_amount, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- Notes --}}
    @if($po->notes)
        <div style="margin-bottom:9px;">
            <div class="notes-label">Notes</div>
            <div class="notes-box">{{ $po->notes }}</div>
        </div>
    @endif

    {{-- Signatures --}}
    <table class="sigs">
        <tr>
            <td><div style="height:24px;"></div><div class="sig-line">Approved By</div></td>
            <td><div style="height:24px;"></div><div class="sig-line">Supplier Signature</div></td>
        </tr>
    </table>

    {{-- Footer --}}
    <div class="footer">
        {{ $setting->clinic_name }}
        @if($setting->clinic_address) &nbsp;|&nbsp; {{ $setting->clinic_address }} @endif
        &nbsp;|&nbsp; Generated: {{ now()->format('d M Y') }}
    </div>

</body>
</html>
