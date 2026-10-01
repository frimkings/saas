<?php

namespace App\Services\Visits;

use App\Models\CashierPatientClearance;
use App\Models\Consultations;
use App\Models\PatientVisit;
use App\Models\RefundLog;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Groups a patient's clinical sales into visits and describes a visit for its receipt.
 *
 * A visit is one clearance: the clearance service, the doctor's prescription sold at the
 * POS, and anything reception added to that prescription cart (frames, accessories ...),
 * since those carry the clearance's consultation. A clinical POS sale with no clearance is
 * a visit of its own. Optical-business sales are never part of a visit.
 */
class PatientVisits
{
    /** Whether this clinic issues one receipt (and one SMS) per visit instead of per payment. */
    public static function enabled(): bool
    {
        return (bool) Setting::getSettings()->visit_receipts_enabled;
    }

    /** Put a clinical sale into its visit, opening the visit if needed. Null for sales outside visits. */
    public function attach(Sales $sale): ?PatientVisit
    {
        if ($sale->patient_visit_id) {
            return PatientVisit::find($sale->patient_visit_id);
        }
        if (!$sale->patient_id || ($sale->business_line ?? 'clinic') !== 'clinic') {
            return null;
        }

        $clearance = $this->clearanceFor($sale);
        $visit = $clearance
            ? $this->visitForClearance($clearance)
            : PatientVisit::create(['patient_id' => $sale->patient_id, 'opened_on' => $sale->created_at ?? now()]);

        $sale->forceFill(['patient_visit_id' => $visit->id])->saveQuietly();

        return $visit;
    }

    /**
     * Everything the visit receipt and SMS need: items grouped by section, every payment,
     * and the totals (the insurer's share is not the patient's balance).
     */
    public function summary(PatientVisit $visit): array
    {
        $sales = Sales::with([
                'items.product.category', 'items.opticalProduct', 'paymentTransactions', 'insurer:id,name', 'user:id,name',
            ])
            ->where('patient_visit_id', $visit->id)
            ->orderBy('created_at')
            ->get();

        $live = $sales->where('is_refunded', false);
        $sections = [];
        foreach ($live as $sale) {
            foreach ($sale->items as $item) {
                $sections[$this->section($item)][] = [
                    'name'     => $item->display_product_name,
                    'quantity' => max((int) $item->dispensed_quantity, (int) $item->prescribed_quantity) - (int) $item->refunded_quantity,
                    'refunded' => (int) $item->refunded_quantity,
                    'on_hold'  => (int) $item->dispensed_quantity === 0 && (int) $item->prescribed_quantity > 0,
                    'price'    => (float) $item->selling_price,
                    'subtotal' => (float) $item->subtotal,
                ];
            }
        }
        // Services first, then drugs, then everything else, as on the counter.
        $order = array_flip(['Consultation & services', 'Drugs', 'Frames, lenses & other items']);
        uksort($sections, fn ($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));

        $payments = $live->flatMap(fn (Sales $sale) => $sale->paymentTransactions)
            ->sortBy('created_at')
            ->map(fn ($payment) => [
                'method' => $payment->payment_method,
                'amount' => (float) $payment->amount,
                'at'     => $payment->created_at,
            ])->values();

        $total    = round((float) $live->sum('total_amount'), 2);
        $discount = round((float) $live->sum('discount_amount'), 2);
        $insurer  = round((float) $live->sum('insurer_amount'), 2);
        $paid     = round((float) $live->sum('amount_paid'), 2);
        $balance  = round((float) $live->sum(fn (Sales $sale) => $sale->remaining_balance), 2);
        $refunded = round((float) RefundLog::whereIn('sale_id', $sales->pluck('id'))
            ->where('status', RefundLog::STATUS_PROCESSED)->sum('refunded_amount'), 2);

        return [
            'visit'        => $visit,
            'patient'      => $visit->patient,
            'sales'        => $sales,
            'sections'     => $sections,
            'payments'     => $payments,
            'transactions' => $sales->pluck('transaction_id')->all(),
            'insurerName'  => $live->firstWhere('insurer_amount', '>', 0)?->insurer?->name,
            'total'        => $total,
            'discount'     => $discount,
            'insurer'      => $insurer,
            'paid'         => $paid,
            'refunded'     => $refunded,
            'balance'      => $balance,
            'settled'      => $live->isNotEmpty() && $balance <= 0.005,
            'servedBy'     => $live->pluck('user.name')->filter()->unique()->values()->all(),
        ];
    }

    private function section(SaleItem $item): string
    {
        $category = $item->product?->category;
        if ($category && ($category->type === 'service' || str_contains(strtolower((string) $category->name), 'service'))) {
            return 'Consultation & services';
        }
        if ($item->product?->isDrugCategory()) {
            return 'Drugs';
        }

        return 'Frames, lenses & other items';
    }

    private function clearanceFor(Sales $sale): ?CashierPatientClearance
    {
        if ($clearance = CashierPatientClearance::where('sale_id', $sale->id)->first()) {
            return $clearance;
        }

        // The doctor's prescription, and items reception added to it, carry the consultation.
        $clearanceId = $sale->consultation_id ? Consultations::whereKey($sale->consultation_id)->value('clearance_id') : null;

        return $clearanceId
            ? CashierPatientClearance::where('patient_id', $sale->patient_id)->find($clearanceId)
            : null;
    }

    private function visitForClearance(CashierPatientClearance $clearance): PatientVisit
    {
        $attributes = ['patient_id' => $clearance->patient_id, 'opened_on' => $clearance->clearance_date ?? now()];

        try {
            return PatientVisit::firstOrCreate(['clearance_id' => $clearance->id], $attributes);
        } catch (UniqueConstraintViolationException) {
            // Two tills opened the same visit at once; use the one that won.
            return PatientVisit::where('clearance_id', $clearance->id)->firstOrFail();
        }
    }
}
