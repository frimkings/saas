<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation Report - {{ $patient->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #333;
            padding: 30px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #3498db;
            padding-bottom: 20px;
        }

        .clinic-logo {
            max-height: 70px;
            max-width: 150px;
            object-fit: contain;
            margin-bottom: 8px;
        }

        .header h1 {
            font-size: 26pt;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .header .subtitle {
            font-size: 12pt;
            color: #7f8c8d;
        }

        .patient-section {
            background: #ecf0f1;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .patient-grid {
            display: table;
            width: 100%;
        }

        .patient-row {
            display: table-row;
        }

        .patient-label {
            display: table-cell;
            width: 25%;
            font-weight: bold;
            padding: 5px;
        }

        .patient-value {
            display: table-cell;
            padding: 5px;
        }

        .consultation-date {
            background: #3498db;
            color: white;
            padding: 10px;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .section {
            margin-bottom: 20px;
        }

        .section-title {
            background: #34495e;
            color: white;
            padding: 8px 12px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .section-content {
            padding: 10px;
            border: 1px solid #ddd;
            min-height: 40px;
        }

        .refraction-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .refraction-table th {
            background: #34495e;
            color: white;
            padding: 10px;
            text-align: left;
        }

        .refraction-table td {
            border: 1px solid #ddd;
            padding: 8px;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .products-table th {
            background: #27ae60;
            color: white;
            padding: 8px;
            text-align: left;
        }

        .products-table td {
            border: 1px solid #ddd;
            padding: 6px;
        }

        .text-right {
            text-align: right;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #bdc3c7;
            font-size: 9pt;
            color: #7f8c8d;
        }

        .signature-section {
            margin-top: 60px;
        }

        .signature-box {
            display: inline-block;
            width: 45%;
            text-align: center;
            border-top: 2px solid #333;
            padding-top: 10px;
            margin-top: 40px;
        }

        .signature-left {
            float: left;
        }

        .signature-right {
            float: right;
        }
    </style>
</head>
<body>
    <div class="header">
        @if(!empty($clinicSettings) && $clinicSettings->logoDataUri())
            <img src="{{ $clinicSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
        @endif
        <div class="subtitle">{{ $clinicSettings->clinic_name ?? 'Eye Care Center' }}</div>
        <div class="subtitle">
            {{ $clinicSettings->clinic_address ?? '' }}
            @if(!empty($clinicSettings->clinic_contact)) | Tel: {{ $clinicSettings->clinic_contact }} @endif
            @if(!empty($clinicSettings->clinic_email)) | Email: {{ $clinicSettings->clinic_email }} @endif
        </div>
        <h1>CONSULTATION REPORT</h1>
    </div>

    <!-- Patient Information -->
    <div class="patient-section">
        <div class="patient-grid">
            <div class="patient-row">
                <div class="patient-label">Patient Name:</div>
                <div class="patient-value">{{ $patient->name }}</div>
                <div class="patient-label">Patient ID:</div>
                <div class="patient-value">{{ $patient->pxnumber }}</div>
            </div>
            <div class="patient-row">
                <div class="patient-label">Age/Gender:</div>
                <div class="patient-value">
                    {{ \Carbon\Carbon::parse($patient->dob)->age }} years / {{ $patient->gender }}
                </div>
                <div class="patient-label">Contact:</div>
                <div class="patient-value">{{ $patient->contact }}</div>
            </div>
        </div>
    </div>

    <!-- Consultation Date -->
    <div class="consultation-date">
        Consultation Date: {{ $consultation->created_at->format('l, d F Y - h:i A') }}
    </div>

    <!-- Chief Complaint -->
    <div class="section">
        <div class="section-title">CHIEF COMPLAINT</div>
        <div class="section-content">
            {{ $consultation->chiefcomplain ?? 'Not specified' }}
        </div>
    </div>

    <!-- History -->
    <div class="section">
        <div class="section-title">HISTORY</div>
        <div class="section-content">
            {{ $consultation->history ?? 'Not specified' }}
        </div>
    </div>

    <!-- Visual Acuity -->
    <div class="section">
        <div class="section-title">VISUAL ACUITY</div>
        <div class="section-content">
            {{ $consultation->visualacuity ?? 'Not recorded' }}
        </div>
    </div>

    <!-- IOP -->
    <div class="section">
        <div class="section-title">INTRAOCULAR PRESSURE (IOP)</div>
        <div class="section-content">
            {{ $consultation->iop ?? 'Not recorded' }}
        </div>
    </div>

    <!-- Examination -->
    <div class="section">
        <div class="section-title">EXAMINATION FINDINGS</div>
        <div class="section-content">
            {{ $consultation->examination ?? 'Not recorded' }}
        </div>
    </div>

    <!-- Diagnosis -->
    <div class="section">
        <div class="section-title">DIAGNOSIS</div>
        <div class="section-content" style="font-weight: bold; font-size: 12pt;">
            {{ $consultation->diagnosis ?? 'Not specified' }}
        </div>
    </div>

    <!-- Prescribed Drugs -->
    @if($consultation->drugs)
    <div class="section">
        <div class="section-title">PRESCRIBED MEDICATIONS</div>
        <div class="section-content">
            {{ $consultation->drugs }}
        </div>
    </div>
    @endif

    <!-- Refraction Data -->
    @if($refraction)
    <div class="section">
        <div class="section-title">REFRACTION</div>
        <div class="section-content">
            @include('doctor.partials.objective-refraction-table', ['refraction' => $refraction])
            <div style="margin:10px 0 5px;font-weight:bold;">{{ $refraction->hasStructuredSubjective() ? 'Subjective Refraction' : 'Previous-format Refraction' }}</div>
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
                        <td><strong>OD (Right)</strong></td>
                        <td>{{ $refraction->subjectiveRx('od') ?: 'N/A' }}</td>
                        <td>{{ $refraction->refractionOD_distance_va ?? 'N/A' }}</td>
                        <td>{{ $refraction->refractionOD_ADD ?? 'N/A' }}</td>
                        <td>{{ $refraction->refractionOD_near_va ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td><strong>OS (Left)</strong></td>
                        <td>{{ $refraction->subjectiveRx('os') ?: 'N/A' }}</td>
                        <td>{{ $refraction->refractionOS_distance_va ?? 'N/A' }}</td>
                        <td>{{ $refraction->refractionOS_ADD ?? 'N/A' }}</td>
                        <td>{{ $refraction->refractionOS_near_va ?? 'N/A' }}</td>
                    </tr>
                </tbody>
            </table>

            @if($refraction->pd || $refraction->lensType)
            <div style="margin-top: 10px;">
                @if($refraction->pd)
                    <strong>PD:</strong> {{ $refraction->pd }} &nbsp;&nbsp;
                @endif
                @if($refraction->lensType)
                    <strong>Lens Type:</strong> {{ $refraction->lensType }}
                @endif
            </div>
            @endif

            @if($refraction->refractionnotes)
            <div style="margin-top: 10px;">
                <strong>Notes:</strong> {{ $refraction->refractionnotes }}
            </div>
            @endif
        </div>
    </div>
    @endif

    <!-- Products/Frames -->
    @if($consultation->products && count($consultation->products) > 0)
    <div class="section">
        <div class="section-title">PRODUCTS/FRAMES PRESCRIBED</div>
        <div class="section-content">
            <table class="products-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Batch Number</th>
                        <th class="text-right">Quantity</th>
                        <th class="text-right">Unit Price</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($consultation->products as $product)
                    <tr>
                        <td>{{ $product['name'] ?? 'N/A' }}</td>
                        <td>{{ $product['batch_number'] ?? 'N/A' }}</td>
                        <td class="text-right">{{ $product['quantity'] ?? 0 }}</td>
                        <td class="text-right">{{ currency() }} {{ number_format($product['price'] ?? 0, 2) }}</td>
                        <td class="text-right">{{ currency() }} {{ number_format($product['total'] ?? 0, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-right"><strong>Grand Total:</strong></td>
                        <td class="text-right">
                            <strong>{{ currency() }} {{ number_format(collect($consultation->products)->sum('total'), 2) }}</strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

    <!-- Notes -->
    @if($consultation->notes || $consultation->addenda->count())
    <div class="section">
        <div class="section-title">ADDITIONAL NOTES</div>
        <div class="section-content">
            @if($consultation->notes)
                <div>{{ $consultation->notes }}</div>
            @endif
            @if($consultation->addenda->count())
                <div style="margin-top:10px; font-weight:bold;">Clinical Addenda</div>
                @foreach($consultation->addenda as $addendum)
                    <div style="margin-top:7px; border-left:3px solid #3498db; padding-left:8px;">
                        <div style="color:#7f8c8d; font-size:9pt;">
                            {{ $addendum->created_at->format('d M Y h:i A') }} by {{ $addendum->user->name ?? 'Unknown user' }}
                        </div>
                        <div>{{ $addendum->note }}</div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
    @endif

    <!-- Next Visit -->
    <div class="section">
        <div class="section-title">NEXT VISIT</div>
        <div class="section-content">
            {{ $consultation->nextvisit ? \Carbon\Carbon::parse($consultation->nextvisit)->format('l, d F Y') : 'To be scheduled' }}
        </div>
    </div>

    <!-- Signatures -->
    <div class="signature-section">
        <div class="signature-box signature-left">
            <div>Patient/Guardian Signature</div>
            <div style="margin-top: 5px; font-size: 9pt;">Date: _________________</div>
        </div>
        <div class="signature-box signature-right">
            <div>Doctor: {{ $consultation->user->name ?? 'N/A' }}</div>
            <div style="margin-top: 5px; font-size: 9pt;">Date: {{ $consultation->created_at->format('d M, Y') }}</div>
        </div>
        <div style="clear: both;"></div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div style="text-align: center;">
            This is an official consultation report from Eye Care Center<br>
            {{ $clinicSettings->clinic_name ?? 'Eye Care Center' }}<br>
            Generated on {{ $generatedAt->format('d M, Y h:i A') }} by {{ $generatedBy->name ?? 'System' }}
        </div>
    </div>
</body>
</html>
