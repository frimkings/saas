<?php

namespace App\Livewire\Admin;

use App\Models\RefundLog;
use App\Models\Sales;
use App\Models\AuditTrail;
use App\Models\Product;
use App\Services\Insurance\InsuranceBilling;
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

        if (!$sale) {
            $this->dispatch('close-processing-modal');
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Sale record not found for this refund.']);
            return;
        }

        try {
            $processedLog = DB::transaction(function () use ($log, $sale) {
                // Pessimistic locks prevent two concurrent requests from double-processing
                $lockedLog  = RefundLog::lockForUpdate()->findOrFail($log->id);
                $lockedSale = Sales::with(['items.product', 'items.opticalProduct', 'paymentTransactions'])
                    ->lockForUpdate()
                    ->findOrFail($sale->id);

                if ($lockedSale->is_refunded) {
                    throw new \RuntimeException('This sale has already been refunded.');
                }
                if ($lockedSale->bill_status === 'open') {
                    throw new \RuntimeException('Open visit bills cannot be refunded. Finalize the bill first.');
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
