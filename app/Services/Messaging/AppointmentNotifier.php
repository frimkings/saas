<?php

namespace App\Services\Messaging;

use App\Mail\AppointmentConfirmationMail;
use App\Models\{Appointments, OnlineBooking, Setting, SmsTemplate};
use App\Services\{EmailService, NotificationService, SmsService};
use Illuminate\Support\Facades\Log;

/** One place for the patient messages sent around appointments, whichever screen or API created them. */
class AppointmentNotifier
{
    /**
     * Booking confirmation by SMS (unless the screen offers a WhatsApp link instead) and email.
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
            ], $branch);
            if ($sendSms && $msg && $patient->contact) {
                app(SmsService::class)->send($patient->contact, $msg, $patient->id, 'appointment_booking');
            }

            if ($patient->email) {
                (new EmailService)->send($patient->email, new AppointmentConfirmationMail(
                    $patient->name,
                    Setting::getSettings()->clinic_name ?? 'the clinic',
                    $date,
                    $time,
                    $appointment->title,
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Appointment confirmation could not be sent.', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
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
