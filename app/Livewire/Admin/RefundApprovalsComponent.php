<?php

namespace App\Livewire\Admin;

use App\Models\RefundLog;
use App\Models\Sales;
use App\Models\AuditTrail;
use App\Models\Product;
use App\Services\Insurance\InsuranceBilling;
use App\Services\Finance\RefundProcessor;
use App\Services\NotificationService;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class RefundApprovalsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    public string $activeTab   = 'pending'; // pending | approved | history
    public string $search      = '';
    public string $fromDate    = '';
    public string $toDate      = '';


    // Reject modal state
    public ?int    $rejectingId      = null;
    public string  $rejectionReason  = '';
    public bool    $showRejectModal  = false;

    protected $queryString = [
        'activeTab' => ['except' => 'pending'],
        'search'    => ['except' => ''],
        'fromDate'  => ['except' => ''],
        'toDate'    => ['except' => ''],
        'page'      => ['except' => 1],
    ];

    protected $rules = [
        'rejectionReason' => 'required|string|min:5|max:500',
    ];

    public function mount(): void
    {
        $this->authorizeRefundManagement();
    }

    public function updatedSearch(): void   { $this->resetPage(); }
    public function updatedFromDate(): void { $this->resetPage(); }
    public function updatedToDate(): void   { $this->resetPage(); }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function confirmApprove(int $id): void
    {
        $this->authorizeRefundManagement();

        $log = RefundLog::where('status', RefundLog::STATUS_PENDING)
            ->findOrFail($id);
        if ($problem = $this->voidDecisionProblem($log)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => $problem]);
            return;
        }

        $log->update([
            'status'      => RefundLog::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        AuditTrail::record(
            'refund.approved',
            ucfirst($log->request_type) . " approved for sale {$log->sale->transaction_id}",
            $log,
            ['status' => RefundLog::STATUS_PENDING],
            ['status' => RefundLog::STATUS_APPROVED, 'approved_by' => auth()->id()],
            $log->sale->patient_id,
            true
        );

        if ($log->initiated_by) {
            NotificationService::send(
                $log->initiated_by,
                'refund_approved',
                'Refund Request Approved',
                "Your refund request for transaction #{$log->sale->transaction_id} was approved by " . auth()->user()->name . '.',
                'fas fa-check-circle',
                'text-success',
                route('cashier.sales-records')
            );
        }

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => "Refund request approved.",
        ]);
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function openRejectModal(int $id): void
    {
        $this->rejectingId     = $id;
        $this->rejectionReason = '';
        $this->showRejectModal = true;
        $this->resetErrorBag();
    }

    public function closeRejectModal(): void
    {
        $this->rejectingId     = null;
        $this->rejectionReason = '';
        $this->showRejectModal = false;
        $this->resetErrorBag();
    }

    public function confirmReject(): void
    {
        $this->authorizeRefundManagement();

        $this->validate(['rejectionReason' => 'required|string|min:5|max:500']);

        $log = RefundLog::where('status', RefundLog::STATUS_PENDING)
            ->findOrFail($this->rejectingId);

        $log->update([
            'status'           => RefundLog::STATUS_REJECTED,
            'rejected_by'      => auth()->id(),
            'rejected_at'      => now(),
            'rejection_reason' => $this->rejectionReason,
        ]);

        AuditTrail::record(
            'refund.rejected',
            ucfirst($log->request_type) . " rejected for sale {$log->sale->transaction_id}",
            $log,
            ['status' => RefundLog::STATUS_PENDING],
            [
                'status' => RefundLog::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejection_reason' => $this->rejectionReason,
            ],
            $log->sale->patient_id,
            true
        );

        if ($log->initiated_by) {
            NotificationService::send(
                $log->initiated_by,
                'refund_rejected',
                'Refund Request Rejected',
                "Your refund request for transaction #{$log->sale->transaction_id} was rejected. Reason: {$this->rejectionReason}",
                'fas fa-times-circle',
                'text-danger',
                route('cashier.sales-records')
            );
        }

        $this->closeRejectModal();
        $this->dispatch('notify', ...[
            'type'    => 'info',
            'message' => "Refund request rejected.",
        ]);
    }

    // ── Process ───────────────────────────────────────────────────────────────

    public function process(int $id): void
    {
        $this->authorizeRefundManagement();

        $log = RefundLog::with('sale')
            ->where('status', RefundLog::STATUS_APPROVED)
            ->findOrFail($id);

        $sale = $log->sale;
        if ($problem = $this->voidDecisionProblem($log)) {
            $this->dispatch('close-processing-modal');
            $this->dispatch('notify', ...['type' => 'error', 'message' => $problem]);
            return;
        }

        if (!$sale) {
            $this->dispatch('close-processing-modal');
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Sale record not found for this refund.']);
            return;
        }

        try {
            $processedLog = app(RefundProcessor::class)->process($log);
        } catch (\RuntimeException $e) {
            $this->dispatch('close-processing-modal');
            $this->dispatch('notify', ...['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        if ($log->initiated_by) {
            NotificationService::send(
                $log->initiated_by,
                'refund_processed',
                'Refund Processed',
                "The refund for transaction #{$sale->transaction_id} has been executed by " . auth()->user()->name . '.',
                'fas fa-check-double',
                'text-success',
                route('cashier.sales-records')
            );
        }

        $this->dispatch('close-processing-modal');
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => "Refund {$processedLog->refund_number} processed. Eligible stock restored.",
        ]);

        $this->dispatch('refund-receipt-ready', ...[
            'url' => route('refunds.receipt', $processedLog),
        ]);
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    private function buildQuery()
    {
        $with = [
            'sale:id,transaction_id,total_amount',
            'initiator:id,name',
            'approvedBy:id,name',
            'processedBy:id,name',
            'rejectedBy:id,name',
        ];

        // Load full sale detail only for the pending tab — these power the JS preview modal.
        if ($this->activeTab === 'pending') {
            $with = array_merge($with, [
                'sale.items:id,sale_id,product_id,dispensed_quantity,selling_price,subtotal',
                'sale.items.product:id,name',
                'sale.patient:id,name,contact',
            ]);
        }

        return RefundLog::with($with)
            ->when($this->activeTab === 'pending',  fn ($q) => $q->pending())
            ->when($this->activeTab === 'approved', fn ($q) => $q->approved())
            ->when($this->activeTab === 'history',  fn ($q) => $q->whereIn('status', [RefundLog::STATUS_PROCESSED, RefundLog::STATUS_REJECTED]))
            ->when($this->search, fn ($q) =>
                $q->whereHas('sale', fn ($s) => $s->where('transaction_id', 'like', '%' . $this->search . '%'))
                  ->orWhere('reason', 'like', '%' . $this->search . '%')
            )
            ->when($this->fromDate, fn ($q) => $q->whereDateIndexed('created_at', '>=', $this->fromDate))
            ->when($this->toDate,   fn ($q) => $q->whereDateIndexed('created_at', '<=', $this->toDate))
            ->orderBy('created_at', 'desc');
    }

    /** Voids are the owner's call, and only while the sale's day is still open. */
    private function voidDecisionProblem(RefundLog $log): ?string
    {
        if ($log->request_type !== RefundLog::TYPE_VOID) return null;
        if (!\App\Services\Finance\DayCloseService::isOwner()) return 'Only the owner can approve a void.';
        if ($log->sale && app(\App\Services\Finance\DayCloseService::class)->isClosed($log->sale->created_at)) {
            return 'The day of this sale has been closed, so it can no longer be voided. Reject it and request a refund instead.';
        }

        return null;
    }

    private function authorizeRefundManagement(): void
    {
        $user = auth()->user();

        abort_if(
            !$user?->hasAnyRole(['Manager', 'Super Admin']) && !$user?->can('manage billing'),
            403
        );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        return view('livewire.admin.refund-approvals-component', [
            'logs'         => $this->buildQuery()->paginate(15),
            'pendingCount' => RefundLog::pendingCount(),
        ])->layout('layouts.admin.admin-layout');
    }
}
