<?php

namespace App\Support\Optical;

use App\Models\Sales;
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

        // A visit's frame and lenses can be paid on more than one bill (e.g. the frame on the
        // visit bill, the lenses later), so add up every clinical sale for the consultation.
        $sales = Sales::with('items.product.category')
            ->where('consultation_id', $consultation->id)
            ->where('business_line', 'clinic')
            ->where('is_refunded', false)
            ->orderBy('id')
            ->get()
            ->filter(fn ($sale) => $sale->items->contains($isOpticalProduct));

        if ($sales->isNotEmpty()) {
            $amount = $paid = $insurer = $discount = 0.0;
            $saleItems = collect();

            foreach ($sales as $sale) {
                $items           = $sale->items->filter($isOpticalProduct);
                $opticalSubtotal = (float) $items->sum('subtotal');
                $allSubtotal     = (float) $sale->items->sum('subtotal');
                $total           = (float) $sale->total_amount;
                // Apply the sale-level discount (or write-off) proportionally to the optical items.
                $share = $allSubtotal > 0 && $total > 0 ? $opticalSubtotal * ($total / $allSubtotal) : $opticalSubtotal;
                $share = round($share, 2);

                // The insurer's share is recorded on each line; the patient pays the rest, and
                // their payments are spread over their part of the bill.
                $lineInsurer  = min($share, round((float) $items->sum('insurer_amount'), 2));
                $patientPart  = max(0, $share - $lineInsurer);
                $billPatient  = max(0, $total - (float) $sale->insurer_amount);
                $paidRatio    = $billPatient > 0 ? min(1, (float) $sale->amount_paid / $billPatient) : 1;

                $amount   += $share;
                $insurer  += $lineInsurer;
                $paid     += round($patientPart * $paidRatio, 2);
                $discount += (float) ($sale->discount_amount ?? 0);
                $saleItems = $saleItems->merge($items);
            }

            $paid    = min($amount, round($paid, 2));
            $insurer = min(round($amount - $paid, 2), round($insurer, 2));
            $balance = max(0, round($amount - $paid - $insurer, 2));
            $isPaid  = $balance <= 0.005;

            return [
                'status' => $isPaid ? 'sold' : 'partial',
                'label' => $isPaid ? 'Sold at POS' : 'Part-paid at POS',
                'class' => $isPaid ? 'sold' : 'partial',
                'amount' => round($amount, 2),
                'paid' => $paid,
                'insurer' => $insurer,
                'balance' => $balance,
                'discount' => $discount,
                'transaction' => $sales->pluck('transaction_id')->implode(', '),
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
