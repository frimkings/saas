<?php

namespace App\Support\Messaging;

/**
 * How the Communications → Messages page lists the SMS templates (DefaultSmsTemplates holds
 * their wording): which group each belongs to, when it is sent, and whether it is
 *  - automatic: sent by the system; the clinic switches it on or off;
 *  - staff:     wording for a message staff send with a button; always available, no switch;
 *  - pro:       part of "SMS reminders & campaigns" (Feature::SMS_CAMPAIGNS).
 * The broadcast message lives on its own page (Communications → Broadcast).
 */
final class MessageCatalog
{
    public const GROUPS = [
        'appointments' => ['Appointments', 'fas fa-calendar-check'],
        'orders'       => ['Glasses & orders', 'fas fa-glasses'],
        'payments'     => ['Payments', 'fas fa-money-bill-wave'],
        'care'         => ['Patient care & marketing', 'fas fa-heartbeat'],
        'staff'        => ['Sent by staff (wording only)', 'fas fa-user-edit'],
    ];

    /** key => [group, icon, colour, when it is sent, automatic|staff, pro] */
    public const MESSAGES = [
        'appointment_booking'         => ['appointments', 'fas fa-calendar-check', 'primary', 'When an appointment is booked.', 'automatic', false],
        'appointment_auto_reminder'   => ['appointments', 'fas fa-clock', 'warning', 'About 24 hours before each appointment.', 'automatic', true],
        'appointment_rescheduled'     => ['appointments', 'fas fa-calendar-alt', 'info', 'When staff move an upcoming appointment.', 'automatic', false],
        'appointment_cancelled'       => ['appointments', 'fas fa-calendar-times', 'danger', 'When staff cancel an upcoming appointment.', 'automatic', false],
        'appointment_missed_auto'     => ['appointments', 'fas fa-user-times', 'warning', 'The day after a missed appointment, unless the patient has rebooked or come in.', 'automatic', true],
        'online_booking_received'     => ['appointments', 'fas fa-globe', 'secondary', 'When someone books on your website.', 'automatic', false],

        'spectacles_ready'            => ['orders', 'fas fa-glasses', 'success', 'When an order is marked Ready.', 'automatic', false],
        'spectacles_reminder'         => ['orders', 'fas fa-redo', 'info', 'Pickup reminders for glasses not yet collected (and the "Send reminder" button).', 'automatic', false],
        'order_delay'                 => ['orders', 'fas fa-hourglass-half', 'warning', 'When staff change the promised ready date of an open order.', 'automatic', false],
        'aftercare_followup'          => ['orders', 'fas fa-hand-holding-heart', 'success', 'A few days after glasses are collected.', 'automatic', true],
        'partner_job_ready'           => ['orders', 'fas fa-handshake', 'success', 'To a partner clinic when its job is ready.', 'automatic', false],
        'partner_jobs_awaiting'       => ['orders', 'fas fa-boxes', 'info', 'To a partner clinic listing its jobs still waiting.', 'automatic', false],

        'payment_receipt'             => ['payments', 'fas fa-receipt', 'dark', 'After a payment at the POS (when one receipt per visit is off).', 'automatic', false],
        'visit_receipt'               => ['payments', 'fas fa-file-invoice', 'success', 'At closing time, for a visit paid in full that day (one receipt per visit on).', 'automatic', false],
        'visit_part_payment'          => ['payments', 'fas fa-file-invoice-dollar', 'warning', 'At closing time, for a visit paid towards that day that still has a balance (one receipt per visit on).', 'automatic', false],
        'balance_reminder'            => ['payments', 'fas fa-money-bill-wave', 'warning', 'To patients and customers with an unpaid balance, on your schedule.', 'automatic', false],

        'clinical_recall'             => ['care', 'fas fa-calendar-plus', 'purple', 'Before the "next eye exam due" date the doctor sets.', 'automatic', true],
        'patient_recall'              => ['care', 'fas fa-user-clock', 'purple', 'To patients who have not visited for a while.', 'automatic', true],
        'spectacle_renewal'           => ['care', 'fas fa-sync-alt', 'info', 'When a patient\'s spectacles are due for renewal.', 'automatic', false],
        'birthday_wishes'             => ['care', 'fas fa-birthday-cake', 'danger', 'On the patient\'s birthday.', 'automatic', true],
        'feedback_request'            => ['care', 'fas fa-star', 'success', 'The day after a visit or collection, at most once every 90 days.', 'automatic', true],

        'appointment_reminder'        => ['staff', 'fas fa-bell', 'warning', 'Sent when staff press the SMS button on an appointment.', 'staff', false],
        'appointment_missed_followup' => ['staff', 'fas fa-phone', 'secondary', 'Sent when staff press "Send follow-up" on a missed appointment.', 'staff', false],
    ];

    /** One click to get going: the messages almost every clinic wants. */
    public const RECOMMENDED = ['appointment_booking', 'appointment_auto_reminder', 'appointment_rescheduled', 'appointment_cancelled', 'spectacles_ready', 'payment_receipt'];

    /** Messages that come with "SMS reminders & campaigns", including the broadcast. */
    public static function proKeys(): array
    {
        $keys = array_keys(array_filter(self::MESSAGES, fn ($meta) => $meta[5]));

        return [...$keys, 'custom_broadcast'];
    }

    public static function isStaffSent(string $key): bool
    {
        return (self::MESSAGES[$key][4] ?? null) === 'staff';
    }

    public static function group(string $key): ?string
    {
        return self::MESSAGES[$key][0] ?? null;
    }
}
