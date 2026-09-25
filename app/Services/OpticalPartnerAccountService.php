<?php

namespace App\Services;

use App\Models\LensOrder;
use App\Models\OpticalPartnerClinic;
use App\Models\OpticalPartnerPayment;
use App\Models\OpticalPartnerPaymentAllocation;
use App\Models\PaymentTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Account of a partner clinic: jobs billed to it, what it has paid, what it owes, and
 * one payment settling many jobs at once. Each job keeps its own sale and payment
 * transactions, so receipts and reports stay as they are.
 */
class OpticalPartnerAccountService
{
    public const AGING = ['0_30' => '0–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', '90_plus' => 'Over 90 days'];

    /** Jobs billed to the partner (cancelled jobs and quotations are not on the account). */
    public function billedOrders(OpticalPartnerClinic $partner): Collection
    {
        return LensOrder::where('partner_clinic_id', $partner->id)->where('bill_to', 'partner')
            ->whereNotIn('status', ['Quotation', 'Cancelled'])->orderBy('created_at')->orderBy('id')->get();
    }

    public function openOrders(OpticalPartnerClinic $partner): Collection
    {
        return $this->billedOrders($partner)->filter(fn (LensOrder $order) => $this->balanceOf($order) > 0)->values();
    }

    public function balanceOf(LensOrder $order): float
    {
        return round(max(0, $order->total - (float) $order->paid_amount), 2);
    }

    public function balance(OpticalPartnerClinic $partner): float
    {
        return round($this->billedOrders($partner)->sum(fn (LensOrder $order) => $this->balanceOf($order)), 2);
    }

    /** @return array<string, float> outstanding by age of the job */
    public function aging(OpticalPartnerClinic $partner): array
    {
        $buckets = array_fill_keys(array_keys(self::AGING), 0.0);
        foreach ($this->openOrders($partner) as $order) {
            $days = (int) $order->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
            $key = $days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus'));
            $buckets[$key] = round($buckets[$key] + $this->balanceOf($order), 2);
        }
        return $buckets;
    }

    /**
     * Statement for a period: opening balance, jobs charged, payments received (a bulk
     * partner payment shows as one line), and a running balance.
     *
     * @return array{opening: float, lines: Collection, charges: float, payments: float, closing: float}
     */
    public function statement(OpticalPartnerClinic $partner, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $orders = $this->billedOrders($partner);
        $transactions = PaymentTransaction::whereIn('sale_id', $orders->pluck('sale_id')->filter())->orderBy('created_at')->orderBy('id')->get();
        $ordersBySale = $orders->keyBy('sale_id');
        $receipts = OpticalPartnerPaymentAllocation::with('payment')->whereIn('payment_transaction_id', $transactions->pluck('id'))->get()
            ->mapWithKeys(fn ($allocation) => [$allocation->payment_transaction_id => $allocation->payment]);

        $entries = collect();
        foreach ($orders as $order) {
            $entries->push(['date' => $order->created_at, 'type' => 'charge', 'amount' => round($order->total, 2), 'group' => 'order-'.$order->id,
                'description' => 'Job '.$order->order_id.($order->customer_name ? ' · '.$order->customer_name : '').($order->partnerReference() ? ' · ref '.$order->partnerReference() : '')]);
        }
        foreach ($transactions as $transaction) {
            $receipt = $receipts->get($transaction->id);
            $order = $ordersBySale->get($transaction->sale_id);
            $entries->push(['date' => $receipt?->created_at ?? $transaction->created_at, 'type' => 'payment', 'amount' => round((float) $transaction->amount, 2),
                'group' => $receipt ? 'receipt-'.$receipt->id : 'txn-'.$transaction->id,
                'description' => $receipt
                    ? 'Payment '.$receipt->receipt_number.' · '.(\App\Models\OpticalPartnerPayment::METHODS[$receipt->payment_method] ?? $receipt->payment_method).($receipt->reference ? ' · '.$receipt->reference : '')
                    : 'Payment on '.$order?->order_id.' · '.str_replace('_', ' ', (string) $transaction->payment_method)]);
        }
        // One line per bulk receipt rather than one per job it covered.
        $entries = $entries->groupBy('group')
            ->map(fn ($group) => ['amount' => round($group->sum('amount'), 2)] + $group->first())
            ->sortBy(fn ($entry) => [$entry['date']->timestamp, $entry['type'] === 'charge' ? 0 : 1])->values();

        $signed = fn ($entry) => $entry['type'] === 'charge' ? $entry['amount'] : -$entry['amount'];
        $opening = round($entries->filter(fn ($entry) => $entry['date']->lt($from))->sum($signed), 2);
        $running = $opening;
        $lines = $entries->filter(fn ($entry) => $entry['date']->between($from, $to))->map(function ($entry) use (&$running, $signed) {
            $running = round($running + $signed($entry), 2);
            return $entry + ['balance' => $running];
        })->values();

        return [
            'opening' => $opening, 'lines' => $lines,
            'charges' => round($lines->where('type', 'charge')->sum('amount'), 2),
            'payments' => round($lines->where('type', 'payment')->sum('amount'), 2),
            'closing' => $running,
        ];
    }

    /**
     * Record one payment from the partner and settle jobs with it: oldest first, or the
     * amounts given per job. It cannot exceed what the partner owes.
     *
     * @param  array<int, float|string|null>|null  $allocations  order id => amount
     */
    public function recordPayment(OpticalPartnerClinic $partner, float $amount, string $method, ?string $reference = null, ?string $notes = null, ?array $allocations = null): OpticalPartnerPayment
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $amount = round($amount, 2);
        if (! array_key_exists($method, OpticalPartnerPayment::METHODS)) throw ValidationException::withMessages(['paymentMethod' => 'Choose how the partner paid.']);
        if ($amount <= 0) throw ValidationException::withMessages(['paymentAmount' => 'Enter the amount received.']);

        return DB::transaction(function () use ($partner, $amount, $method, $reference, $notes, $allocations) {
            OpticalPartnerClinic::whereKey($partner->id)->lockForUpdate()->first();
            $open = $this->openOrders($partner)->keyBy('id');
            $owed = round($open->sum(fn ($order) => $this->balanceOf($order)), 2);
            if ($amount > $owed) {
                throw ValidationException::withMessages(['paymentAmount' => 'This is more than the '.currency().' '.number_format($owed, 2).' '.$partner->name.' owes. Record the exact amount owed.']);
            }
            $plan = $allocations === null ? $this->oldestFirst($open, $amount) : $this->manualPlan($open, $amount, $allocations);

            $payment = OpticalPartnerPayment::create([
                'optical_partner_clinic_id' => $partner->id,
                'receipt_number' => 'OPR-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'amount' => $amount, 'payment_method' => $method,
                'reference' => $reference ? trim($reference) : null, 'notes' => $notes ? trim($notes) : null,
                'received_by' => auth()->id(),
            ]);
            $workflow = app(OpticalOrderWorkflowService::class);
            foreach ($plan as $orderId => $share) {
                $order = $workflow->recordPayment($orderId, $share, $method, 'Partner payment '.$payment->receipt_number);
                $payment->allocations()->create([
                    'lens_order_id' => $order->id, 'amount' => $share,
                    'payment_transaction_id' => PaymentTransaction::where('sale_id', $order->sale_id)->latest('id')->value('id'),
                ]);
            }
            return $payment->load('allocations.order');
        });
    }

    /** @return array<int, float> */
    private function oldestFirst(Collection $open, float $amount): array
    {
        $plan = [];
        $left = $amount;
        foreach ($open as $order) {
            if ($left <= 0) break;
            $share = round(min($left, $this->balanceOf($order)), 2);
            $plan[$order->id] = $share;
            $left = round($left - $share, 2);
        }
        return $plan;
    }

    /** @return array<int, float> */
    private function manualPlan(Collection $open, float $amount, array $allocations): array
    {
        $plan = [];
        foreach ($allocations as $orderId => $value) {
            $share = round((float) $value, 2);
            if ($share <= 0) continue;
            $order = $open->get((int) $orderId);
            if (! $order) throw ValidationException::withMessages(['allocations' => 'A selected job is not open on this partner account.']);
            if ($share > $this->balanceOf($order)) throw ValidationException::withMessages(['allocations' => "{$order->order_id} only owes ".currency()." ".number_format($this->balanceOf($order), 2).'.']);
            $plan[$order->id] = $share;
        }
        if ($plan === [] || abs(array_sum($plan) - $amount) > 0.001) {
            throw ValidationException::withMessages(['allocations' => 'The amounts applied to jobs must add up to the payment received ('.currency().' '.number_format($amount, 2).').']);
        }
        return $plan;
    }
}
