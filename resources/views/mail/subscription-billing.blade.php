{{-- Billing notices use the same layout as every other owner email. --}}
@include('mail.owner-notice', [
    'clinicName' => $messageData['clinic_name'],
    'heading' => $messageData['title'],
    'intro' => $messageData['body'],
    'details' => array_filter([
        'Amount' => $messageData['amount'] ?? null,
        'Invoice' => $messageData['invoice'] ?? null,
    ]),
    'buttonLabel' => 'View subscription & billing',
    'buttonUrl' => $messageData['url'],
    'footnote' => null,
])
