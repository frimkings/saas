<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clinical Visit Summaries - {{ $patient->name }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; font-size: 11px; margin: 0; }
        .summary-page { page-break-after: always; padding: 12mm; }
        .summary-page:last-child { page-break-after: auto; }
        .header { border-bottom: 3px solid #0d6efd; padding-bottom: 10px; margin-bottom: 14px; }
        .header h1 { margin: 0; font-size: 19px; }
        .clinic-logo { max-height: 58px; max-width: 130px; object-fit: contain; margin-bottom: 6px; }
        .clinic-name { font-size: 16px; font-weight: bold; color: #0d6efd; text-transform: uppercase; }
        .muted { color: #6b7280; }
        .grid { display: table; width: 100%; margin-bottom: 12px; }
        .row { display: table-row; }
        .cell { display: table-cell; padding: 3px 8px 3px 0; vertical-align: top; }
        .label { font-weight: bold; width: 115px; }
        .section { margin-top: 12px; page-break-inside: avoid; }
        .section-title { background: #f3f4f6; border-left: 4px solid #0d6efd; padding: 6px 8px; font-weight: bold; }
        .section-body { border: 1px solid #e5e7eb; border-top: 0; padding: 8px; min-height: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 5px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        .footer { margin-top: 18px; border-top: 1px solid #d1d5db; padding-top: 8px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
@foreach($consultations as $consultation)
    <div class="summary-page">
        <div class="header">
            @if(!empty($clinicSettings) && $clinicSettings->logoDataUri())
                <img src="{{ $clinicSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
            @endif
            <div class="clinic-name">{{ $clinicSettings->clinic_name ?? 'Eye Clinic' }}</div>
            <div class="muted">
                {{ $clinicSettings->clinic_address ?? '' }}
                @if(!empty($clinicSettings->clinic_contact)) | Tel: {{ $clinicSettings->clinic_contact }} @endif
                @if(!empty($clinicSettings->clinic_email)) | Email: {{ $clinicSettings->clinic_email }} @endif
            </div>
            <h1>Clinical Visit Summary</h1>
            <div class="muted">Generated {{ $generatedAt->format('d M Y h:i A') }} by {{ $generatedBy->name ?? 'System' }}</div>
        </div>

        <div class="grid">
            <div class="row">
                <div class="cell label">Patient</div>
                <div class="cell">{{ $consultation->patient->name }} ({{ $consultation->patient->pxnumber }})</div>
                <div class="cell label">Visit Date</div>
                <div class="cell">{{ $consultation->created_at->format('d M Y h:i A') }}</div>
            </div>
            <div class="row">
                <div class="cell label">Age/Gender</div>
                <div class="cell">{{ \Carbon\Carbon::parse($consultation->patient->dob)->age }} / {{ $consultation->patient->gender }}</div>
                <div class="cell label">Doctor</div>
                <div class="cell">{{ $consultation->doctor->name ?? $consultation->user->name ?? 'N/A' }}</div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Chief Complaint</div>
            <div class="section-body">{{ $consultation->chiefComplaint ?: 'Not recorded' }}</div>
        </div>

        <div class="section">
            <div class="section-title">Clinical Findings</div>
            <div class="section-body">
                <table>
                    <tr><th></th><th>OD</th><th>OS</th></tr>
                    <tr><td>Visual Acuity 6m</td><td>{{ $consultation->vaOD6m ?: 'N/A' }}</td><td>{{ $consultation->vaOS6m ?: 'N/A' }}</td></tr>
                    <tr><td>Anterior chamber</td><td>{{ $consultation->acOD ?: 'N/A' }}</td><td>{{ $consultation->acOS ?: 'N/A' }}</td></tr>
                    <tr><td>IOP</td><td>{{ $consultation->IOPOD ?: 'N/A' }}</td><td>{{ $consultation->IOPOS ?: 'N/A' }}</td></tr>
                    <tr><td>Fundus</td><td>{{ $consultation->fundusOD ?: 'N/A' }}</td><td>{{ $consultation->fundusOS ?: 'N/A' }}</td></tr>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Diagnosis</div>
            <div class="section-body">
                @forelse($consultation->diagnoses as $diagnosis)
                    {{ $diagnosis->name }}@if(!$loop->last), @endif
                @empty
                    Not recorded
                @endforelse
            </div>
        </div>

        <div class="section">
            <div class="section-title">Prescription / Items</div>
            <div class="section-body">
                @if($consultation->cartItems->count())
                    <table>
                        <thead>
                            <tr><th>Item</th><th>Qty</th><th>Eye</th><th>Frequency</th><th>Duration</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            @foreach($consultation->cartItems as $item)
                                <tr>
                                    <td>{{ $item->product->name ?? 'Unknown' }}</td>
                                    <td>{{ $item->quantity }}</td>
                                    <td>{{ $item->eye ?: 'N/A' }}</td>
                                    <td>{{ $item->frequency ?: 'N/A' }}</td>
                                    <td>{{ $item->duration_unit === 'until_finished' ? 'Until finished' : ($item->duration_value && $item->duration_unit ? $item->duration_value.' '.ucfirst($item->duration_unit) : 'N/A') }}</td>
                                    <td>{{ $item->is_dispensed ? 'Dispensed' : ($item->purchased ? 'On Hold' : 'Pending') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    No prescription items recorded.
                @endif
            </div>
        </div>

        <div class="section">
            <div class="section-title">Plan / Notes</div>
            <div class="section-body">
                <div>{{ $consultation->notes ?: $consultation->others ?: 'Not recorded' }}</div>
                @if($consultation->addenda->count())
                    <div style="margin-top:8px; font-weight:bold;">Clinical Addenda</div>
                    @foreach($consultation->addenda as $addendum)
                        <div style="margin-top:6px; border-left:3px solid #0d6efd; padding-left:7px;">
                            <div class="muted">
                                {{ $addendum->created_at->format('d M Y h:i A') }} by {{ $addendum->user->name ?? 'Unknown user' }}
                            </div>
                            <div>{{ $addendum->note }}</div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        @if($consultation->documents->count())
            <div class="section">
                <div class="section-title">Attached Documents</div>
                <div class="section-body">
                    @foreach($consultation->documents as $document)
                        <div>{{ ucwords(str_replace('_', ' ', $document->document_type)) }}: {{ $document->title }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="footer">
            This visit summary is generated from the clinic record system and is intended for patient care continuity.
        </div>
    </div>
@endforeach
</body>
</html>
