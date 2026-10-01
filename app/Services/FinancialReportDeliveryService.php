<?php

namespace App\Services;

use App\Mail\FinancialReportMail;
use App\Models\ReportDelivery;
use App\Models\Sales;
use App\Models\Setting;
use App\Support\BusinessLine;
use App\Support\FinanceStatements;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class FinancialReportDeliveryService
{
    public function queueReport(string $period, bool $force = false, bool $useToday = false): ReportDelivery
    {
        $settings = Setting::getSettings();
        [$start, $end, $subject, $periodLabel] = $this->resolvePeriod($period, $force, $useToday);
        $recipients = $this->normalizeRecipients((array) ($settings->report_recipients ?? []));
        $report = $this->buildReport($period, $start, $end, $subject, $periodLabel);

        $deliveryKey = $force
            ? 'manual:financial:' . $period . ':' . now()->format('YmdHis')
            : 'scheduled:financial:' . $period . ':' . $start->toDateString() . ':' . $end->toDateString();

        return ReportDelivery::firstOrCreate(
            ['delivery_key' => $deliveryKey],
            [
                'period' => $period,
                'period_start' => $start,
                'period_end' => $end,
                'subject' => $subject,
                'report_payload' => $this->serializeReport($report),
                'recipients' => $recipients,
                'status' => ReportDelivery::STATUS_PENDING,
            ]
        );
    }

    public function attemptDelivery(ReportDelivery $delivery): array
    {
        if ($delivery->status === ReportDelivery::STATUS_SENT) {
            return ['sent' => 0, 'failed' => 0];
        }

        $sent = [];
        $failed = [];

        foreach ($delivery->pendingRecipients() as $email) {
            try {
                Mail::to($email)->send(new FinancialReportMail($this->reportForMail($delivery)));
                $sent[] = $email;
            } catch (\Throwable $e) {
                $failed[] = [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $delivery->markAttempt(
            $sent,
            $failed,
            collect($failed)->pluck('error')->filter()->first()
        );

        return ['sent' => count($sent), 'failed' => count($failed)];
    }

    public function reportForMail(ReportDelivery $delivery): array
    {
        $report = $delivery->report_payload;
        $report['start'] = Carbon::parse($report['start']);
        $report['end'] = Carbon::parse($report['end']);
        $report['clinic'] = Setting::getSettings();

        return $report;
    }

    private function buildReport(string $period, Carbon $start, Carbon $end, string $subject, string $periodLabel): array
    {
        // The report covers every business the subscriber runs; with both, each is also shown on its own.
        $lines = FinanceStatements::availableLines() ?: BusinessLine::all();
        $inPeriod = fn ($query, string $column) => $period === 'daily'
            ? $query->whereDateIndexed($column, $start->toDateString())
            : $query->whereBetween($column, [$start->toDateTimeString(), $end->toDateTimeString()]);

        $sales = $inPeriod(Sales::whereIn('business_line', $lines), 'created_at')->get();
        $refundsInPeriod = $inPeriod(Sales::whereIn('business_line', $lines), 'refunded_at')->where('is_refunded', true)->get();

        $report = [
            'subject' => $subject,
            'period_label' => $periodLabel,
            'start' => $start,
            'end' => $end,
            'clinic' => Setting::getSettings(),
        ] + $this->figures($sales, $refundsInPeriod);

        if (count($lines) > 1) {
            foreach ($lines as $line) {
                $report['lines'][$line] = $this->figures($sales->where('business_line', $line), $refundsInPeriod->where('business_line', $line));
            }
        }

        return $report;
    }

    /** Totals for a set of sales and the refunds processed in the same period. */
    private function figures($sales, $refundsInPeriod): array
    {
        $nonRefunded = $sales->where('is_refunded', false);
        $grossRevenue = (float) $nonRefunded->sum('total_amount');
        $refundTotal = (float) $refundsInPeriod->sum('total_amount');

        return [
            'total_transactions' => $sales->count(),
            'gross_revenue' => $grossRevenue,
            'net_revenue' => $grossRevenue - $refundTotal,
            'paid_count' => $nonRefunded->where('payment_status', 'paid')->count(),
            'partial_count' => $nonRefunded->where('payment_status', 'partial')->count(),
            'pending_count' => $nonRefunded->where('payment_status', 'pending')->count(),
            'total_discounts' => $nonRefunded->sum('discount_amount'),
            'refund_count' => $refundsInPeriod->count(),
            'refund_total' => $refundTotal,
            'outstanding' => $nonRefunded->sum(fn ($s) => $s->remaining_balance),
        ];
    }

    private function serializeReport(array $report): array
    {
        unset($report['clinic']);

        $report['start'] = $report['start']->toDateTimeString();
        $report['end'] = $report['end']->toDateTimeString();

        return $report;
    }

    private function resolvePeriod(string $period, bool $isTest, bool $useToday): array
    {
        if ($period === 'weekly') {
            if ($useToday) {
                $start = Carbon::now()->startOfWeek();
                $end = Carbon::now();
            } else {
                $start = Carbon::now()->subWeek()->startOfWeek();
                $end = Carbon::now()->subWeek()->endOfWeek()->endOfDay();
            }
            $periodLabel = $useToday ? 'This Week (so far)' : 'Weekly';
            $subject = 'Weekly Financial Report - ' . $start->format('d M') . ' to ' . $end->format('d M Y');
        } else {
            if ($useToday) {
                $start = Carbon::today()->startOfDay();
                $end = Carbon::now();
            } else {
                $start = Carbon::yesterday()->startOfDay();
                $end = Carbon::yesterday()->endOfDay();
            }
            $periodLabel = $useToday ? 'Today (so far)' : 'Daily';
            $subject = 'Daily Financial Report - ' . $start->format('d M Y');
        }

        return [$start, $end, $subject, $periodLabel];
    }

    private function normalizeRecipients(array $recipients): array
    {
        return collect($recipients)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
