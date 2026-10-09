<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Optical Docket {{ $order->order_id }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #142238; font: 14px/1.5 system-ui, sans-serif; }
        main { max-width: 860px; margin: 24px auto; padding: 28px; background: white; border: 1px solid #dbe4ef; border-radius: 12px; }
        header, .row { display: flex; justify-content: space-between; gap: 20px; align-items: start; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; margin: 22px 0 8px; border-bottom: 1px solid #dbe4ef; padding-bottom: 5px; }
        p { margin: 3px 0; }
        .muted { color: #64748b; }
        .badge { display: inline-block; padding: 4px 9px; background: #e2f7f6; color: #0f766e; border-radius: 6px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe4ef; text-align: left; padding: 8px; }
        th { background: #f8fafc; }
        .right { text-align: right; }
        .actions { max-width: 860px; margin: 16px auto 0; text-align: right; }
        button { border: 0; border-radius: 7px; background: #0f766e; color: white; padding: 9px 16px; cursor: pointer; }
        @media print { body { background: white; } main { margin: 0; max-width: none; border: 0; border-radius: 0; padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
@php
    $details = $order->docketDetails();
    $rx = $order->prescription_snapshot ?? [];
@endphp
<div class="actions"><button type="button" onclick="window.print()">Print docket</button></div>
<main>
    <header>
        <div>
            <h1>Optical Order Docket</h1>
            <p class="muted">{{ app(\App\Support\Tenancy\TenantContext::class)->clinic()?->name }}</p>
            <p class="muted">{{ app(\App\Support\Tenancy\TenantContext::class)->branch()?->name }}</p>
        </div>
        <div class="right">
            <strong>{{ $order->order_id }}</strong><br>
            <span class="badge">{{ $order->status }}</span><br>
            <span class="muted">{{ $order->created_at?->format('d M Y') }}</span>
        </div>
    </header>

    <h2>Customer and job</h2>
    <div class="row">
        <div><strong>{{ $order->display_customer_name }}</strong><br><span class="muted">{{ $order->customer?->pxnumber }} · {{ $order->display_customer_phone }}</span></div>
        <div class="right">{{ $order->work_type === 'service' ? 'Optical service' : 'Prescription order' }}<br>Pickup: {{ $order->pickUpDate ? \Carbon\Carbon::parse($order->pickUpDate)->format('d M Y') : '—' }}</div>
    </div>
    @if($order->partner_clinic_name)<p><strong>Partner clinic:</strong> {{ $order->partner_clinic_name }} · Bill to {{ $order->bill_to === 'partner' ? 'clinic' : 'customer' }}</p>@endif
    @if(data_get($details, 'reference'))<p><strong>Reference:</strong> {{ data_get($details, 'reference') }}</p>@endif
    @if($order->optical_prescription_id || $order->refraction_id || filled(data_get($order->prescription_snapshot, 'od.sph')))
    <h2>Prescription</h2>
    <table style="margin-top: 12px">
        <thead><tr><th>Eye</th><th>SPH</th><th>CYL</th><th>Axis</th><th>Add</th><th>HGT</th><th>PD</th></tr></thead>
        <tbody>
            @foreach(['od' => 'Right (OD)', 'os' => 'Left (OS)'] as $eye => $label)
                <tr><th>{{ $label }}</th><td>{{ data_get($rx, "$eye.sph", '—') }}</td><td>{{ data_get($rx, "$eye.cyl", '—') }}</td><td>{{ data_get($rx, "$eye.axis", '—') }}</td><td>{{ data_get($rx, "$eye.add", '—') }}</td><td>{{ data_get($rx, "$eye.hgt", '—') }}</td><td>{{ data_get($rx, "$eye.pd", data_get($rx, 'pd', '—')) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($order->serviceLines->isNotEmpty())
    <h2>Services requested</h2>
    <table><thead><tr><th>Service</th><th>Qty</th><th class="right">Unit price</th><th class="right">Amount</th></tr></thead><tbody>
        @foreach($order->serviceLines as $line)<tr><td>{{ $line->description }}</td><td>{{ $line->quantity }}</td><td class="right">{{ currency() }} {{ number_format((float) $line->unit_price, 2) }}</td><td class="right">{{ currency() }} {{ number_format((float) $line->line_total, 2) }}</td></tr>@endforeach
    </tbody></table>
    @endif
    <h2>Frame and lenses</h2>
    @if($order->frame_model_number)
    <p><strong>Frame source:</strong> {{ data_get($details, 'frame_source') === 'customer' ? "Customer's own frame" : (data_get($details, 'frame_source') === 'stock' ? 'Optical branch stock' : 'Custom / special order') }}</p>
    <p><strong>Frame:</strong> {{ $order->frame_model_number }} @if($order->frameOpticalProduct) ({{ $order->frameOpticalProduct->sku }}) @elseif($order->frameProduct) ({{ $order->frameProduct->name }}) @endif</p>
    @endif
    @if($order->work_type !== 'service')
    <p><strong>Lenses:</strong> {{ trim(data_get($details, 'lens_details.type', '—').' '.data_get($details, 'lens_details.stock_form')) }} · Index {{ data_get($details, 'lens_details.index', '—') }} · {{ data_get($details, 'lens_details.coatings', '—') }}</p>
    @if($order->lensOpticalProduct)<p><strong>Lens SKU:</strong> {{ $order->lensOpticalProduct->sku }} · {{ $order->lensOpticalProduct->name }}</p>@endif
    @endif
    <p><strong>Instructions:</strong> {{ data_get($details, 'lab.instructions', '—') }}</p>
    @if(data_get($details, 'notes'))<p><strong>Notes:</strong> {{ data_get($details, 'notes') }}</p>@endif
    @if($order->remakeOf)<p><strong>Remake of:</strong> {{ $order->remakeOf->order_id }} · {{ \App\Models\LensOrder::REMAKE_REASONS[$order->remake_reason] ?? $order->remake_reason }} · {{ $order->remake_charge === 'free' ? 'Free of charge' : 'Charged' }}</p>@endif
    @php $warrantyMonths = (int) (\App\Models\OpticalSetting::first()?->warranty_months ?? 0); @endphp
    @if($order->warranty_expires_at)
    <p><strong>Lens warranty:</strong> until {{ $order->warranty_expires_at->format('d M Y') }}</p>
    @elseif($warrantyMonths > 0 && $order->work_type === 'prescription')
    <p><strong>Lens warranty:</strong> {{ $warrantyMonths }} months from collection</p>
    @endif

    <h2>Pricing</h2>
    <table>
        <tbody>
            <tr><td>Frame</td><td class="right">{{ currency() }} {{ number_format((float) $order->frame_price, 2) }}</td></tr>
            @forelse($order->lensLines as $lens)
            <tr><td>{{ strtoupper($lens->eye) }} lens · {{ $lens->source === 'stock' ? 'branch stock' : 'special order' }}</td><td class="right">{{ currency() }} {{ number_format((float) $lens->unit_price, 2) }}</td></tr>
            @empty
            <tr><td>Lenses</td><td class="right">{{ currency() }} {{ number_format((float) $order->lens_price, 2) }}</td></tr>
            @endforelse
            @if((float) $order->glazing_fee > 0)<tr><td>Glazing</td><td class="right">{{ currency() }} {{ number_format((float) $order->glazing_fee, 2) }}</td></tr>@endif
            @foreach($order->serviceLines as $line)<tr><td>{{ $line->description }} × {{ $line->quantity }}</td><td class="right">{{ currency() }} {{ number_format((float) $line->line_total, 2) }}</td></tr>@endforeach
            <tr><td>Discount</td><td class="right">− {{ currency() }} {{ number_format((float) $order->discount_amount, 2) }}</td></tr>
            <tr><th>Total</th><th class="right">{{ currency() }} {{ number_format($order->total, 2) }}</th></tr>
            <tr><td>Paid</td><td class="right">{{ currency() }} {{ number_format((float) $order->paid_amount, 2) }}</td></tr>
            <tr><th>Balance</th><th class="right">{{ currency() }} {{ number_format(max(0, $order->total - (float) $order->paid_amount), 2) }}</th></tr>
        </tbody>
    </table>
</main>
</body>
</html>
