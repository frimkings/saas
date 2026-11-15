@include('pdf.slip.frame', ['body' => 'pdf.slip.refraction-body', 'pageTitle' => 'Spectacle prescription - '.($patient->name ?? 'Patient')])
