<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lab Bench Sheet</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #142238; font: 13px/1.45 system-ui, sans-serif; }
        main { max-width: 900px; margin: 16px auto 24px; padding: 24px; background: white; border: 1px solid #dbe4ef; border-radius: 12px; }
        .actions { max-width: 900px; margin: 16px auto 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .actions a { color: #0f766e; }
        button { border: 0; border-radius: 7px; background: #0f766e; color: white; padding: 9px 16px; font-weight: 600; cursor: pointer; }
        header { display: flex; justify-content: space-between; gap: 16px; align-items: start; padding-bottom: 10px; border-bottom: 2px solid #142238; }
        h1 { font-size: 20px; margin: 0; }
        p { margin: 2px 0; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .note { margin: 12px 0 0; padding: 8px 10px; border-radius: 8px; background: #fef3c7; color: #92400e; }
        .job { margin-top: 14px; border: 1px solid #94a3b8; border-radius: 8px; padding: 10px 12px; break-inside: avoid; page-break-inside: avoid; }
        .job-head { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; margin-bottom: 6px; }
        .job-id { font-size: 15px; font-weight: 800; font-family: ui-monospace, monospace; }
        .late { color: #b91c1c; font-weight: 700; }
        .tag { display: inline-block; padding: 1px 7px; border: 1px solid #94a3b8; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
        .label { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        th, td { border: 1px solid #94a3b8; padding: 3px 6px; text-align: center; font-family: ui-monospace, monospace; }
        th { background: #f1f5f9; font-family: system-ui, sans-serif; font-size: 11px; }
        th:first-child, td:first-child { text-align: left; }
        .checks { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 8px; padding-top: 6px; border-top: 1px dashed #94a3b8; font-size: 12px; }
        .box { display: inline-block; width: 13px; height: 13px; border: 1.5px solid #142238; vertical-align: -2px; margin-right: 4px; }
        .line { display: inline-block; min-width: 110px; border-bottom: 1px solid #142238; }
        .empty { padding: 40px 0; text-align: center; color: #64748b; }
        @media print {
            @page { margin: 12mm; }
            body { background: white; font-size: 12px; }
            main { margin: 0; max-width: none; border: 0; border-radius: 0; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
@php
    $tenant = app(\App\Support\Tenancy\TenantContext::class);
    $filters = array_filter([$typeFilter ? 'Type: '.$typeFilter : null, $searchTerm !== '' ? 'Search: "'.$searchTerm.'"' : null]);
@endphp
<div class="actions">
    <a href="{{ route('optical.lab-workbench', array_filter(['stage' => request('stage'), 'typeFilter' => $typeFilter, 'searchTerm' => $searchTerm])) }}">← Back to Lab Workbench</a>
    <button type="button" onclick="window.print()">Print bench sheet</button>
</div>
<main>
    <header>
        <div>
            <h1>Lab Bench Sheet · {{ $stageLabel }}</h1>
            <p class="muted">{{ $tenant->clinic()?->name }}@if($tenant->branch()) · {{ $tenant->branch()->name }}@endif</p>
            @if($filters)<p class="muted">{{ implode(' · ', $filters) }}</p>@endif
        </div>
        <div class="right">
            <strong>{{ $total }} {{ \Illuminate\Support\Str::plural('job', $total) }}</strong>
            <p class="muted">Printed {{ now()->format('d M Y H:i') }}</p>
            <p class="muted">by {{ auth()->user()?->name }}</p>
        </div>
    </header>
    @if($total > $orders->count())<p class="note">Showing the first {{ $orders->count() }} of {{ $total }} jobs, most urgent first. Narrow the workbench filters to print the rest.</p>@endif

    @forelse($orders as $order)
        @php
            $details = $order->docketDetails();
            $rx = $order->prescription_snapshot ?? [];
            $hasRx = $order->optical_prescription_id || $order->refraction_id || filled(data_get($rx, 'od.sph')) || filled(data_get($rx, 'os.sph'));
            $due = $order->pickUpDate ? \Carbon\Carbon::parse($order->pickUpDate) : null;
            $late = $due && $due->lt(today()) && in_array($order->status, \App\Livewire\Optical\OpticalLabWorkbenchComponent::STAGES['active'][1], true);
            $frameSource = match (data_get($details, 'frame_source')) { 'customer' => "Customer's own frame", 'stock' => 'Branch stock', 'custom' => 'Custom / special order', default => null };
        @endphp
        <section class="job">
            <div class="job-head">
                <div><span class="job-id">{{ $order->order_id }}</span> <span class="tag">{{ $order->status }}</span>@if($order->remakeOf) <span class="tag">Remake</span>@endif</div>
                <div class="right">Due: @if($due)<span class="{{ $late ? 'late' : '' }}">{{ $due->format('D d M Y') }}{{ $late ? ' · LATE' : '' }}</span>@else — @endif</div>
            </div>
            <div class="grid">
                <p><span class="label">Customer:</span> {{ $order->display_customer_name }}@if($order->display_customer_phone) · {{ $order->display_customer_phone }}@endif</p>
                <p><span class="label">Work:</span> {{ $order->work_type === 'service' ? 'Optical service' : 'Prescription glasses' }}@if($order->serviceLines->isNotEmpty()) · {{ $order->serviceLines->map(fn ($line) => $line->description.($line->quantity > 1 ? ' × '.$line->quantity : ''))->implode(', ') }}@endif</p>
                @if($order->partner_clinic_name)<p><span class="label">Partner clinic:</span> {{ $order->partner_clinic_name }}</p>@endif
                @if(data_get($details, 'reference'))<p><span class="label">Reference:</span> {{ data_get($details, 'reference') }}</p>@endif
            </div>

            @if($hasRx)
                <table>
                    <thead><tr><th>Eye</th><th>SPH</th><th>CYL</th><th>Axis</th><th>Add</th><th>HGT</th><th>PD</th></tr></thead>
                    <tbody>
                    @foreach(['od' => 'Right (OD)', 'os' => 'Left (OS)'] as $eye => $label)
                        <tr><td>{{ $label }}</td><td>{{ data_get($rx, "$eye.sph", '—') }}</td><td>{{ data_get($rx, "$eye.cyl", '—') }}</td><td>{{ data_get($rx, "$eye.axis", '—') }}</td><td>{{ data_get($rx, "$eye.add", '—') }}</td><td>{{ data_get($rx, "$eye.hgt", '—') }}</td><td>{{ data_get($rx, "$eye.pd", data_get($rx, 'pd', '—')) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            <div class="grid">
                @if($order->frame_model_number)
                    <p><span class="label">Frame:</span> {{ $order->frame_model_number }}@if($order->frameOpticalProduct) ({{ $order->frameOpticalProduct->sku }})@elseif($order->frameProduct) ({{ $order->frameProduct->name }})@endif{{ $frameSource ? ' · '.$frameSource : '' }}</p>
                @endif
                @if($order->work_type !== 'service')
                    <p><span class="label">Lenses:</span> {{ trim(data_get($details, 'lens_details.type', '—').' '.data_get($details, 'lens_details.stock_form')) }} · Index {{ data_get($details, 'lens_details.index', '—') }} · {{ data_get($details, 'lens_details.coatings', '—') }}</p>
                    @foreach($order->lensLines as $lens)
                        <p><span class="label">{{ strtoupper($lens->eye) }} lens:</span> {{ $lens->source === 'stock' ? 'From branch stock' : 'Special order' }}@if($lens->source !== 'stock' && ! $lens->received_at) · <strong>not yet received</strong>@endif</p>
                    @endforeach
                    @if($order->lensOpticalProduct)<p><span class="label">Lens SKU:</span> {{ $order->lensOpticalProduct->sku }}</p>@endif
                @endif
            </div>
            @if(data_get($details, 'lab.instructions'))<p><span class="label">Instructions:</span> {{ data_get($details, 'lab.instructions') }}</p>@endif
            @if(data_get($details, 'notes'))<p><span class="label">Notes:</span> {{ data_get($details, 'notes') }}</p>@endif
            @if($order->remakeOf)<p><span class="label">Remake of:</span> {{ $order->remakeOf->order_id }} · {{ \App\Models\LensOrder::REMAKE_REASONS[$order->remake_reason] ?? $order->remake_reason }}</p>@endif

            <div class="checks">
                <span><span class="box"></span>Started</span>
                <span><span class="box"></span>Glazed / done</span>
                <span><span class="box"></span>QC passed</span>
                <span>Technician: <span class="line"></span></span>
            </div>
        </section>
    @empty
        <p class="empty">No jobs match these workbench filters.</p>
    @endforelse
</main>
</body>
</html>
