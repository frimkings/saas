<?php

namespace App\Services;

use App\Mail\OwnerSummaryMail;
use App\Models\Branch;
use App\Models\InsuranceClaim;
use App\Models\SaleAdjustment;
use App\Models\InsurerPayment;
use App\Models\Clinic;
use App\Models\Expense;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Models\Sales;
use App\Support\Feature;
use App\Support\Tenancy\ActAsClinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The daily, weekly and monthly sales emails to the clinic owner. Each goes at 10 AM clinic
 * time: the daily one for yesterday, the weekly one on Monday for last week, the monthly one
 * on the 1st for last month. The platform decides which a clinic gets by ticking them on its
 * plan. Every branch is counted, and each is also shown on its own.
 */
class OwnerSummaryService
{
    public const SEND_HOUR = 10;

    public const PERIODS = [
        'daily' => Feature::DAILY_SUMMARY,
        'weekly' => Feature::WEEKLY_SUMMARY,
        'monthly' => Feature::MONTHLY_SUMMARY,
    ];

    public function __construct(private readonly OwnerMailer $mailer, private readonly OpticalReportService $optical) {}

    /**
     * Send whatever is due for this clinic now. Safe to run every hour: each summary goes once
     * (a failed one is tried again on the next run).
     *
     * @return array<string, string> period => status, for the summaries that were due
     */
    public function sendDue(Clinic $clinic, ?Carbon $now = null): array
    {
        $local = ($now ?? Carbon::now())->copy()->setTimezone($this->timezone($clinic));
        if ($local->hour < self::SEND_HOUR) return [];

        $due = ['daily' => true, 'weekly' => $local->isMonday(), 'monthly' => $local->day === 1];
        $results = [];
        foreach (array_keys(array_filter($due)) as $period) {
            [$from, $to] = self::period($period, $local);
            if (! $this->includes($clinic, self::PERIODS[$period])) continue;
            $results[$period] = $this->send($clinic, $period, $from, $to);
        }

        return $results;
    }

    /** Build and send one summary. Also used to send a sample on demand. */
    public function send(Clinic $clinic, string $period, Carbon $from, Carbon $to, ?string $key = null): string
    {
        $subject = self::title($period) . ' - ' . $clinic->name . ' - ' . self::periodLabel($period, $from, $to);

        return $this->mailer->send($clinic, 'summary_' . $period, $key ?? "summary:{$period}:{$from->toDateString()}", $subject,
            fn () => new OwnerSummaryMail($this->build($clinic, $period, $from, $to)));
    }

    /** The period a summary sent on $local covers: yesterday, last week or last month. */
    public static function period(string $period, Carbon $local): array
    {
        // Clinic-time days: the figures count from the clinic's midnight, not the server's.
        $day = Carbon::parse($local->toDateString(), $local->getTimezone());

        return match ($period) {
            'weekly' => [$day->copy()->subWeek()->startOfWeek(), $day->copy()->subWeek()->endOfWeek()->startOfDay()],
            'monthly' => [$day->copy()->subMonthNoOverflow()->startOfMonth(), $day->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$day->copy()->subDay(), $day->copy()->subDay()],
        };
    }

    public static function title(string $period): string
    {
        return ucfirst($period) . ' summary';
    }

    public static function periodLabel(string $period, Carbon $from, Carbon $to): string
    {
        return match ($period) {
            'weekly' => $from->format('j M') . ' – ' . $to->format('j M Y'),
            'monthly' => $from->format('F Y'),
            default => $from->format('D j M Y'),
        };
    }

    /** Everything the email shows. */
    public function build(Clinic $clinic, string $period, Carbon $from, Carbon $to): array
    {
        // Compared with the day, week or month before.
        [$prevFrom, $prevTo] = match ($period) {
            'weekly' => [$from->copy()->subWeek(), $to->copy()->subWeek()],
            'monthly' => [$from->copy()->subMonthNoOverflow()->startOfMonth(), $from->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$from->copy()->subDay(), $to->copy()->subDay()],
        };
        // Weekly and monthly emails also look at the longer view: old debts, discounts, insurance.
        $longView = $period !== 'daily';
        $rows = [];
        $before = [];
        ActAsClinic::eachBranch($clinic, function (Branch $branch, bool $clinical, bool $optical) use ($from, $to, $prevFrom, $prevTo, $longView, &$rows, &$before) {
            $rows[$branch->name] = $this->figures($from, $to, $clinical, $optical, true, $longView);
            $before[] = $this->figures($prevFrom, $prevTo, $clinical, $optical, false, false);
        });

        $total = $this->sum($rows);
        $previous = $this->sum($before);

        return [
            'clinic' => $clinic->name,
            'title' => self::title($period),
            'period' => $period,
            'label' => self::periodLabel($period, $from, $to),
            'currency' => $clinic->default_currency ?: currency(),
            'total' => $total,
            'change' => [
                'sales' => OpticalReportService::change($total['sales'], $previous['sales']),
                'received' => OpticalReportService::change($total['received'], $previous['received']),
                'expenses' => OpticalReportService::change($total['expenses'], $previous['expenses']),
            ],
            'longView' => $longView,
            'compareWith' => match ($period) { 'weekly' => 'the week before', 'monthly' => 'the month before', default => 'the day before' },
            'branches' => count($rows) > 1 ? $rows : [],
            'url' => $total['hasClinic'] ? route('admin.reports') : route('optical.reports'),
        ];
    }

    /** One branch's figures; the tenant context is already set to it. */
    private function figures(Carbon $from, Carbon $to, bool $clinical, bool $optical, bool $withBalances, bool $longView = false): array
    {
        // Times are stored in UTC; $from and $to are clinic-time days.
        $range = [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()];
        $clinicSales = $clinical
            ? Sales::where('business_line', 'clinic')->where('is_refunded', false)->whereBetween('created_at', $range)->selectRaw('COUNT(*) as n, COALESCE(SUM(total_amount),0) as total')->first()
            : null;
        $opticalSales = $optical ? $this->optical->sales($from, $to) : null;
        $payments = PaymentTransaction::whereBetween('created_at', $range)->get(['amount', 'payment_method']);
        $methods = OpticalReportService::METHODS;

        $figures = [
            'hasClinic' => $clinical,
            'hasOptical' => $optical,
            'clinicSales' => (float) ($clinicSales->total ?? 0),
            'opticalSales' => (float) ($opticalSales['revenue'] ?? 0),
            'transactions' => (int) ($clinicSales->n ?? 0) + (int) ($opticalSales['jobs'] ?? 0) + (int) ($opticalSales['retailCount'] ?? 0),
            'received' => round((float) $payments->sum('amount'), 2),
            'byMethod' => $payments->groupBy('payment_method')
                ->mapWithKeys(fn ($group, $method) => [$methods[$method] ?? (ucfirst(str_replace('_', ' ', (string) $method)) ?: 'Other') => (float) $group->sum('amount')])->all(),
            'refunds' => round((float) RefundLog::where('status', RefundLog::STATUS_PROCESSED)->whereBetween('processed_at', $range)->sum('refunded_amount'), 2),
            'expenses' => round((float) Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2),
            'owed' => 0.0,
            'owed90' => 0.0,
            'discounts' => 0.0,
            'claimsWaiting' => 0,
            'claimsWaitingAmount' => 0.0,
            'claimsRejected' => 0,
            'claimsRejectedAmount' => 0.0,
            'insurerReceived' => 0.0,
            'insurerOwed' => 0.0,
            'insurerOwed90' => 0.0,
            'insurerWrittenOff' => 0.0,
        ];
        $figures['sales'] = round($figures['clinicSales'] + $figures['opticalSales'], 2);

        if ($clinical) {
            // Insurer remittances are money in too, shown as their own line.
            $figures['insurerReceived'] = round((float) InsurerPayment::whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2);
            if ($figures['insurerReceived'] > 0) {
                $figures['received'] = round($figures['received'] + $figures['insurerReceived'], 2);
                $figures['byMethod']['Insurer payments'] = $figures['insurerReceived'];
            }
        }

        if ($withBalances) {
            // Still owed today, whenever the sale was made.
            $clinicOwed = $clinical
                ? (float) Sales::where('business_line', 'clinic')->where('is_refunded', false)->whereRaw(Sales::PATIENT_BALANCE_SQL . ' > 0')->sum(DB::raw(Sales::PATIENT_BALANCE_SQL))
                : 0.0;
            $opticalOwed = $optical ? $this->optical->owed() : null;
            $figures['owed'] = round($clinicOwed + (float) ($opticalOwed['total'] ?? 0), 2);
            $figures['insurerOwed'] = $clinical ? round((float) Sales::awaitingInsurer()->sum(DB::raw(Sales::INSURER_OWED_SQL)), 2) : 0.0;

            if ($longView) {
                // Owed for more than 90 days: the debts least likely to be paid.
                $clinicOld = $clinical
                    ? (float) Sales::where('business_line', 'clinic')->where('is_refunded', false)->whereRaw(Sales::PATIENT_BALANCE_SQL . ' > 0')
                        ->where('created_at', '<', today()->subDays(90))->sum(DB::raw(Sales::PATIENT_BALANCE_SQL))
                    : 0.0;
                $figures['owed90'] = round($clinicOld + (float) ($opticalOwed['ages']['90+']['amount'] ?? 0), 2);
                $figures['discounts'] = round((float) ($clinical ? Sales::where('business_line', 'clinic')->where('is_refunded', false)->whereBetween('created_at', $range)->sum('discount_amount') : 0)
                    + (float) ($opticalSales['discounts'] ?? 0), 2);
                if ($clinical) {
                    // Claims sent over 30 days ago with no answer yet, and claims turned down in the period.
                    $waiting = InsuranceClaim::where('status', 'submitted')->whereDateIndexed('submission_date', '<', today()->subDays(30));
                    $rejected = InsuranceClaim::where('status', 'rejected')->whereBetween('updated_at', $range);
                    $figures['claimsWaiting'] = (clone $waiting)->count();
                    $figures['claimsWaitingAmount'] = round((float) $waiting->sum('claim_amount'), 2);
                    $figures['claimsRejected'] = (clone $rejected)->count();
                    $figures['claimsRejectedAmount'] = round((float) $rejected->sum('claim_amount'), 2);
                    $figures['insurerOwed90'] = round((float) Sales::awaitingInsurer()->where('created_at', '<', today()->subDays(90))->sum(DB::raw(Sales::INSURER_OWED_SQL)), 2);
                    $figures['insurerWrittenOff'] = round((float) SaleAdjustment::where('type', 'insurance_write_off')->whereBetween('created_at', $range)->sum('amount'), 2);
                }
            }
        }

        return $figures;
    }

    private function sum(array $rows): array
    {
        $total = ['hasClinic' => false, 'hasOptical' => false, 'byMethod' => []];
        foreach ($rows as $row) {
            $total['hasClinic'] = $total['hasClinic'] || $row['hasClinic'];
            $total['hasOptical'] = $total['hasOptical'] || $row['hasOptical'];
            foreach (['sales', 'clinicSales', 'opticalSales', 'transactions', 'received', 'refunds', 'expenses', 'owed', 'owed90', 'discounts',
                'claimsWaiting', 'claimsWaitingAmount', 'claimsRejected', 'claimsRejectedAmount',
                'insurerReceived', 'insurerOwed', 'insurerOwed90', 'insurerWrittenOff'] as $field) {
                $total[$field] = round(($total[$field] ?? 0) + $row[$field], 2);
            }
            foreach ($row['byMethod'] as $method => $amount) {
                $total['byMethod'][$method] = ($total['byMethod'][$method] ?? 0) + $amount;
            }
        }
        arsort($total['byMethod']);
        $total['net'] = round(($total['received'] ?? 0) - ($total['refunds'] ?? 0) - ($total['expenses'] ?? 0), 2);

        return $total;
    }

    /** Whether the clinic's plan (or licence) includes this email. */
    public function includes(Clinic $clinic, string $feature): bool
    {
        $allowed = false;
        ActAsClinic::run($clinic, null, function () use ($feature, &$allowed) {
            $allowed = LicenseService::has($feature);
        });

        return $allowed;
    }

    private function timezone(Clinic $clinic): string
    {
        return $clinic->default_timezone ?: config('app.timezone');
    }
}
