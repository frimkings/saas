<?php

namespace App\Services\Finance;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\RefundLog;
use App\Models\Sales;
use App\Services\Insurance\InsuranceBilling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carries out an approved refund or void: stock back, the insurer's share off its draft claim,
 * the refund numbered and recorded. Used by the approvals page, and straight from the request
 * on plans without approvals. Throws \RuntimeException with a message for the person when it can't.
 */
class RefundProcessor
{
    /** Marks a pending request approved by the signed-in person. */
    public function approve(RefundLog $log): void
    {
        $log->update(['status' => RefundLog::STATUS_APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        AuditTrail::record(
            'refund.approved',
            ucfirst($log->request_type) . " approved for sale {$log->sale->transaction_id}",
            $log,
            ['status' => RefundLog::STATUS_PENDING],
            ['status' => RefundLog::STATUS_APPROVED, 'approved_by' => auth()->id()],
            $log->sale->patient_id,
            true
        );
    }

    public function process(RefundLog $log): RefundLog
    {
        $sale = $log->sale;
        if (!$sale) {
            throw new \RuntimeException('Sale record not found for this refund.');
        }

        return DB::transaction(function () use ($log, $sale) {
            // Pessimistic locks prevent two concurrent requests from double-processing
            $lockedLog  = RefundLog::lockForUpdate()->findOrFail($log->id);
            $lockedSale = Sales::with(['items.product', 'items.opticalProduct', 'paymentTransactions'])
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($lockedSale->is_refunded) {
                throw new \RuntimeException('This sale has already been refunded.');
            }
            // Requests now close a paid visit bill when made; older requests may still be open.
            if (!$lockedSale->finalizeForRefund()) {
                throw new \RuntimeException('This bill still has a balance owing. Settle it under Outstanding Balances first.');
            }
            if ($lockedLog->status !== RefundLog::STATUS_APPROVED) {
                throw new \RuntimeException('This refund is no longer in approved status.');
            }

            $paymentReferences = $lockedSale->paymentTransactions
                ->map(fn ($payment) => [
                    'payment_transaction_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'collected_by' => $payment->collected_by,
                    'collected_at' => $payment->created_at?->toISOString(),
                ])
                ->values()
                ->all();

            if (!$paymentReferences) {
                $paymentReferences[] = [
                    'payment_transaction_id' => null,
                    'amount' => (float) $lockedSale->amount_paid,
                    'payment_method' => 'legacy',
                    'collected_by' => $lockedSale->user_id,
                    'collected_at' => $lockedSale->created_at?->toISOString(),
                ];
            }

            $selectedItemIds = collect($lockedLog->sale_item_ids ?: $lockedSale->items->pluck('id')->all())
                ->map(fn ($id) => (int) $id)->unique();
            $refundableItems = $lockedSale->items
                ->whereIn('id', $selectedItemIds)
                ->filter(fn ($item) => $item->dispensed_quantity > $item->refunded_quantity);
            if ($refundableItems->count() !== $selectedItemIds->count()) {
                throw new \RuntimeException('One or more selected items are invalid or were already refunded.');
            }
            // Requests made before the doctor saved a consultation, or by older versions, may include the fee.
            if ($selectedItemIds->intersect($lockedSale->lockedConsultationFeeItemIds())->isNotEmpty()) {
                throw new \RuntimeException(Sales::CONSULTATION_FEE_LOCKED.' Reject this request and ask for a new one without the fee.');
            }

            // The insurer's share of these items was never paid by the patient, so it is
            // not refunded to them; it comes off the insurer's draft claim instead.
            $selectedInsurer = round((float) $refundableItems->sum('insurer_amount'), 2);
            if ($selectedInsurer > 0 && app(InsuranceBilling::class)->claimIsLocked($lockedSale)) {
                throw new \RuntimeException('These items were billed to the insurer and the claim has already been submitted. Adjust the claim with the insurer before refunding.');
            }

            $stockRestoration = [];
            $productIds = $refundableItems
                ->where('dispensed_quantity', '>', 0)
                ->pluck('product_id')
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $products = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($refundableItems as $item) {
                $quantity = (int) $item->dispensed_quantity - (int) $item->refunded_quantity;
                $product = $products->get($item->product_id);
                if ($quantity <= 0) continue;
                if ($product) {
                    $before = (int) $product->quantity;
                    app(\App\Services\Inventory\BranchInventoryService::class)->increase($product, $quantity);
                    $product->refresh();
                    $stockRestoration[] = [
                        'sale_item_id' => $item->id, 'product_id' => $product->id,
                        'product_name' => $product->name, 'quantity_restored' => $quantity,
                        'quantity_before' => $before, 'quantity_after' => (int) $product->quantity,
                    ];
                } elseif ($item->optical_product_id) {
                    $opticalProduct = $item->opticalProduct;
                    if (! $opticalProduct) throw new \RuntimeException('The optical product for this refund is unavailable.');
                    $before = \App\Models\OpticalProductStock::where('optical_product_id', $opticalProduct->id)->value('quantity') ?? 0;
                    app(\App\Services\OpticalProductInventoryService::class)->increase($opticalProduct, $quantity, 'Refund for sale #'.$lockedSale->id);
                    $stockRestoration[] = [
                        'sale_item_id' => $item->id, 'optical_product_id' => $opticalProduct->id,
                        'product_name' => $opticalProduct->name, 'quantity_restored' => $quantity,
                        'quantity_before' => $before, 'quantity_after' => $before + $quantity,
                    ];
                }
                $item->update(['refunded_quantity' => $item->dispensed_quantity]);
            }

            $refundNumber = 'RF-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
            $selectedGross = (float) $refundableItems->sum('subtotal');
            $saleGross = max(0.01, (float) $lockedSale->items->sum('subtotal'));
            $allocatedDiscount = (float) $lockedSale->discount_amount * ($selectedGross / $saleGross);
            $refundedAmount = min((float) $lockedSale->amount_paid, max(0, $selectedGross - $allocatedDiscount - $selectedInsurer));
            $allItemsRefunded = $lockedSale->items->every(fn ($item) =>
                $item->refunded_quantity >= $item->dispensed_quantity
            );

            $lockedSale->update([
                'is_refunded'   => $allItemsRefunded,
                'refund_reason' => $allItemsRefunded ? $lockedLog->reason : $lockedSale->refund_reason,
                'refunded_at'   => $allItemsRefunded ? now() : $lockedSale->refunded_at,
                'refunded_by'   => $allItemsRefunded ? auth()->id() : $lockedSale->refunded_by,
            ]);

            if ($selectedInsurer > 0) {
                $lockedSale->update(['insurer_amount' => max(0, round((float) $lockedSale->insurer_amount - $selectedInsurer, 2))]);
                app(InsuranceBilling::class)->syncDraftClaim($lockedSale);
            }

            $cartIds = $lockedSale->items->pluck('cart_id')->filter()->unique();
            if ($cartIds->isNotEmpty()) {
                \App\Models\Cart::whereIn('id', $cartIds)->update(['status' => 'refunded']);
            }

            $lockedLog->update([
                'status'       => RefundLog::STATUS_PROCESSED,
                'processed_by' => auth()->id(),
                'processed_at' => now(),
                'refund_number' => $refundNumber,
                'refunded_amount' => $refundedAmount,
                'original_payment_references' => $paymentReferences,
                'stock_restoration' => $stockRestoration,
                'sale_item_ids' => $selectedItemIds->values()->all(),
            ]);

            AuditTrail::record(
                'refund.processed',
                ucfirst($lockedLog->request_type) . " {$refundNumber} processed for sale {$lockedSale->transaction_id}",
                $lockedLog,
                ['status' => RefundLog::STATUS_APPROVED],
                [
                    'status' => RefundLog::STATUS_PROCESSED,
                    'refund_number' => $refundNumber,
                    'refunded_amount' => $refundedAmount,
                    'reason_code' => $lockedLog->reason_code,
                    'original_payment_references' => $paymentReferences,
                    'stock_restoration' => $stockRestoration,
                ],
                $lockedSale->patient_id,
                true
            );

            return $lockedLog->fresh(['sale']);
        });
    }
}
