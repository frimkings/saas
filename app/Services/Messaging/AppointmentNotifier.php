<?php

namespace App\Services\Messaging;

use App\Models\{Appointments, OnlineBooking, SmsTemplate};
use App\Services\{NotificationService, SmsService};
use Illuminate\Support\Facades\Log;

/** One place for the patient messages sent around appointments, whichever screen or API created them. */
class AppointmentNotifier
{
    /**
     * Booking confirmation by SMS (unless the screen offers a WhatsApp link instead).
     * Returns the confirmation text. Never throws: a failed message must not undo a booking.
     */
    public function confirmed(Appointments $appointment, bool $sendSms = true): string
    {
        $patient = $appointment->patient;
        if (!$patient) {
            return '';
        }

        $msg = '';
        try {
            $branch = $appointment->branch_id ? $appointment->branch : null;
            $date   = $appointment->scheduled_at->format('M d, Y');
            $time   = $appointment->scheduled_at->format('h:i A');

            $msg = SmsTemplate::render('appointment_booking', [
                '[NAME]'   => $patient->name,
                '[DATE]'   => $date,
                '[TIME]'   => $time,
                '[REASON]' => $appointment->title,
            ], $branch, evenIfOff: ! $sendSms);
            if ($sendSms && $msg && $patient->contact) {
                app(SmsService::class)->send($patient->contact, $msg, $patient->id, 'appointment_booking');
            }
            // Patients hear from the clinic by SMS only; email is for the clinic owner.
        } catch (\Throwable $e) {
            Log::warning('Appointment confirmation could not be sent.', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
        }

        return $msg;
    }

    /** The appointment moved: tell the patient the new date and time. See notify(). */
    public function rescheduled(Appointments $appointment): string
    {
        return $this->notify($appointment, 'appointment_rescheduled');
    }

    /** The clinic cancelled the appointment. See notify(). */
    public function cancelled(Appointments $appointment): string
    {
        return $this->notify($appointment, 'appointment_cancelled');
    }

    /**
     * Same channel rule as the confirmation: SMS, unless the booking prefers WhatsApp (the text
     * is returned for a WhatsApp link instead) or asked for no messages. Only upcoming
     * appointments: tidying up old ones never texts anyone. Never throws.
     */
    private function notify(Appointments $appointment, string $templateKey): string
    {
        $patient = $appointment->patient;
        $channel = $appointment->reminder_channel ?: 'sms';
        if (! $patient || $channel === 'none' || $appointment->scheduled_at->lt(now()->startOfDay())) {
            return '';
        }

        $sendSms = $channel !== 'whatsapp';
        $msg = '';
        try {
            $msg = SmsTemplate::render($templateKey, [
                '[NAME]'   => $patient->name,
                '[DATE]'   => $appointment->scheduled_at->format('M d, Y'),
                '[TIME]'   => $appointment->scheduled_at->format('h:i A'),
                '[REASON]' => (string) $appointment->title,
            ], $appointment->branch_id ? $appointment->branch : null);
            if ($sendSms && $msg && $patient->contact) {
                app(SmsService::class)->send($patient->contact, $msg, $patient->id, $templateKey);
            }
        } catch (\Throwable $e) {
            Log::warning('Appointment change could not be sent.', ['appointment_id' => $appointment->id, 'template' => $templateKey, 'error' => $e->getMessage()]);
        }

        return $msg;
    }

    /** Acknowledge a website booking request and alert the branch front desk. */
    public function onlineBookingReceived(OnlineBooking $booking): void
    {
        try {
            $msg = SmsTemplate::render('online_booking_received', [
                '[NAME]' => $booking->name,
                '[DATE]' => $booking->preferred_date?->format('M d, Y') ?? 'your preferred date',
            ]);
            if ($msg) app(SmsService::class)->send($booking->phone, $msg, null, 'online_booking_received');

            NotificationService::sendToRoles(
                ['Secretary', 'Super Admin'],
                'online_booking',
                'New online booking request',
                "{$booking->name} ({$booking->phone}) requested {$booking->service}.",
                'fas fa-globe',
                'text-info',
                route('secretary.appointments', absolute: false)
            );
        } catch (\Throwable $e) {
            Log::warning('Online booking acknowledgement could not be sent.', ['online_booking_id' => $booking->id, 'error' => $e->getMessage()]);
        }
    }
}
