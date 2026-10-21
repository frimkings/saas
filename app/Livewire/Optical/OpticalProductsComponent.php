<?php

namespace App\Livewire\Optical;

use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Services\ClinicAccessService;
use App\Services\OpticalProductInventoryService;
use App\Services\OpticalProductCsvService;
use App\Support\Optical\LensOptions;
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
    /** The product's category: frame, lens or accessory (OpticalProduct::TYPES). */
    public string $type = '';
    /** A frame is described by brand (Settings → Lens options → Frame brands), model, colour and size. */
    public string $frameBrand = '';
    public string $frameModel = '';
    public string $frameColour = '';
    public string $frameSize = '';
    public string $newFrameBrand = '';
    /** A lens is classified by lens type, form, treatment and manufacturer (Settings → Lens options). */
    public string $lensDesign = '';
    public string $lensForm = LensOptions::STANDARD_FORM;
    public string $lensTreatment = '';
    public string $lensManufacturer = '';
    /** A stock lens (with powers) keeps the classification it was received with. */
    public bool $stockLens = false;
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
        $this->validate(['importFile' => 'required|file|mimes:csv,txt|'.\App\Support\UploadLimits::rule()]);
        $count = app(OpticalProductCsvService::class)->import($this->importFile->getRealPath());
        \App\Models\AuditTrail::record('optical.products_imported', "Imported or updated {$count} optical product(s) from a CSV file", null, [], ['count' => $count]);
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
        $this->reset(['editingId', 'name', 'sku', 'type', 'frameBrand', 'frameModel', 'frameColour', 'frameSize', 'newFrameBrand', 'specifications', 'costPrice', 'sellingPrice', 'lensDesign', 'lensForm', 'lensTreatment', 'lensManufacturer', 'stockLens']);
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
        $this->type = (string) $product->type;
        $this->stockLens = $product->lens_specs !== null;
        $lens = $product->lens_specs ?? $product->details ?? [];
        // A frame saved before brands were a list keeps the brand it has, when the list has it.
        $this->frameBrand = (string) ($lens['brand'] ?? $product->brand ?? '');
        $this->frameModel = (string) ($lens['model'] ?? '');
        $this->frameColour = (string) ($lens['colour'] ?? '');
        $this->frameSize = (string) ($lens['size'] ?? '');
        $this->newFrameBrand = '';
        $this->lensDesign = (string) ($lens['design'] ?? '');
        $this->lensForm = (string) ($lens['form'] ?? LensOptions::STANDARD_FORM);
        $this->lensTreatment = (string) ($lens['coating'] ?? '');
        $this->lensManufacturer = (string) ($lens['range'] ?? '');
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
        // A choice since hidden in Lens options stays valid on the product that already has it.
        $kept = $this->editingId ? (OpticalProduct::find($this->editingId)?->details ?? []) : [];
        $this->validate([
            'name' => 'required|string|max:180',
            'sku' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', 'max:80', Rule::unique('optical_products', 'sku')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'type' => ['required', Rule::in(array_keys(OpticalProduct::TYPES))],
            'specifications' => 'nullable|string|max:3000',
            'costPrice' => 'required|numeric|min:0|max:9999999999',
            'sellingPrice' => 'required|numeric|min:0|max:9999999999|gte:costPrice',
            'quantity' => 'required|integer|min:0|max:100000000',
            'reorderLevel' => 'required|integer|min:0|max:100000000',
            'active' => 'boolean',
        ] + ($this->type === 'lens' && ! $this->stockLens ? [
            'lensDesign' => ['required', Rule::in(array_keys(LensOptions::choices('design', $kept['design'] ?? null)))],
            'lensForm' => ['required', Rule::in(array_keys(LensOptions::choices('form', $kept['form'] ?? null, $this->lensDesign)))],
            'lensTreatment' => ['required', Rule::in(array_keys(LensOptions::choices('treatment', $kept['coating'] ?? null)))],
            'lensManufacturer' => ['nullable', Rule::in(array_keys(LensOptions::choices('manufacturer', $kept['range'] ?? null)))],
        ] : []) + ($this->type === 'frame' ? [
            'frameBrand' => ['required', Rule::in(array_keys(LensOptions::choices('frame_brand', $kept['brand'] ?? null)))],
            'frameModel' => 'nullable|string|max:80',
            'frameColour' => 'nullable|string|max:80',
            'frameSize' => 'nullable|string|max:30',
        ] : []), ['type.required' => 'Choose the category.', 'frameBrand.in' => 'Choose a brand from the list, or add it as a new brand.'],
            ['type' => 'category', 'lensDesign' => 'lens type', 'lensForm' => 'form', 'lensTreatment' => 'treatment', 'lensManufacturer' => 'manufacturer',
                'frameBrand' => 'brand', 'frameModel' => 'model', 'frameColour' => 'colour', 'frameSize' => 'size']);
        DB::transaction(function () {
            $product = $this->editingId ? OpticalProduct::findOrFail($this->editingId) : new OpticalProduct();
            // Stock lenses (with powers) are matched to prescriptions, so they stay lenses.
            if ($product->lens_specs !== null && $this->type !== 'lens') {
                throw ValidationException::withMessages(['type' => 'This is a stock lens, so it must stay in Lenses.']);
            }
            // Stock lenses keep the classification they were received with (their lens_specs).
            if ($product->lens_specs === null) {
                $product->fill(['details' => $this->details(), 'brand' => match ($this->type) {
                    'lens' => LensOptions::label('manufacturer', $this->lensManufacturer) ?: null,
                    'frame' => LensOptions::label('frame_brand', $this->frameBrand),
                    default => $product->brand,
                }]);
            }
            $product->fill([
                'name' => $this->name, 'sku' => $this->sku,
                'type' => $this->type,
                'specifications' => trim($this->specifications) ?: null,
                'cost_price' => round((float) $this->costPrice, 2),
                'selling_price' => round((float) $this->sellingPrice, 2),
                'is_active' => $this->active,
            ]);
            \App\Models\AuditTrail::recordSave($product, 'optical.product', 'optical product '.$product->name.' ('.$product->sku.')');
            app(OpticalProductInventoryService::class)->setBalance(
                $product, (int) $this->quantity, (int) $this->reorderLevel,
                $this->editingId ? 'Product stock correction' : 'Initial optical stock'
            );
        });
        $this->showForm = false;
        session()->flash('success', 'Optical product saved.');
    }

    /** What describes the product within its category: a lens's classification, a frame's details, nothing for accessories. */
    private function details(): ?array
    {
        $details = match ($this->type) {
            'lens' => ['design' => $this->lensDesign, 'form' => LensOptions::storedForm($this->lensForm),
                'coating' => $this->lensTreatment, 'range' => $this->lensManufacturer ?: null],
            'frame' => ['brand' => $this->frameBrand, 'model' => trim($this->frameModel) ?: null,
                'colour' => trim($this->frameColour) ?: null, 'size' => trim($this->frameSize) ?: null],
            default => [],
        };
        return array_filter($details, fn ($value) => $value !== null) ?: null;
    }

    /** A frame brand added while adding a frame; it joins the list in Lens options. */
    public function addFrameBrand(): void
    {
        $this->assertManager();
        $name = trim(preg_replace('/\s+/', ' ', $this->newFrameBrand));
        $this->resetErrorBag(['newFrameBrand', 'frameBrand']);
        if ($name === '' || mb_strlen($name) > 100) { $this->addError('newFrameBrand', 'Enter the brand name, up to 100 characters.'); return; }
        $this->frameBrand = LensOptions::frameBrand($name)->code;
        $this->newFrameBrand = '';
    }

    public function delete(int $id): void
    {
        $this->assertManager();
        $product = OpticalProduct::findOrFail($id);
        if ($product->stocks()->withoutGlobalScope('branch')->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['product' => 'Set stock to zero in every branch before archiving this product.']);
        }
        $product->delete();
        \App\Models\AuditTrail::record('optical.product_archived', 'Archived optical product '.$product->name.' ('.$product->sku.')', $product,
            $product->only(['name', 'sku', 'cost_price', 'selling_price']), []);
        session()->flash('success', 'Optical product archived.');
    }

    public function render()
    {
        $branchId = app(TenantContext::class)->branchId();
        $stock = fn ($query) => $query->where('branch_id', $branchId);
        $base = OpticalProduct::query()->with(['stocks' => $stock]);
        $summary = (clone $base)->get();
        $term = trim($this->search);
        $products = (clone $base)
            ->when($term !== '', fn ($q) => $q->where(fn ($search) => $search
                ->where('sku', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('brand', 'like', '%'.$term.'%')
                ->orWhere('specifications', 'like', '%'.$term.'%')))
            ->when($this->categoryFilter !== '', fn ($q) => $q->where('type', $this->categoryFilter))
            ->when($this->stockFilter === 'low', fn ($q) => $q->whereHas('stocks', fn ($s) => $s->where('branch_id', $branchId)->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'reorder_level')))
            ->when($this->stockFilter === 'out', fn ($q) => $q->whereDoesntHave('stocks', fn ($s) => $s->where('branch_id', $branchId)->where('quantity', '>', 0)))
            ->when($this->stockFilter === 'in', fn ($q) => $q->whereHas('stocks', fn ($s) => $s->where('branch_id', $branchId)->where('quantity', '>', 0)))
            ->orderBy('name')->paginate(15);

        return view('livewire.optical.optical-products-component', [
            'products' => $products,
            'activeCount' => $summary->where('is_active', true)->count(),
            'inventoryValue' => $summary->sum(fn ($p) => ($p->stocks->first()?->quantity ?? 0) * (float) $p->selling_price),
            'lowStockCount' => $summary->filter(fn ($p) => $p->is_active && ($p->stocks->first()?->quantity ?? 0) <= ($p->stocks->first()?->reorder_level ?? 5))->count(),
            'averageMarkup' => $summary->where('is_active', true)->filter(fn ($p) => (float) $p->cost_price > 0)->avg(fn ($p) => ((float) $p->selling_price - (float) $p->cost_price) / (float) $p->cost_price * 100) ?? 0,
        ])->layout('layouts.optical');
    }
}
