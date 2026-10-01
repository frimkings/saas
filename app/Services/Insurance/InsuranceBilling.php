<?php

namespace App\Services\Insurance;

use App\Models\InsuranceClaim;
use App\Models\Insurer;
use App\Models\InsurerCoverageRule;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Sales;
use Illuminate\Support\Facades\Auth;

/**
 * Splits a bill between the patient and their insurer, using the insurer's coverage
 * rules, and keeps each insured sale's draft claim in step with the insurer's share.
 */
class InsuranceBilling
{
    /** @var array<int, array{product: array<int, InsurerCoverageRule>, category: array<int, InsurerCoverageRule>}> */
    private array $rules = [];

    /** The insurer to bill for this patient, or null when the patient pays everything. */
    public function insurerFor(?Patient $patient): ?Insurer
    {
        if (!$patient?->insurer_id) {
            return null;
        }

        $insurer = $patient->relationLoaded('insurer') ? $patient->insurer : Insurer::find($patient->insurer_id);

        return $insurer && $insurer->active ? $insurer : null;
    }

    public function ruleFor(Insurer $insurer, Product $product): ?InsurerCoverageRule
    {
        $rules = $this->rules[$insurer->id] ??= $this->loadRules($insurer);

        return $rules['product'][$product->id] ?? $rules['category'][$product->category_id] ?? null;
    }

    /**
     * Split one bill line.
     *
     * unit_price is what the clinic charges per item: its own price, or the insurer's
     * tariff when the insurer does not let the clinic charge the patient the difference.
     * insurer is the insurer's share of the whole line (unit share × quantity).
     *
     * @return array{unit_price: float, insurer: float, covered: bool}
     */
    public function splitLine(Insurer $insurer, Product $product, int $quantity = 1): array
    {
        $price = round((float) $product->selling_price, 2);
        $rule = $this->ruleFor($insurer, $product);

        if (!$rule || $rule->coverage_type === 'excluded') {
            return ['unit_price' => $price, 'insurer' => 0.0, 'covered' => false];
        }

        // The insurer pays against its tariff, never more than the clinic's own price.
        $base = $rule->tariff_price !== null ? min($price, round((float) $rule->tariff_price, 2)) : $price;
        $unitPrice = $insurer->patient_pays_difference ? $price : $base;

        $insurerUnit = $rule->coverage_type === 'percent'
            ? $base * min(100, max(0, (float) $rule->coverage_value)) / 100
            : min($base, max(0, (float) $rule->coverage_value));

        return [
            'unit_price' => $unitPrice,
            'insurer'    => round($insurerUnit * max(0, $quantity), 2),
            'covered'    => true,
        ];
    }

    /** True once the sale's claim has gone past draft, so its amount can no longer change. */
    public function claimIsLocked(Sales $sale): bool
    {
        return InsuranceClaim::where('sale_id', $sale->id)
            ->where('status', '!=', 'draft')
            ->exists();
    }

    /**
     * Create or update the sale's draft claim so it asks the insurer for exactly
     * sale.insurer_amount. Claims already submitted are never changed here.
     */
    public function syncDraftClaim(Sales $sale): ?InsuranceClaim
    {
        $claim = InsuranceClaim::withTrashed()->where('sale_id', $sale->id)->first();

        if ($claim && !$claim->trashed() && $claim->status !== 'draft') {
            return $claim;
        }

        $amount = round((float) $sale->insurer_amount, 2);

        if (!$sale->insurer_id || $amount <= 0) {
            if ($claim && !$claim->trashed()) {
                $claim->update(['claim_amount' => 0, 'updated_by' => Auth::id()]);
                $claim->delete();
            }

            return null;
        }

        $patient = $sale->patient;
        $details = [
            'patient_id'    => $sale->patient_id,
            'insurer_id'    => $sale->insurer_id,
            'member_id'     => $patient?->insurance_member_id,
            'member_name'   => $patient?->insurance_member_name ?: $patient?->name,
            'policy_number' => $patient?->insurance_policy_number,
            'claim_amount'  => $amount,
        ];

        if ($claim) {
            if ($claim->trashed()) {
                $claim->restore();
            }
            $claim->update($details + ['status' => 'draft', 'updated_by' => Auth::id()]);

            return $claim;
        }

        return InsuranceClaim::create($details + [
            'sale_id'    => $sale->id,
            'status'     => 'draft',
            'notes'      => "Created automatically from bill {$sale->transaction_id}.",
            'created_by' => Auth::id() ?? $sale->user_id,
        ]);
    }

    private function loadRules(Insurer $insurer): array
    {
        $rules = ['product' => [], 'category' => []];

        foreach (InsurerCoverageRule::where('insurer_id', $insurer->id)->get() as $rule) {
            if ($rule->product_id) {
                $rules['product'][$rule->product_id] = $rule;
            } elseif ($rule->category_id) {
                $rules['category'][$rule->category_id] = $rule;
            }
        }

        return $rules;
    }
}
