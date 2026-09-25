<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body { 
            font-family: 'Arial', sans-serif; 
            font-size: 11px; 
            width: 80mm; 
            margin: 0 auto; 
            padding: 8px;
            background: #fff;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .clinic-name {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .clinic-logo {
            max-width: 35mm;
            max-height: 18mm;
            object-fit: contain;
            margin-bottom: 4px;
        }
        .clinic-address {
            font-size: 10px;
            color: #333;
            line-height: 1.4;
        }
        .prescription-title {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 10px 0;
            padding: 5px;
            background: #f0f0f0;
            border: 1px solid #000;
        }
        .patient-info {
            margin: 10px 0;
            font-size: 10px;
            line-height: 1.6;
        }
        .patient-info .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .patient-info strong {
            font-weight: bold;
            min-width: 80px;
            display: inline-block;
        }
        table { 
            width: 100%; 
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td { 
            border: 1px solid #000; 
            padding: 5px 3px; 
            text-align: center;
            font-size: 10px;
        }
        th {
            background: #e8e8e8;
            font-weight: bold;
        }
        .lens-details {
            margin: 10px 0;
            padding: 8px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            font-size: 10px;
        }
        .lens-details .row {
            margin-bottom: 4px;
        }
        .lens-details strong {
            font-weight: bold;
        }
        .doctor-section {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #666;
        }
        .doctor-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            font-size: 10px;
        }
        .doctor-name {
            flex: 1;
        }
        .signature-box {
            flex: 1;
            text-align: right;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 30px;
            padding-top: 3px;
            font-size: 9px;
        }
        .footer {
            text-align: center;
            margin-top: 15px;
            padding-top: 8px;
            border-top: 2px solid #000;
            font-size: 9px;
            color: #666;
        }
        @media print {
            body {
                width: 80mm;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <div class="header">
        @if(!empty($appSettings) && $appSettings->logoDataUri())
            <img src="{{ $appSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
        @endif
        <div class="clinic-name"><h2>{{ $appSettings->clinic_name }}</h2>
</div>
        <div class="clinic-address">
            <span>{{ $appSettings->clinic_address }}</span> ||
    <span>{{ $appSettings->clinic_contact }}</span>|| <span>{{ $appSettings->clinic_email }}</span>
    </div>

    <!-- PRESCRIPTION TITLE -->
    <div class="prescription-title">Spectacle Prescription</div>

    <!-- PATIENT INFO -->
    <div class="patient-info">
        <div class="row">
            <span><strong>Patient:</strong> {{ $patient->name }}</span>
        </div>
        <div class="row">
            <span><strong>Age:</strong> {{ \Carbon\Carbon::parse($patient->dob)->diff(\Carbon\Carbon::now())->format('%y years') }}</span>
            <span><strong>Date:</strong> {{ $refraction->created_at->format("d/m/Y") }}</span>
        </div>
        <div class="row">
            <span><strong>Contact:</strong> {{ $patient->contact ?? 'N/A' }}</span>
        </div>
        <div class="row">
            <span><strong>Address:</strong> {{ $patient->address ?? 'N/A' }}</span>
        </div>
    </div>

    @include('doctor.partials.objective-refraction-table', ['refraction' => $refraction])
    <div style="margin:10px 0 5px;font-weight:bold;">{{ $refraction->hasStructuredSubjective() ? 'Subjective Refraction' : 'Previous-format Refraction' }}</div>
    <!-- SUBJECTIVE / LEGACY REFRACTION TABLE -->
    <table>
        <thead>
            <tr>
                <th>Eye</th>
                <th>Rx</th>
                <th>Distance VA</th>
                <th>ADD</th>
                <th>Near VA</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>OD</strong></td>
                <td>{{ $refraction->subjectiveRx('od') ?: '—' }}</td>
                <td>{{ $refraction->refractionOD_distance_va ?? '—' }}</td>
                <td>{{ $refraction->refractionOD_ADD ?? '—' }}</td>
                <td>{{ $refraction->refractionOD_near_va ?? '—' }}</td>
            </tr>
            <tr>
                <td><strong>OS</strong></td>
                <td>{{ $refraction->subjectiveRx('os') ?: '—' }}</td>
                <td>{{ $refraction->refractionOS_distance_va ?? '—' }}</td>
                <td>{{ $refraction->refractionOS_ADD ?? '—' }}</td>
                <td>{{ $refraction->refractionOS_near_va ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <!-- LENS DETAILS -->
    <div class="lens-details">
        <div class="row">
            <strong>PD (Pupillary Distance):</strong> {{ $refraction->pd ?? '—' }} mm
        </div>
        <div class="row">
            <strong>Lens Type:</strong> {{ $refraction->lensType ?? '—' }}
        </div>
         <div class="row">
            <strong>Notes:</strong> {{ $refraction->refractionnotes ?? '—' }}
        </div>
    </div>

    <!-- DOCTOR SECTION -->
    <div class="doctor-section">
        <div class="doctor-info">
            <div class="doctor-name">
                <strong>Prescribed by:</strong><br>
                Dr. {{ $refraction->user->name ?? 'N/A' }}<br>
                <span style="font-size: 9px;">Optometrist</span>
            </div>
            <div class="signature-box">
                <div class="signature-line">
                    Doctor's Signature
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="footer">
        Thank you for choosing our services!<br>
        Please bring this prescription when ordering spectacles
    </div>
</body>
</html>
