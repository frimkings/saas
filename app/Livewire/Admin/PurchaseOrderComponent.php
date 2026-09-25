<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Stock;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class PurchaseOrderComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Filters
    public string $search         = '';
    public string $status         = '';
    public string $supplierId     = '';
    public string $invoiceStatus  = '';
    public int    $perPage        = 15;

    // Create/Edit PO modal
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $poId       = null;

    // GRN (receive goods) modal
    public bool  $showGrnModal = false;
    public ?int  $grnPoId      = null;
    public array $grnLines     = [];

    // Invoice modal
    public bool   $showInvoiceModal  = false;
    public ?int   $invoicePoId       = null;
    public string $inv_number        = '';
    public string $inv_date          = '';
    public string $inv_due_date      = '';
    public string $inv_amount        = '';

    // Payment modal
    public bool   $showPaymentModal   = false;
    public ?int   $paymentPoId        = null;
    public string $pay_amount         = '';
    public string $pay_method         = 'cash';
    public string $pay_reference      = '';
    public string $pay_date           = '';

    // Product search per-line
    public string $productSearch  = '';
    public int    $productLineIdx = -1;
    public array  $productResults = [];

    // PO form fields
    public string $supplier_id   = '';
    public string $order_date    = '';
    public string $expected_date = '';
    public string $notes         = '';
    public string $status_field  = 'draft';
    public array  $items         = [];

    protected $queryString = [
        'search'        => ['except' => ''],
        'status'        => ['except' => ''],
        'supplierId'    => ['except' => ''],
        'invoiceStatus' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->order_date = now()->toDateString();
    }

    public function updatedSearch(): void        { $this->resetPage(); }
    public function updatedStatus(): void        { $this->resetPage(); }
    public function updatedSupplierId(): void    { $this->resetPage(); }
    public function updatedInvoiceStatus(): void { $this->resetPage(); }

    public function selectProduct(int $productId): void
    {
        $p = Product::find($productId);
        if (!$p || $this->productLineIdx < 0) return;
        $idx = $this->productLineIdx;
        $this->items[$idx]['product_id']  = $p->id;
        $this->items[$idx]['description'] = $p->name;
        $this->items[$idx]['unit_cost']   = (float) $p->cost_price;
        $this->productResults = [];
        $this->productLineIdx = -1;
        $this->recalcLine($idx);
    }

    public function addLine(): void
    {
        $this->items[] = [
            'product_id'       => null,
            'description'      => '',
            'quantity_ordered' => 1,
            'unit_cost'        => 0,
            'subtotal'         => 0,
        ];
    }

    public function removeLine(int $index): void
    {
        array_splice($this->items, $index, 1);
        $this->items = array_values($this->items);
    }

    public function updatedItems($value, $key): void
    {
        $parts = explode('.', $key);
        if (count($parts) !== 2) return;

        $idx   = (int) $parts[0];
        $field = $parts[1];

        if ($field === 'description') {
            if (strlen((string) $value) >= 2) {
                $this->productLineIdx = $idx;
                $this->productResults = Product::where('name', 'like', "%{$value}%")
                    ->limit(8)->get(['id', 'name', 'cost_price'])->toArray();
            } else {
                $this->productResults = [];
                $this->productLineIdx = -1;
            }
        } elseif (in_array($field, ['quantity_ordered', 'unit_cost'])) {
            $this->recalcLine($idx);
        }
    }

    private function recalcLine(int $idx): void
    {
        if (!isset($this->items[$idx])) return;
        $qty  = max(0, (float) ($this->items[$idx]['quantity_ordered'] ?? 0));
        $cost = max(0, (float) ($this->items[$idx]['unit_cost']        ?? 0));
        $this->items[$idx]['subtotal'] = round($qty * $cost, 2);
    }

    private function calcTotal(): float
    {
        return round(array_sum(array_column($this->items, 'subtotal')), 2);
    }

    public function openCreate(): void
    {
        $this->reset(['poId', 'supplier_id', 'expected_date', 'notes', 'items', 'productResults']);
        $this->status_field = 'draft';
        $this->order_date   = now()->toDateString();
        $this->addLine();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $po = PurchaseOrder::with('items')->findOrFail($id);
        abort_if(!in_array($po->status, ['draft', 'ordered']), 403);

        $this->poId          = $po->id;
        $this->supplier_id   = (string) ($po->supplier_id ?? '');
        $this->order_date    = $po->order_date->toDateString();
        $this->expected_date = $po->expected_date?->toDateString() ?? '';
        $this->notes         = $po->notes ?? '';
        $this->status_field  = $po->status;

        $this->items = $po->items->map(fn ($i) => [
            'product_id'       => $i->product_id,
            'description'      => $i->description,
            'quantity_ordered' => $i->quantity_ordered,
            'unit_cost'        => $i->unit_cost,
            'subtotal'         => $i->subtotal,
        ])->toArray();

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $clinicId = \App\Models\PurchaseOrder::clinicIdForWrite();
        $this->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('clinic_id', $clinicId)],
            'order_date'       => 'required|date',
            'status_field'     => 'required|in:draft,ordered',
            'items'            => 'required|array|min:1',
            'items.*.description'      => 'required|string|max:255',
            'items.*.product_id'       => ['nullable', Rule::exists('products', 'id')->where('clinic_id', $clinicId)],
            'items.*.quantity_ordered' => 'required|numeric|min:0.01',
            'items.*.unit_cost'        => 'required|numeric|min:0',
        ]);

        DB::transaction(function () {
            $normalizedItems = collect($this->items)->map(function (array $line): array {
                $line['quantity_ordered'] = round((float) $line['quantity_ordered'], 2);
                $line['unit_cost'] = round((float) $line['unit_cost'], 2);
                $line['subtotal'] = round($line['quantity_ordered'] * $line['unit_cost'], 2);
                return $line;
            });
            $data = [
                'supplier_id'   => $this->supplier_id ?: null,
                'status'        => $this->status_field,
                'order_date'    => $this->order_date,
                'expected_date' => $this->expected_date ?: null,
                'notes'         => $this->notes ?: null,
                'total_amount'  => round((float) $normalizedItems->sum('subtotal'), 2),
                'created_by'    => auth()->id(),
            ];

            if ($this->isEditing) {
                $po = PurchaseOrder::findOrFail($this->poId);
                $po->update($data);
                $po->items()->delete();
                $event = 'purchase_order.updated';
            } else {
                $data['po_number'] = PurchaseOrder::nextNumber();
                $po = PurchaseOrder::create($data);
                $event = 'purchase_order.created';
            }

            foreach ($normalizedItems as $line) {
                $po->items()->create([
                    'product_id'       => $line['product_id'] ?: null,
                    'description'      => $line['description'],
                    'quantity_ordered'  => $line['quantity_ordered'],
                    'quantity_received' => 0,
                    'unit_cost'        => $line['unit_cost'],
                    'subtotal'         => $line['subtotal'],
                ]);
            }

            AuditTrail::record($event, ($this->isEditing ? 'Updated' : 'Created') . " PO {$po->po_number}", $po, force: true);
        });

        $this->showModal = false;
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => $this->isEditing ? 'Purchase order updated.' : 'Purchase order created.',
        ]);
    }

    // ── GRN / Receive Goods ──────────────────────────────────────────────────

    public function openGrn(int $id): void
    {
        $po = PurchaseOrder::with('items.product')->findOrFail($id);
        abort_if(in_array($po->status, ['received', 'cancelled']), 403);

        $this->grnPoId = $po->id;
        $this->grnLines = $po->items->map(fn ($i) => [
            'id'                => $i->id,
            'description'       => $i->description,
            'quantity_ordered'  => $i->quantity_ordered,
            'quantity_received' => $i->quantity_received,
            'receive_qty'       => 0,
            'batch_number'      => $i->batch_number ?? '',
            'expiry_date'       => $i->expiry_date?->toDateString() ?? '',
            'product_id'        => $i->product_id,
        ])->toArray();

        $this->showGrnModal = true;
    }

    public function receiveGoods(): void
    {
        $this->validate([
            'grnLines'            => 'required|array|min:1',
            'grnLines.*.id'          => 'required|integer|distinct',
            'grnLines.*.receive_qty' => 'required|integer|min:0',
            'grnLines.*.batch_number' => 'nullable|string|max:100',
            'grnLines.*.expiry_date' => 'nullable|date',
        ]);

        DB::transaction(function () {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($this->grnPoId);
            abort_if(in_array($po->status, ['received', 'cancelled']), 403);

            $items = $po->items()->lockForUpdate()->get()->keyBy('id');
            $submittedIds = collect($this->grnLines)->pluck('id')->map(fn ($id) => (int) $id);
            if ($submittedIds->diff($items->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'grnLines' => 'One or more receiving lines do not belong to this purchase order.',
                ]);
            }

            foreach ($this->grnLines as $line) {
                $qty = (int) $line['receive_qty'];
                if ($qty <= 0) continue;

                $item = $items->get((int) $line['id']);
                if ($qty > (int) $item->remaining) {
                    throw ValidationException::withMessages([
                        'grnLines' => "Received quantity for {$item->description} exceeds the outstanding quantity.",
                    ]);
                }
                $newReceived = $item->quantity_received + $qty;
                $item->update([
                    'quantity_received' => $newReceived,
                    'batch_number'      => $line['batch_number'] ?: null,
                    'expiry_date'       => $line['expiry_date'] ?: null,
                ]);

                // Create stock movement
                if ($item->product_id) {
                    $product = Product::find($item->product_id);
                    $qtyBefore = $product ? (int) $product->quantity : 0;
                    Stock::create([
                        'product_id'      => $item->product_id,
                        'user_id'         => auth()->id(),
                        'reference_no'    => $po->po_number,
                        'movement_type'   => 'received',
                        'supplier'        => $po->supplier?->name,
                        'batch_number'    => $line['batch_number'] ?: null,
                        'expiry_date'     => $line['expiry_date'] ?: null,
                        'quantity_before' => $qtyBefore,
                        'quantity'        => (int) $qty,
                        'quantity_after'  => $qtyBefore + (int) $qty,
                        'cost_price'      => $item->unit_cost ?: null,
                        'notes'           => "Received against {$po->po_number}",
                    ]);

                    app(\App\Services\Inventory\BranchInventoryService::class)->increase(
                        $item->product_id,
                        (int) $qty,
                        [
                            'batch_number' => $line['batch_number'] ?: null,
                            'expiry_date' => $line['expiry_date'] ?: null,
                            'unit_cost' => $item->unit_cost ?: null,
                        ]
                    );
                }
            }

            // Recalculate PO status
            $po->load('items');
            $allReceived = $po->items->every(fn ($i) => $i->quantity_received >= $i->quantity_ordered);
            $anyReceived = $po->items->some(fn ($i)  => $i->quantity_received > 0);

            $po->update([
                'status'      => $allReceived ? 'received' : ($anyReceived ? 'partial' : $po->status),
                'received_by' => auth()->id(),
                'received_at' => now(),
            ]);

            AuditTrail::record('purchase_order.received', "Goods received against {$po->po_number}", $po, force: true);
        });

        $this->showGrnModal = false;
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Goods received and stock updated.']);
    }

    public function confirmCancel(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        $this->dispatch('show-po-confirm', ...[
            'id'      => $id,
            'action'  => 'cancelPo',
            'message' => "Cancel purchase order {$po->po_number}? This cannot be undone.",
        ]);
    }

    public function cancelPo(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        abort_if($po->status === 'received', 403);
        $po->update(['status' => 'cancelled']);
        AuditTrail::record('purchase_order.cancelled', "Cancelled PO {$po->po_number}", $po, force: true);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Purchase order cancelled.']);
    }

    // ── Invoice ───────────────────────────────────────────────────────────────

    public function openInvoiceModal(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        abort_if($po->status === 'cancelled', 403);

        $this->invoicePoId  = $po->id;
        $this->inv_number   = $po->invoice_number ?? '';
        $this->inv_date     = $po->invoice_date?->toDateString() ?? now()->toDateString();
        $this->inv_due_date = $po->invoice_due_date?->toDateString() ?? '';
        $this->inv_amount   = $po->invoice_amount !== null ? (string) $po->invoice_amount : (string) $po->total_amount;

        $this->showInvoiceModal = true;
    }

    public function saveInvoice(): void
    {
        $this->validate([
            'inv_date'   => 'required|date',
            'inv_amount' => 'required|numeric|min:0.01',
        ], [], ['inv_date' => 'Invoice Date', 'inv_amount' => 'Invoice Amount']);

        $po = PurchaseOrder::findOrFail($this->invoicePoId);

        $po->update([
            'invoice_number'   => $this->inv_number ?: null,
            'invoice_date'     => $this->inv_date,
            'invoice_due_date' => $this->inv_due_date ?: null,
            'invoice_amount'   => $this->inv_amount,
            'invoice_status'   => $po->invoice_status === 'none' ? 'invoiced' : $po->invoice_status,
        ]);

        AuditTrail::record('purchase_order.invoice_recorded', "Invoice recorded for {$po->po_number}", $po, force: true);

        $this->showInvoiceModal = false;
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Invoice details saved.']);
    }

    // ── Payment ───────────────────────────────────────────────────────────────

    public function openPaymentModal(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        abort_if(in_array($po->invoice_status, ['none', 'paid']), 403);

        $this->paymentPoId   = $po->id;
        $this->pay_amount    = (string) $po->invoice_balance_due;
        $this->pay_method    = 'cash';
        $this->pay_reference = '';
        $this->pay_date      = now()->toDateString();

        $this->showPaymentModal = true;
    }

    public function savePayment(): void
    {
        $this->validate([
            'pay_amount' => 'required|numeric|min:0.01',
            'pay_date'   => 'required|date',
        ], [], ['pay_amount' => 'Payment Amount', 'pay_date' => 'Payment Date']);

        DB::transaction(function (): void {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($this->paymentPoId);
            abort_if(in_array($po->invoice_status, ['none', 'paid']), 403);

            $payment = round((float) $this->pay_amount, 2);
            if ($payment > round($po->invoice_balance_due, 2)) {
                throw ValidationException::withMessages([
                    'pay_amount' => 'Payment cannot exceed the outstanding invoice balance.',
                ]);
            }

            $newPaid = round((float) $po->paid_amount + $payment, 2);
            $base    = round((float) ($po->invoice_amount ?? $po->total_amount), 2);
            $status  = $newPaid >= $base ? 'paid' : 'partial';

            $po->update([
                'paid_amount'       => $newPaid,
                'payment_method'    => $this->pay_method ?: null,
                'payment_reference' => $this->pay_reference ?: null,
                'paid_at'           => $status === 'paid' ? $this->pay_date : $po->paid_at,
                'invoice_status'    => $status,
            ]);

            AuditTrail::record('purchase_order.payment_recorded', "Payment of {$this->pay_amount} recorded for {$po->po_number}", $po, force: true);
        });

        $this->showPaymentModal = false;
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Payment recorded.']);
    }

    public function render()
    {
        $orders = PurchaseOrder::query()
            ->with('supplier')
            ->when($this->search, fn ($q) =>
                $q->where('po_number', 'like', "%{$this->search}%")
                  ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$this->search}%"))
            )
            ->when($this->status,        fn ($q) => $q->where('status', $this->status))
            ->when($this->supplierId,    fn ($q) => $q->where('supplier_id', $this->supplierId))
            ->when($this->invoiceStatus, fn ($q) => $q->where('invoice_status', $this->invoiceStatus))
            ->latest()
            ->paginate($this->perPage);

        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();

        return view('livewire.admin.purchase-order-component', [
            'orders'    => $orders,
            'suppliers' => $suppliers,
            'total'     => $this->calcTotal(),
        ])->layout('layouts.admin.admin-layout');
    }
}
