<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\CashierPatientClearance;
use App\Models\ClearanceRevokeLog;
use App\Models\RefundLog;
use App\Models\Sales;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class ClearanceRevokeApprovalsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    public string $activeTab  = 'pending';
    public string $search     = '';
    public string $fromDate   = '';
    public string $toDate     = '';

    public ?int   $rejectingLogId    = null;
    public string $rejectionReason   = '';
    public bool   $showRejectModal   = false;

    protected $rules = [
        'rejectionReason' => 'required|string|min:5|max:500',
    ];

    public function mount(): void
    {
        abort_if(
            !auth()->user()?->hasAnyRole(['Manager', 'Super Admin']) &&
            !auth()->user()?->can('approve clearance revoke'),
            403
        );

        $this->fromDate = now()->subDays(30)->toDateString();
        $this->toDate   = now()->toDateString();
    }

    public function updatedSearch(): void  { $this->resetPage(); }
    public function updatedFromDate(): void { $this->resetPage(); }
    public function updatedToDate(): void   { $this->resetPage(); }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function approve(int $id): void
    {
        $log = ClearanceRevokeLog::with('clearance')->findOrFail($id);

        if ($log->status !== ClearanceRevokeLog::STATUS_PENDING) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'This request is no longer pending.']);
            return;
        }

        $clearance = $log->clearance;

        if (!$clearance) {
            $log->update([
                'status'      => ClearanceRevokeLog::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);
            $this->dispatch('notify', ...['type' => 'info', 'message' => 'Approved — clearance record no longer exists.']);
            return;
        }

        if ($clearance->consultation()->exists()) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Cannot approve — a consultation has since been linked to this clearance.',
            ]);
            return;
        }

        $refundNote = DB::transaction(function () use ($log, $clearance) {
            $log->update([
                'status'      => ClearanceRevokeLog::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);
            $clearance->delete();

            return $this->requestFeeRefund($log, $clearance);
        });

        if ($log->requested_by) {
            NotificationService::send(
                $log->requested_by,
                'clearance_revoke_approved',
                'Revoke Request Approved',
                auth()->user()->name . ' approved your clearance revoke request for ' .
                    optional(optional($clearance)->patient)->name . '.',
                'fas fa-check-circle',
                'text-success',
                route('secretary.patient-clearance')
            );
        }

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Clearance revoked and requester notified.'.($refundNote ? ' '.$refundNote : '')]);
    }

    /**
     * A revoked clearance's fee is refunded through the normal refund approvals: this raises the
     * request (pending, in the requester's name) for the clearance service line only, since later
     * items of the visit can sit on the same bill. Returns a note for the approver, or null.
     */
    private function requestFeeRefund(ClearanceRevokeLog $log, CashierPatientClearance $clearance): ?string
    {
        $sale = $clearance->sale_id ? Sales::with('items')->lockForUpdate()->find($clearance->sale_id) : null;
        if (!$sale || $sale->is_refunded) {
            return null;
        }
        if (RefundLog::where('sale_id', $sale->id)->whereIn('status', [RefundLog::STATUS_PENDING, RefundLog::STATUS_APPROVED])->exists()) {
            return 'A refund for this payment was already requested.';
        }

        $feeItems = $sale->items->filter(fn ($item) => (int) $item->product_id === (int) $clearance->service_id
            && $item->notes === 'Clearance Service' && $item->dispensed_quantity > $item->refunded_quantity);
        if ($feeItems->isEmpty()) {
            return null;
        }
        if (!$sale->finalizeForRefund()) {
            return 'The fee was not refunded automatically: the bill still has a balance owing.';
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
        NotificationService::sendToRoles(['Manager', 'Super Admin'], 'refund_requested', 'Refund Request Submitted',
            "The consultation fee for transaction #{$sale->transaction_id} awaits refund approval (clearance revoked).",
            'fas fa-undo', 'text-warning', route('admin.refund-approvals'));

        $amount = $feeItems->sum(fn ($item) => (float) $item->subtotal);

        return 'A refund request for the '.currency().' '.number_format($amount, 2).' fee is waiting under Approvals → Refunds.';
    }

    public function openRejectModal(int $id): void
    {
        $this->rejectingLogId  = $id;
        $this->rejectionReason = '';
        $this->showRejectModal = true;
        $this->resetErrorBag();
    }

    public function closeRejectModal(): void
    {
        $this->rejectingLogId  = null;
        $this->rejectionReason = '';
        $this->showRejectModal = false;
        $this->resetErrorBag();
    }

    public function confirmReject(): void
    {
        $this->validate(['rejectionReason' => 'required|string|min:5|max:500']);

        $log = ClearanceRevokeLog::with('clearance.patient')->findOrFail($this->rejectingLogId);

        if ($log->status !== ClearanceRevokeLog::STATUS_PENDING) {
            $this->closeRejectModal();
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'This request is no longer pending.']);
            return;
        }

        $log->update([
            'status'           => ClearanceRevokeLog::STATUS_REJECTED,
            'rejected_by'      => auth()->id(),
            'rejected_at'      => now(),
            'rejection_reason' => $this->rejectionReason,
        ]);

        if ($log->requested_by) {
            NotificationService::send(
                $log->requested_by,
                'clearance_revoke_rejected',
                'Revoke Request Rejected',
                auth()->user()->name . ' rejected your clearance revoke request: ' . $this->rejectionReason,
                'fas fa-times-circle',
                'text-danger',
                route('secretary.patient-clearance')
            );
        }

        $this->closeRejectModal();
        $this->dispatch('notify', ...['type' => 'info', 'message' => 'Revoke request rejected and requester notified.']);
    }

    public function render()
    {
        $pendingCount = ClearanceRevokeLog::pendingCount();

        $query = ClearanceRevokeLog::with(['clearance.patient', 'requestedBy', 'approvedBy', 'rejectedBy'])
            ->when($this->activeTab === 'pending', fn($q) => $q->where('status', ClearanceRevokeLog::STATUS_PENDING))
            ->when($this->activeTab !== 'pending', fn($q) => $q->whereIn('status', [
                ClearanceRevokeLog::STATUS_APPROVED,
                ClearanceRevokeLog::STATUS_REJECTED,
            ]))
            ->when($this->search, fn($q) => $q->where(function ($inner) {
                $inner->where('reason', 'like', '%' . $this->search . '%')
                      ->orWhereHas('clearance.patient', fn($p) =>
                          $p->where('name', 'like', '%' . $this->search . '%')
                      );
            }))
            ->when($this->activeTab !== 'pending', fn($q) =>
                $q->whereBetween('requested_at', [
                    $this->fromDate . ' 00:00:00',
                    $this->toDate   . ' 23:59:59',
                ])
            )
            ->latest('requested_at');

        return view('livewire.admin.clearance-revoke-approvals-component', [
            'logs'         => $query->paginate(15),
            'pendingCount' => $pendingCount,
        ])->layout('layouts.admin.admin-layout');
    }
}
