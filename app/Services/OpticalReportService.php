<?php

namespace App\Services;

use App\Models\LensOrder;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Models\SaleItem;
use App\Models\Sales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The figures behind Optical Reports: the cards at the top, the tabs below them and the
 * printable reports (end of day, sales by category, aged receivables).
 *
 * Sales count when the job is ordered; money counts when it is received. Owed is every
 * balance still open today, whenever the job was ordered.
 */
class OpticalReportService
{
    public const METHODS = ['cash' => 'Cash', 'momo' => 'Mobile Money', 'card' => 'Card', 'bank_transfer' => 'Bank transfer'];
    public const AGES = ['0-30' => '0–30 days', '31-60' => '31–60 days', '61-90' => '61–90 days', '90+' => 'Over 90 days'];
    private const ORDER_TOTAL = LensOrder::TOTAL_SQL;

    /** Sales made in the period: jobs ordered, accessories sold at the counter, and fees kept on cancelled jobs. */
    public function sales(Carbon $from, Carbon $to): array
    {
        $range = $this->range($from, $to);
        $orders = LensOrder::whereBetween('created_at', $range)->whereNotIn('status', ['Quotation', 'Cancelled'])
            ->selectRaw('COUNT(*) as jobs, SUM(COALESCE(frame_price,0)) as frames, SUM(COALESCE(lens_price,0)+COALESCE(glazing_fee,0)) as lenses, SUM(COALESCE(service_total,0)) as services, SUM(COALESCE(discount_amount,0)) as discounts, SUM(COALESCE(paid_amount,0)) as paid')
            ->first();
        $retail = $this->retail()->whereBetween('created_at', $range)
            ->selectRaw('COUNT(*) as sales, SUM(total_amount) as total, SUM(amount_paid) as paid')->first();
        // Refunded orders are cancelled; any cancellation fee kept is still income.
        $refunded = LensOrder::with('refundLog')->where('status', 'Cancelled')->whereNotNull('refund_log_id')->whereBetween('cancelled_at', $range)->get();
        // Jobs closed as abandoned keep their deposit: income, like a cancellation fee.
        $abandoned = LensOrder::where('status', 'Cancelled')->whereNull('refund_log_id')->where('cancellation_reason', 'like', 'Abandoned:%')
            ->whereBetween('cancelled_at', $range)->get(['id', 'cancellation_fee', 'cancelled_at']);

        $jobs = (int) $orders->jobs;
        $orderRevenue = round((float) $orders->frames + (float) $orders->lenses + (float) $orders->services - (float) $orders->discounts, 2);
        $refundFees = (float) $refunded->sum('cancellation_fee');
        $depositsKept = (float) $abandoned->sum('cancellation_fee');
        $categories = [
            'Frames' => (float) $orders->frames,
            'Lenses & coatings' => (float) $orders->lenses,
            'Services' => (float) $orders->services,
            'Accessories & POS' => (float) $retail->total,
            'Cancellation fees & deposits kept' => $refundFees + $depositsKept,
        ];

        return [
            'jobs' => $jobs,
            'orderRevenue' => $orderRevenue,
            'retailRevenue' => (float) $retail->total,
            'retailCount' => (int) $retail->sales,
            'categories' => $categories,
            'gross' => array_sum($categories),
            'discounts' => (float) $orders->discounts,
            'revenue' => round(array_sum($categories) - (float) $orders->discounts, 2),
            'avgOrder' => $jobs > 0 ? $orderRevenue / $jobs : 0.0,
            // How much of what was sold in the period has been paid so far.
            'paidShare' => $orderRevenue + (float) $retail->total > 0
                ? min(100, ((float) $orders->paid + (float) $retail->paid) / ($orderRevenue + (float) $retail->total) * 100) : null,
            'refundCount' => $refunded->count(),
            'refundTotal' => (float) $refunded->sum(fn ($order) => (float) $order->refundLog?->refunded_amount),
            'cancellationFees' => $refundFees + $depositsKept,
            'abandonedCount' => $abandoned->count(),
            'depositsKept' => $depositsKept,
            'fees' => $refunded->concat($abandoned),
        ];
    }

    /** Sales per day (per month over long ranges) for the trend chart. */
    public function trend(Carbon $from, Carbon $to, Collection $fees): array
    {
        $range = $this->range($from, $to);
        $monthly = $from->diffInDays($to) > 62;
        $key = fn ($date) => Carbon::parse($date)->format($monthly ? 'Y-m' : 'Y-m-d');
        $buckets = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $monthly ? $day->addMonthNoOverflow()->startOfMonth() : $day->addDay()) {
            $buckets[$key($day)] = 0.0;
        }
        LensOrder::whereBetween('created_at', $range)->whereNotIn('status', ['Quotation', 'Cancelled'])
            ->selectRaw('DATE(created_at) as day, SUM('.self::ORDER_TOTAL.') as amount')->groupBy('day')->get()
            ->each(function ($row) use (&$buckets, $key) { $buckets[$key($row->day)] = ($buckets[$key($row->day)] ?? 0) + (float) $row->amount; });
        $this->retail()->whereBetween('created_at', $range)->selectRaw('DATE(created_at) as day, SUM(total_amount) as amount')->groupBy('day')->get()
            ->each(function ($row) use (&$buckets, $key) { $buckets[$key($row->day)] = ($buckets[$key($row->day)] ?? 0) + (float) $row->amount; });
        $fees->each(function ($order) use (&$buckets, $key) { $buckets[$key($order->cancelled_at)] = ($buckets[$key($order->cancelled_at)] ?? 0) + (float) $order->cancellation_fee; });

        return collect($buckets)->map(fn ($amount, $bucket) => [
            'label' => Carbon::parse($monthly ? $bucket.'-01' : $bucket)->format($monthly ? 'M Y' : 'D M j'),
            'short' => Carbon::parse($monthly ? $bucket.'-01' : $bucket)->format($monthly ? 'M' : 'j'),
            'amount' => round($amount, 2),
        ])->values()->all();
    }

    /** Money received and refunded in the period: by method, by staff member and by day. */
    public function cash(Carbon $from, Carbon $to): array
    {
        $range = $this->range($from, $to);
        $opticalSales = Sales::withTrashed()->where('business_line', 'optical')->select('id');
        $payments = PaymentTransaction::with('collectedBy:id,name')->whereIn('sale_id', $opticalSales)->whereBetween('created_at', $range)->get();
        $refunds = RefundLog::whereIn('sale_id', $opticalSales)->where('status', RefundLog::STATUS_PROCESSED)->whereBetween('processed_at', $range)->get(['id', 'refunded_amount', 'processed_at']);
        $received = round((float) $payments->sum('amount'), 2);
        $refunded = round((float) $refunds->sum('refunded_amount'), 2);
        $byMethod = collect(self::METHODS)->mapWithKeys(fn ($label, $method) => [$label => (float) $payments->where('payment_method', $method)->sum('amount')]);
        $payments->whereNotIn('payment_method', array_keys(self::METHODS))->groupBy('payment_method')
            ->each(function ($group, $method) use (&$byMethod) { $byMethod[ucfirst(str_replace('_', ' ', (string) $method)) ?: 'Other'] = (float) $group->sum('amount'); });
        $days = $payments->groupBy(fn ($payment) => $payment->created_at->toDateString())->map(fn ($group) => (float) $group->sum('amount'));
        $refundDays = $refunds->groupBy(fn ($refund) => Carbon::parse($refund->processed_at)->toDateString())->map(fn ($group) => (float) $group->sum('refunded_amount'));

        // Expenses paid in cash came out of the till.
        $cashPaidOut = round((float) \App\Models\Expense::businessLine(\App\Models\Expense::OPTICAL)->where('payment_method', 'cash')
            ->whereBetween('expense_date', [$range[0]->toDateString(), $range[1]->toDateString()])->sum('amount'), 2);
        $cashIn = (float) $payments->where('payment_method', 'cash')->sum('amount');

        return [
            'received' => $received,
            'refunded' => $refunded,
            'net' => round($received - $refunded, 2),
            'cashIn' => round($cashIn, 2),
            'cashPaidOut' => $cashPaidOut,
            'cashExpected' => round($cashIn - $cashPaidOut, 2),
            'payments' => $payments->count(),
            'byMethod' => $byMethod->all(),
            'byStaff' => $payments->groupBy(fn ($payment) => $payment->collectedBy?->name ?? 'Not recorded')->map(fn ($group) => (float) $group->sum('amount'))->sortDesc()->all(),
            'byDay' => $days->keys()->merge($refundDays->keys())->unique()->sort()->values()
                ->mapWithKeys(fn ($day) => [$day => ['received' => $days[$day] ?? 0.0, 'refunded' => $refundDays[$day] ?? 0.0]])->all(),
        ];
    }

    /**
     * Every job with a balance still to pay, oldest first, grouped by how long ago it was
     * ordered. $from/$to also give the part owed on jobs ordered in the chosen dates.
     */
    public function owed(?Carbon $from = null, ?Carbon $to = null): array
    {
        $today = today();
        $orders = LensOrder::with(['patient', 'refraction.consultation.patient'])->balanceDue()
            ->orderBy('created_at')->get()
            ->map(function (LensOrder $order) use ($today) {
                $days = (int) $order->created_at->copy()->startOfDay()->diffInDays($today);
                return [
                    'id' => $order->id, 'order' => $order->order_id, 'status' => $order->status,
                    'customer' => $order->display_customer_name, 'phone' => $order->display_customer_phone,
                    'partner' => $order->isPartnerJob(), 'ordered' => $order->created_at, 'days' => $days,
                    'age' => match (true) { $days <= 30 => '0-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+' },
                    'total' => $order->total, 'balance' => round($order->total - (float) $order->paid_amount, 2),
                ];
            });
        $inPeriod = $from && $to ? $orders->filter(fn ($row) => $row['ordered']->between(...$this->range($from, $to))) : collect();

        return [
            'total' => round((float) $orders->sum('balance'), 2),
            'count' => $orders->count(),
            'fromPeriod' => round((float) $inPeriod->sum('balance'), 2),
            'ages' => collect(self::AGES)->map(fn ($label, $age) => ['label' => $label, 'amount' => round((float) $orders->where('age', $age)->sum('balance'), 2), 'count' => $orders->where('age', $age)->count()])->all(),
            'rows' => $orders->sortByDesc('days')->values(),
        ];
    }

    /** Remakes, what free remakes cost in lenses, and why they were needed. */
    public function remakes(Carbon $from, Carbon $to, int $jobs): array
    {
        $remakes = LensOrder::with('lensLines.product')->whereNotNull('remake_of_id')
            ->whereNotIn('status', ['Quotation', 'Cancelled'])->whereBetween('created_at', $this->range($from, $to))->get();
        $free = $remakes->where('remake_charge', 'free');

        return [
            'count' => $remakes->count(),
            'free' => $free->count(),
            'rate' => $jobs > 0 ? $remakes->count() / $jobs * 100 : 0.0,
            // Lenses used on free remakes, at cost price. Special-order lenses are not costed.
            'lensCost' => (float) $free->sum(fn ($order) => $order->lensLines->whereIn('status', ['held', 'consumed'])
                ->sum(fn ($line) => (float) ($line->unit_cost ?? $line->product?->cost_price))),
            'reasons' => $remakes->countBy('remake_reason')->sortDesc()
                ->mapWithKeys(fn ($count, $reason) => [LensOrder::REMAKE_REASONS[$reason] ?? ($reason ?: 'Not given') => $count])->all(),
        ];
    }

    /** Best sellers by value, stock products only. */
    public function topProducts(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return SaleItem::with('opticalProduct')->whereNotNull('optical_product_id')->whereBetween('created_at', $this->range($from, $to))
            ->selectRaw('optical_product_id, SUM(dispensed_quantity - refunded_quantity) as units, SUM((dispensed_quantity - refunded_quantity) * selling_price) as gross')
            ->groupBy('optical_product_id')->havingRaw('SUM(dispensed_quantity - refunded_quantity) > 0')
            ->orderByDesc('gross')->limit($limit)->get();
    }

    /** Change from the previous period as a percentage; null when there is nothing to compare with. */
    public static function change(float $now, float $before): ?float
    {
        return abs($before) < 0.005 ? null : round(($now - $before) / abs($before) * 100, 1);
    }

    /** The period of the same length just before this one. */
    public static function previous(Carbon $from, Carbon $to): array
    {
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    // ── Printable reports: rows for the shared statement layout ─────────────────

    public function endOfDayRows(Carbon $from, Carbon $to): array
    {
        $cash = $this->cash($from, $to);
        $sales = $this->sales($from, $to);
        $rows = [['type' => 'heading', 'label' => 'Money received']];
        foreach ($cash['byMethod'] as $method => $amount) $rows[] = ['type' => 'line', 'label' => $method, 'amount' => $amount];
        $rows[] = ['type' => 'total', 'label' => 'Total received', 'amount' => $cash['received'], 'note' => $cash['payments'].' '.\Illuminate\Support\Str::plural('payment', $cash['payments'])];
        $rows[] = ['type' => 'line', 'label' => 'Refunds paid out', 'amount' => -$cash['refunded']];
        $rows[] = ['type' => 'final', 'label' => 'Net takings', 'amount' => $cash['net']];
        $rows[] = ['type' => 'heading', 'label' => 'Cash in the till'];
        $rows[] = ['type' => 'line', 'label' => 'Cash received', 'amount' => $cash['cashIn']];
        $rows[] = ['type' => 'line', 'label' => 'Expenses paid in cash', 'amount' => -$cash['cashPaidOut']];
        $rows[] = ['type' => 'total', 'label' => 'Cash expected in the till', 'amount' => $cash['cashExpected'], 'note' => 'Before any refunds paid out in cash, and any opening float'];
        if ($cash['byStaff']) {
            $rows[] = ['type' => 'heading', 'label' => 'Received by'];
            foreach ($cash['byStaff'] as $name => $amount) $rows[] = ['type' => 'line', 'label' => $name, 'amount' => $amount];
        }
        if (count($cash['byDay']) > 1) {
            $rows[] = ['type' => 'heading', 'label' => 'By day'];
            foreach ($cash['byDay'] as $day => $amounts) {
                $rows[] = ['type' => 'line', 'label' => Carbon::parse($day)->format('D, M j'), 'amount' => $amounts['received'] - $amounts['refunded'],
                    'note' => $amounts['refunded'] > 0 ? 'Received '.number_format($amounts['received'], 2).', refunded '.number_format($amounts['refunded'], 2) : null];
            }
        }
        $rows[] = ['type' => 'heading', 'label' => 'Sales made'];
        $rows[] = ['type' => 'line', 'label' => 'Spectacle jobs ordered', 'amount' => $sales['orderRevenue'], 'note' => $sales['jobs'].' '.\Illuminate\Support\Str::plural('job', $sales['jobs'])];
        $rows[] = ['type' => 'line', 'label' => 'Accessories & POS', 'amount' => $sales['retailRevenue'], 'note' => $sales['retailCount'].' '.\Illuminate\Support\Str::plural('sale', $sales['retailCount'])];

        return $rows;
    }

    public function salesRows(Carbon $from, Carbon $to): array
    {
        $sales = $this->sales($from, $to);
        $rows = [['type' => 'heading', 'label' => 'Sales by category']];
        foreach ($sales['categories'] as $label => $amount) {
            $rows[] = ['type' => 'line', 'label' => $label, 'amount' => $amount, 'note' => $sales['gross'] > 0 ? number_format($amount / $sales['gross'] * 100, 1).'% of sales' : null];
        }
        $rows[] = ['type' => 'total', 'label' => 'Gross sales', 'amount' => $sales['gross']];
        $rows[] = ['type' => 'line', 'label' => 'Order discounts', 'amount' => -$sales['discounts']];
        $rows[] = ['type' => 'final', 'label' => 'Net sales', 'amount' => $sales['revenue']];
        $rows[] = ['type' => 'heading', 'label' => 'Volume'];
        $rows[] = ['type' => 'line', 'label' => 'Spectacle jobs', 'note' => $sales['jobs'].' jobs · average '.currency().' '.number_format($sales['avgOrder'], 2)];
        $rows[] = ['type' => 'line', 'label' => 'Counter sales', 'note' => $sales['retailCount'].' sales'];
        $rows[] = ['type' => 'line', 'label' => 'Refunded and cancelled jobs', 'amount' => -$sales['refundTotal'], 'note' => $sales['refundCount'].' jobs refunded'];

        return $rows;
    }

    public function owedRows(): array
    {
        $owed = $this->owed();
        $rows = [];
        foreach (self::AGES as $age => $label) {
            $group = $owed['rows']->where('age', $age);
            if ($group->isEmpty()) continue;
            $rows[] = ['type' => 'heading', 'label' => $label];
            foreach ($group as $row) {
                $rows[] = ['type' => 'line', 'label' => $row['order'].' · '.$row['customer'].($row['phone'] ? ' · '.$row['phone'] : ''), 'amount' => $row['balance'],
                    'note' => 'Ordered '.$row['ordered']->format('M j, Y').' ('.$row['days'].' days) · '.$row['status'].' · total '.number_format($row['total'], 2)];
            }
            $rows[] = ['type' => 'total', 'label' => $label.' total', 'amount' => $owed['ages'][$age]['amount']];
        }
        $rows[] = ['type' => 'final', 'label' => 'Total owed', 'amount' => $owed['total'], 'note' => $owed['count'].' '.\Illuminate\Support\Str::plural('job', $owed['count'])];

        return $rows;
    }

    private function retail()
    {
        return Sales::where('business_line', 'optical')->where('transaction_id', 'like', 'OPOS-%')->where('is_refunded', false);
    }

    /**
     * The whole days from $from to $to, in the dates' own timezone, as stored (UTC) times.
     * Callers passing app-time dates get what they always did; the owner summary passes
     * clinic-time dates so a day means the clinic's day.
     */
    private function range(Carbon $from, Carbon $to): array
    {
        return [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()];
    }
}
