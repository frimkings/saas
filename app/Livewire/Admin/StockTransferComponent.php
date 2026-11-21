<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;

/**
 * Stock between branches (21 Nov 2026): send stock to another branch, or ask another branch for it; several products
 * per transfer; the branch asked sends or declines; the receiving branch counts what arrived.
 */
class StockTransferComponent extends Component
{
    /** send: from this branch to another; request: ask another branch to send to us. */
    public string $mode = 'send';
    public ?int $otherBranchId = null;
    /** @var list<array{product_id: string|int|null, quantity: int|string}> */
    public array $lines = [['product_id' => '', 'quantity' => 1]];
    public string $notes = '';

    public ?int $declineId = null;
    public string $declineReason = '';
    public ?int $receiveId = null;
    /** item id => quantity that arrived */
    public array $received = [];

    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'request' ? 'request' : 'send';
        $this->resetErrorBag();
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => '', 'quantity' => 1];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines) ?: [['product_id' => '', 'quantity' => 1]];
    }

    public function createTransfer(StockTransferService $service): void
    {
        $this->context();
        $this->resetErrorBag();
        $this->validate([
            'otherBranchId' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['otherBranchId' => $this->mode === 'request' ? 'branch to ask' : 'destination']);
        // The service's refusals (not stocked, expired, more than the branch holds) are shown, not thrown.
        try {
            $this->mode === 'request'
                ? $service->request((int) $this->otherBranchId, $this->lines, $this->notes ?: null)
                : $service->create((int) $this->otherBranchId, $this->lines, $this->notes ?: null);
        } catch (\RuntimeException $e) {
            $this->addError('lines', $e->getMessage());
            return;
        }
        $this->reset(['otherBranchId', 'notes', 'lines']);
        $this->dispatch('notify', type: 'success', message: $this->mode === 'request' ? 'Request sent. The other branch sends the stock or says why not.' : 'Draft transfer created. Dispatch it when the stock leaves.');
    }

    public function dispatchTransfer(int $id, StockTransferService $service): void
    {
        $this->run(fn () => $service->dispatch($this->transfer($id)), 'Stock sent: it is in transit until the other branch receives it.');
    }

    public function startDecline(int $id): void
    {
        $this->declineId = $id;
        $this->declineReason = '';
        $this->resetErrorBag();
    }

    public function declineTransfer(StockTransferService $service): void
    {
        $this->resetErrorBag();
        $id = (int) $this->declineId;
        $this->run(fn () => $service->decline($this->transfer($id), $this->declineReason), 'Request declined. The other branch sees why.', 'declineReason');
        if (! $this->getErrorBag()->has('declineReason')) $this->declineId = null;
    }

    public function cancelTransfer(int $id, StockTransferService $service): void
    {
        $this->run(fn () => $service->cancel($this->transfer($id)), 'Transfer cancelled. Nothing moved.');
    }

    public function startReceive(int $id): void
    {
        $transfer = $this->transfer($id);
        $this->receiveId = $transfer->id;
        $this->received = $transfer->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity])->all();
        $this->resetErrorBag();
    }

    public function receiveTransfer(StockTransferService $service): void
    {
        $this->resetErrorBag();
        $id = (int) $this->receiveId;
        $this->run(fn () => $service->receive($this->transfer($id), $this->received), 'Stock received into this branch.', 'received');
        if (! $this->getErrorBag()->has('received')) $this->reset(['receiveId', 'received']);
    }

    private function run(\Closure $action, string $done, ?string $errorKey = null): void
    {
        $this->context();
        try {
            $action();
        } catch (\RuntimeException $e) {
            $errorKey ? $this->addError($errorKey, $e->getMessage()) : $this->dispatch('notify', type: 'error', message: $e->getMessage());
            return;
        }
        $this->dispatch('notify', type: 'success', message: $done);
    }

    /** A transfer this branch is part of (sending or receiving). */
    private function transfer(int $id): StockTransfer
    {
        $context = $this->context();

        return StockTransfer::withoutGlobalScopes()->with('items.product')->where('clinic_id', $context->clinicId())
            ->where(fn ($q) => $q->where('branch_id', $context->branchId())->orWhere('destination_branch_id', $context->branchId()))
            ->findOrFail($id);
    }

    public function render()
    {
        $context = $this->context();
        $here = $context->branchId();
        $branches = Branch::query()->where('clinic_id', $context->clinicId())->where('is_active', true)
            ->whereIn('id', $context->authorizedBranchIds())
            ->where('id', '!=', $here)->orderBy('name')->get();
        $all = StockTransfer::withoutGlobalScopes()->where('clinic_id', $context->clinicId())
            ->where(fn ($q) => $q->where('branch_id', $here)->orWhere('destination_branch_id', $here))
            ->with(['sourceBranch', 'destinationBranch', 'items.product'])->latest('id')->limit(100)->get();

        return view('livewire.admin.stock-transfer-component', [
            'branches' => $branches,
            // Sending: what this branch holds. Asking: anything the clinic keeps in stock.
            'products' => $this->mode === 'request'
                ? Product::query()->stocked()->where('made_to_order', false)->orderBy('name')->get(['id', 'name'])
                : Product::query()->stocked()->where('quantity', '>', 0)->orderBy('name')->get(),
            'askedOfUs' => $all->filter(fn ($t) => $t->status === 'requested' && (int) $t->branch_id === (int) $here)->values(),
            'outgoing' => $all->filter(fn ($t) => (int) $t->branch_id === (int) $here && $t->status !== 'requested')->values(),
            'incoming' => $all->filter(fn ($t) => (int) $t->destination_branch_id === (int) $here)->values(),
            'statuses' => StockTransferService::STATUSES,
            'here' => $here,
        ])->layout('layouts.admin.admin-layout');
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class)->ensureFor(auth()->user());
    }
}
