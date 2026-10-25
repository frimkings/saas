<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;

class StockTransferComponent extends Component
{
    public ?int $destinationBranchId = null;
    public ?int $productId = null;
    public int $quantity = 1;
    public string $notes = '';

    public function createTransfer(StockTransferService $service): void
    {
        $this->context();
        $data = $this->validate([
            'destinationBranchId' => ['required', 'integer'],
            'productId' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        // The service's refusals (not stocked, expired, more than the branch holds) are shown, not thrown.
        try {
            $service->create($data['destinationBranchId'], [[
                'product_id' => $data['productId'], 'quantity' => $data['quantity'],
            ]], $data['notes'] ?: null);
        } catch (\RuntimeException $e) {
            $this->addError('quantity', $e->getMessage());
            return;
        }
        $this->reset(['destinationBranchId', 'productId', 'notes']);
        $this->quantity = 1;
        $this->dispatch('notify', type: 'success', message: 'Draft transfer created.');
    }

    public function dispatchTransfer(int $id, StockTransferService $service): void
    {
        $this->context();
        try {
            $service->dispatch(StockTransfer::query()->findOrFail($id));
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
            return;
        }
        $this->dispatch('notify', type: 'success', message: 'Stock dispatched.');
    }

    public function receiveTransfer(int $id, StockTransferService $service): void
    {
        $context = $this->context();
        $transfer = StockTransfer::withoutGlobalScopes()->where('clinic_id', $context->clinicId())
            ->where('destination_branch_id', $context->branchId())->findOrFail($id);
        try {
            $service->receive($transfer);
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
            return;
        }
        $this->dispatch('notify', type: 'success', message: 'Stock received into this branch.');
    }

    public function render()
    {
        $context = $this->context();
        $branches = Branch::query()->where('clinic_id', $context->clinicId())->where('is_active', true)
            ->whereIn('id', $context->authorizedBranchIds())
            ->where('id', '!=', $context->branchId())->orderBy('name')->get();
        $outgoing = StockTransfer::query()->with(['destinationBranch', 'items.product'])->latest()->get();
        $incoming = StockTransfer::withoutGlobalScopes()->where('clinic_id', $context->clinicId())
            ->where('destination_branch_id', $context->branchId())->with(['items.product'])->latest()->get();

        return view('livewire.admin.stock-transfer-component', [
            'branches' => $branches, 'products' => Product::query()->stocked()->where('quantity', '>', 0)->orderBy('name')->get(),
            'outgoing' => $outgoing, 'incoming' => $incoming,
        ])->layout('layouts.admin.admin-layout');
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class)->ensureFor(auth()->user());
    }
}
