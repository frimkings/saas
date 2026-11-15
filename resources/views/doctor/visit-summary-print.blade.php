<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Visit Summary - {{ $patient->name }}</title>
    @include('pdf.clinical.styles', ['screen' => true])
</head>
<body>
    <div class="print-bar"><button type="button" onclick="window.print()">Print visit summary</button></div>
    @include('pdf.clinical.header', ['title' => 'Visit summary'])

    @include('pdf.clinical.visit-card', ['consultation' => $consultation, 'refraction' => null])

    @if($consultation->relationLoaded('documents') && $consultation->documents->count())
        <div class="small"><span class="muted">Attached documents:</span>
            {{ $consultation->documents->map(fn ($d) => ucwords(str_replace('_', ' ', $d->document_type)).': '.$d->title)->implode('; ') }}</div>
    @endif

    @include('pdf.clinical.footer')

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
