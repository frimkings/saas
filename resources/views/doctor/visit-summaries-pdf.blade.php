<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visit Summaries - {{ $patient->name }}</title>
    @include('pdf.clinical.styles')
</head>
<body>
    @include('pdf.clinical.footer')
    @include('pdf.clinical.header', ['title' => $consultations->count() === 1 ? 'Visit summary' : 'Visit summaries ('.$consultations->count().')'])

    @foreach($consultations as $consultation)
        @include('pdf.clinical.visit-card', ['consultation' => $consultation, 'refraction' => null])
    @endforeach
</body>
</html>
