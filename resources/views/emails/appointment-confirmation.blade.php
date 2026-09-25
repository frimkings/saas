<x-mail::message>
# Appointment Confirmed

Dear {{ $patientName }},

Your appointment has been successfully booked. Here are the details:

<x-mail::table>
| | |
|:--|:--|
| **Clinic** | {{ $clinic }} |
| **Date** | {{ $date }} |
| **Time** | {{ $time }} |
| **Reason** | {{ $reason }} |
</x-mail::table>

Please arrive a few minutes early. If you need to reschedule or cancel, contact us as soon as possible.

Thank you for choosing {{ $clinic }}. We look forward to seeing you.

Warm regards,<br>
{{ $clinic }}
</x-mail::message>
