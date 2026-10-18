<?php

namespace App\Services\Finance;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\InsurerPayment;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Support\PaymentMethods;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The branch's collections ledger: for each payment method (cash drawer, MoMo wallet, card
 * settlements...), money in (patient and insurer payments, money added) and out (refunds,
 * expenses paid from it, money banked or handed over), with a running balance.
 *
 * The book starts at the branch's first opening count (a CashMovement of type opening); until
 * then each period starts from zero. Refunds go back out by the methods the sale was paid with,
 * split in proportion when it was paid more than one way.
 */
class CashBook
{
    /** Every method a till offers, plus any already used on this branch's book: key => name. */
    public function methods(): array
    {
        $methods = PaymentMethods::active(PaymentMethods::CLINIC) + PaymentMethods::active(PaymentMethods::OPTICAL);
        $used = PaymentTransaction::query()->distinct()->pluck('payment_method')
            ->merge(CashMovement::query()->distinct()->pluck('payment_method'))
            ->filter()->unique();
        foreach ($used as $key) {
            $methods[$key] ??= PaymentMethods::label($key);
        }
        // Cash first: it is the drawer the day close counts.
        return isset($methods['cash']) ? ['cash' => $methods['cash']] + $methods : $methods;
    }

    /** When the book starts: the first opening count, if there is one. */
    public function startDate(): ?Carbon
    {
        $date = CashMovement::active()->where('type', CashMovement::OPENING)->min('moved_on');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    /** The balance of a method at the start of a day: the opening count, then everything since. */
    public function balanceBefore(string $method, CarbonInterface $date): float
    {
        $start = $this->startDate();
        if (!$start || $date->copy()->startOfDay()->lt($start)) return 0.0;

        $opening = (float) CashMovement::active()->where('type', CashMovement::OPENING)->where('payment_method', $method)
            ->whereDate('moved_on', '<=', $date->toDateString())->sum('amount');
        $since = $date->copy()->startOfDay()->gt($start)
            ? $this->rows($method, $start, $date->copy()->startOfDay()->subSecond())->sum(fn ($row) => $row['in'] - $row['out'])
            : 0;

        return round($opening + $since, 2);
    }

    /**
     * A method's book for a period: an opening row, then every entry with a running balance.
     *
     * @return Collection<int, array{date: Carbon, kind: string, label: string, reference: ?string, in: float, out: float, by: ?string, balance: float, cancelled: bool, movement_id: ?int}>
     */
    public function entries(string $method, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $start = $this->startDate();
        $rangeFrom = $start && $start->gt($from) ? $start : $from;

        $balance = $this->balanceBefore($method, $rangeFrom);
        $rows = collect([[
            'date' => $from, 'kind' => 'opening', 'label' => 'Balance brought forward', 'reference' => null,
            'in' => 0.0, 'out' => 0.0, 'by' => null, 'balance' => $balance, 'cancelled' => false, 'movement_id' => null,
        ]]);

        if ($rangeFrom->gt($to)) return $rows;

        return $rows->concat($this->rows($method, $rangeFrom, $to, true)->map(function ($row) use (&$balance) {
            $balance = round($balance + $row['in'] - $row['out'], 2);
            return $row + ['balance' => $balance];
        }));
    }

    /**
     * Each method's figures for one day: opening, in, out and what should be there at the end.
     * Movements made by the close itself (count difference, banking) can be left out, so a
     * closed day still shows what was expected before the count.
     *
     * @return array<string, array{label: string, opening: float, in: float, out: float, expected: float}>
     */
    public function day(CarbonInterface $date, ?int $leaveOutClose = null): array
    {
        $figures = [];
        foreach ($this->methods() as $key => $label) {
            $rows = $this->rows($key, $date->copy()->startOfDay(), $date->copy()->endOfDay(), false, $leaveOutClose);
            $opening = $this->balanceBefore($key, $date);
            $in = round($rows->sum('in'), 2);
            $out = round($rows->sum('out'), 2);
            $figures[$key] = ['label' => $label, 'opening' => $opening, 'in' => $in, 'out' => $out, 'expected' => round($opening + $in - $out, 2)];
        }

        return $figures;
    }

    /**
     * Who took what on a day: each cashier's collections by method, and refunds they paid out.
     *
     * @return array<int|string, array{name: string, methods: array<string, float>, refunded: float, total: float}>
     */
    public function cashiers(CarbonInterface $date): array
    {
        $range = [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        $people = [];
        $person = function ($user) use (&$people) {
            $id = $user?->id ?? 0;
            $people[$id] ??= ['name' => $user?->name ?? 'Unknown', 'methods' => [], 'refunded' => 0.0, 'total' => 0.0];
            return $id;
        };

        foreach (PaymentTransaction::with('collectedBy:id,name')->whereBetween('created_at', $range)->get() as $payment) {
            $id = $person($payment->collectedBy);
            $people[$id]['methods'][$payment->payment_method] = round(($people[$id]['methods'][$payment->payment_method] ?? 0) + (float) $payment->amount, 2);
            $people[$id]['total'] = round($people[$id]['total'] + (float) $payment->amount, 2);
        }
        foreach (RefundLog::with('processedBy:id,name')->where('status', RefundLog::STATUS_PROCESSED)->whereBetween('processed_at', $range)->get() as $refund) {
            $id = $person($refund->processedBy);
            $people[$id]['refunded'] = round($people[$id]['refunded'] + (float) $refund->refunded_amount, 2);
            $people[$id]['total'] = round($people[$id]['total'] - (float) $refund->refunded_amount, 2);
        }

        return $people;
    }

    /** How much of a processed refund went back by each method. */
    public static function refundSplit(RefundLog $refund): array
    {
        $references = collect($refund->original_payment_references ?: []);
        $paid = (float) $references->sum('amount');
        $amount = (float) $refund->refunded_amount;
        if ($paid <= 0) return $amount > 0 ? ['cash' => $amount] : [];

        $split = [];
        foreach ($references->groupBy(fn ($ref) => ($ref['payment_method'] ?? 'cash') === 'legacy' ? 'cash' : ($ref['payment_method'] ?? 'cash')) as $method => $refs) {
            $split[$method] = round($amount * (float) $refs->sum('amount') / $paid, 2);
        }

        return $split;
    }

    /** One method's entries between two moments, oldest first, without a balance. */
    private function rows(string $method, CarbonInterface $from, CarbonInterface $to, bool $detail = false, ?int $leaveOutClose = null): Collection
    {
        $rows = collect();
        $add = fn (Carbon $date, string $kind, string $label, ?string $reference, float $in, float $out, ?string $by = null, bool $cancelled = false, ?int $movementId = null)
            => $rows->push(compact('date', 'kind', 'label', 'reference', 'in', 'out', 'by', 'cancelled') + ['movement_id' => $movementId]);
        $days = [$from->toDateString(), $to->toDateString()];

        $payments = PaymentTransaction::where('payment_method', $method)->whereBetween('created_at', [$from, $to]);
        if ($detail) {
            foreach ($payments->with(['sale' => fn ($q) => $q->withTrashed()->select('id', 'transaction_id', 'patient_id', 'customer_name'), 'sale.patient:id,name', 'collectedBy:id,name'])->get() as $payment) {
                $who = $payment->sale?->patient?->name ?? $payment->sale?->customer_name;
                $add($payment->created_at, 'payment', 'Payment' . ($who ? " — {$who}" : ''), $payment->sale?->transaction_id, (float) $payment->amount, 0, $payment->collectedBy?->name);
            }
        } else {
            $total = (float) $payments->sum('amount');
            if ($total) $add(Carbon::parse($from), 'payment', 'Payments', null, $total, 0);
        }

        $insurer = InsurerPayment::where('payment_method', $method)->whereBetween('paid_on', $days);
        foreach ($insurer->with('insurer:id,name')->get() as $payment) {
            $add($payment->paid_on->copy()->setTime(12, 0), 'insurer', 'Insurer payment — ' . ($payment->insurer?->name ?? 'insurer'), $payment->receipt_number, (float) $payment->amount, 0);
        }

        $refunds = RefundLog::where('status', RefundLog::STATUS_PROCESSED)->whereBetween('processed_at', [$from, $to])
            ->with(['sale' => fn ($q) => $q->withTrashed()->select('id', 'transaction_id'), 'processedBy:id,name'])->get();
        foreach ($refunds as $refund) {
            $amount = self::refundSplit($refund)[$method] ?? 0;
            if ($amount <= 0) continue;
            $kind = $refund->request_type === RefundLog::TYPE_VOID ? 'Void' : 'Refund';
            $add($refund->processed_at, 'refund', "{$kind} paid out — {$refund->refund_number}", $refund->sale?->transaction_id, 0, $amount, $refund->processedBy?->name);
        }

        $expenses = Expense::where('payment_method', $method)->whereBetween('expense_date', $days)->with(['category:id,name', 'recorder:id,name']);
        foreach ($expenses->get() as $expense) {
            $label = 'Expense — ' . trim(($expense->category?->name ? $expense->category->name . ': ' : '') . $expense->description);
            $add($expense->expense_date->copy()->setTime(12, 0), 'expense', $label, $expense->reference, 0, (float) $expense->amount, $expense->recorder?->name);
        }

        // Opening counts are the balance at the start of their day (see balanceBefore), not entries.
        $movements = CashMovement::where('payment_method', $method)->where('type', '!=', CashMovement::OPENING)->whereBetween('moved_on', $days)
            ->when($leaveOutClose, fn ($q) => $q->where(fn ($q) => $q->whereNull('day_close_id')->orWhere('day_close_id', '!=', $leaveOutClose)))
            ->when(!$detail, fn ($q) => $q->active())
            ->with('recorder:id,name')->orderBy('id')->get();
        foreach ($movements as $movement) {
            $at = $movement->moved_on->copy()->setTime(23, 59);
            $amount = $movement->cancelled_at ? 0.0 : (float) $movement->amount;
            $label = $movement->label . ($movement->notes ? " — {$movement->notes}" : '');
            $add($at, 'movement', $label, $movement->reference, max(0, $amount), max(0, -$amount), $movement->recorder?->name, $movement->cancelled_at !== null, $movement->id);
        }

        return $rows->sortBy(fn ($row) => $row['date']->format('YmdHis'))->values();
    }
}
