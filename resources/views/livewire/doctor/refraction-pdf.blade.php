<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Refraction Result</title>
    <style>
        body { font-family: Arial, sans-serif; font-size:12px; margin:0; padding:5px; }
        table { width:100%; border-collapse:collapse; margin-top:5px; }
        th, td { border:1px solid #000; padding:3px; text-align:center; }
        h2, h3 { text-align:center; margin:2px 0; }
        p { margin:2px 0; }
        .clinic-logo { max-width:35mm; max-height:18mm; object-fit:contain; display:block; margin:0 auto 4px; }
    </style>
</head>
<body>
    @if(!empty($appSettings) && $appSettings->logoDataUri())
        <img src="{{ $appSettings->logoDataUri() }}" class="clinic-logo" alt="Clinic Logo">
    @endif
    <h2>{{ $appSettings->clinic_name ?? 'Clinic Name' }}</h2>
    <h3>Refraction Result</h3>

    <p>
        <strong>Patient:</strong> {{ $patient->name }}<br>
        <strong>Age:</strong> {{ \Carbon\Carbon::parse($patient->dob)->age }}<br>
        <strong>Date:</strong> {{ $refraction->created_at->format('d/m/Y') }}
    </p>

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
                <td>OD</td>
                <td>{{ $refraction->refractionOD }}</td>
                <td>{{ $refraction->refractionOD_distance_va }}</td>
                <td>{{ $refraction->refractionOD_ADD }}</td>
                <td>{{ $refraction->refractionOD_near_va }}</td>
            </tr>
            <tr>
                <td>OS</td>
                <td>{{ $refraction->refractionOS }}</td>
                <td>{{ $refraction->refractionOS_distance_va }}</td>
                <td>{{ $refraction->refractionOS_ADD }}</td>
                <td>{{ $refraction->refractionOS_near_va }}</td>
            </tr>
        </tbody>
    </table>

    <p><strong>PD:</strong> {{ $refraction->pd }} | <strong>Lens Type:</strong> {{ $refraction->lensType }}</p>

    <hr>
    <p style="text-align:center;">Thank you for visiting!</p>
</body>
</html>
