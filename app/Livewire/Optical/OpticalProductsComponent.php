<?php

namespace App\Livewire\Optical;

use App\Models\OpticalCategory;
use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Services\ClinicAccessService;
use App\Services\OpticalProductInventoryService;
use App\Services\OpticalProductCsvService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

class OpticalProductsComponent extends Component
{
    use WithPagination, WithFileUploads;

    public string $search = '';
    public string $categoryFilter = '';
    public string $stockFilter = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $sku = '';
    public string $categoryId = '';
    public string $brand = '';
    public string $specifications = '';
    public string $costPrice = '';
    public string $sellingPrice = '';
    public string $quantity = '0';
    public string $reorderLevel = '5';
    public bool $active = true;
    public bool $showImport = false;
    public $importFile;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedCategoryFilter(): void { $this->resetPage(); }
    public function updatedStockFilter(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search', 'categoryFilter', 'stockFilter']);
        $this->resetPage();
    }

    private function assertManager(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        app(ClinicAccessService::class)->assertWritable('optical');
    }

    private function assertManagerRole(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
    }

    public function downloadTemplate()
    {
        $this->assertManagerRole();
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'wb');
            app(OpticalProductCsvService::class)->writeCsv($handle, true);
            fclose($handle);
        }, 'optical_products_template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportCsv()
    {
        $this->assertManagerRole();
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'wb');
            app(OpticalProductCsvService::class)->writeCsv($handle);
            fclose($handle);
        }, 'optical_products_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function openImport(): void
    {
        $this->assertManager();
        $this->reset('importFile');
        $this->resetValidation('importFile');
        $this->showImport = true;
    }

    public function importCsv(): void
    {
        $this->assertManager();
        $this->validate(['importFile' => 'required|file|mimes:csv,txt|max:4096']);
        $count = app(OpticalProductCsvService::class)->import($this->importFile->getRealPath());
        $this->reset('importFile');
        $this->showImport = false;
        $this->resetPage();
        session()->flash('success', "{$count} optical product(s) imported or updated for this branch.");
    }

    public function mount(): void
    {
        // Deep link from the catalogue's item panel: /optical/products?edit=ID
        if (ctype_digit((string) request()->query('edit')) && auth()->user()?->hasAnyRole(['Manager', 'Super Admin'])
            && OpticalProduct::whereKey((int) request()->query('edit'))->exists()) {
            $this->edit((int) request()->query('edit'));
        }
    }

    public function add(): void
    {
        $this->assertManager();
        $this->reset(['editingId', 'name', 'sku', 'categoryId', 'brand', 'specifications', 'costPrice', 'sellingPrice']);
        $this->quantity = '0';
        $this->reorderLevel = '5';
        $this->active = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->assertManager();
        $product = OpticalProduct::findOrFail($id);
        $stock = OpticalProductStock::where('optical_product_id', $id)->first();
        $this->editingId = $id;
        $this->name = $product->name;
        $this->sku = $product->sku;
        $this->categoryId = (string) $product->optical_category_id;
        $this->brand = $product->brand ?? '';
        $this->specifications = $product->specifications ?? '';
        $this->costPrice = (string) $product->cost_price;
        $this->sellingPrice = (string) $product->selling_price;
        $this->quantity = (string) ($stock?->quantity ?? 0);
        $this->reorderLevel = (string) ($stock?->reorder_level ?? 5);
        $this->active = $product->is_active;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->assertManager();
        $this->name = trim($this->name);
        $this->sku = strtoupper(trim($this->sku));
        $clinicId = OpticalProduct::clinicIdForWrite();
        $this->validate([
            'name' => 'required|string|max:180',
            'sku' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', 'max:80', Rule::unique('optical_products', 'sku')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'categoryId' => ['required', Rule::exists('optical_categories', 'id')->where('clinic_id', $clinicId)->where('is_active', true)->whereNull('deleted_at')],
            'brand' => 'nullable|string|max:120',
            'specifications' => 'nullable|string|max:3000',
            'costPrice' => 'required|numeric|min:0|max:9999999999',
            'sellingPrice' => 'required|numeric|min:0|max:9999999999|gte:costPrice',
            'quantity' => 'required|integer|min:0|max:100000000',
            'reorderLevel' => 'required|integer|min:0|max:100000000',
            'active' => 'boolean',
        ]);
        DB::transaction(function () {
            $product = $this->editingId ? OpticalProduct::findOrFail($this->editingId) : new OpticalProduct();
            $product->fill([
                'name' => $this->name, 'sku' => $this->sku,
                'optical_category_id' => (int) $this->categoryId,
                'brand' => trim($this->brand) ?: null,
                'specifications' => trim($this->specifications) ?: null,
                'cost_price' => round((float) $this->costPrice, 2),
                'selling_price' => round((float) $this->sellingPrice, 2),
                'is_active' => $this->active,
            ]);
            $product->save();
            app(OpticalProductInventoryService::class)->setBalance(
                $product, (int) $this->quantity, (int) $this->reorderLevel,
                $this->editingId ? 'Product stock correction' : 'Initial optical stock'
            );
        });
        $this->showForm = false;
        session()->flash('success', 'Optical product saved.');
    }

    public function delete(int $id): void
    {
        $this->assertManager();
        $product = OpticalProduct::findOrFail($id);
        if ($product->stocks()->withoutGlobalScope('branch')->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['product' => 'Set stock to zero in every branch before archiving this product.']);
        }
        $product->delete();
        session()->flash('success', 'Optical product archived.');
    }

    public function render()
    {
        $branchId = app(TenantContext::class)->branchId();
        $stock = fn ($query) => $query->where('branch_id', $branchId);
        $base = OpticalProduct::query()->with(['category', 'stocks' => $stock]);
        $summary = (clone $base)->get();
        $term = trim($this->search);
        $products = (clone $base)
            ->when($term !== '', fn ($q) => $q->where(fn ($search) => $search
                ->where('sku', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('brand', 'like', '%'.$term.'%')
                ->orWhere('specifications', 'like', '%'.$term.'%')
                ->orWhereHas('category', fn ($category) => $category->where('name', 'like', '%'.$term.'%'))))
            ->when($this->categoryFilter !== '', fn ($q) => $q->where('optical_category_id', $this->categoryFilter))
            ->when($this->stockFilter === 'low', fn ($q) => $q->whereHas('stocks', fn ($s) => $s->where('branch_id', $branchId)->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'reorder_level')))
            ->when($this->stockFilter === 'out', fn ($q) => $q->whereDoesntHave('stocks', fn ($s) => $s->where('branch_id', $branchId)->where('quantity', '>', 0)))
            ->when($this->stockFilter === 'in', fn ($q) => $q->whereHas('stocks', fn ($s) => $s->where('branch_id', $branchId)->where('quantity', '>', 0)))
            ->orderBy('name')->paginate(15);

        return view('livewire.optical.optical-products-component', [
            'products' => $products,
            'categories' => OpticalCategory::orderBy('name')->get(),
            'activeCount' => $summary->where('is_active', true)->count(),
            'inventoryValue' => $summary->sum(fn ($p) => ($p->stocks->first()?->quantity ?? 0) * (float) $p->selling_price),
            'lowStockCount' => $summary->filter(fn ($p) => $p->is_active && ($p->stocks->first()?->quantity ?? 0) <= ($p->stocks->first()?->reorder_level ?? 5))->count(),
            'averageMarkup' => $summary->where('is_active', true)->filter(fn ($p) => (float) $p->cost_price > 0)->avg(fn ($p) => ((float) $p->selling_price - (float) $p->cost_price) / (float) $p->cost_price * 100) ?? 0,
        ])->layout('layouts.optical');
    }
}
