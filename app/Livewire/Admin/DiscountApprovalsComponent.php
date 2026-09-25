<?php

namespace App\Livewire\Admin;

use App\Models\Cart;
use App\Models\DiscountApprovalRequest;
use App\Models\Sales;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class DiscountApprovalsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search       = '';
    public $dateFrom     = '';
    public $dateTo       = '';
    public $filterType   = ''; // 'percentage' | 'fixed' | ''
    public $filterApprover = '';

    protected $queryString = ['search', 'dateFrom', 'dateTo', 'filterType'];

    public function mount()
    {
        $user = auth()->user();
        abort_if(
            !$user?->hasAnyRole(['Manager', 'Super Admin']) && !$user?->can('manage billing'),
            403
        );
    }

    public function updatedSearch()      { $this->resetPage(); }
    public function updatedDateFrom()    { $this->resetPage(); }
    public function updatedDateTo()      { $this->resetPage(); }
    public function updatedFilterType()  { $this->resetPage(); }
    public function updatedFilterApprover() { $this->resetPage(); }

    public function clearFilters()
    {
        $this->search         = '';
        $this->dateFrom       = '';
        $this->dateTo         = '';
        $this->filterType     = '';
        $this->filterApprover = '';
        $this->resetPage();
    }

    public function approveRequest($requestId)
    {
        $u = auth()->user();
        abort_if(!$u?->hasAnyRole(['Manager', 'Super Admin']) && !$u?->can('manage billing'), 403);

        $request = DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_PENDING)
            ->findOrFail($requestId);

        if (!$this->requestCartIsStillOpen($request)) {
            $request->delete();

            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'This cart was already sold or removed, so the discount request was deleted.',
            ]);
            return;
        }

        if ($this->hasApprovedDuplicate($request)) {
            $request->update([
                'status' => DiscountApprovalRequest::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'notes' => trim(($request->notes ? $request->notes . "\n" : '') . 'Auto-rejected: an approved discount already exists for the same patient/product.'),
            ]);

            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'This duplicate request was rejected because the product already has an approved discount.',
            ]);
            return;
        }

        $request->update([
            'status' => DiscountApprovalRequest::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $rejectedDuplicates = $this->rejectPendingDuplicates($request);

        \App\Services\NotificationService::send(
            $request->cashier_id,
            'discount_approved',
            'Discount Request Approved',
            'Your discount request was approved by ' . auth()->user()->name . '. You can now complete the sale.',
            'fas fa-check-circle',
            'text-success',
            route('cashier.seller-desk')
        );

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Discount request approved.' . ($rejectedDuplicates > 0 ? " {$rejectedDuplicates} duplicate request(s) rejected." : ' The cashier will be notified on POS.'),
        ]);
    }

    public function rejectRequest($requestId)
    {
        $u = auth()->user();
        abort_if(!$u?->hasAnyRole(['Manager', 'Super Admin']) && !$u?->can('manage billing'), 403);

        $request = DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_PENDING)
            ->findOrFail($requestId);

        $request->update([
            'status' => DiscountApprovalRequest::STATUS_REJECTED,
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
        ]);

        \App\Services\NotificationService::send(
            $request->cashier_id,
            'discount_rejected',
            'Discount Request Rejected',
            'Your discount request was rejected by ' . auth()->user()->name . '. Remove the discount or request again.',
            'fas fa-times-circle',
            'text-danger',
            route('cashier.seller-desk')
        );

        $this->dispatch('notify', ...[
            'type' => 'info',
            'message' => 'Discount request rejected. The cashier will be notified on POS.',
        ]);
    }

    private function baseQuery()
    {
        return Sales::with(['patient', 'user', 'approvedBy'])
            ->where('discount_amount', '>', 0)
            ->when($this->search, function ($q) {
                $q->where(function ($inner) {
                    $inner->where('transaction_id', 'like', '%' . $this->search . '%')
                          ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', '%' . $this->search . '%'));
                });
            })
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->filterType, fn ($q) => $q->where('discount_type', $this->filterType))
            ->when($this->filterApprover, fn ($q) => $q->where('discount_approved_by', $this->filterApprover));
    }

    private function requestProductIds(DiscountApprovalRequest $request)
    {
        return collect($request->cart_snapshot ?? [])
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function requestCartIds(DiscountApprovalRequest $request)
    {
        return collect($request->cart_snapshot ?? [])
            ->pluck('cart_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function requestCartIsStillOpen(DiscountApprovalRequest $request): bool
    {
        $cartIds = $this->requestCartIds($request);

        if ($cartIds->isNotEmpty()) {
            $query = Cart::whereIn('id', $cartIds)
                ->where('purchased', false)
                ->where('status', 'pending');

            if ($request->patient_id) {
                $query->where('patient_id', $request->patient_id);
            }

            return (int) $query->count() === $cartIds->count();
        }

        $productIds = $this->requestProductIds($request);

        if ($productIds->isEmpty() || !$request->patient_id) {
            return false;
        }

        $openProductIds = Cart::where('patient_id', $request->patient_id)
            ->where('purchased', false)
            ->where('status', 'pending')
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $productIds->diff($openProductIds)->isEmpty();
    }

    private function deleteStalePendingRequests($pendingRequests): int
    {
        $deleted = 0;

        foreach ($pendingRequests as $request) {
            if (!$this->requestCartIsStillOpen($request)) {
                $request->delete();
                $deleted++;
            }
        }

        return $deleted;
    }

    private function overlapsRequestProducts(DiscountApprovalRequest $request, $productIds): bool
    {
        $requestProductIds = $this->requestProductIds($request);

        return $requestProductIds->intersect($productIds)->isNotEmpty();
    }

    private function hasApprovedDuplicate(DiscountApprovalRequest $request): bool
    {
        $productIds = $this->requestProductIds($request);

        if ($productIds->isEmpty()) {
            return false;
        }

        return DiscountApprovalRequest::where('id', '!=', $request->id)
            ->where('status', DiscountApprovalRequest::STATUS_APPROVED)
            ->where('patient_id', $request->patient_id)
            ->get()
            ->contains(fn ($other) => $this->overlapsRequestProducts($other, $productIds));
    }

    private function rejectPendingDuplicates(DiscountApprovalRequest $approvedRequest): int
    {
        $productIds = $this->requestProductIds($approvedRequest);

        if ($productIds->isEmpty()) {
            return 0;
        }

        $duplicates = DiscountApprovalRequest::where('id', '!=', $approvedRequest->id)
            ->where('status', DiscountApprovalRequest::STATUS_PENDING)
            ->where('patient_id', $approvedRequest->patient_id)
            ->get()
            ->filter(fn ($other) => $this->overlapsRequestProducts($other, $productIds));

        foreach ($duplicates as $duplicate) {
            $duplicate->update([
                'status' => DiscountApprovalRequest::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'notes' => trim(($duplicate->notes ? $duplicate->notes . "\n" : '') . 'Auto-rejected: duplicate discount for the same patient/product.'),
            ]);
        }

        return $duplicates->count();
    }

    public function render()
    {
        $pendingRequests = DiscountApprovalRequest::with(['cashier', 'patient'])
            ->where('status', DiscountApprovalRequest::STATUS_PENDING)
            ->latest()
            ->get();

        if ($this->deleteStalePendingRequests($pendingRequests) > 0) {
            $pendingRequests = DiscountApprovalRequest::with(['cashier', 'patient'])
                ->where('status', DiscountApprovalRequest::STATUS_PENDING)
                ->latest()
                ->get();
        }

        $pendingDuplicateProductIds = $pendingRequests->mapWithKeys(function ($request) use ($pendingRequests) {
            $productIds = $this->requestProductIds($request);
            $duplicateProductIds = $pendingRequests
                ->where('id', '!=', $request->id)
                ->where('patient_id', $request->patient_id)
                ->flatMap(fn ($other) => $this->requestProductIds($other))
                ->intersect($productIds)
                ->unique()
                ->values();

            return [$request->id => $duplicateProductIds];
        });

        $sales = $this->baseQuery()->latest()->paginate(15);

        $summary = $this->baseQuery()->selectRaw('
            COUNT(*) as total_count,
            SUM(discount_amount) as total_discounted,
            AVG(discount_amount) as avg_discount
        ')->first();

        $approvers = User::whereIn('id',
            Sales::where('discount_amount', '>', 0)
                ->whereNotNull('discount_approved_by')
                ->pluck('discount_approved_by')
                ->unique()
        )->get(['id', 'name']);

        return view('livewire.admin.discount-approvals-component', compact('sales', 'summary', 'approvers', 'pendingRequests', 'pendingDuplicateProductIds'))
            ->layout('layouts.admin.admin-layout');
    }
}
