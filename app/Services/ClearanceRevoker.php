<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\CashierPatientClearance;
use App\Models\ClearanceRevokeLog;
use App\Models\RefundLog;
use App\Models\Sales;
use App\Services\Finance\RefundProcessor;
use Illuminate\Support\Facades\DB;

/**
 * Approves a clearance revoke: the clearance goes, and its consultation fee is refunded. Used by the
 * approvals page, and straight from the request on plans without approvals ($atOnce), where the fee
 * refund is carried out too instead of waiting under Approvals → Refunds.
 *
 * @return array{ok: bool, message: string, clearance: ?CashierPatientClearance}
 */
class ClearanceRevoker
{
    public function approve(ClearanceRevokeLog $log, bool $atOnce = false): array
    {
        if ($log->status !== ClearanceRevokeLog::STATUS_PENDING) {
            return ['ok' => false, 'message' => 'This request is no longer pending.', 'clearance' => null];
        }

        $clearance = $log->clearance;
        if (!$clearance) {
            $log->update(['status' => ClearanceRevokeLog::STATUS_APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now()]);
            return ['ok' => true, 'message' => 'Approved — clearance record no longer exists.', 'clearance' => null];
        }
        if ($clearance->consultation()->exists()) {
            return ['ok' => false, 'message' => 'Cannot approve — a consultation has since been linked to this clearance.', 'clearance' => $clearance];
        }

        [$note, $refund] = DB::transaction(function () use ($log, $clearance, $atOnce) {
            $log->update(['status' => ClearanceRevokeLog::STATUS_APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now()]);
            $clearance->delete();

            return $this->requestFeeRefund($log, $clearance, $atOnce);
        });

        if ($atOnce && $refund) {
            $processor = app(RefundProcessor::class);
            try {
                $processor->approve($refund);
                $done = $processor->process($refund->fresh('sale'));
                $note = 'The '.currency().' '.number_format((float) $done->refunded_amount, 2).' fee was refunded ('.$done->refund_number.').';
            } catch (\RuntimeException $e) {
                $refund->update(['status' => RefundLog::STATUS_PENDING, 'approved_by' => null, 'approved_at' => null]);
                $note = 'The fee refund is waiting under Approvals: '.$e->getMessage();
            }
        }

        return ['ok' => true, 'message' => trim('Clearance revoked. '.($note ?? '')), 'clearance' => $clearance];
    }

    /**
     * A revoked clearance's fee is refunded through the normal refund requests: this raises one (pending,
     * in the requester's name) for the clearance service line only, since later items of the visit can sit
     * on the same bill. Returns [note for the approver or null, the refund request or null].
     */
    private function requestFeeRefund(ClearanceRevokeLog $log, CashierPatientClearance $clearance, bool $atOnce): array
    {
        $sale = $clearance->sale_id ? Sales::with('items')->lockForUpdate()->find($clearance->sale_id) : null;
        if (!$sale || $sale->is_refunded) {
            return [null, null];
        }
        if (RefundLog::where('sale_id', $sale->id)->whereIn('status', [RefundLog::STATUS_PENDING, RefundLog::STATUS_APPROVED])->exists()) {
            return ['A refund for this payment was already requested.', null];
        }

        $feeItems = $sale->items->filter(fn ($item) => (int) $item->product_id === (int) $clearance->service_id
            && $item->notes === 'Clearance Service' && $item->dispensed_quantity > $item->refunded_quantity);
        if ($feeItems->isEmpty()) {
            return [null, null];
        }
        if (!$sale->finalizeForRefund()) {
            return ['The fee was not refunded automatically: the bill still has a balance owing.', null];
        }

        $reason = 'Clearance revoked: '.trim((string) $log->reason);
        $refund = RefundLog::create([
            'sale_id'       => $sale->id,
            'sale_item_ids' => $feeItems->pluck('id')->values()->all(),
            'status'        => RefundLog::STATUS_PENDING,
            'initiated_by'  => $log->requested_by ?? auth()->id(),
            'request_type'  => RefundLog::TYPE_REFUND,
            'reason_code'   => 'service_cancelled',
            'reason'        => mb_substr($reason, 0, 500),
            'initiated_at'  => now(),
        ]);
        AuditTrail::record('refund.requested', "Refund requested for sale {$sale->transaction_id} after its clearance was revoked",
            $sale, [], ['request_type' => RefundLog::TYPE_REFUND, 'reason_code' => 'service_cancelled', 'reason' => $refund->reason,
                'sale_item_ids' => $refund->sale_item_ids, 'clearance_revoke_log_id' => $log->id], $sale->patient_id, true);
        if (!$atOnce) {
            NotificationService::sendToRoles(['Manager', 'Super Admin'], 'refund_requested', 'Refund Request Submitted',
                "The consultation fee for transaction #{$sale->transaction_id} awaits refund approval (clearance revoked).",
                'fas fa-undo', 'text-warning', route('admin.refund-approvals'));
        }

        $amount = $feeItems->sum(fn ($item) => (float) $item->subtotal);

        return ['A refund request for the '.currency().' '.number_format($amount, 2).' fee is waiting under Approvals → Refunds.', $refund];
    }
}
