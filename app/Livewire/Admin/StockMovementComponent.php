<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Validation\Rule;

class StockMovementComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    public $productId = '';
    public $supplier = '';
    public $batchNumber = '';
    public $quantity = 1;
    public $costPrice = '';
    public $manufactureDate = '';
    public $expiryDate = '';
    public $notes = '';

    public $search = '';
    public $productSearch = '';
    public $fromDate = '';
    public $toDate = '';

    protected function rules(): array
    {
        return [
        'productId' => ['required', Rule::exists('products', 'id')->where(
            'clinic_id', Product::clinicIdForWrite()
        )],
        'supplier' => 'nullable|string|max:150',
        'batchNumber' => 'nullable|string|max:100',
        'quantity' => 'required|integer|min:1|max:1000000',
        'costPrice' => 'nullable|numeric|min:0|max:999999999',
        'manufactureDate' => 'nullable|date',
        'expiryDate' => 'nullable|date',
        'notes' => 'nullable|string|max:1000',
        ];
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFromDate()
    {
        $this->resetPage();
    }

    public function updatingToDate()
    {
        $this->resetPage();
    }

    public function updatedProductId($value)
    {
        $product = Product::find($value);

        if (!$product) {
            return;
        }

        $this->batchNumber = $this->batchNumber ?: $product->batch_number;
        $this->costPrice = $this->costPrice !== '' ? $this->costPrice : $product->cost_price;
        $this->manufactureDate = $this->manufactureDate ?: optional($product->manufacture_date)->format('Y-m-d');
        $this->expiryDate = $this->expiryDate ?: optional($product->expiry_date)->format('Y-m-d');
    }

    public function receiveStock()
    {
        $this->validate();

        if ($this->manufactureDate && $this->expiryDate && $this->expiryDate < $this->manufactureDate) {
            $this->addError('expiryDate', 'The expiry date must be after or equal to the manufacture date.');
            return;
        }

        if (Product::whereKey($this->productId)->value('made_to_order')) {
            $this->addError('productId', 'This product is made to order, so its stock is not counted. Untick "Made to order" on the product to keep it in stock.');
            return;
        }

        DB::transaction(function () {
            $product = Product::whereKey($this->productId)->lockForUpdate()->firstOrFail();
            $inventory = app(\App\Services\Inventory\BranchInventoryService::class);
            $quantityBefore = $inventory->quantity($product);
            $quantityReceived = (int) $this->quantity;
            $quantityAfter = $quantityBefore + $quantityReceived;

            $movement = Stock::create([
                'product_id' => $product->id,
                'user_id' => Auth::id(),
                'reference_no' => $this->makeReferenceNo(),
                'movement_type' => 'received',
                'supplier' => $this->supplier ?: null,
                'batch_number' => $this->batchNumber ?: $product->batch_number,
                'quantity_before' => $quantityBefore,
                'quantity' => $quantityReceived,
                'quantity_after' => $quantityAfter,
                'cost_price' => $this->costPrice !== '' ? $this->costPrice : $product->cost_price,
                'manufacture_date' => $this->manufactureDate ?: $product->manufacture_date,
                'expiry_date' => $this->expiryDate ?: $product->expiry_date,
                'notes' => $this->notes ?: null,
            ]);

            $oldValues = [
                'quantity' => $quantityBefore,
                'batch_number' => $product->batch_number,
                'cost_price' => $product->cost_price,
                'manufacture_date' => optional($product->manufacture_date)->format('Y-m-d'),
                'expiry_date' => optional($product->expiry_date)->format('Y-m-d'),
            ];

            $product->batch_number = $this->batchNumber ?: $product->batch_number;
            $product->cost_price = $this->costPrice !== '' ? $this->costPrice : $product->cost_price;
            $product->manufacture_date = $this->manufactureDate ?: $product->manufacture_date;
            $product->expiry_date = $this->expiryDate ?: $product->expiry_date;
            $product->save();
            $inventory->increase($product, $quantityReceived, [
                'batch_number' => $this->batchNumber ?: $product->batch_number,
                'manufacture_date' => $this->manufactureDate ?: $product->manufacture_date,
                'expiry_date' => $this->expiryDate ?: $product->expiry_date,
                'unit_cost' => $this->costPrice !== '' ? $this->costPrice : $product->cost_price,
            ]);

            AuditTrail::record(
                'stock.received',
                'Received ' . $quantityReceived . ' unit(s) of ' . $product->name . ' - ' . $movement->reference_no,
                $movement,
                $oldValues,
                [
                    'quantity' => $quantityAfter,
                    'received_quantity' => $quantityReceived,
                    'supplier' => $movement->supplier,
                    'batch_number' => $movement->batch_number,
                    'cost_price' => $movement->cost_price,
                    'manufacture_date' => optional($movement->manufacture_date)->format('Y-m-d'),
                    'expiry_date' => optional($movement->expiry_date)->format('Y-m-d'),
                    'reference_no' => $movement->reference_no,
                ]
            );
        });

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Stock received and product quantity updated.']);
        $this->resetReceiveForm();
        $this->resetPage();
    }

    public function resetReceiveForm()
    {
        $this->productId = '';
        $this->supplier = '';
        $this->batchNumber = '';
        $this->quantity = 1;
        $this->costPrice = '';
        $this->manufactureDate = '';
        $this->expiryDate = '';
        $this->notes = '';
        $this->resetValidation();
    }

    private function makeReferenceNo(): string
    {
        do {
            $reference = 'GRN-' . now()->format('Ymd-His') . '-' . random_int(100, 999);
        } while (Stock::where('reference_no', $reference)->exists());

        return $reference;
    }

    private function movementsQuery()
    {
        return Stock::with(['product.category', 'user'])
            ->when($this->search, function ($query) {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('reference_no', 'like', $search)
                        ->orWhere('supplier', 'like', $search)
                        ->orWhere('batch_number', 'like', $search)
                        ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $search));
                });
            })
            ->when($this->fromDate, fn ($query) => $query->where('created_at', '>=', $this->fromDate . ' 00:00:00'))
            ->when($this->toDate, fn ($query) => $query->where('created_at', '<=', $this->toDate . ' 23:59:59'))
            ->latest();
    }

    public function render()
    {
        $products = Product::with('category')
            ->when($this->productSearch, function ($query) {
                $search = '%' . $this->productSearch . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', $search)
                        ->orWhere('batch_number', 'like', $search)
                        ->orWhereHas('category', fn ($cq) => $cq->where('name', 'like', $search));
                });
            })
            ->orderBy('name')
            ->limit(80)
            ->get();

        return view('livewire.admin.stock-movement-component', [
            'products' => $products,
            'movements' => $this->movementsQuery()->paginate(15),
            'totalReceivedToday' => Stock::whereDateIndexed('created_at', today())->sum('quantity'),
            'receiptsToday' => Stock::whereDateIndexed('created_at', today())->count(),
            'lastMovement' => Stock::latest()->first(),
        ])->layout('layouts.admin.admin-layout');
    }
}
