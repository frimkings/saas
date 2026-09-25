<?php

namespace App\Support\Messaging;

/**
 * Built-in SMS wording. Clinics get an editable copy of each template on the SMS
 * Templates screen; until they have one, messages fall back to this text.
 */
class DefaultSmsTemplates
{
    public const TEMPLATES = [
        'appointment_booking' => [
            'label'        => 'Appointment Booking Confirmation',
            'message'      => 'Hello [NAME], your appointment at [CLINIC] is confirmed for [DATE] at [TIME] – [REASON].',
            'placeholders' => ['[NAME]', '[DATE]', '[TIME]', '[REASON]', '[CLINIC]'],
        ],
        'appointment_reminder' => [
            'label'        => 'Appointment Reminder',
            'message'      => 'Hello [NAME], this is a reminder of your appointment at [CLINIC] on [DATE] at [TIME] – [REASON]. Please be on time.',
            'placeholders' => ['[NAME]', '[DATE]', '[TIME]', '[REASON]', '[CLINIC]'],
        ],
        'appointment_auto_reminder' => [
            'label'        => 'Appointment Auto-Reminder',
            'message'      => 'Hello [NAME], this is a reminder of your appointment at [CLINIC] tomorrow, [DATE] at [TIME]. Please call us if you need to reschedule.',
            'placeholders' => ['[NAME]', '[CLINIC]', '[DATE]', '[TIME]'],
        ],
        'appointment_missed_followup' => [
            'label'        => 'Missed Appointment Follow-up',
            'message'      => 'Hello [NAME], we noticed you missed your appointment on [DATE]. Please call us to reschedule.',
            'placeholders' => ['[NAME]', '[DATE]', '[REASON]', '[CLINIC]'],
        ],
        'online_booking_received' => [
            'label'        => 'Online Booking Received',
            'message'      => 'Hello [NAME], we have received your booking request at [CLINIC] ([BRANCH]) for [DATE]. We will contact you shortly to confirm the time.',
            'placeholders' => ['[NAME]', '[DATE]', '[CLINIC]'],
        ],
        'spectacles_ready' => [
            'label'        => 'Spectacles Ready for Pickup',
            'message'      => 'Hello [NAME], your spectacles (Order [ORDER_ID]) are ready for collection at [CLINIC]. Please bring this message when you come in.',
            'placeholders' => ['[NAME]', '[ORDER_ID]', '[CLINIC]'],
        ],
        'partner_job_ready' => [
            'label'        => 'Partner Job Ready',
            'message'      => 'Hello [PARTNER], job [ORDER_ID] for [WEARER] (your ref [REFERENCE]) is ready for collection at [CLINIC].',
            'placeholders' => ['[PARTNER]', '[ORDER_ID]', '[WEARER]', '[REFERENCE]', '[CLINIC]'],
        ],
        'partner_jobs_awaiting' => [
            'label'        => 'Partner Jobs Awaiting Collection',
            'message'      => 'Hello [PARTNER], [COUNT] job(s) are ready for collection at [CLINIC]: [JOBS].',
            'placeholders' => ['[PARTNER]', '[COUNT]', '[JOBS]', '[CLINIC]'],
        ],
        'spectacles_reminder' => [
            'label'        => 'Spectacles Pickup Reminder',
            'message'      => 'Hello [NAME], your spectacles (Order [ORDER_ID]) are still waiting for collection at [CLINIC]. Please come in at your earliest convenience.',
            'placeholders' => ['[NAME]', '[ORDER_ID]', '[CLINIC]'],
        ],
        'payment_receipt' => [
            'label'        => 'Payment Receipt',
            'message'      => 'Hello [NAME], payment of GHS [AMOUNT] received at [CLINIC]. Transaction: [TXN_ID]. Thank you!',
            'placeholders' => ['[NAME]', '[AMOUNT]', '[TXN_ID]', '[CLINIC]'],
        ],
        'birthday_wishes' => [
            'label'        => 'Birthday Wishes',
            'message'      => 'Happy Birthday [NAME]! Wishing you good health and clear vision. From all of us at [CLINIC].',
            'placeholders' => ['[NAME]', '[CLINIC]'],
        ],
        'patient_recall' => [
            'label'        => 'Patient Recall',
            'message'      => "Hello [NAME], it's been a while since your last visit to [CLINIC]. Your eyes deserve regular care — book your next check-up today. Call us anytime!",
            'placeholders' => ['[NAME]', '[CLINIC]'],
        ],
        'spectacle_renewal' => [
            'label'        => 'Spectacle Renewal Reminder',
            'message'      => 'Dear [NAME], your spectacles are due for renewal on [DATE]. Please visit [CLINIC] for your annual eye review.',
            'placeholders' => ['[NAME]', '[DATE]', '[CLINIC]'],
        ],
        'custom_broadcast' => [
            'label'        => 'Custom Broadcast',
            'message'      => 'Dear [NAME], [CLINIC] wishes you a joyful [OCCASION]! Thank you for trusting us with your eye care.',
            'placeholders' => ['[NAME]', '[CLINIC]', '[OCCASION]'],
        ],
    ];

    public static function message(string $key): ?string
    {
        return self::TEMPLATES[$key]['message'] ?? null;
    }
}
