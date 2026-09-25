<x-mail::message>
# Your Spectacles Are Ready

Dear {{ $patientName }},

Great news! Your spectacles are ready for collection at {{ $clinic }}.

<x-mail::table>
| | |
|:--|:--|
| **Order ID** | {{ $orderId }} |
| **Clinic** | {{ $clinic }} |
</x-mail::table>

Please bring this email or your order reference when you come in to collect your spectacles. Our team will be happy to assist you.

We look forward to seeing you soon.

Warm regards,<br>
{{ $clinic }}
</x-mail::message>
