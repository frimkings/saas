<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockTransferService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BranchInventoryService $inventory,
    ) {
    }

    public function create(int $destinationBranchId, array $items, ?string $notes = null): StockTransfer
    {
        $source = $this->context->requireBranch();
        $destination = Branch::query()->whereKey($destinationBranchId)->where('is_active', true)->firstOrFail();
        if ((int) $destination->clinic_id !== (int) $source->clinic_id
            || $destination->is($source)
            || ! in_array((int) $destination->id, $this->context->authorizedBranchIds(), true)) {
            throw new RuntimeException('Stock transfers require two different branches in the same clinic.');
        }

        return DB::transaction(function () use ($source, $destination, $items, $notes) {
            $transfer = StockTransfer::create([
                'destination_branch_id' => $destination->id,
                'transfer_number' => $this->nextNumber($source->clinic_id),
                'status' => 'draft',
                'created_by' => $this->context->user()?->id ?? auth()->id(),
                'notes' => $notes,
            ]);

            foreach ($items as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);
                $product = Product::query()->findOrFail((int) ($line['product_id'] ?? 0));
                if ($quantity <= 0) {
                    throw new RuntimeException('Every transfer quantity must be greater than zero.');
                }
                $transfer->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
            }

            if ($transfer->items()->count() === 0) {
                throw new RuntimeException('A stock transfer must contain at least one item.');
            }

            return $transfer->load('items');
        });
    }

    public function dispatch(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->with('items')->findOrFail($transfer->id);
            if ((int) $transfer->clinic_id !== (int) $this->context->clinicId()
                || $transfer->status !== 'draft'
                || (int) $transfer->branch_id !== (int) $this->context->branchId()) {
                throw new RuntimeException('Only a draft transfer may be dispatched by its source branch.');
            }
            foreach ($transfer->items as $item) {
                $this->inventory->decrease($item->product_id, $item->quantity, $transfer->branch_id);
            }
            $transfer->update(['status' => 'in_transit', 'dispatched_by' => auth()->id() ?? $this->context->user()?->id, 'dispatched_at' => now()]);
            return $transfer->fresh('items');
        });
    }

    public function receive(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->with('items')->findOrFail($transfer->id);
            if ((int) $transfer->clinic_id !== (int) $this->context->clinicId()
                || $transfer->status !== 'in_transit'
                || (int) $transfer->destination_branch_id !== (int) $this->context->branchId()) {
                throw new RuntimeException('Only the destination branch may receive an in-transit transfer.');
            }
            foreach ($transfer->items as $item) {
                $this->inventory->increase($item->product_id, $item->quantity, [], $transfer->destination_branch_id);
            }
            $transfer->update(['status' => 'received', 'received_by' => auth()->id() ?? $this->context->user()?->id, 'received_at' => now()]);
            return $transfer->fresh('items');
        });
    }

    private function nextNumber(int $clinicId): string
    {
        $next = StockTransfer::withoutGlobalScopes()->where('clinic_id', $clinicId)->lockForUpdate()->count() + 1;
        return 'TRF-'.now()->format('Ym').'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
