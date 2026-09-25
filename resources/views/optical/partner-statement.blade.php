@php $settings = \App\Models\Setting::getSettings(); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement · {{ $partner->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        .text-right { text-align: right; }
        .muted { color: #555; }
        .aging td, .aging th { text-align: right; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()">Print</button>
    <h1>Statement of account</h1>
    <p><strong>{{ $settings->clinic_name ?? '' }}</strong></p>
    <p><strong>To:</strong> {{ $partner->name }}@if($partner->contact_person) · {{ $partner->contact_person }}@endif @if($partner->address)<br>{{ $partner->address }}@endif</p>
    <p class="muted">Period {{ $fromDate->format('d M Y') }} – {{ $toDate->format('d M Y') }} · printed {{ now()->format('d M Y') }}</p>
    @include('livewire.optical.partials.partner-statement-table')
    <table class="aging">
        <thead><tr><th>Balance due today</th>@foreach(\App\Services\OpticalPartnerAccountService::AGING as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody><tr><td><strong>{{ currency() }} {{ number_format($balance, 2) }}</strong></td>@foreach(\App\Services\OpticalPartnerAccountService::AGING as $key => $label)<td>{{ number_format($aging[$key], 2) }}</td>@endforeach</tr></tbody>
    </table>
</body>
</html>
