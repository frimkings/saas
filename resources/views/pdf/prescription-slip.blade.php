<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drug Prescription - {{ $patient->name ?? 'Patient' }}</title>
    <style>
        @page {
            size: 148mm 210mm;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            width: 128mm;
            min-height: 190mm;
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
        }

        .letterhead {
            border-bottom: 2px solid #0b5ed7;
            padding-bottom: 8px;
            text-align: center;
        }

        .logo {
            max-height: 42px;
            max-width: 90px;
            object-fit: contain;
            margin-bottom: 4px;
        }

        .clinic-name {
            color: #0b5ed7;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .clinic-meta {
            color: #4b5563;
            font-size: 9px;
            margin-top: 2px;
        }

        .title {
            background: #0b5ed7;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.2px;
            margin: 10px 0;
            padding: 5px 8px;
            text-align: center;
            text-transform: uppercase;
        }

        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        .info-grid td {
            width: 50%;
            padding: 3px 6px;
            border: 1px solid #d1d5db;
            vertical-align: top;
        }

        .label {
            color: #6b7280;
            display: block;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .value {
            color: #111827;
            font-size: 10px;
            font-weight: 700;
        }

        .diagnoses {
            margin: 8px 0 10px;
            padding: 6px 8px;
            border-left: 3px solid #0b5ed7;
            background: #f3f7ff;
        }

        .rx-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        .rx-table th {
            background: #e5e7eb;
            color: #111827;
            font-size: 8px;
            padding: 5px;
            text-align: left;
            text-transform: uppercase;
        }

        .rx-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 6px 5px;
            vertical-align: top;
        }

        .drug-name {
            font-weight: 700;
        }

        .muted {
            color: #6b7280;
            font-size: 8px;
        }

        .empty {
            border: 1px dashed #cbd5e1;
            color: #64748b;
            margin-top: 8px;
            padding: 14px;
            text-align: center;
        }

        .signature-row {
            width: 100%;
            margin-top: 22px;
        }

        .signature-row td {
            width: 50%;
            vertical-align: bottom;
        }

        .signature-line {
            border-top: 1px solid #111827;
            margin-left: auto;
            padding-top: 3px;
            text-align: center;
            width: 120px;
        }

        .footer {
            border-top: 1px solid #d1d5db;
            color: #6b7280;
            font-size: 8px;
            margin-top: 16px;
            padding-top: 5px;
            text-align: center;
        }
    </style>
</head>
<body>
    @php
        $birthDate = $patient->dob ?? $patient->date_of_birth ?? null;
    @endphp

    <div class="letterhead">
        @if($settings && method_exists($settings, 'logoDataUri') && $settings->logoDataUri())
            <img src="{{ $settings->logoDataUri() }}" class="logo" alt="Clinic Logo">
        @endif
        <div class="clinic-name">{{ $settings->clinic_name ?? config('app.name') }}</div>
        <div class="clinic-meta">
            {{ $settings->clinic_address ?? '' }}
            @if(!empty($settings->clinic_contact))
                | Tel: {{ $settings->clinic_contact }}
            @endif
            @if(!empty($settings->clinic_email) && strtoupper($settings->clinic_email) !== 'N/A')
                | {{ $settings->clinic_email }}
            @endif
        </div>
    </div>

    <div class="title">Drug Prescription</div>

    <table class="info-grid">
        <tr>
            <td>
                <span class="label">Patient</span>
                <span class="value">{{ $patient->name ?? 'N/A' }}</span>
            </td>
            <td>
                <span class="label">PX Number</span>
                <span class="value">{{ $patient->pxnumber ?? 'N/A' }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="label">Age / Sex</span>
                <span class="value">
                    @if(!empty($birthDate))
                        {{ \Carbon\Carbon::parse($birthDate)->age }} yrs
                    @else
                        N/A
                    @endif
                    @if(!empty($patient->gender))
                        / {{ ucfirst($patient->gender) }}
                    @endif
                </span>
            </td>
            <td>
                <span class="label">Date</span>
                <span class="value">{{ optional($consultation->created_at)->format('M d, Y h:i A') }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="label">Doctor</span>
                <span class="value">{{ $doctor->name ?? 'N/A' }}</span>
            </td>
            <td>
                <span class="label">Contact</span>
                <span class="value">{{ $patient->contact ?? $patient->phone ?? 'N/A' }}</span>
            </td>
        </tr>
    </table>

  

    @if($items->isEmpty())
        <div class="empty">No drug items were recorded for this consultation.</div>
    @else
        <table class="rx-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 34%;">Drug</th>
                    <th style="width: 14%;">Eye</th>
                    <th style="width: 20%;">Frequency</th>
                    <th style="width: 17%;">Duration</th>
                    <th style="width: 10%; text-align: center;">Qty</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>
                            <div class="drug-name">{{ $item->product->name ?? 'Unknown drug' }}</div>
                            @if(!empty($item->product->batch_number))
                                <div class="muted">Batch: {{ $item->product->batch_number }}</div>
                            @endif
                        </td>
                        <td>{{ $item->eye ? strtoupper($item->eye) : '-' }}</td>
                        <td>{{ $item->frequency ?: '-' }}</td>
                        <td>
                            @if($item->duration_unit === 'until_finished')
                                Until finished
                            @elseif($item->duration_value && $item->duration_unit)
                                {{ $item->duration_value }} {{ ucfirst($item->duration_unit) }}
                            @else
                                -
                            @endif
                        </td>
                        <td style="text-align: center;">{{ $item->quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="signature-row">
        <tr>
            <td>
                <div class="value">{{ $doctor->name ?? 'Doctor' }}</div>
                <div class="muted">{{ $settings->clinic_name ?? config('app.name') }}</div>
            </td>
            <td>
                <div class="signature-line">Signature / Stamp</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Generated by {{ auth()->user()->name ?? 'System' }} on {{ now()->format('M d, Y h:i A') }}.
    </div>
</body>
</html>
