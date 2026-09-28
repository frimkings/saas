<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\LensOrder;
use App\Models\OpticalSetting;
use App\Models\RefundLog;
use App\Models\PaymentTransaction;
use App\Models\Sales;
use App\Models\SaleItem;
use App\Services\Inventory\BranchInventoryService;
use App\Services\OpticalProductInventoryService;
use App\Models\Product;
use App\Models\OpticalProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OpticalOrderWorkflowService
{
    private const NEXT = [
        'Pending' => ['Sent to Lab', 'In Production'],
        'Sent to Lab' => ['In Production', 'Ready for Collection'],
        'In Production' => ['Ready for Collection'],
        'Ready for Collection' => ['Collected'],
        'Ready' => ['Collected'],
    ];

    /** Outcome of the automatic "glasses ready" SMS from the last transition (null = not attempted). */
    public ?array $lastReadySms = null;

    /**
     * Move an order to its next status. Sending it to an outside lab can record which lab
     * (a supplier) and when it is due back — by default the lab's lead time.
     */
    public function transition(int $id, string $status, ?int $labSupplierId = null, ?string $expectedBack = null): LensOrder
    {
        $this->lastReadySms = null;
        app(ClinicAccessService::class)->assertWritable('optical');
        $order = DB::transaction(function () use ($id, $status, $labSupplierId, $expectedBack) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if (! in_array($status, self::NEXT[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid order status transition.']);
            }
            $lenses = app(OpticalLensAvailabilityService::class);
            // Glazing, or finishing a job that went to an outside lab, needs every lens to have arrived.
            if (in_array($status, ['In Production', 'Ready for Collection'], true) && ($awaited = $lenses->awaitedEyes($order))) {
                throw ValidationException::withMessages(['status' => 'Mark the special-order '.implode(' and ', array_map('strtoupper', $awaited)).' lens as received '
                    .($status === 'In Production' ? 'before glazing.' : 'before the glasses can be ready.')]);
            }
            // Held stock lenses leave the branch ledger when glazing starts.
            if ($order->status === 'Pending') $lenses->consumeForGlazing($order);
            $onAccount = $order->order_source === 'partner' && $order->partner_billing_terms === 'on_account';
            if ($status === 'Collected' && ! $onAccount) {
                // Clinic spectacle orders are paid on the clinic consultation bill, not here.
                $clinicBilled = \App\Support\Optical\ClinicSpectacleBilling::appliesTo($order);
                $owed = $clinicBilled
                    ? (float) (\App\Support\Optical\ClinicSpectacleBilling::summary($order->refraction)['balance'] ?? 0)
                    : $order->total - (float) $order->paid_amount;
                if ($owed > 0.004) {
                    throw ValidationException::withMessages(['status' => $clinicBilled
                        ? 'The clinic bill still has '.currency().' '.number_format($owed, 2).' to pay for these glasses. Take it at the clinic reception or cashier first.'
                        : 'Record the remaining payment before collection.']);
                }
            }
            $order->status = $status;
            $order->status_changed_at = now();
            if ($status === 'Sent to Lab') {
                $lab = $labSupplierId ? \App\Models\Supplier::where('is_active', true)->find($labSupplierId) : null;
                if ($labSupplierId && ! $lab) throw ValidationException::withMessages(['labSupplierId' => 'Choose an active lab.']);
                if ($expectedBack !== null && $expectedBack !== '' && (! strtotime($expectedBack) || \Illuminate\Support\Carbon::parse($expectedBack)->lt(today()))) {
                    throw ValidationException::withMessages(['expectedBack' => 'The expected return date cannot be in the past.']);
                }
                $order->lab_supplier_id = $lab?->id;
                $order->sent_to_lab_at = now();
                $order->expected_back_at = $expectedBack ?: ($lab?->lead_time_days ? today()->addDays((int) $lab->lead_time_days)->toDateString() : null);
            }
            if ($status === 'Ready for Collection') $order->ready_at = now();
            if ($status === 'Collected') {
                $order->collected_at = now();
                // Warranty runs from collection. Renewal (recall) is a year on,
                // matching clinic spectacle orders, so optical-only shops get reminders too.
                $months = (int) (OpticalSetting::first()?->warranty_months ?? 0);
                $order->warranty_expires_at = $months > 0 ? now()->addMonthsNoOverflow($months)->toDateString() : null;
                $order->renewal_date ??= now()->addYear()->toDateString();
            }
            $order->save();
            return $order;
        });
        // Text the customer once the status change is committed; a failed SMS never undoes it.
        if ($status === 'Ready for Collection') {
            $this->lastReadySms = app(OpticalCollectionNotifier::class)->notifyReady($order);
        }
        return $order;
    }

    public function receiveSpecialOrderLens(int $id, string $eye): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        abort_unless(in_array($eye, ['od', 'os'], true), 422);
        return DB::transaction(function () use ($id, $eye) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if (in_array($order->status, ['Quotation', 'Cancelled', 'Collected'], true)) {
                throw ValidationException::withMessages(['lens' => 'This order is not awaiting lenses.']);
            }
            app(OpticalLensAvailabilityService::class)->receiveSpecialOrderLens($order, $eye);
            return $order;
        });
    }

    /** The deposit a new order must carry (none for partners billed on account). */
    public function minimumDeposit(LensOrder $order): float
    {
        $onAccount = $order->order_source === 'partner' && $order->partner_billing_terms === 'on_account';
        $percent = $onAccount ? 0 : (int) (OpticalSetting::first()?->min_deposit_percentage ?? 0);

        return round($order->total * $percent / 100, 2);
    }

    /**
     * Turn a quotation into an order, taking the deposit the shop requires. An expired
     * quotation needs a decision: keep the quoted prices ($reprice = false) or charge
     * today's prices ($reprice = true).
     */
    public function activateQuotation(int $id, float $deposit = 0, string $method = 'cash', ?bool $reprice = null): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        return DB::transaction(function () use ($id, $deposit, $method, $reprice) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if ($order->status !== 'Quotation' || ! $order->display_customer_name) {
                throw ValidationException::withMessages(['order' => 'This quotation cannot be converted.']);
            }
            if ($order->isQuoteExpired() && $reprice === null) {
                throw ValidationException::withMessages(['quote' => 'This quotation expired on '.$order->quote_valid_until->format('d M Y').'. Keep the quoted prices or re-price it at current prices.']);
            }
            if ($reprice) $this->reprice($order);
            if (round($deposit, 2) < $this->minimumDeposit($order)) {
                throw ValidationException::withMessages(['paid_amount' => 'A deposit of '.currency().' '.number_format($this->minimumDeposit($order), 2).' is required to place this order.']);
            }
            if ($order->frame_product_id || $order->lens_product_id) {
                throw ValidationException::withMessages(['order' => 'Replace clinic stock with Optical Product SKUs before converting this quotation.']);
            }
            $inventory = app(BranchInventoryService::class);
            foreach (array_filter([$order->frameProduct, $order->lensProduct]) as $product) {
                $inventory->decrease($product, 1);
            }
            foreach (array_filter([$order->frameOpticalProduct, $order->lensOpticalProduct]) as $product) {
                app(OpticalProductInventoryService::class)->decrease($product, 1, 'Optical quotation activation #'.$order->id);
            }
            $sale = Sales::create([
                'business_line' => 'optical',
                'user_id' => auth()->id(), 'patient_id' => $order->patient_id,
                'customer_name' => $order->bill_to === 'partner' ? $order->partner_clinic_name : $order->display_customer_name,
                'transaction_id' => 'OPT-' . Str::upper(Str::random(14)),
                'total_amount' => $order->total, 'amount_paid' => 0,
                'payment_status' => 'unpaid', 'discount_amount' => $order->discount_amount,
            ]);
            foreach ([[$order->frameProduct, $order->frame_price], [$order->lensProduct, $order->lens_price], [$order->frameOpticalProduct, $order->frame_price], [$order->lensOpticalProduct, $order->lens_price]] as [$product, $price]) {
                if ($product) SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product instanceof Product ? $product->id : null,
                    'optical_product_id' => $product instanceof OpticalProduct ? $product->id : null,
                    'prescribed_quantity' => 0, 'dispensed_quantity' => 1,
                    'selling_price' => $price, 'subtotal' => $price,
                ]);
            }
            $order->sale_id = $sale->id;
            $order->paid_amount = 0;
            $order->order_id = 'OPT-' . Str::upper(Str::random(12));
            $order->status = 'Pending';
            $order->status_changed_at = now();
            $order->stock_reserved_at = ($order->frameProduct || $order->lensProduct || $order->frameOpticalProduct || $order->lensOpticalProduct) ? now() : null;
            $order->save();
            app(OpticalLensAvailabilityService::class)->reserveForOrder($order);
            return $deposit > 0 ? $this->recordPayment($order->id, $deposit, $method, 'Optical order deposit') : $order;
        });
    }

    /** Charge today's prices for catalogue items and services on a quotation. Hand-entered prices stay. */
    private function reprice(LensOrder $order): void
    {
        if ($order->frameOpticalProduct) $order->frame_price = (float) $order->frameOpticalProduct->selling_price;
        if ($order->lensOpticalProduct) $order->lens_price = (float) $order->lensOpticalProduct->selling_price;
        $details = json_decode((string) $order->notes, true) ?: [];
        $stockKey = (string) data_get($details, 'lens_details.stock_key', '');
        if ($order->lens_supply_source === 'stock' && $stockKey !== '') {
            $order->lens_price = app(OpticalLensAvailabilityService::class)->resolveStockOption(
                $order->prescription_snapshot ?? [], $stockKey, (array) data_get($details, 'lens_details.stock_split', []), $order->id,
            )['price'];
        }
        foreach ($order->serviceLines as $line) {
            $service = $line->optical_service_id ? \App\Models\OpticalService::find($line->optical_service_id) : null;
            if ($service) $line->update(['unit_price' => round((float) $service->price, 2), 'line_total' => round($line->quantity * (float) $service->price, 2)]);
        }
        $order->service_total = round((float) $order->serviceLines()->sum('line_total'), 2);
        if ($order->frame_price + $order->lens_price + $order->glazing_fee + $order->service_total < (float) $order->discount_amount) {
            throw ValidationException::withMessages(['quote' => 'At current prices the discount is more than the order. Edit the quotation instead.']);
        }
        $order->save();
    }

    public function recordPayment(int $id, float $amount, string $method, ?string $note = null): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        if ($amount <= 0 || ! in_array($method, ['cash', 'momo', 'card', 'bank_transfer'], true)) {
            throw ValidationException::withMessages(['paymentAmount' => 'Enter a valid payment amount and method.']);
        }
        return DB::transaction(function () use ($id, $amount, $method, $note) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if (! $order->sale_id || in_array($order->status, ['Quotation', 'Cancelled'], true)
                || $amount > round($order->total - (float) $order->paid_amount, 2)) {
                throw ValidationException::withMessages(['paymentAmount' => 'Payment exceeds the outstanding balance or this order is closed.']);
            }
            $sale = Sales::lockForUpdate()->findOrFail($order->sale_id);
            PaymentTransaction::create([
                'sale_id' => $sale->id, 'amount' => $amount,
                'payment_method' => $method, 'collected_by' => auth()->id(),
                'notes' => $note ?: 'Optical order '.$order->order_id,
            ]);
            $order->paid_amount = round((float) $order->paid_amount + $amount, 2);
            $order->save();
            $sale->amount_paid = $order->paid_amount;
            $sale->payment_status = $sale->amount_paid >= $sale->total_amount ? 'paid' : 'partial';
            $sale->save();
            return $order;
        });
    }

    public function cancel(int $id): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        return DB::transaction(function () use ($id) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if (in_array($order->status, ['Cancelled', 'Collected'], true)) {
                throw ValidationException::withMessages(['order' => 'This order cannot be cancelled.']);
            }
            if ((float) $order->paid_amount > 0) {
                throw ValidationException::withMessages(['order' => 'Refund the payment before cancelling this order.']);
            }
            $this->returnStock($order);
            $order->status = 'Cancelled';
            $order->status_changed_at = now();
            $order->cancelled_at = now();
            $order->save();
            if ($order->sale_id) {
                $sale = Sales::findOrFail($order->sale_id);
                $sale->items()->delete();
                $sale->delete();
            }
            return $order;
        });
    }

    /**
     * Refund a paid order and cancel it in one step. A manager may keep part of
     * the payment as a cancellation fee (e.g. once glazing has started). The
     * sale is kept, marked refunded, so the refund log and receipt stay valid.
     */
    public function refundAndCancel(int $id, float $amount, string $reasonCode, string $reason): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'Only a manager can refund an optical order.');
        if (! array_key_exists($reasonCode, RefundLog::REASON_CODES) || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['refundReason' => 'Choose a refund reason and describe it in at least 10 characters.']);
        }
        return DB::transaction(function () use ($id, $amount, $reasonCode, $reason) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if (in_array($order->status, ['Quotation', 'Cancelled', 'Collected'], true)) {
                throw ValidationException::withMessages(['refundAmount' => 'Only open, uncollected orders can be refunded and cancelled. Use a remake for collected glasses.']);
            }
            $paid = round((float) $order->paid_amount, 2);
            $amount = round($amount, 2);
            if ($paid <= 0) throw ValidationException::withMessages(['refundAmount' => 'Nothing has been paid on this order. Cancel it instead.']);
            if ($amount <= 0 || $amount > $paid) {
                throw ValidationException::withMessages(['refundAmount' => 'Refund between '.currency().' 0.01 and the '.currency().' '.number_format($paid, 2).' paid.']);
            }
            $sale = Sales::with(['items', 'paymentTransactions'])->lockForUpdate()->findOrFail($order->sale_id);
            $fee = round($paid - $amount, 2);
            $log = RefundLog::create([
                'sale_id' => $sale->id,
                'sale_item_ids' => $sale->items->pluck('id')->all(),
                'refund_number' => 'RF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
                'request_type' => RefundLog::TYPE_REFUND,
                'reason_code' => $reasonCode,
                'reason' => trim($reason),
                'status' => RefundLog::STATUS_PROCESSED,
                'initiated_by' => auth()->id(), 'approved_by' => auth()->id(), 'processed_by' => auth()->id(),
                'initiated_at' => now(), 'approved_at' => now(), 'processed_at' => now(),
                'refunded_amount' => $amount,
                'original_payment_references' => $sale->paymentTransactions->map(fn ($payment) => [
                    'payment_transaction_id' => $payment->id, 'amount' => (float) $payment->amount,
                    'payment_method' => $payment->payment_method, 'collected_by' => $payment->collected_by,
                    'collected_at' => $payment->created_at?->toISOString(),
                ])->values()->all(),
                'stock_restoration' => [],
            ]);

            $this->returnStock($order);
            $sale->items()->update(['refunded_quantity' => DB::raw('dispensed_quantity')]);
            $sale->update([
                'total_amount' => $fee, 'amount_paid' => $fee,
                'payment_status' => $fee > 0 ? 'paid' : 'unpaid',
                'is_refunded' => $fee == 0,
                'refund_reason' => trim($reason), 'refunded_at' => now(), 'refunded_by' => auth()->id(),
            ]);
            $order->update([
                'status' => 'Cancelled', 'cancelled_at' => now(), 'status_changed_at' => now(),
                'paid_amount' => $fee, 'cancellation_fee' => $fee, 'refund_log_id' => $log->id,
            ]);
            AuditTrail::record(
                'optical.order_refunded',
                "Optical order {$order->order_id} refunded (".currency()." ".number_format($amount, 2).') and cancelled',
                $order, ['status' => 'open', 'paid_amount' => $paid],
                ['status' => 'Cancelled', 'refunded_amount' => $amount, 'cancellation_fee' => $fee, 'refund_number' => $log->refund_number],
                $order->patient_id, true,
            );
            return $order;
        });
    }

    /**
     * Close a job the customer has abandoned: cancel it, put its stock back (lenses already
     * cut are written off) and keep any deposit as a cancellation fee. The sale stays on
     * record at the amount kept, so takings and receipts still reconcile.
     */
    public function closeAbandoned(int $id, string $reason): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'Only a manager can close an abandoned job.');
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['abandonReason' => 'Say why the job is being closed, in at least 10 characters.']);
        }
        return DB::transaction(function () use ($id, $reason) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if (in_array($order->status, ['Quotation', 'Cancelled', 'Collected'], true)) {
                throw ValidationException::withMessages(['abandonReason' => 'Only open, uncollected jobs can be closed as abandoned.']);
            }
            $kept = round((float) $order->paid_amount, 2);
            $this->returnStock($order);
            if ($order->sale_id && ($sale = Sales::lockForUpdate()->find($order->sale_id))) {
                $sale->items()->update(['refunded_quantity' => DB::raw('dispensed_quantity')]);
                $sale->update(['total_amount' => $kept, 'amount_paid' => $kept, 'payment_status' => $kept > 0 ? 'paid' : 'unpaid']);
            }
            $order->update([
                'status' => 'Cancelled', 'cancelled_at' => now(), 'status_changed_at' => now(),
                'cancellation_fee' => $kept, 'cancellation_reason' => 'Abandoned: '.$reason,
            ]);
            AuditTrail::record(
                'optical.order_abandoned',
                "Optical order {$order->order_id} closed as abandoned".($kept > 0 ? ', deposit of '.currency().' '.number_format($kept, 2).' kept' : ''),
                $order, ['status' => 'open'], ['status' => 'Cancelled', 'deposit_kept' => $kept, 'reason' => $reason],
                $order->patient_id, true,
            );
            return $order;
        });
    }

    /**
     * Put reserved stock back on the shelf and release held lenses. Once glazing has started
     * the lenses were cut for this customer: the frame goes back, the lenses are written off.
     * Special-order lenses not yet bought are taken off any draft supplier order.
     */
    private function returnStock(LensOrder $order): void
    {
        $glazed = ! in_array($order->status, ['Quotation', 'Pending'], true);
        if ($order->stock_reserved_at) {
            $inventory = app(BranchInventoryService::class);
            foreach (array_filter([$order->frameProduct, $glazed ? null : $order->lensProduct]) as $product) {
                $inventory->increase($product, 1);
            }
            foreach (array_filter([$order->frameOpticalProduct, $glazed ? null : $order->lensOpticalProduct]) as $product) {
                app(OpticalProductInventoryService::class)->increase($product, 1, 'Optical order cancellation #'.$order->id);
            }
        }
        if ($glazed && ($order->lens_optical_product_id || $order->lens_product_id
            || $order->lensLines()->where('source', 'stock')->where('status', 'consumed')->exists())) {
            $order->lenses_scrapped_at = now();
        }
        \App\Models\OpticalPurchaseOrderLine::where('lens_order_id', $order->id)
            ->whereHas('purchaseOrder', fn ($query) => $query->where('status', 'draft'))->delete();
        app(OpticalLensAvailabilityService::class)->releaseForOrder($order);
    }
}
