<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Consultation Report - {{ $patient->name }}</title>
    @include('pdf.clinical.styles')
</head>
<body>
    @include('pdf.clinical.footer')
    @include('pdf.clinical.header', ['title' => 'Consultation report'])

    @include('pdf.clinical.visit-card', ['consultation' => $consultation, 'refraction' => $refraction ?? null])

    <table class="sign">
        <tr>
            <td><div class="line">Patient / guardian signature &nbsp;·&nbsp; Date</div></td>
            <td style="text-align:right;"><div class="line" style="margin-left:auto;">{{ $consultation->doctor->name ?? $consultation->user->name ?? 'Doctor' }} &nbsp;·&nbsp; {{ $consultation->created_at->format('d M Y') }}</div></td>
        </tr>
    </table>
</body>
</html>
