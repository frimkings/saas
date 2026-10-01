<?php

namespace App\Services\Visits;

use App\Models\PatientVisit;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Support\Messaging\SmsAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * One SMS per visit per day, at the clinic's closing time (Settings: closing_time, default
 * 17:00 clinic time), for clinics with "one receipt per visit" on. A visit the patient paid
 * towards that day gets:
 *  - visit_receipt when it is fully paid (including a part-paid visit settled on a later day);
 *  - visit_part_payment with what was paid that day and the balance, when a balance remains.
 *
 * Runs hourly and catches up: a day not yet texted goes on the next run between closing time
 * and 21:00, or from 08:00 the next morning if the server was asleep.
 */
class VisitReceiptSms
{
    public const DEFAULT_CLOSING_TIME = '17:00';

    /** Clinic-time hours when catch-up texts for an earlier day may still go out. */
    private const SEND_FROM_HOUR = 8;
    private const SEND_UNTIL_HOUR = 21;

    public function __construct(private PatientVisits $visits)
    {
    }

    /** @return array{sent: int, skipped: int} */
    public function sendDue(?Carbon $now = null): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        if (!PatientVisits::enabled()) {
            return $counts;
        }

        $timezone = app(TenantContext::class)->clinic()?->default_timezone ?: config('app.timezone');
        $local = ($now ?? Carbon::now())->copy()->setTimezone($timezone);
        if ($local->hour < self::SEND_FROM_HOUR || $local->hour >= self::SEND_UNTIL_HOUR) {
            return $counts;
        }

        foreach ([$local->copy()->subDay()->startOfDay(), $local->copy()->startOfDay()] as $day) {
            if ($local->lt($this->closingTime($day))) {
                continue; // that day has not closed yet
            }
            $this->sendForDay($day, $counts);
        }

        return $counts;
    }

    public static function normalizeClosingTime(?string $time): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time) ? $time : self::DEFAULT_CLOSING_TIME;
    }

    private function closingTime(Carbon $day): Carbon
    {
        [$hour, $minute] = explode(':', self::normalizeClosingTime(Setting::getSettings()->closing_time));

        return $day->copy()->setTime((int) $hour, (int) $minute);
    }

    /** Text every visit the patient paid towards on this clinic day, once. */
    private function sendForDay(Carbon $day, array &$counts): void
    {
        $range = [$day->copy()->utc(), $day->copy()->endOfDay()->utc()];
        $paidThatDay = fn ($payments) => $payments->whereBetween('created_at', $range);

        $visits = PatientVisit::with(['patient', 'branch'])
            ->where(fn ($q) => $q->whereNull('sms_sent_for')->orWhere('sms_sent_for', '<', $day->toDateString()))
            ->whereHas('sales', fn ($sales) => $sales->where('is_refunded', false)->whereHas('paymentTransactions', $paidThatDay))
            ->orderBy('id')
            ->get();

        foreach ($visits as $visit) {
            $summary = $this->visits->summary($visit);
            $paidToday = round((float) $summary['sales']->where('is_refunded', false)
                ->flatMap(fn ($sale) => $sale->paymentTransactions)
                ->filter(fn ($payment) => $payment->created_at->between($range[0], $range[1]))
                ->sum('amount'), 2);
            $phone = $visit->patient?->contact;

            if ($paidToday <= 0 || !$phone) {
                $visit->forceFill(['sms_sent_for' => $day->toDateString()])->save();
                $counts['skipped']++;
                continue;
            }

            $key = $summary['settled'] ? 'visit_receipt' : 'visit_part_payment';
            $message = SmsTemplate::render($key, [
                '[NAME]'     => $visit->patient->name,
                '[AMOUNT]'   => number_format($summary['paid'], 2),
                '[PAID]'     => number_format($paidToday, 2),
                '[BALANCE]'  => number_format($summary['balance'], 2),
                '[VISIT_NO]' => (string) $visit->visit_number,
            ], $visit->branch);

            if ($message === '') {
                // This message is switched off: nothing to send for this day.
                $visit->forceFill(['sms_sent_for' => $day->toDateString()])->save();
                $counts['skipped']++;
                continue;
            }

            if (!SmsAvailability::check()['available']) {
                $counts['skipped']++; // no credits or SMS off: try again on the next run
                continue;
            }

            $sent = (bool) (app(SmsService::class)->send($phone, $message, $visit->patient_id, $key)['success'] ?? false);
            if ($sent) {
                $visit->forceFill(['sms_sent_for' => $day->toDateString()])->save();
                $counts['sent']++;
            } else {
                $counts['skipped']++;
            }
        }
    }
}
