<?php

namespace App\Livewire\Optical;

use App\Models\OpticalProduct;
use App\Models\OpticalPurchaseOrder;
use App\Models\OpticalSupplierReturn;
use App\Models\Supplier;
use App\Services\ClinicAccessService;
use App\Services\OpticalPurchasingService;
use App\Services\OpticalSupplierReturnService;
use App\Support\Messaging\WhatsAppLink;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Optical purchasing: supplier orders (stock and special-order lenses), receiving against
 * them, returns to suppliers, and the supplier list.
 */
class OpticalPurchasingComponent extends Component
{
    use WithPagination;

    public string $tab = 'orders';
    public string $statusFilter = 'open';
    protected $queryString = ['tab', 'statusFilter'];

    // Order detail / receiving
    #[Locked]
    public ?int $viewOrderId = null;
    public array $receiveQty = [];
    /** line id => expiry date of what arrived, for stock that expires */
    public array $receiveExpiry = [];
    public string $invoiceReference = '';
    public string $batchNumber = '';

    // New order
    public bool $showOrderForm = false;
    public ?int $supplierId = null;
    public string $expectedDate = '';
    public string $orderNotes = '';
    public array $draftLines = [];
    public string $productSearch = '';

    // Special-order backlog
    public array $backlogSelected = [];
    public array $backlogCosts = [];
    public ?int $backlogSupplierId = null;

    // Returns
    public bool $showReturnForm = false;
    public ?int $returnSupplierId = null;
    public ?int $returnPoId = null;
    public string $returnReason = '';
    public string $returnNotes = '';
    public array $returnLines = [];
    public string $returnSearch = '';
    #[Locked]
    public ?int $settleId = null;
    public string $settleStatus = 'credited';
    public string $settleReference = '';

    // Suppliers
    public bool $showSupplierForm = false;
    #[Locked]
    public ?int $editingSupplierId = null;
    public string $supplierName = '';
    public string $supplierContact = '';
    public string $supplierPhone = '';
    public string $supplierEmail = '';
    public string $supplierLeadTime = '';
    public bool $supplierActive = true;

    public function mount(): void
    {
        abort_unless(in_array($this->tab, ['orders', 'special', 'returns', 'suppliers'], true), 404);
        $planner = app(\App\Services\OpticalLensOrderPlanner::class);
        // "Create supplier order" on the lens stock grid: every power below its reorder level,
        // topped up to target, in pairs (both eyes for progressive and bifocal).
        if (is_string($specs = request()->query('reorder'))) {
            $specs = json_decode(base64_decode($specs, true) ?: '', true);
            if (is_array($specs)) {
                unset($specs['eye']);
                $pairs = app(\App\Services\OpticalLensReplenishmentService::class)->rows($specs)->where('pairs', '>', 0)
                    ->groupBy(fn ($row) => $planner->key((float) $row['sphere'], (float) $row['power']))
                    ->map(fn ($rows) => (int) $rows->max('pairs'))->all();
                $this->openOrderForm();
                $this->draftLines = $planner->draftLines($specs, $pairs);
            }
        }
        // Powers picked on the lens stock grid, each ordered in the chosen number of pairs.
        if (is_string($payload = request()->query('lensorder'))) {
            $payload = json_decode(base64_decode($payload, true) ?: '', true);
            if (is_array($payload) && is_array($payload['specs'] ?? null) && is_array($payload['cells'] ?? null)) {
                $this->openOrderForm();
                $this->draftLines = $planner->draftLines($payload['specs'], array_map('intval', $payload['cells']));
            }
        }
    }

    public function updatedTab(): void
    {
        abort_unless(in_array($this->tab, ['orders', 'special', 'returns', 'suppliers'], true), 404);
        $this->resetValidation();
        $this->resetPage();
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    public function openOrderForm(): void
    {
        $this->reset(['supplierId', 'expectedDate', 'orderNotes', 'draftLines', 'productSearch']);
        $this->viewOrderId = null;
        $this->showOrderForm = true;
        $this->tab = 'orders';
    }

    public function addDraftProduct(int $productId): void
    {
        $product = OpticalProduct::where('is_active', true)->findOrFail($productId);
        foreach ($this->draftLines as $i => $line) {
            if ((int) ($line['product_id'] ?? 0) === $product->id) { $this->draftLines[$i]['quantity'] = (int) $line['quantity'] + 1; $this->productSearch = ''; return; }
        }
        $this->draftLines[] = ['product_id' => $product->id, 'label' => $product->sku.' · '.$product->name, 'quantity' => 1, 'unit_cost' => (string) $product->cost_price];
        $this->productSearch = '';
    }

    public function removeDraftLine(int $index): void
    {
        unset($this->draftLines[$index]);
        $this->draftLines = array_values($this->draftLines);
    }

    public function saveDraft(bool $place = false): void
    {
        $rules = [
            'supplierId' => 'required|integer',
            'expectedDate' => 'nullable|date',
            'orderNotes' => 'nullable|string|max:2000',
            'draftLines' => 'required|array|min:1',
        ];
        foreach ($this->draftLines as $i => $line) {
            // Lens lines are entered in pairs at a cost per pair; other items per unit.
            $rules += ! empty($line['lens_pairs'])
                ? ["draftLines.$i.pairs" => 'required|integer|min:1|max:50000', "draftLines.$i.pair_cost" => 'nullable|numeric|min:0|max:99999999']
                : ["draftLines.$i.quantity" => 'required|integer|min:1|max:100000', "draftLines.$i.unit_cost" => 'nullable|numeric|min:0|max:99999999'];
        }
        $this->validate($rules, ['draftLines.required' => 'Add at least one item to the order.']);
        $planner = app(\App\Services\OpticalLensOrderPlanner::class);
        $service = app(OpticalPurchasingService::class);
        // Lens powers new to a range get their stock items here; nothing is left behind if the order fails.
        $order = \Illuminate\Support\Facades\DB::transaction(function () use ($planner, $service) {
            $lines = [];
            foreach ($this->draftLines as $line) {
                if (! empty($line['lens_pairs'])) array_push($lines, ...$planner->expand($line));
                else $lines[] = ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'], 'unit_cost' => $line['unit_cost']];
            }
            return $service->createDraft((int) $this->supplierId, $lines, $this->expectedDate ?: null, $this->orderNotes);
        });
        if ($place) $service->place($order->id);
        $this->showOrderForm = false;
        $this->viewOrderId = $order->id;
        session()->flash('success', "Supplier order {$order->po_number} ".($place ? 'placed.' : 'saved as a draft.'));
    }

    public function viewOrder(int $id): void
    {
        $order = OpticalPurchaseOrder::with('lines')->findOrFail($id);
        $this->viewOrderId = $order->id;
        $this->showOrderForm = false;
        $this->receiveQty = $order->lines->mapWithKeys(fn ($line) => [$line->id => (string) $line->outstanding()])->all();
        $this->reset(['invoiceReference', 'batchNumber', 'receiveExpiry']);
        $this->resetValidation();
    }

    /** Renderless: the browser has already closed the order panel (dismissCall). The id is locked, so it closes here. */
    #[\Livewire\Attributes\Renderless]
    public function closeOrder(): void
    {
        $this->viewOrderId = null;
        $this->resetValidation();
    }

    public function placeOrder(int $id): void
    {
        $order = app(OpticalPurchasingService::class)->place($id);
        $this->viewOrder($order->id);
        session()->flash('success', "Supplier order {$order->po_number} placed.");
    }

    public function receiveOrder(): void
    {
        $this->validate(['invoiceReference' => 'nullable|string|max:100', 'batchNumber' => 'nullable|string|max:100', 'receiveQty.*' => 'nullable|integer|min:0|max:100000',
            'receiveExpiry.*' => 'nullable|date|after:2000-01-01'], ['receiveExpiry.*.date' => 'Enter a valid expiry date.']);
        $order = app(OpticalPurchasingService::class)->receive((int) $this->viewOrderId, $this->receiveQty, $this->invoiceReference, $this->batchNumber, $this->receiveExpiry);
        $this->viewOrder($order->id);
        session()->flash('success', $order->status === 'received' ? "{$order->po_number} fully received." : "Delivery recorded against {$order->po_number}. Some items are still to come.");
    }

    public function cancelOrder(int $id): void
    {
        $order = app(OpticalPurchasingService::class)->cancel($id);
        $this->viewOrder($order->id);
        session()->flash('success', $order->status === 'cancelled' ? "{$order->po_number} cancelled." : "{$order->po_number} closed; items not delivered were dropped.");
    }

    // ── Special-order lenses ──────────────────────────────────────────────────

    public function orderBacklog(): void
    {
        $this->validate(['backlogSupplierId' => 'required|integer', 'backlogSelected' => 'required|array|min:1', 'backlogCosts.*' => 'nullable|numeric|min:0'],
            ['backlogSelected.required' => 'Tick the lenses to order.', 'backlogSupplierId.required' => 'Choose the supplier.']);
        $rows = app(OpticalPurchasingService::class)->specialOrderBacklog()->keyBy(fn ($row) => $row['order']->id.'|'.($row['eye'] ?? ''));
        $lines = [];
        foreach ($this->backlogSelected as $key) {
            $row = $rows->get($key) ?? abort(404);
            $lines[] = ['lens_order_id' => $row['order']->id, 'eye' => $row['eye'], 'quantity' => $row['eye'] ? 1 : 2,
                'unit_cost' => $this->backlogCosts[$key] ?? 0, 'description' => $row['description']];
        }
        $order = app(OpticalPurchasingService::class)->createDraft((int) $this->backlogSupplierId, $lines);
        $this->reset(['backlogSelected', 'backlogCosts']);
        $this->tab = 'orders';
        $this->viewOrder($order->id);
        session()->flash('success', "Draft {$order->po_number} created. Check it and place the order.");
    }

    // ── Returns ───────────────────────────────────────────────────────────────

    public function openReturnForm(?int $purchaseOrderId = null): void
    {
        $this->reset(['returnSupplierId', 'returnPoId', 'returnReason', 'returnNotes', 'returnLines', 'returnSearch']);
        if ($purchaseOrderId) {
            $order = OpticalPurchaseOrder::with('lines.product')->findOrFail($purchaseOrderId);
            $this->returnSupplierId = $order->supplier_id;
            $this->returnPoId = $order->id;
            foreach ($order->lines->where('quantity_received', '>', 0)->whereNotNull('optical_product_id') as $line) {
                $this->returnLines[] = ['product_id' => $line->optical_product_id, 'label' => $line->description, 'quantity' => 1, 'unit_cost' => (string) $line->unit_cost];
            }
        }
        $this->viewOrderId = null;
        $this->showOrderForm = false;
        $this->showReturnForm = true;
        $this->tab = 'returns';
    }

    public function addReturnProduct(int $productId): void
    {
        $product = OpticalProduct::findOrFail($productId);
        $this->returnLines[] = ['product_id' => $product->id, 'label' => $product->sku.' · '.$product->name, 'quantity' => 1, 'unit_cost' => (string) $product->cost_price];
        $this->returnSearch = '';
    }

    public function removeReturnLine(int $index): void
    {
        unset($this->returnLines[$index]);
        $this->returnLines = array_values($this->returnLines);
    }

    public function saveReturn(): void
    {
        $this->validate([
            'returnSupplierId' => 'required|integer', 'returnReason' => ['required', Rule::in(array_keys(OpticalSupplierReturn::REASONS))],
            'returnNotes' => 'nullable|string|max:2000', 'returnLines' => 'required|array|min:1',
            'returnLines.*.quantity' => 'required|integer|min:1|max:100000', 'returnLines.*.unit_cost' => 'nullable|numeric|min:0',
        ], ['returnLines.required' => 'Add the items going back.']);
        $return = app(OpticalSupplierReturnService::class)->create((int) $this->returnSupplierId, $this->returnReason, array_map(fn ($line) => [
            'product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'], 'unit_cost' => $line['unit_cost'],
        ], $this->returnLines), $this->returnPoId, $this->returnNotes);
        $this->showReturnForm = false;
        session()->flash('success', "Return {$return->return_number} recorded. Stock reduced; credit of ".currency()." ".number_format((float) $return->credit_expected, 2).' expected.');
    }

    public function openSettle(int $id): void
    {
        $this->settleId = OpticalSupplierReturn::where('credit_status', 'pending')->findOrFail($id)->id;
        $this->settleStatus = 'credited';
        $this->settleReference = '';
    }

    /** The settle id is locked, so the browser cannot clear it: Cancel comes here. */
    public function cancelSettle(): void
    {
        $this->settleId = null;
        $this->resetValidation();
    }

    public function settleReturn(): void
    {
        $this->validate(['settleStatus' => 'required|in:credited,replaced,written_off', 'settleReference' => 'nullable|string|max:100']);
        $return = app(OpticalSupplierReturnService::class)->settle((int) $this->settleId, $this->settleStatus, $this->settleReference);
        $this->settleId = null;
        session()->flash('success', "Return {$return->return_number} settled: ".OpticalSupplierReturn::SETTLEMENTS[$return->credit_status].'.');
    }

    // ── Suppliers ─────────────────────────────────────────────────────────────

    public function openSupplierForm(?int $id = null): void
    {
        $supplier = $id ? Supplier::findOrFail($id) : null;
        $this->editingSupplierId = $supplier?->id;
        $this->supplierName = $supplier->name ?? '';
        $this->supplierContact = $supplier->contact_person ?? '';
        $this->supplierPhone = $supplier->phone ?? '';
        $this->supplierEmail = $supplier->email ?? '';
        $this->supplierLeadTime = (string) ($supplier->lead_time_days ?? '');
        $this->supplierActive = $supplier?->is_active ?? true;
        $this->showSupplierForm = true;
        $this->resetValidation();
    }

    public function saveSupplier(): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $this->validate([
            'supplierName' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->where('clinic_id', app(\App\Support\Tenancy\TenantContext::class)->clinicId())->ignore($this->editingSupplierId)],
            'supplierContact' => 'nullable|string|max:255', 'supplierPhone' => 'nullable|string|max:50',
            'supplierEmail' => 'nullable|email|max:255', 'supplierLeadTime' => 'nullable|integer|min:0|max:365',
            'supplierActive' => 'boolean',
        ]);
        $supplier = $this->editingSupplierId ? Supplier::findOrFail($this->editingSupplierId) : new Supplier();
        $supplier->fill([
            'name' => trim($this->supplierName), 'contact_person' => trim($this->supplierContact) ?: null,
            'phone' => trim($this->supplierPhone) ?: null, 'email' => trim($this->supplierEmail) ?: null,
            'lead_time_days' => $this->supplierLeadTime === '' ? null : (int) $this->supplierLeadTime, 'is_active' => $this->supplierActive,
        ])->save();
        $this->showSupplierForm = false;
        session()->flash('success', "Supplier {$supplier->name} saved.");
    }

    // ── Render ────────────────────────────────────────────────────────────────

    /** Plain-text order for WhatsApp to the supplier. */
    public function supplierMessage(OpticalPurchaseOrder $order): string
    {
        $lines = $order->supplierLines()->map(fn ($row) => '- '.$row['quantity'].' × '.$row['description'])->implode("\n");
        return "Purchase order {$order->po_number}\n{$lines}".($order->expected_date ? "\nNeeded by ".$order->expected_date->format('d M Y') : '')."\nThank you.";
    }

    private function productMatches(string $term)
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) return collect();
        return OpticalProduct::with('stocks')->where('is_active', true)
            ->where(fn ($q) => $q->where('sku', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"))
            ->orderBy('name')->limit(10)->get();
    }

    public function render()
    {
        $orders = OpticalPurchaseOrder::with(['supplier', 'lines'])
            ->when($this->statusFilter === 'open', fn ($q) => $q->whereIn('status', ['draft', 'ordered', 'partially_received']))
            ->when(array_key_exists($this->statusFilter, OpticalPurchaseOrder::STATUSES), fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id')->paginate(15, ['*'], 'ordersPage');
        $viewOrder = $this->viewOrderId ? OpticalPurchaseOrder::with(['supplier', 'lines.product', 'lines.lensOrder', 'creator'])->find($this->viewOrderId) : null;

        return view('livewire.optical.optical-purchasing-component', [
            'orders' => $orders,
            'viewOrder' => $viewOrder,
            'supplierWhatsApp' => $viewOrder ? WhatsAppLink::to($viewOrder->supplier?->phone, $this->supplierMessage($viewOrder)) : null,
            'suppliers' => Supplier::orderBy('name')->get(),
            'productMatches' => $this->showOrderForm ? $this->productMatches($this->productSearch) : collect(),
            'returnMatches' => $this->showReturnForm ? $this->productMatches($this->returnSearch) : collect(),
            'backlog' => $this->tab === 'special' ? app(OpticalPurchasingService::class)->specialOrderBacklog() : collect(),
            'returns' => $this->tab === 'returns' ? OpticalSupplierReturn::with(['supplier', 'lines.product', 'purchaseOrder'])->latest('id')->paginate(15, ['*'], 'returnsPage') : collect(),
            'returnOrders' => $this->returnSupplierId ? OpticalPurchaseOrder::where('supplier_id', $this->returnSupplierId)->whereIn('status', ['partially_received', 'received'])->latest('id')->limit(20)->get() : collect(),
            'openCount' => OpticalPurchaseOrder::whereIn('status', ['ordered', 'partially_received'])->count(),
            'backlogCount' => app(OpticalPurchasingService::class)->specialOrderBacklog()->count(),
            'pendingCredits' => OpticalSupplierReturn::where('credit_status', 'pending')->sum('credit_expected'),
        ])->layout('layouts.optical');
    }
}
