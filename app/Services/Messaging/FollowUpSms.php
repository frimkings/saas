<?php

namespace App\Services\Messaging;

use App\Models\Appointments;
use App\Models\Consultations;
use App\Models\LensOrder;
use App\Models\Patient;
use App\Models\Sales;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\OpticalCollectionNotifier;
use App\Services\SmsService;
use App\Support\Messaging\SmsAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The automatic patient follow-up SMS, each switched on per clinic on the SMS Templates screen
 * (all start off, since they spend the clinic's credits):
 *
 *  - missed appointment: the day after a no-show, unless the patient has rebooked or come in;
 *  - order delay: straight away when staff promise a new ready date for an open order;
 *  - aftercare: a few days after glasses are collected (Settings: aftercare_sms_days);
 *  - clinical recall: ahead of the "next eye exam due" date the doctor set (clinical_recall_lead_days);
 *  - balance reminder: what a person still owes, on the clinic's schedule (balance_reminder_*);
 *  - feedback request: the day after a visit or collection, with the clinic's review link.
 *
 * The scheduled ones run hourly and catch up: whatever is due and not yet sent goes on the next
 * run within sending hours, so a sleeping server delays them rather than losing them. Each only
 * looks back a few days, so switching a message on never texts a backlog of old records.
 */
class FollowUpSms
{
    /** Clinic-time hours (start inclusive, end exclusive) when scheduled follow-ups may go out. */
    public const SEND_FROM_HOUR = 9;
    public const SEND_UNTIL_HOUR = 19;

    /** How far back each catch-up looks. */
    public const MISSED_LOOKBACK_DAYS = 2;
    public const AFTERCARE_WINDOW_DAYS = 3;
    public const RECALL_LOOKBACK_DAYS = 30;
    public const FEEDBACK_WINDOW_DAYS = 3;

    /** A patient is asked for feedback at most once in this many days. */
    public const FEEDBACK_EVERY_DAYS = 90;

    /** Order statuses that already mean the date no longer matters to the customer. */
    private const ORDER_DONE = ['Quotation', 'Ready', 'Ready for Collection', 'Collected', 'Cancelled'];

    /** @return array{sent: int, skipped: int} */
    public function missedAppointments(): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        if (! $this->switchedOn('appointment_missed_auto') || ! $this->inSendingHours()) return $counts;

        Appointments::markPastAsMissed();
        $appointments = Appointments::with(['patient', 'branch'])
            ->where('status', 'Missed')
            ->whereNull('missed_followup_sent_at')
            ->where('scheduled_at', '>=', Carbon::today()->subDays(self::MISSED_LOOKBACK_DAYS))
            ->where('scheduled_at', '<', Carbon::today())
            ->where(fn ($q) => $q->whereNull('reminder_channel')->orWhere('reminder_channel', '!=', 'none'))
            ->orderBy('scheduled_at')
            ->get();

        foreach ($appointments as $appointment) {
            $patient = $appointment->patient;
            if (! $patient?->contact || $this->rebookedOrSeen($appointment)) {
                $counts['skipped']++;
                continue;
            }

            $message = SmsTemplate::render('appointment_missed_auto', [
                '[NAME]' => $patient->name,
                '[DATE]' => $appointment->scheduled_at->format('M d, Y'),
                '[REASON]' => (string) $appointment->title,
            ], $appointment->branch);

            if ($this->send($patient->contact, $message, $patient->id, 'appointment_missed_auto')) {
                $appointment->forceFill(['missed_followup_sent_at' => now()])->save();
                $counts['sent']++;
            } else {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /** @return array{sent: int, skipped: int} */
    public function aftercare(): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        if (! $this->switchedOn('aftercare_followup') || ! $this->inSendingHours()) return $counts;

        $days = max(1, (int) (Setting::getSettings()->aftercare_sms_days ?: 5));
        $orders = LensOrder::with('branch')
            ->where('status', 'Collected')
            ->whereNull('cancelled_at')
            ->whereNull('aftercare_sent_at')
            ->whereBetween('collected_at', [now()->subDays($days + self::AFTERCARE_WINDOW_DAYS), now()->subDays($days)])
            ->orderBy('collected_at')
            ->get();

        foreach ($orders as $order) {
            // Partner clinics look after their own patients; renewalRecipient() returns null for them.
            $wearer = $order->renewalRecipient();
            if (! ($wearer['phone'] ?? null)) {
                $counts['skipped']++;
                continue;
            }

            $message = SmsTemplate::render('aftercare_followup', [
                '[NAME]' => $wearer['name'],
                '[ORDER_ID]' => (string) $order->order_id,
            ], $order->branch);

            if ($this->send($wearer['phone'], $message, $wearer['patient_id'], 'aftercare_followup')) {
                $order->forceFill(['aftercare_sent_at' => now()])->save();
                $counts['sent']++;
            } else {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * Patients whose doctor-set exam date is near, unless they already have an appointment
     * booked or have been seen since the reminder window opened.
     *
     * @return array{sent: int, skipped: int}
     */
    public function clinicalRecalls(): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        if (! $this->switchedOn('clinical_recall') || ! $this->inSendingHours()) return $counts;

        $lead = max(0, (int) (Setting::getSettings()->clinical_recall_lead_days ?? 7));
        $patients = Patient::whereNotNull('next_exam_due_on')
            ->where('next_exam_due_on', '<=', Carbon::today()->addDays($lead)->toDateString())
            ->where('next_exam_due_on', '>=', Carbon::today()->subDays(self::RECALL_LOOKBACK_DAYS)->toDateString())
            ->where(fn ($q) => $q->whereNull('clinical_recall_sent_for')->orWhereColumn('clinical_recall_sent_for', '!=', 'next_exam_due_on'))
            ->whereNotNull('contact')->where('contact', '!=', '')
            ->orderBy('next_exam_due_on')
            ->get();

        foreach ($patients as $patient) {
            $due = $patient->next_exam_due_on;
            // Any branch of this clinic counts: the patient may book or be seen anywhere.
            $booked = Appointments::withoutGlobalScope('branch')->where('patient_id', $patient->id)
                ->where('scheduled_at', '>=', now())->whereNotIn('status', ['Cancelled', 'Missed'])->exists();
            $seen = Consultations::withoutGlobalScope('branch')->where('patient_id', $patient->id)
                ->where('created_at', '>=', $due->copy()->subDays($lead)->startOfDay())->exists();
            if ($booked || $seen) {
                $counts['skipped']++;
                continue;
            }

            $message = SmsTemplate::render('clinical_recall', [
                '[NAME]' => $patient->name,
                '[DATE]' => $due->format('M d, Y'),
            ]);

            if ($this->send($patient->contact, $message, $patient->id, 'clinical_recall')) {
                $patient->forceFill(['clinical_recall_sent_for' => $due->toDateString()])->save();
                $counts['sent']++;
            } else {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * One text per person with what they still owe this branch: clinic part-payments (the
     * Outstanding Balances list, not visit bills still open) and collected glasses not fully
     * paid. Partner jobs are billed through partner accounts. The first reminder waits
     * balance_reminder_first_days; each debt is reminded every balance_reminder_every_days,
     * at most balance_reminder_max times.
     *
     * @return array{sent: int, skipped: int}
     */
    public function balanceReminders(): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        if (! $this->switchedOn('balance_reminder') || ! $this->inSendingHours()) return $counts;

        $settings = Setting::getSettings();
        $firstAfter = max(0, (int) ($settings->balance_reminder_first_days ?? 3));
        $every = max(1, (int) ($settings->balance_reminder_every_days ?? 7));
        $max = max(1, (int) ($settings->balance_reminder_max ?? 3));
        $due = fn ($query) => $query->where('balance_reminders_sent', '<', $max)
            ->where(fn ($q) => $q->whereNull('balance_reminded_at')->orWhere('balance_reminded_at', '<=', now()->subDays($every)));

        $people = [];
        $sales = Sales::with('patient')
            ->where('payment_status', 'partial')->where('business_line', 'clinic')->where('is_refunded', false)
            ->where(fn ($q) => $q->whereNull('bill_status')->orWhere('bill_status', '!=', 'open'))
            ->whereNotNull('patient_id')->whereRaw('total_amount - insurer_amount > amount_paid')
            ->where('created_at', '<=', now()->subDays($firstAfter))
            ->tap($due)->get();
        foreach ($sales as $sale) {
            $owed = $sale->remaining_balance; // the insurer's share is not the patient's debt
            $this->addDebt($people, $sale->patient?->contact, $sale->patient?->name, $sale->patient_id, $owed, $sale);
        }

        $orders = LensOrder::balanceDue()->where('status', 'Collected')
            ->where(fn ($q) => $q->whereNull('order_source')->orWhere('order_source', '!=', 'partner'))
            ->where('collected_at', '<=', now()->subDays($firstAfter))
            ->tap($due)->get();
        foreach ($orders as $order) {
            $wearer = $order->renewalRecipient();
            $owed = round((float) $order->frame_price + (float) $order->lens_price + (float) $order->glazing_fee
                + (float) $order->service_total - (float) $order->discount_amount - (float) $order->paid_amount, 2);
            $this->addDebt($people, $wearer['phone'] ?? null, $wearer['name'] ?? null, $wearer['patient_id'] ?? null, $owed, $order);
        }

        foreach ($people as $person) {
            if (! $person['phone'] || $person['amount'] <= 0) {
                $counts['skipped']++;
                continue;
            }

            $message = SmsTemplate::render('balance_reminder', [
                '[NAME]' => $person['name'] ?: 'Customer',
                '[AMOUNT]' => currency().' '.number_format($person['amount'], 2),
            ]);
            if (! $this->send($person['phone'], $message, $person['patient_id'], 'balance_reminder')) {
                $counts['skipped']++;
                continue;
            }

            foreach ($person['debts'] as $debt) {
                $debt->forceFill(['balance_reminders_sent' => (int) $debt->balance_reminders_sent + 1, 'balance_reminded_at' => now()])->save();
            }
            $counts['sent']++;
        }

        return $counts;
    }

    /**
     * "How was your visit?" the day after a consultation or a glasses collection, with the
     * clinic's review link. At most once per patient every FEEDBACK_EVERY_DAYS days. Never
     * sent while the message uses [REVIEW_LINK] and no link is set.
     *
     * @return array{sent: int, skipped: int}
     */
    public function feedbackRequests(): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        if (! $this->switchedOn('feedback_request') || ! $this->inSendingHours() || ! SmsAvailability::check()['available']) return $counts;

        $link = trim((string) Setting::getSettings()->review_link);
        $text = SmsTemplate::where('key', 'feedback_request')->value('message') ?? \App\Support\Messaging\DefaultSmsTemplates::message('feedback_request');
        if ($link === '' && str_contains((string) $text, '[REVIEW_LINK]')) return $counts;

        $from = now()->subDays(1 + self::FEEDBACK_WINDOW_DAYS);
        $until = now()->subDay();
        $visits = Consultations::with(['patient', 'branch'])->whereNull('feedback_requested_at')
            ->whereBetween('created_at', [$from, $until])->get()
            ->map(fn (Consultations $visit) => [$visit, $visit->patient?->name, $visit->patient?->contact, $visit->patient_id, $visit->branch]);
        $collections = LensOrder::with('branch')->where('status', 'Collected')->whereNull('feedback_requested_at')
            ->where(fn ($q) => $q->whereNull('order_source')->orWhere('order_source', '!=', 'partner'))
            ->whereBetween('collected_at', [$from, $until])->get()
            ->map(function (LensOrder $order) {
                $wearer = $order->renewalRecipient();
                return [$order, $wearer['name'] ?? null, $wearer['phone'] ?? null, $wearer['patient_id'] ?? null, $order->branch];
            });

        foreach ($visits->concat($collections) as [$record, $name, $phone, $patientId, $branch]) {
            // Handled either way: an opted-out patient or a bad number won't change by the next run.
            $record->forceFill(['feedback_requested_at' => now()])->save();
            if (! $phone || ($patientId && $this->askedForFeedbackRecently($patientId))) {
                $counts['skipped']++;
                continue;
            }

            $message = SmsTemplate::render('feedback_request', ['[NAME]' => $name ?: 'there', '[REVIEW_LINK]' => $link], $branch);
            $this->send($phone, $message, $patientId, 'feedback_request') ? $counts['sent']++ : $counts['skipped']++;
        }

        return $counts;
    }

    /**
     * Staff promised a new ready date for an order: tell the customer (or partner clinic).
     * Returns whether a text went out, so the screen can skip offering a manual message.
     */
    public function orderDateChanged(LensOrder $order, $previousDate): bool
    {
        $new = $order->pickUpDate ? Carbon::parse($order->pickUpDate)->startOfDay() : null;
        $old = $previousDate ? Carbon::parse($previousDate)->toDateString() : null;
        if (! $new || ! $old || $new->toDateString() === $old || $new->lt(Carbon::today())) return false;
        if ($order->cancelled_at || in_array($order->status, self::ORDER_DONE, true)) return false;
        if (! $this->switchedOn('order_delay')) return false;

        $recipient = app(OpticalCollectionNotifier::class)->recipient($order);
        if (! ($recipient['phone'] ?? null) || ($recipient['notify_via'] ?? 'sms') !== 'sms') return false;

        $message = SmsTemplate::render('order_delay', [
            '[NAME]' => $recipient['name'],
            '[ORDER_ID]' => (string) $order->order_id,
            '[DATE]' => $new->format('D j M Y'),
        ], $order->branch);

        return $this->send($recipient['phone'], $message, $recipient['patient_id'], 'order_delay');
    }

    /** Within the clinic's sending hours, in its own timezone. */
    public function inSendingHours(?Carbon $now = null): bool
    {
        $timezone = app(TenantContext::class)->clinic()?->default_timezone ?: config('app.timezone');
        $hour = ($now ?? Carbon::now())->copy()->setTimezone($timezone)->hour;

        return $hour >= self::SEND_FROM_HOUR && $hour < self::SEND_UNTIL_HOUR;
    }

    private function switchedOn(string $key): bool
    {
        $enabled = SmsTemplate::where('key', $key)->value('is_enabled');

        return $enabled === null ? \App\Support\Messaging\DefaultSmsTemplates::onByDefault($key) : (bool) $enabled;
    }

    /** Gather one person's debts so they get a single text with the total. */
    private function addDebt(array &$people, ?string $phone, ?string $name, ?int $patientId, float $owed, $debt): void
    {
        if ($owed <= 0.004) return;
        $key = $patientId ? 'patient:'.$patientId : 'phone:'.preg_replace('/\D+/', '', (string) $phone);
        $people[$key] ??= ['phone' => $phone, 'name' => $name, 'patient_id' => $patientId, 'amount' => 0.0, 'debts' => []];
        $people[$key]['amount'] = round($people[$key]['amount'] + $owed, 2);
        $people[$key]['debts'][] = $debt;
    }

    private function askedForFeedbackRecently(int $patientId): bool
    {
        return \App\Models\SmsLog::where('template_key', 'feedback_request')->where('patient_id', $patientId)
            ->where('created_at', '>=', now()->subDays(self::FEEDBACK_EVERY_DAYS))->exists();
    }

    private function rebookedOrSeen(Appointments $missed): bool
    {
        $rebooked = Appointments::withoutGlobalScope('branch')->where('patient_id', $missed->patient_id)
            ->whereKeyNot($missed->id)->where('scheduled_at', '>', $missed->scheduled_at)
            ->whereNotIn('status', ['Cancelled', 'Missed'])->exists();

        return $rebooked || Consultations::withoutGlobalScope('branch')->where('patient_id', $missed->patient_id)
            ->where('created_at', '>=', $missed->scheduled_at->copy()->startOfDay())->exists();
    }

    private function send(string $phone, string $message, ?int $patientId, string $templateKey): bool
    {
        if ($message === '' || ! SmsAvailability::check()['available']) return false;

        return (bool) (app(SmsService::class)->send($phone, $message, $patientId, $templateKey)['success'] ?? false);
    }
}
