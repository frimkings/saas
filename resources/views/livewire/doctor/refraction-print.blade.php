<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refraction Prescription - {{ $patient->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            padding: 20px;
            color: #333;
            line-height: 1.6;
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #2c3e50;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .clinic-logo {
            max-height: 70px;
            max-width: 150px;
            object-fit: contain;
            margin-bottom: 8px;
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header p {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .patient-info {
            background: #ecf0f1;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .patient-info table {
            width: 100%;
        }
        
        .patient-info td {
            padding: 5px;
            font-size: 14px;
        }
        
        .patient-info td:first-child {
            font-weight: bold;
            width: 120px;
        }
        
        .section-title {
            background: #3498db;
            color: white;
            padding: 10px;
            font-size: 16px;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
            border-radius: 3px;
        }
        
        .refraction-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .refraction-table th,
        .refraction-table td {
            border: 1px solid #bdc3c7;
            padding: 10px;
            text-align: left;
        }
        
        .refraction-table th {
            background: #34495e;
            color: white;
            font-weight: bold;
        }
        
        .refraction-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .eye-label {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .info-label {
            font-weight: bold;
            color: #7f8c8d;
        }
        
        .info-value {
            color: #2c3e50;
        }
        
        .notes-section {
            background: #fff9e6;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin-top: 20px;
        }
        
        .notes-section h3 {
            color: #d68910;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
            text-align: center;
            color: #7f8c8d;
            font-size: 12px;
        }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            margin-top: 50px;
            padding-top: 5px;
        }
        
        @media print {
            body {
                padding: 10px;
            }
            
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    {{-- Header --}}
    <div class="header">
        @if(!empty($appSettings) && $appSettings->logoDataUri())
            <img src="{{ $appSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
        @endif
        <p>{{ $appSettings->clinic_name ?? 'Eye Clinic' }}</p>
        <h1>REFRACTION PRESCRIPTION</h1>
        <p>Eye Examination & Optical Prescription</p>
    </div>

    {{-- Patient Information --}}
    <div class="patient-info">
        <table>
            <tr>
                <td>Patient Name:</td>
                <td><strong>{{ $patient->name }}</strong></td>
                <td>Patient ID:</td>
                <td><strong>{{ $patient->pxnumber }}</strong></td>
            </tr>
            <tr>
                <td>Date of Birth:</td>
                <td>{{ \Carbon\Carbon::parse($patient->dob)->format('d M, Y') }} ({{ \Carbon\Carbon::parse($patient->dob)->age }} years)</td>
                <td>Gender:</td>
                <td>{{ $patient->gender }}</td>
            </tr>
            <tr>
                <td>Contact:</td>
                <td>{{ $patient->contact }}</td>
                <td>Exam Date:</td>
                <td><strong>{{ \Carbon\Carbon::parse($consultation->created_at)->format('d M, Y') }}</strong></td>
            </tr>
        </table>
    </div>

    {{-- Refraction Details --}}
    <div class="section-title">
        REFRACTION DETAILS
    </div>

    <div class="info-row">
        <span class="info-label">PD (Pupillary Distance):</span>
        <span class="info-value"><strong>{{ $refraction->pd }} mm</strong></span>
    </div>

    <div class="info-row">
        <span class="info-label">Lens Type:</span>
        <span class="info-value"><strong>{{ $refraction->lensType }}</strong></span>
    </div>

    {{-- Eye Measurements Table --}}
    <div class="section-title">
        EYE MEASUREMENTS
    </div>

    <table class="refraction-table">
        <thead>
            <tr>
                <th>Eye</th>
                <th>Refraction</th>
                <th>Distance VA</th>
                <th>ADD</th>
                <th>Near VA</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="eye-label">OD (Right Eye)</td>
                <td>{{ $refraction->refractionOD }}</td>
                <td>{{ $refraction->refractionOD_distance_va }}</td>
                <td>{{ $refraction->refractionOD_ADD ?? 'N/A' }}</td>
                <td>{{ $refraction->refractionOD_near_va ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="eye-label">OS (Left Eye)</td>
                <td>{{ $refraction->refractionOS ?? 'N/A' }}</td>
                <td>{{ $refraction->refractionOS_distance_va }}</td>
                <td>{{ $refraction->refractionOS_ADD ?? 'N/A' }}</td>
                <td>{{ $refraction->refractionOS_near_va ?? 'N/A' }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Notes Section --}}
    @if($refraction->refractionnotes)
    <div class="notes-section">
        <h3><i>📝 Additional Notes:</i></h3>
        <p>{{ $refraction->refractionnotes }}</p>
    </div>
    @endif

    {{-- Consultation Details --}}
    @if($consultation->chiefComplaint || $consultation->diagnosis)
    <div class="section-title">
        CONSULTATION DETAILS
    </div>

    @if($consultation->chiefComplaint)
    <div class="info-row">
        <span class="info-label">Chief Complaint:</span>
        <span class="info-value">{{ $consultation->chiefComplaint }}</span>
    </div>
    @endif

    @if($consultation->diagnosis)
    <div class="info-row">
        <span class="info-label">Diagnosis:</span>
        <span class="info-value">{{ $consultation->diagnosis }}</span>
    </div>
    @endif
    @endif

    {{-- Signature Section --}}
    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line">
                Doctor's Signature
            </div>
            <p style="margin-top: 5px;">{{ $consultation->user->name ?? 'Doctor' }}</p>
        </div>
        <div class="signature-box">
            <div class="signature-line">
                Date
            </div>
            <p style="margin-top: 5px;">{{ \Carbon\Carbon::parse($consultation->created_at)->format('d M, Y') }}</p>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        <p>This is a computer-generated prescription. Valid for optical purposes.</p>
        <p>Printed on {{ \Carbon\Carbon::now()->format('d M, Y h:i A') }}</p>
    </div>
</body>
</html>
