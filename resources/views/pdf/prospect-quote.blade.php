@php($proforma = $quote->document_type === 'proforma')
@php($money = fn ($v) => $quote->currency . ' ' . number_format((float) $v, 2))
<!doctype html><html><head><meta charset="utf-8"><title>{{ $quote->number }}</title><style>
body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:12px;margin:0}
.head{border-bottom:3px solid #0d7377;padding-bottom:14px;margin-bottom:22px}
.right{float:right;text-align:right}
h1{margin:0;color:#0d4f52;font-size:22px}
.doc{font-size:16px;font-weight:bold;color:#0d7377;letter-spacing:.5px}
.muted{color:#667085}
.cols{width:100%;margin-bottom:18px}.cols td{vertical-align:top;border:0;padding:0}
.label{font-size:10px;font-weight:bold;letter-spacing:.6px;text-transform:uppercase;color:#667085;margin-bottom:4px}
table.lines{width:100%;border-collapse:collapse;margin-top:6px}
table.lines th,table.lines td{padding:9px 10px;border:1px solid #d8e0ea;text-align:left}
table.lines th{background:#eef7f7}
.num{text-align:right!important;white-space:nowrap}
.totals td{border:0!important;padding:5px 10px}
.grand td{font-size:15px;font-weight:bold;border-top:2px solid #0d7377!important}
.box{margin-top:18px;border:1px solid #d8e0ea;border-radius:6px;padding:10px 12px;line-height:1.55}
.foot{margin-top:24px;font-size:10px;color:#667085;line-height:1.5}
</style></head><body>
<div class="head">
    <div class="right"><div class="doc">{{ strtoupper($quote->typeLabel()) }}</div>{{ $quote->number }}</div>
    <h1>{{ $support['name'] }}</h1>
    <span class="muted">@if($support['phone']){{ $support['phone'] }}@endif @if($support['email']) · {{ $support['email'] }}@endif</span>
</div>

<table class="cols"><tr>
    <td style="width:55%">
        <div class="label">{{ $proforma ? 'Bill to' : 'Prepared for' }}</div>
        <b style="font-size:14px">{{ $quote->business_name }}</b><br>
        @if($quote->contact_name){{ $quote->contact_name }}<br>@endif
        @if($quote->address){{ $quote->address }}<br>@endif
        @if($quote->phone){{ $quote->phone }}<br>@endif
        @if($quote->email){{ $quote->email }}@endif
    </td>
    <td style="width:45%;text-align:right">
        <div class="label">Date</div>{{ $quote->issued_on->format('j M Y') }}<br><br>
        <div class="label">Valid until</div>{{ $quote->valid_until->format('j M Y') }}<br><br>
        <div class="label">Billing</div>{{ $quote->billing_interval === 'yearly' ? 'Yearly' : 'Monthly' }}
    </td>
</tr></table>

<table class="lines">
    <tr><th>Description</th><th class="num" style="width:28%">Amount</th></tr>
    @foreach($quote->lines as $line)
        <tr><td>{{ $line['description'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
    @endforeach
</table>
<table class="lines totals" style="width:50%;margin-left:50%">
    <tr><td>Subtotal</td><td class="num">{{ $money($quote->subtotal) }}</td></tr>
    @if((float) $quote->discount > 0)<tr><td>Discount</td><td class="num">− {{ $money($quote->discount) }}</td></tr>@endif
    @if((float) $quote->tax > 0)<tr><td>Tax ({{ rtrim(rtrim(number_format((float) $quote->tax_rate, 2), '0'), '.') }}%)</td><td class="num">{{ $money($quote->tax) }}</td></tr>@endif
    <tr class="grand"><td>{{ $proforma ? 'Amount due' : 'Total' }}</td><td class="num">{{ $money($quote->total) }}</td></tr>
</table>
<p class="muted" style="text-align:right;margin:4px 10px 0">{{ $quote->billing_interval === 'yearly' ? 'For 12 months of service.' : 'Per month of service.' }}</p>

@if($quote->notes)<div class="box"><div class="label">Notes</div>{!! nl2br(e($quote->notes)) !!}</div>@endif
@if($quote->terms)<div class="box"><div class="label">{{ $proforma ? 'Payment details and terms' : 'Terms' }}</div>{!! nl2br(e($quote->terms)) !!}</div>@endif

<div class="foot">
    @if($proforma)
        This pro-forma invoice is a request for payment in advance, not a tax invoice. Your account is set up once payment is received, and a tax invoice and receipt are issued then.
    @else
        This quotation is valid until {{ $quote->valid_until->format('j M Y') }}. Prices may change after that date. To accept, contact us on {{ $support['phone'] ?: $support['email'] }}.
    @endif
</div>
</body></html>
