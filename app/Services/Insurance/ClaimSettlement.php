<?php

namespace App\Services\Insurance;

use App\Models\AuditTrail;
use App\Models\InsuranceClaim;
use App\Models\Insurer;
use App\Models\InsurerPayment;
use App\Models\InsurerPaymentAllocation;
use App\Models\SaleAdjustment;
use App\Models\Sales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Money coming back from insurers: recording remittances against claims, and dealing
 * with whatever the insurer will not pay (billed to the patient or written off, per
 * the insurer's shortfall setting).
 */
class ClaimSettlement
{
    /**
     * Record one insurer payment split across claims.
     *
     * @param  array{amount: float|string, payment_method: string, reference?: ?string, paid_on: string, notes?: ?string}  $details
     * @param  array<int, array{amount: float|string, settle?: bool}>  $allocations  claim id => amount, and whether this closes the claim
     */
    public function recordPayment(Insurer $insurer, array $details, array $allocations): InsurerPayment
    {
        $amount = round((float) $details['amount'], 2);
        $allocations = collect($allocations)
            ->map(fn ($row) => ['amount' => round((float) ($row['amount'] ?? 0), 2), 'settle' => (bool) ($row['settle'] ?? false)])
            ->filter(fn ($row) => $row['amount'] > 0 || $row['settle']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['payment.amount' => 'Enter the amount the insurer paid.']);
        }
        if ($allocations->isEmpty()) {
            throw ValidationException::withMessages(['allocations' => 'Choose the claims this payment covers.']);
        }
        if (abs($allocations->sum('amount') - $amount) > 0.005) {
            throw ValidationException::withMessages([
                'allocations' => 'The amounts given to claims (' . number_format($allocations->sum('amount'), 2)
                    . ') must add up to the payment (' . number_format($amount, 2) . ').',
            ]);
        }

        return DB::transaction(function () use ($insurer, $details, $amount, $allocations) {
            $claims = InsuranceClaim::whereIn('id', $allocations->keys())
                ->where('insurer_id', $insurer->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($allocations as $claimId => $row) {
                $claim = $claims->get($claimId);
                if (!$claim || !in_array($claim->status, InsuranceClaim::PAYABLE_STATUSES, true)) {
                    throw ValidationException::withMessages(['allocations' => "Claim #{$claimId} is not open for payment from {$insurer->name}."]);
                }
                if ($row['amount'] - $claim->outstandingAmount() > 0.005) {
                    throw ValidationException::withMessages([
                        'allocations' => "Claim #{$claimId} only has " . number_format($claim->outstandingAmount(), 2) . ' outstanding.',
                    ]);
                }
            }

            $payment = InsurerPayment::create([
                'insurer_id'     => $insurer->id,
                'receipt_number' => 'IP-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
                'amount'         => $amount,
                'payment_method' => $details['payment_method'],
                'reference'      => ($details['reference'] ?? null) ?: null,
                'paid_on'        => $details['paid_on'],
                'notes'          => ($details['notes'] ?? null) ?: null,
                'received_by'    => Auth::id(),
            ]);

            foreach ($allocations as $claimId => $row) {
                $claim = $claims->get($claimId);

                if ($row['amount'] > 0) {
                    InsurerPaymentAllocation::create([
                        'insurer_payment_id' => $payment->id,
                        'insurance_claim_id' => $claim->id,
                        'amount'             => $row['amount'],
                    ]);
                    $claim->amount_received = round((float) $claim->amount_received + $row['amount'], 2);
                    $claim->updated_by = Auth::id();
                    $claim->save();
                }

                if ($row['settle'] || $claim->outstandingAmount() <= 0.005) {
                    $this->close($claim, $payment);
                }
            }

            AuditTrail::record(
                'insurer_payment.recorded',
                "{$insurer->name} payment {$payment->receipt_number} of " . currency() . ' ' . number_format($amount, 2)
                    . ' across ' . $allocations->count() . ' claim(s)',
                $payment,
                [],
                ['amount' => $amount, 'allocations' => $allocations->all(), 'reference' => $payment->reference],
                null,
                true
            );

            return $payment;
        });
    }

    /**
     * The insurer will pay no more than $insurerPays on this claim. Anything the sale still
     * expects from the insurer above that becomes the patient's to pay or is written off,
     * per the insurer's shortfall setting. Calling it again with the same figure does nothing.
     */
    public function limitInsurerShare(InsuranceClaim $claim, float $insurerPays, string $reason): float
    {
        if (!$claim->sale_id) {
            return 0.0;
        }

        $sale = Sales::whereKey($claim->sale_id)->lockForUpdate()->first();
        if (!$sale) {
            return 0.0;
        }

        $shortfall = round((float) $sale->insurer_amount - max(0, $insurerPays), 2);
        if ($shortfall <= 0.005) {
            return 0.0;
        }

        $insurer = Insurer::withTrashed()->find($claim->insurer_id);
        $action = $insurer?->shortfall_action === 'write_off' ? 'write_off' : 'bill_patient';
        $old = $sale->only(['total_amount', 'insurer_amount', 'payment_status', 'profit']);

        if ($action === 'write_off') {
            SaleAdjustment::create([
                'sale_id'    => $sale->id,
                'type'       => 'insurance_write_off',
                'amount'     => $shortfall,
                'method'     => 'fixed',
                'created_by' => Auth::id(),
                'reason'     => $reason,
            ]);
            $sale->total_amount = round((float) $sale->total_amount - $shortfall, 2);
            $sale->profit = max(0, round((float) $sale->profit - $shortfall, 2));
        }

        $sale->insurer_amount = round((float) $sale->insurer_amount - $shortfall, 2);
        if ($sale->remaining_balance > 0.005) {
            // The patient now owes the gap: it shows in Outstanding Balances and balance reminders.
            $sale->payment_status = 'partial';
        } elseif ($sale->payment_status === 'partial' || $sale->payment_status === 'unpaid') {
            $sale->payment_status = 'paid';
        }
        $sale->save();

        $claim->shortfall_amount = round((float) $claim->shortfall_amount + $shortfall, 2);
        $claim->shortfall_action = $action;
        $claim->updated_by = Auth::id();
        $claim->save();

        AuditTrail::record(
            'insurance.shortfall',
            ($action === 'write_off' ? 'Wrote off ' : 'Billed patient ') . currency() . ' ' . number_format($shortfall, 2)
                . " not paid by " . ($insurer?->name ?? 'the insurer') . " on sale {$sale->transaction_id}: {$reason}",
            $sale,
            $old,
            $sale->only(['total_amount', 'insurer_amount', 'payment_status', 'profit']) + ['shortfall' => $shortfall, 'action' => $action, 'claim_id' => $claim->id],
            $sale->patient_id,
            true
        );

        return $shortfall;
    }

    /** Mark the claim paid and settle whatever the insurer did not pay. */
    private function close(InsuranceClaim $claim, InsurerPayment $payment): void
    {
        $received = round((float) $claim->amount_received, 2);

        $claim->status = 'paid';
        $claim->payment_date = $payment->paid_on;
        $claim->approved_amount ??= $received;
        $claim->updated_by = Auth::id();
        $claim->save();

        $this->limitInsurerShare($claim, $received, "claim #{$claim->id} closed at " . number_format($received, 2) . " (payment {$payment->receipt_number})");
    }
}
