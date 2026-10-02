<?php

namespace App\Services;

use App\Models\LensOrder;
use App\Models\OpticalOrderLensLine;
use App\Models\OpticalProduct;
use App\Models\OpticalPurchaseOrder;
use App\Models\OpticalPurchaseOrderLine;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Supplier orders for optical stock. Stock lines are received into the branch ledger;
 * special-order lines (a lens for a customer job) are received straight to the job.
 */
class OpticalPurchasingService
{
    private const OPEN_JOB_STATUSES = ['Pending', 'Sent to Lab', 'In Production'];

    /**
     * @param  array<int, array{product_id?: ?int, lens_order_id?: ?int, eye?: ?string, description?: ?string, quantity: int, unit_cost?: float|string|null}>  $lines
     */
    public function createDraft(int $supplierId, array $lines, ?string $expectedDate = null, ?string $notes = null): OpticalPurchaseOrder
    {
        $this->assertManager();
        $supplier = Supplier::where('is_active', true)->find($supplierId);
        if (! $supplier) throw ValidationException::withMessages(['supplierId' => 'Choose an active supplier.']);
        if ($lines === []) throw ValidationException::withMessages(['lines' => 'Add at least one item to the order.']);

        return DB::transaction(function () use ($supplier, $lines, $expectedDate, $notes) {
            $order = OpticalPurchaseOrder::create([
                'po_number' => 'OPO-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'supplier_id' => $supplier->id, 'status' => 'draft',
                'expected_date' => $expectedDate ?: ($supplier->lead_time_days ? now()->addDays($supplier->lead_time_days)->toDateString() : null),
                'notes' => $notes ? trim($notes) : null, 'created_by' => auth()->id(),
            ]);
            foreach ($lines as $line) $this->addLine($order, $line);
            return $order->load('lines');
        });
    }

    public function addLine(OpticalPurchaseOrder $order, array $line): OpticalPurchaseOrderLine
    {
        if ($order->status !== 'draft') throw ValidationException::withMessages(['lines' => 'Only draft orders can be changed.']);
        $quantity = (int) ($line['quantity'] ?? 0);
        $cost = round(max(0, (float) ($line['unit_cost'] ?? 0)), 2);
        if ($quantity < 1 || $quantity > 100000) throw ValidationException::withMessages(['lines' => 'Each line needs a quantity from 1 to 100000.']);

        if (! empty($line['lens_order_id'])) {
            $job = LensOrder::whereIn('status', self::OPEN_JOB_STATUSES)->findOrFail((int) $line['lens_order_id']);
            $eye = in_array($line['eye'] ?? null, ['od', 'os'], true) ? $line['eye'] : null;
            $this->assertNotAlreadyOrdered($job->id, $eye);
            return $order->lines()->create([
                'lens_order_id' => $job->id, 'eye' => $eye, 'quantity_ordered' => $eye ? 1 : max(1, $quantity),
                'unit_cost' => $cost, 'description' => mb_substr($line['description'] ?? $this->jobDescription($job, $eye), 0, 255),
            ]);
        }
        $product = OpticalProduct::where('is_active', true)->findOrFail((int) ($line['product_id'] ?? 0));
        $existing = $order->lines()->where('optical_product_id', $product->id)->first();
        if ($existing) {
            $existing->update(['quantity_ordered' => $existing->quantity_ordered + $quantity]);
            return $existing;
        }
        return $order->lines()->create([
            'optical_product_id' => $product->id, 'quantity_ordered' => $quantity,
            'unit_cost' => $cost ?: round((float) $product->cost_price, 2),
            'description' => mb_substr($product->sku.' · '.$product->name, 0, 255),
        ]);
    }

    public function place(int $orderId): OpticalPurchaseOrder
    {
        $this->assertManager();
        return DB::transaction(function () use ($orderId) {
            $order = OpticalPurchaseOrder::lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'draft') throw ValidationException::withMessages(['order' => 'This order has already been placed.']);
            if (! $order->lines()->exists()) throw ValidationException::withMessages(['order' => 'Add at least one item before placing the order.']);
            $order->update(['status' => 'ordered', 'ordered_at' => now()]);
            return $order;
        });
    }

    /**
     * @param  array<int, int|string|null>  $quantities  line id => quantity received now
     * @param  array<int, string|null>  $expiries  line id => expiry date of what arrived (contact lenses, solutions)
     */
    public function receive(int $orderId, array $quantities, ?string $invoiceReference = null, ?string $batchNumber = null, array $expiries = []): OpticalPurchaseOrder
    {
        $this->assertManager();
        return DB::transaction(function () use ($orderId, $quantities, $invoiceReference, $batchNumber, $expiries) {
            $order = OpticalPurchaseOrder::with(['supplier', 'lines.product'])->lockForUpdate()->findOrFail($orderId);
            if (! $order->isOpen()) throw ValidationException::withMessages(['receive' => 'Only placed orders can be received.']);
            $received = 0;
            foreach ($order->lines as $line) {
                $quantity = (int) ($quantities[$line->id] ?? 0);
                if ($quantity === 0) continue;
                if ($quantity < 0 || $quantity > $line->outstanding()) {
                    throw ValidationException::withMessages(['receive' => "Receive between 0 and {$line->outstanding()} of {$line->description}."]);
                }
                if ($line->isSpecialOrder()) {
                    $this->receiveForJob($line, $order, $invoiceReference);
                } else {
                    app(OpticalStockLedgerService::class)->receive($line->product, $quantity, [
                        'unit_cost' => (float) $line->unit_cost, 'unit_price' => (float) $line->product->selling_price,
                        'supplier' => $order->supplier->name,
                        'reference' => trim($order->po_number.' '.($invoiceReference ? '· inv '.trim($invoiceReference) : '')),
                        'batch_number' => $batchNumber ? trim($batchNumber) : null,
                        'optical_purchase_order_line_id' => $line->id,
                        'expiry_date' => ($expiries[$line->id] ?? null) ?: null,
                    ]);
                }
                $line->update(['quantity_received' => $line->quantity_received + $quantity]);
                $received += $quantity;
            }
            if ($received === 0) throw ValidationException::withMessages(['receive' => 'Enter the quantity received for at least one line.']);
            $complete = $order->lines->every(fn (OpticalPurchaseOrderLine $line) => $line->fresh()->outstanding() === 0);
            $order->update(['status' => $complete ? 'received' : 'partially_received', 'received_at' => $complete ? now() : null]);
            return $order;
        });
    }

    /** Cancel an order nothing has been received on, or close a part-received one (short delivery). */
    public function cancel(int $orderId): OpticalPurchaseOrder
    {
        $this->assertManager();
        return DB::transaction(function () use ($orderId) {
            $order = OpticalPurchaseOrder::lockForUpdate()->findOrFail($orderId);
            if (in_array($order->status, ['received', 'cancelled'], true)) throw ValidationException::withMessages(['order' => 'This order is already closed.']);
            $order->status === 'partially_received'
                ? $order->update(['status' => 'received', 'received_at' => now(), 'notes' => trim(($order->notes ? $order->notes."\n" : '').'Closed short: remaining items not delivered.')])
                : $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            return $order;
        });
    }

    /**
     * Customer jobs whose lenses must be bought in and are not yet on an open supplier order:
     * the special-order eye of a half pair, or both lenses of a special-order job.
     *
     * @return Collection<int, array{order: LensOrder, eye: ?string, description: string}>
     */
    public function specialOrderBacklog(): Collection
    {
        $linked = OpticalPurchaseOrderLine::whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->whereNotNull('lens_order_id')->get(['lens_order_id', 'eye'])
            ->map(fn ($line) => $line->lens_order_id.'|'.($line->eye ?? ''))->flip();
        $jobs = LensOrder::with(['lensLines', 'patient', 'partnerClinic'])
            ->whereIn('status', self::OPEN_JOB_STATUSES)->where('work_type', 'prescription')
            ->where(fn ($q) => $q->where('lens_supply_source', 'outside')->orWhereHas('lensLines', fn ($l) => $l->where('source', 'special_order')->where('status', 'ordered')))
            ->oldest()->orderBy('id')->get();

        $rows = collect();
        foreach ($jobs as $job) {
            $eyes = $job->lensLines->where('source', 'special_order')->where('status', 'ordered')->pluck('eye')->all();
            foreach ($eyes ?: [null] as $eye) {
                if ($linked->has($job->id.'|'.($eye ?? ''))) continue;
                $rows->push(['order' => $job, 'eye' => $eye, 'description' => $this->jobDescription($job, $eye)]);
            }
        }
        return $rows;
    }

    /** Draft order from the reorder report of a lens range (pairs converted to pieces). */
    public function draftFromReplenishment(int $supplierId, array $specs): OpticalPurchaseOrder
    {
        $rows = app(OpticalLensReplenishmentService::class)->rows($specs)->where('pairs', '>', 0);
        if ($rows->isEmpty()) throw ValidationException::withMessages(['supplierId' => 'No powers in this range need reordering.']);
        return $this->createDraft($supplierId, $rows->map(fn ($row) => ['product_id' => $row['id'], 'quantity' => $row['pairs'] * 2])->values()->all());
    }

    public function jobDescription(LensOrder $job, ?string $eye): string
    {
        $details = json_decode((string) $job->notes, true) ?: [];
        $rx = $job->prescription_snapshot ?? [];
        $describe = function (string $e) use ($rx) {
            $power = fn (string $field) => is_numeric($value = data_get($rx, "$e.$field")) ? sprintf('%+.2f', (float) $value) : null;
            $cyl = $power('cyl');
            $parts = array_filter([
                'SPH '.($power('sph') ?? '+0.00'),
                $cyl && (float) $cyl != 0 ? 'CYL '.$cyl.(filled(data_get($rx, "$e.axis")) ? ' x '.data_get($rx, "$e.axis") : '') : null,
                ($add = $power('add')) && (float) $add != 0 ? 'ADD '.$add : null,
            ]);
            return strtoupper($e).' '.implode(' ', $parts);
        };
        $powers = $eye ? $describe($eye) : $describe('od').' / '.$describe('os');
        $design = trim(implode(' ', array_filter([data_get($details, 'lens_details.type'), data_get($details, 'lens_details.index'), data_get($details, 'lens_details.stock_coating') ?: data_get($details, 'lens_details.coatings')])));
        return trim($powers.($design ? ' · '.$design : '').' · job '.$job->order_id.($job->display_customer_name ? ' ('.$job->display_customer_name.')' : ''));
    }

    private function receiveForJob(OpticalPurchaseOrderLine $line, OpticalPurchaseOrder $order, ?string $invoiceReference): void
    {
        if (! $line->eye) return;
        $lens = OpticalOrderLensLine::where('lens_order_id', $line->lens_order_id)->where('eye', $line->eye)
            ->where('source', 'special_order')->latest('id')->first();
        if (! $lens || $lens->status === 'ordered') {
            $lens?->update(['status' => 'received', 'received_at' => now()]);
            return;
        }
        // The job was cancelled after this lens was ordered. It still arrived and was paid for,
        // so it goes into stock rather than disappearing.
        $product = $lens->optical_product_id ? OpticalProduct::withTrashed()->find($lens->optical_product_id) : null;
        if (! $product) {
            throw ValidationException::withMessages(['receive' => "{$line->description}: the job was cancelled and this lens is not a stock item, so it cannot be received into stock. Return it to the supplier, or close the order short."]);
        }
        app(OpticalStockLedgerService::class)->receive($product, 1, [
            'unit_cost' => (float) $line->unit_cost, 'unit_price' => (float) $product->selling_price,
            'supplier' => $order->supplier->name,
            'reference' => trim($order->po_number.' · cancelled job'.($invoiceReference ? ' · inv '.trim($invoiceReference) : '')),
            'optical_purchase_order_line_id' => $line->id,
        ]);
    }

    private function assertNotAlreadyOrdered(int $jobId, ?string $eye): void
    {
        $exists = OpticalPurchaseOrderLine::where('lens_order_id', $jobId)->where('eye', $eye)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', 'cancelled'))->exists();
        if ($exists) throw ValidationException::withMessages(['lines' => 'These lenses are already on a supplier order.']);
    }

    private function assertManager(): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'Only a manager can manage supplier orders.');
    }
}
