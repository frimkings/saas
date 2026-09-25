<x-mail::message>
# Payment Receipt

Dear {{ $patientName }},

Thank you for your payment. Please find your receipt details below.

<x-mail::table>
| | |
|:--|:--|
| **Clinic** | {{ $clinic }} |
| **Amount Paid** | {{ currency() }} {{ $amount }} |
| **Transaction ID** | {{ $transactionId }} |
| **Date** | {{ $paymentDate }} |
</x-mail::table>

Please keep this email as your proof of payment. If you have any questions about your balance or services, feel free to contact us.

Thank you for trusting {{ $clinic }} with your eye care.

Warm regards,<br>
{{ $clinic }}
</x-mail::message>
