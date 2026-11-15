@include('pdf.slip.frame', ['body' => 'pdf.slip.prescription-body', 'pageTitle' => 'Prescription - '.($patient->name ?? 'Patient')])
