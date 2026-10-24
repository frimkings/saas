<?php

namespace App\Services\Finance;

use App\Models\AuditTrail;
use App\Models\PaymentCorrection;
use App\Models\PaymentTransaction;
use App\Support\PaymentMethods;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A payment recorded under the wrong method (cash taken as MoMo, say) is moved to the right
 * one once the owner approves, so the till balances. The amount and the sale stay as they
 * were; the request is kept with who asked, who decided and why.
 */
class PaymentCorrectionService
{
    public function __construct(private DayCloseService $days) {}

    public function request(PaymentTransaction $payment, string $toMethod, string $reason): PaymentCorrection
    {
        $line = $payment->sale()->withTrashed()->value('business_line') === 'optical' ? PaymentMethods::OPTICAL : PaymentMethods::CLINIC;
        if (!array_key_exists($toMethod, PaymentMethods::active($line))) throw new RuntimeException('Choose a payment method the till offers.');
        if ($toMethod === $payment->payment_method) throw new RuntimeException('The payment is already recorded under that method.');
        if (trim($reason) === '') throw new RuntimeException('Say what went wrong.');
        $this->days->assertOpen($payment->created_at, 'its payments');
        if (PaymentCorrection::where('payment_transaction_id', $payment->id)->where('status', PaymentCorrection::PENDING)->exists()) {
            throw new RuntimeException('This payment already has a correction waiting for the owner.');
        }

        $correction = PaymentCorrection::create([
            'payment_transaction_id' => $payment->id, 'sale_id' => $payment->sale_id,
            'from_method' => $payment->payment_method, 'to_method' => $toMethod, 'amount' => $payment->amount,
            'reason' => trim($reason), 'status' => PaymentCorrection::PENDING, 'requested_by' => auth()->id(),
        ]);
        AuditTrail::record('payment_correction.requested', 'Asked to move a payment of ' . number_format((float) $payment->amount, 2)
            . ' from ' . PaymentMethods::label($payment->payment_method) . ' to ' . PaymentMethods::label($toMethod), $correction);

        // Clinic plans without approvals: the correction is made at once by the person who asked.
        if ($line === PaymentMethods::CLINIC && !\App\Services\LicenseService::has(\App\Support\Feature::APPROVALS)) {
            $this->approve($correction, asOwner: false);
            return $correction->fresh();
        }

        return $correction;
    }

    public function approve(PaymentCorrection $correction, bool $asOwner = true): void
    {
        if ($asOwner) $this->assertOwner();

        DB::transaction(function () use ($correction) {
            $correction = PaymentCorrection::lockForUpdate()->findOrFail($correction->id);
            if ($correction->status !== PaymentCorrection::PENDING) throw new RuntimeException('This correction has already been decided.');
            $payment = PaymentTransaction::lockForUpdate()->findOrFail($correction->payment_transaction_id);
            $this->days->assertOpen($payment->created_at, 'its payments');
            if ($payment->payment_method !== $correction->from_method) throw new RuntimeException('The payment has changed since the request was made. Reject it and ask again.');

            $payment->update(['payment_method' => $correction->to_method]);
            $correction->update(['status' => PaymentCorrection::APPROVED, 'decided_by' => auth()->id(), 'decided_at' => now()]);
            AuditTrail::record('payment_correction.approved', 'Moved a payment of ' . number_format((float) $payment->amount, 2) . ' from '
                . PaymentMethods::label($correction->from_method) . ' to ' . PaymentMethods::label($correction->to_method), $payment,
                ['payment_method' => $correction->from_method], ['payment_method' => $correction->to_method]);
        });
    }

    public function reject(PaymentCorrection $correction, string $reason): void
    {
        $this->assertOwner();
        if ($correction->status !== PaymentCorrection::PENDING) throw new RuntimeException('This correction has already been decided.');
        if (trim($reason) === '') throw new RuntimeException('Give a reason for rejecting it.');

        $correction->update(['status' => PaymentCorrection::REJECTED, 'decided_by' => auth()->id(), 'decided_at' => now(), 'rejection_reason' => trim($reason)]);
        AuditTrail::record('payment_correction.rejected', 'Rejected a payment correction: ' . trim($reason), $correction);
    }

    private function assertOwner(): void
    {
        if (!DayCloseService::isOwner()) throw new RuntimeException('Only the owner can decide payment corrections.');
    }
}
