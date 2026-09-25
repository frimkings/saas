<?php

namespace App\Support\Optical;

use Illuminate\Support\Str;

/**
 * Clinic spectacle orders (created from a refraction) are billed on the clinic's
 * consultation POS sale, not on an optical sale. This works out what the patient
 * owes for the frame and lens items on that bill.
 */
class ClinicSpectacleBilling
{
    /** Orders whose money lives on the clinic consultation bill. */
    public static function appliesTo($order): bool
    {
        return $order->refraction_id && ! $order->sale_id;
    }

    public static function summary($refraction): array
    {
        $consultation = $refraction->consultation;

        if (!$consultation) {
            return [
                'status' => 'none',
                'label' => 'No POS order',
                'class' => 'none',
                'amount' => 0,
                'paid' => 0,
                'balance' => 0,
                'discount' => 0,
                'transaction' => null,
                'items' => collect(),
            ];
        }

        $isOpticalProduct = function ($item) {
            $category = strtolower((string) optional(optional($item->product)->category)->name);

            return Str::contains($category, ['frame', 'lens']);
        };

        $opticalCarts = $consultation->cartItems
            ? $consultation->cartItems->filter($isOpticalProduct)
            : collect();

        $sale = $consultation->sale;
        $saleItems = $sale && $sale->items
            ? $sale->items->filter($isOpticalProduct)
            : collect();

        if ($sale && $saleItems->isNotEmpty()) {
            $isPaid          = $sale->payment_status === 'paid';
            $opticalSubtotal = (float) $saleItems->sum('subtotal');
            if ($opticalSubtotal <= 0) {
                $opticalSubtotal = (float) $opticalCarts->sum('total');
            }
            if ($opticalSubtotal <= 0) {
                $opticalSubtotal = (float) $sale->total_amount;
            }
            $allSubtotal     = (float) ($sale->items ? $sale->items->sum('subtotal') : 0);
            // Apply the sale-level discount proportionally to optical items
            $discountRatio   = $allSubtotal > 0 && (float) $sale->total_amount > 0 ? ((float) $sale->total_amount / $allSubtotal) : 1.0;
            $amount          = round($opticalSubtotal * $discountRatio, 2);
            $paid            = (float) $sale->total_amount > 0
                ? min($amount, round(((float) $sale->amount_paid / (float) $sale->total_amount) * $amount, 2))
                : ($isPaid ? $amount : 0);
            $balance         = max(0, $amount - $paid);

            return [
                'status' => $isPaid ? 'sold' : 'partial',
                'label' => $isPaid ? 'Sold at POS' : 'Part-paid at POS',
                'class' => $isPaid ? 'sold' : 'partial',
                'amount' => $amount,
                'paid' => $paid,
                'balance' => $balance,
                'discount' => (float) ($sale->discount_amount ?? 0),
                'transaction' => $sale->transaction_id,
                'items' => $saleItems,
            ];
        }

        if ($opticalCarts->where('purchased', true)->isNotEmpty()) {
            $items = $opticalCarts->where('purchased', true);

            return [
                'status' => 'sold',
                'label' => 'Sold at POS',
                'class' => 'sold',
                'amount' => (float) $items->sum('total'),
                'paid' => (float) $items->sum('total'),
                'balance' => 0,
                'discount' => 0,
                'transaction' => null,
                'items' => $items,
            ];
        }

        if ($opticalCarts->where('purchased', false)->isNotEmpty()) {
            $items = $opticalCarts->where('purchased', false);

            return [
                'status' => 'pending',
                'label' => 'Pending at POS',
                'class' => 'pending',
                'amount' => (float) $items->sum('total'),
                'paid' => 0,
                'balance' => (float) $items->sum('total'),
                'discount' => 0,
                'transaction' => null,
                'items' => $items,
            ];
        }

        return [
            'status' => 'none',
            'label' => 'Not sent to POS',
            'class' => 'none',
            'amount' => 0,
            'paid' => 0,
            'balance' => 0,
            'discount' => 0,
            'transaction' => null,
            'items' => collect(),
        ];
        }
}
