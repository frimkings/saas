<?php

namespace App\Services\Inventory;

use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stock moving between branches of one clinic (21 Nov 2026 flow):
 * - send: the source makes a draft and dispatches it (stock leaves, in transit);
 * - request: the branch that needs stock asks another; that branch sends it (dispatch) or declines with a reason;
 * - receive: the destination counts what arrived; a shortfall is recorded on the line, never hidden;
 * - a draft or a request not yet answered can be cancelled by either branch.
 */
class StockTransferService
{
    public const STATUSES = ['requested' => 'Requested', 'draft' => 'Draft', 'in_transit' => 'In transit', 'received' => 'Received',
        'declined' => 'Declined', 'cancelled' => 'Cancelled'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BranchInventoryService $inventory,
    ) {
    }

    /** This branch sends stock to another: a draft to dispatch. */
    public function create(int $destinationBranchId, array $items, ?string $notes = null): StockTransfer
    {
        $source = $this->context->requireBranch();
        $destination = $this->otherBranch($destinationBranchId, $source);

        return DB::transaction(function () use ($source, $destination, $items, $notes) {
            $transfer = StockTransfer::create([
                'destination_branch_id' => $destination->id,
                'transfer_number' => $this->nextNumber($source->clinic_id),
                'status' => 'draft',
                'created_by' => $this->context->user()?->id ?? auth()->id(),
                'notes' => $notes,
            ]);
            $this->addLines($transfer, $items, $source, true);

            return $transfer->load('items');
        });
    }

    /** This branch asks another for stock. The request belongs to the branch asked (its source), with us as destination. */
    public function request(int $sourceBranchId, array $items, ?string $notes = null): StockTransfer
    {
        $here = $this->context->requireBranch();
        $source = $this->otherBranch($sourceBranchId, $here);

        return DB::transaction(function () use ($source, $here, $items, $notes) {
            $transfer = new StockTransfer;
            $transfer->forceFill([
                'uuid' => (string) Str::uuid(), 'clinic_id' => $here->clinic_id, 'branch_id' => $source->id,
                'destination_branch_id' => $here->id, 'transfer_number' => $this->nextNumber($here->clinic_id),
                'status' => 'requested', 'created_by' => $this->context->user()?->id ?? auth()->id(), 'notes' => $notes,
            ]);
            // Saved without the tenant hook, which would make this branch the source.
            StockTransfer::withoutEvents(fn () => $transfer->save());
            $this->addLines($transfer, $items, $source, false);
            AuditTrail::record('stock.transfer_requested', "Asked {$source->name} for stock ({$transfer->transfer_number})", $transfer);

            return $transfer->load('items');
        });
    }

    public function dispatch(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->with('items.product')->findOrFail($transfer->id);
            if ((int) $transfer->clinic_id !== (int) $this->context->clinicId()
                || ! in_array($transfer->status, ['draft', 'requested'], true)
                || (int) $transfer->branch_id !== (int) $this->context->branchId()) {
                throw new RuntimeException('Only a draft or a request may be sent, by the branch it comes from.');
            }
            // A request was made without checking our shelf: check it now, before anything leaves.
            foreach ($transfer->items as $item) {
                $available = $this->inventory->quantity($item->product_id, $transfer->branch_id);
                if ($item->quantity > $available) {
                    throw new RuntimeException("Only {$available} of {$item->product?->name} here, so {$item->quantity} cannot be sent. Decline the request or ask them to change it.");
                }
            }
            foreach ($transfer->items as $item) {
                $this->inventory->decrease($item->product_id, $item->quantity, $transfer->branch_id);
            }
            $wasRequest = $transfer->status === 'requested';
            $transfer->update(['status' => 'in_transit', 'dispatched_by' => auth()->id() ?? $this->context->user()?->id, 'dispatched_at' => now()]
                + ($wasRequest ? ['answered_by' => auth()->id(), 'answered_at' => now()] : []));
            AuditTrail::record('stock.transfer_dispatched', "Sent stock transfer {$transfer->transfer_number}".($wasRequest ? ' (requested)' : ''), $transfer);

            return $transfer->fresh('items');
        });
    }

    /** The branch asked says no, with a reason the asking branch sees. */
    public function decline(StockTransfer $transfer, string $reason): StockTransfer
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new RuntimeException('Say why, so the other branch knows.');

        return DB::transaction(function () use ($transfer, $reason) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->id);
            if ((int) $transfer->clinic_id !== (int) $this->context->clinicId() || $transfer->status !== 'requested'
                || (int) $transfer->branch_id !== (int) $this->context->branchId()) {
                throw new RuntimeException('Only the branch asked can decline a request.');
            }
            $transfer->update(['status' => 'declined', 'response_note' => mb_substr($reason, 0, 500), 'answered_by' => auth()->id(), 'answered_at' => now()]);
            AuditTrail::record('stock.transfer_declined', "Declined stock request {$transfer->transfer_number}: {$reason}", $transfer);

            return $transfer;
        });
    }

    /** A draft, or a request not yet answered, is called off by either branch. Nothing has moved. */
    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->id);
            $here = (int) $this->context->branchId();
            if ((int) $transfer->clinic_id !== (int) $this->context->clinicId() || ! in_array($transfer->status, ['draft', 'requested'], true)
                || ! in_array($here, [(int) $transfer->branch_id, (int) $transfer->destination_branch_id], true)) {
                throw new RuntimeException('Only a transfer that has not been sent can be cancelled.');
            }
            $transfer->update(['status' => 'cancelled', 'answered_by' => auth()->id(), 'answered_at' => now()]);
            AuditTrail::record('stock.transfer_cancelled', "Cancelled stock transfer {$transfer->transfer_number}", $transfer);

            return $transfer;
        });
    }

    /**
     * The destination takes in what arrived. $received: item id => quantity (blank or missing = all of it).
     * Less than was sent is recorded on the line as a shortfall; more than was sent is refused.
     */
    public function receive(StockTransfer $transfer, array $received = []): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $received) {
            $transfer = StockTransfer::withoutGlobalScopes()->lockForUpdate()->with('items.product')->findOrFail($transfer->id);
            if ((int) $transfer->clinic_id !== (int) $this->context->clinicId()
                || $transfer->status !== 'in_transit'
                || (int) $transfer->destination_branch_id !== (int) $this->context->branchId()) {
                throw new RuntimeException('Only the destination branch may receive an in-transit transfer.');
            }
            $short = [];
            foreach ($transfer->items as $item) {
                $value = $received[$item->id] ?? null;
                $quantity = $value === null || $value === '' ? $item->quantity : (int) $value;
                if ($quantity < 0 || $quantity > $item->quantity || ! is_numeric($value ?? $quantity)) {
                    throw new RuntimeException("Enter between 0 and {$item->quantity} for {$item->product?->name}.");
                }
                if ($quantity > 0) $this->inventory->increase($item->product_id, $quantity, [], $transfer->destination_branch_id);
                $item->update(['received_quantity' => $quantity]);
                if ($quantity < $item->quantity) $short[] = ($item->quantity - $quantity).' × '.$item->product?->name;
            }
            $transfer->update(['status' => 'received', 'received_by' => auth()->id() ?? $this->context->user()?->id, 'received_at' => now()]);
            AuditTrail::record('stock.transfer_received', "Received stock transfer {$transfer->transfer_number}".($short ? '; short: '.implode(', ', $short) : ''), $transfer,
                [], $short ? ['short' => $short] : [], null, (bool) $short);

            return $transfer->fresh('items');
        });
    }

    private function otherBranch(int $branchId, Branch $here): Branch
    {
        $branch = Branch::query()->whereKey($branchId)->where('is_active', true)->firstOrFail();
        if ((int) $branch->clinic_id !== (int) $here->clinic_id || $branch->is($here)
            || ! in_array((int) $branch->id, $this->context->authorizedBranchIds(), true)) {
            throw new RuntimeException('Stock transfers require two different branches in the same clinic.');
        }

        return $branch;
    }

    /** $checkShelf: sending now, so the source must hold it; a request is checked when the other branch sends it. */
    private function addLines(StockTransfer $transfer, array $items, Branch $source, bool $checkShelf): void
    {
        $lines = collect($items)->filter(fn ($line) => ! empty($line['product_id']));
        if ($lines->isEmpty()) throw new RuntimeException('A stock transfer must contain at least one item.');
        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);
            $product = Product::query()->findOrFail((int) $line['product_id']);
            if ($quantity <= 0) {
                throw new RuntimeException('Every transfer quantity must be greater than zero.');
            }
            // Only stock on the shelf can move: not services or made-to-order items, nothing expired,
            // and no more than this branch holds.
            if ($product->made_to_order) {
                throw new RuntimeException($product->name . ' is not kept in stock, so it cannot be transferred.');
            }
            if ($checkShelf) {
                $inDateBatch = \App\Models\InventoryLot::withoutGlobalScopes()->where('product_id', $product->id)
                    ->where('branch_id', $source->id)->where('quantity', '>', 0)->whereDate('expiry_date', '>=', today())->exists();
                if ($product->isExpired() && ! $inDateBatch) {
                    throw new RuntimeException($product->name . ' has expired stock; take it off the shelf rather than transfer it.');
                }
                $available = $this->inventory->quantity($product, $source->id);
                if ($quantity > $available) {
                    throw new RuntimeException("Only {$available} of {$product->name} at {$source->name}, so {$quantity} cannot be transferred.");
                }
            }
            $transfer->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
        }
    }

    private function nextNumber(int $clinicId): string
    {
        $next = StockTransfer::withoutGlobalScopes()->where('clinic_id', $clinicId)->lockForUpdate()->count() + 1;
        return 'TRF-'.now()->format('Ym').'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
