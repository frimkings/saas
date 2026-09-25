<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Record - {{ $patient->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #333;
        }

        .container {
            padding: 20px;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #2c3e50;
            padding-bottom: 15px;
        }

        .clinic-logo {
            max-height: 70px;
            max-width: 150px;
            object-fit: contain;
            margin-bottom: 8px;
        }

        .header h1 {
            font-size: 24pt;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .header h2 {
            font-size: 16pt;
            color: #34495e;
            font-weight: normal;
            margin-bottom: 10px;
        }

        .header .subtitle {
            font-size: 10pt;
            color: #7f8c8d;
        }

        /* Patient Info Card */
        .patient-info {
            background: #ecf0f1;
            border: 2px solid #bdc3c7;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .patient-info h3 {
            color: #2c3e50;
            font-size: 14pt;
            margin-bottom: 10px;
            border-bottom: 1px solid #bdc3c7;
            padding-bottom: 5px;
        }

        .info-grid {
            display: table;
            width: 100%;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            width: 30%;
            font-weight: bold;
            padding: 5px 10px;
            color: #34495e;
        }

        .info-value {
            display: table-cell;
            width: 70%;
            padding: 5px 10px;
        }

        /* Section Headers */
        .section {
            margin-top: 25px;
            margin-bottom: 15px;
        }

        .section-header {
            background: #3498db;
            color: white;
            padding: 8px 12px;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 10px;
        }

        /* Consultation Cards */
        .consultation-card {
            border: 1px solid #ddd;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
            page-break-inside: avoid;
        }

        .consultation-header {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }

        .consultation-date {
            display: table-cell;
            font-weight: bold;
            font-size: 12pt;
            color: #2c3e50;
        }

        .consultation-doctor {
            display: table-cell;
            text-align: right;
            color: #7f8c8d;
            font-size: 10pt;
        }

        .consultation-body {
            margin-top: 10px;
        }

        .field-group {
            margin-bottom: 8px;
        }

        .field-label {
            font-weight: bold;
            color: #34495e;
            display: inline-block;
            min-width: 150px;
        }

        .field-value {
            display: inline;
            color: #333;
        }

        /* Refraction Table */
        .refraction-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .refraction-table th {
            background: #34495e;
            color: white;
            padding: 8px;
            text-align: left;
            font-size: 10pt;
        }

        .refraction-table td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            font-size: 10pt;
        }

        .refraction-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        /* Products Table */
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
            font-size: 10pt;
        }

        .products-table td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            font-size: 10pt;
        }

        .products-table .text-right {
            text-align: right;
        }

        .products-table tfoot td {
            font-weight: bold;
            background: #ecf0f1;
        }

        /* No Data Message */
        .no-data {
            text-align: center;
            padding: 20px;
            color: #7f8c8d;
            font-style: italic;
        }

        /* Footer */
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #bdc3c7;
            font-size: 9pt;
            color: #7f8c8d;
        }

        .footer-grid {
            display: table;
            width: 100%;
        }

        .footer-left {
            display: table-cell;
            width: 50%;
        }

        .footer-right {
            display: table-cell;
            width: 50%;
            text-align: right;
        }

        /* Page Break */
        .page-break {
            page-break-after: always;
        }

        /* Badge Styles */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 9pt;
            font-weight: bold;
        }

        .badge-success {
            background: #27ae60;
            color: white;
        }

        .badge-warning {
            background: #f39c12;
            color: white;
        }

        .badge-info {
            background: #3498db;
            color: white;
        }

        /* Print Specific */
        @media print {
            body {
                margin: 0;
            }
            .no-print {
                display: none;
            }
        }

        /* Summary Box */
        .summary-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            padding: 10px;
        }

        .summary-number {
            font-size: 24pt;
            font-weight: bold;
            color: #2c3e50;
        }

        .summary-label {
            font-size: 10pt;
            color: #7f8c8d;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            @if(!empty($clinicSettings) && $clinicSettings->logoDataUri())
                <img src="{{ $clinicSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
            @endif
            <h2>{{ $clinicSettings->clinic_name ?? 'Eye Care Center' }}</h2>
            <div class="subtitle">
                {{ $clinicSettings->clinic_address ?? '' }}
                @if(!empty($clinicSettings->clinic_contact)) | Tel: {{ $clinicSettings->clinic_contact }} @endif
                @if(!empty($clinicSettings->clinic_email)) | Email: {{ $clinicSettings->clinic_email }} @endif
            </div>
            <h1>MEDICAL RECORD</h1>
            <div class="subtitle">Complete Patient Medical History</div>
        </div>

        <!-- Patient Information -->
        <div class="patient-info">
            <h3>Patient Information</h3>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Patient Name:</div>
                    <div class="info-value">{{ $patient->name }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Patient ID:</div>
                    <div class="info-value">{{ $patient->pxnumber }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date of Birth:</div>
                    <div class="info-value">
                        {{ \Carbon\Carbon::parse($patient->dob)->format('d M, Y') }}
                        ({{ \Carbon\Carbon::parse($patient->dob)->age }} years old)
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Gender:</div>
                    <div class="info-value">{{ $patient->gender }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Contact:</div>
                    <div class="info-value">{{ $patient->contact }}</div>
                </div>
                @if($patient->email)
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value">{{ $patient->email }}</div>
                </div>
                @endif
                <div class="info-row">
                    <div class="info-label">Address:</div>
                    <div class="info-value">{{ $patient->address }}</div>
                </div>
                @if($patient->occupation)
                <div class="info-row">
                    <div class="info-label">Occupation:</div>
                    <div class="info-value">{{ $patient->occupation }}</div>
                </div>
                @endif
                @if($patient->civil_status)
                <div class="info-row">
                    <div class="info-label">Civil Status:</div>
                    <div class="info-value">{{ $patient->civil_status }}</div>
                </div>
                @endif
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="summary-box">
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-number">{{ $consultations->count() }}</div>
                    <div class="summary-label">Total Consultations</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number">{{ $refractions->count() }}</div>
                    <div class="summary-label">Refraction Records</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number">
                        @if($consultations->count() > 0)
                            {{ $consultations->first()->created_at->diffForHumans() }}
                        @else
                            N/A
                        @endif
                    </div>
                    <div class="summary-label">Last Visit</div>
                </div>
            </div>
        </div>

        <!-- Consultation History -->
        <div class="section">
            <div class="section-header">CONSULTATION HISTORY</div>

            @forelse($consultations as $consultation)
                <div class="consultation-card">
                    <div class="consultation-header">
                        <div class="consultation-date">
                            Consultation Date: {{ $consultation->created_at->format('d M, Y h:i A') }}
                        </div>
                        <div class="consultation-doctor">
                            Doctor: {{ $consultation->user->name ?? 'N/A' }}
                        </div>
                    </div>

                    <div class="consultation-body">
                        <div class="field-group">
                            <span class="field-label">Chief Complaint:</span>
                            <span class="field-value">{{ $consultation->chiefcomplain ?? 'N/A' }}</span>
                        </div>

                        <div class="field-group">
                            <span class="field-label">History:</span>
                            <span class="field-value">{{ $consultation->history ?? 'N/A' }}</span>
                        </div>

                        <div class="field-group">
                            <span class="field-label">Visual Acuity:</span>
                            <span class="field-value">{{ $consultation->visualacuity ?? 'N/A' }}</span>
                        </div>

                        <div class="field-group">
                            <span class="field-label">IOP:</span>
                            <span class="field-value">{{ $consultation->iop ?? 'N/A' }}</span>
                        </div>

                        <div class="field-group">
                            <span class="field-label">Examination:</span>
                            <span class="field-value">{{ $consultation->examination ?? 'N/A' }}</span>
                        </div>

                        <div class="field-group">
                            <span class="field-label">Diagnosis:</span>
                            <span class="field-value"><strong>{{ $consultation->diagnosis ?? 'N/A' }}</strong></span>
                        </div>

                        @if($consultation->drugs)
                        <div class="field-group">
                            <span class="field-label">Prescribed Drugs:</span>
                            <span class="field-value">{{ $consultation->drugs }}</span>
                        </div>
                        @endif

                        @if($consultation->notes || $consultation->addenda->count())
                        <div class="field-group">
                            <span class="field-label">Notes:</span>
                            <span class="field-value">
                                @if($consultation->notes)
                                    {{ $consultation->notes }}
                                @endif
                                @if($consultation->addenda->count())
                                    <br><strong>Clinical Addenda:</strong>
                                    @foreach($consultation->addenda as $addendum)
                                        <br>{{ $addendum->created_at->format('d M Y h:i A') }} by {{ $addendum->user->name ?? 'Unknown user' }}: {{ $addendum->note }}
                                    @endforeach
                                @endif
                            </span>
                        </div>
                        @endif

                        <div class="field-group">
                            <span class="field-label">Next Visit:</span>
                            <span class="field-value">
                                {{ $consultation->nextvisit ? \Carbon\Carbon::parse($consultation->nextvisit)->format('d M, Y') : 'N/A' }}
                            </span>
                        </div>

                        <!-- Products/Frames -->
                        @if($consultation->products && count($consultation->products) > 0)
                        <div style="margin-top: 15px;">
                            <strong>Products/Frames Prescribed:</strong>
                            <table class="products-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Batch</th>
                                        <th class="text-right">Qty</th>
                                        <th class="text-right">Price</th>
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
                                        <td colspan="4" class="text-right">Grand Total:</td>
                                        <td class="text-right">
                                            {{ currency() }} {{ number_format(collect($consultation->products)->sum('total'), 2) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="no-data">No consultation records found</div>
            @endforelse
        </div>

        <!-- Refraction History -->
        @if($refractions->count() > 0)
        <div class="section page-break">
            <div class="section-header">REFRACTION HISTORY</div>

            @foreach($refractions as $refraction)
                <div class="consultation-card">
                    <div class="consultation-header">
                        <div class="consultation-date">
                            Refraction Date: {{ $refraction->created_at->format('d M, Y h:i A') }}
                        </div>
                        <div class="consultation-doctor">
                            @if($refraction->consultation)
                                Related to Consultation: {{ $refraction->consultation->created_at->format('d M, Y') }}
                            @endif
                        </div>
                    </div>

                    <div class="consultation-body">
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

                        @if($refraction->pd)
                        <div class="field-group">
                            <span class="field-label">PD (Pupillary Distance):</span>
                            <span class="field-value">{{ $refraction->pd }}</span>
                        </div>
                        @endif

                        @if($refraction->lensType)
                        <div class="field-group">
                            <span class="field-label">Lens Type:</span>
                            <span class="field-value">{{ $refraction->lensType }}</span>
                        </div>
                        @endif

                        @if($refraction->refractionnotes)
                        <div class="field-group">
                            <span class="field-label">Notes:</span>
                            <span class="field-value">{{ $refraction->refractionnotes }}</span>
                        </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <div class="footer-grid">
                <div class="footer-left">
                    Generated on: {{ $generatedAt->format('d M, Y h:i A') }}<br>
                    Generated by: {{ $generatedBy->name ?? 'System' }}
                </div>
                <div class="footer-right">
                    This is an official medical record<br>
                    {{ $clinicSettings->clinic_name ?? 'Eye Care Center' }} - Patient ID: {{ $patient->pxnumber }}
                </div>
            </div>
        </div>
    </div>
</body>
</html>
