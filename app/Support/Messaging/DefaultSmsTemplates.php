<?php

namespace App\Support\Messaging;

/**
 * Built-in SMS wording. Clinics get an editable copy of each template on the SMS
 * Templates screen; until they have one, messages fall back to this text. Every message
 * starts switched off: a clinic chooses which ones its patients get, since each spends credits.
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
        // Automatic follow-ups (FollowUpSms).
        'appointment_missed_auto' => [
            'label'        => 'Missed Appointment — Automatic Follow-up',
            'message'      => 'Hello [NAME], we missed you at [CLINIC] on [DATE]. Please call us to book a new time that suits you.',
            'placeholders' => ['[NAME]', '[DATE]', '[REASON]', '[CLINIC]'],
        ],
        'order_delay' => [
            'label'        => 'Order Delay — New Ready Date',
            'message'      => 'Hello [NAME], an update on your order [ORDER_ID] at [CLINIC]: it will now be ready on [DATE]. We are sorry for the wait.',
            'placeholders' => ['[NAME]', '[ORDER_ID]', '[DATE]', '[CLINIC]'],
        ],
        'aftercare_followup' => [
            'label'        => 'Aftercare Follow-up',
            'message'      => 'Hello [NAME], we hope you are enjoying your new glasses from [CLINIC]. If anything feels uncomfortable or unclear, please call us, we are happy to help.',
            'placeholders' => ['[NAME]', '[ORDER_ID]', '[CLINIC]'],
        ],
        'clinical_recall' => [
            'label'        => 'Eye Exam Due (Doctor\'s Recall)',
            'message'      => 'Hello [NAME], your next eye examination at [CLINIC] is due on [DATE]. Please call us to book your appointment.',
            'placeholders' => ['[NAME]', '[DATE]', '[CLINIC]'],
        ],
        // Sent when staff move or cancel an upcoming appointment (AppointmentNotifier).
        'appointment_rescheduled' => [
            'label'        => 'Appointment Rescheduled',
            'message'      => 'Hello [NAME], your appointment at [CLINIC] has been moved to [DATE] at [TIME]. Please call us if this time does not suit you.',
            'placeholders' => ['[NAME]', '[DATE]', '[TIME]', '[REASON]', '[CLINIC]'],
        ],
        'appointment_cancelled' => [
            'label'        => 'Appointment Cancelled',
            'message'      => 'Hello [NAME], your appointment at [CLINIC] on [DATE] at [TIME] has been cancelled. Please call us to book a new time.',
            'placeholders' => ['[NAME]', '[DATE]', '[TIME]', '[REASON]', '[CLINIC]'],
        ],
        // Automatic (FollowUpSms).
        'balance_reminder' => [
            'label'        => 'Outstanding Balance Reminder',
            'message'      => 'Hello [NAME], a friendly reminder from [CLINIC] that you have an outstanding balance of [AMOUNT]. Please visit or call us to settle it. Thank you.',
            'placeholders' => ['[NAME]', '[AMOUNT]', '[CLINIC]'],
        ],
        'feedback_request' => [
            'label'        => 'Feedback / Review Request',
            'message'      => 'Hello [NAME], thank you for visiting [CLINIC]. How was your experience? We would love your feedback: [REVIEW_LINK]',
            'placeholders' => ['[NAME]', '[CLINIC]', '[REVIEW_LINK]'],
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
        // One receipt per visit (VisitReceiptSms), sent at the clinic's closing time.
        'visit_receipt' => [
            'label'        => 'Visit Receipt',
            'message'      => 'Hello [NAME], thank you for visiting [CLINIC]. You have paid GHS [AMOUNT] in full. Receipt: [VISIT_NO].',
            'placeholders' => ['[NAME]', '[AMOUNT]', '[VISIT_NO]', '[CLINIC]'],
        ],
        'visit_part_payment' => [
            'label'        => 'Visit Part Payment',
            'message'      => 'Hello [NAME], [CLINIC] received GHS [PAID] from you today. Paid so far: GHS [AMOUNT]. Balance due: GHS [BALANCE]. Ref: [VISIT_NO].',
            'placeholders' => ['[NAME]', '[PAID]', '[AMOUNT]', '[BALANCE]', '[VISIT_NO]', '[CLINIC]'],
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

    /**
     * Whether a clinic that has no saved switch for this message gets it: never. Every message
     * is the clinic's own choice (all were switched off on release, 2026_10_01_000006).
     */
    public static function onByDefault(string $key): bool
    {
        return false;
    }
}
