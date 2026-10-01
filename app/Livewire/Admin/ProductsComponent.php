<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Category;
use App\Models\LensOption;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class ProductsComponent extends Component
{
    use WithPagination, WithFileUploads;

    protected $paginationTheme = 'bootstrap';

    // Tab Management
    public $activeTab = 'all';
    
    // Search
    public $searchTerm = null;
    public $searchByCategory = false;
    public $selectedCategoryFilter = null;
    public $selectedProductIds = [];
    public $selectAllPage = false;
    
    // Form State
    public $state = [];
    public ?Product $editingProduct = null;
    public $showForm = false;
    public $showLensCatalog = false;
    public $lensState = ['family' => '', 'display_name' => ''];
    public ?LensOption $editingLensOption = null;

    // CSV Import
    public $showImportPanel = false;
    public $importFile = null;
    public $importResults = null;
    
    // Query Strings for tab persistence
    protected $queryString = ['activeTab'];

    protected $listeners = [
        'confirmProductDelete' => 'confirmProductDelete'
    ];

    public function mount()
    {
        $this->resetForm();
    }

    public function updatedActiveTab()
    {
        $this->resetPage();
        $this->searchTerm = null;
        $this->searchByCategory = false;
        $this->selectedCategoryFilter = null;
        $this->clearSelection();
    }

    public function updatedSearchTerm()
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedSearchByCategory()
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedSelectedCategoryFilter()
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingPage()
    {
        $this->clearSelection();
    }

    public function updatedSelectAllPage($value)
    {
        if (!$value) {
            $this->selectedProductIds = [];
            return;
        }

        $this->selectedProductIds = $this->currentPageProductIds();
    }

    public function updatedSelectedProductIds()
    {
        $pageIds = $this->currentPageProductIds();
        $selected = array_map('intval', $this->selectedProductIds);
        $this->selectAllPage = !empty($pageIds) && empty(array_diff($pageIds, $selected));
    }

    public function clearSelection()
    {
        $this->selectedProductIds = [];
        $this->selectAllPage = false;
    }

    public function showAddForm()
    {
        $this->showForm = true;
        $this->resetForm();
        $this->resetValidation();
    }

    public function cancelForm()
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    public function toggleLensCatalog()
    {
        $this->showLensCatalog = !$this->showLensCatalog;
        $this->cancelLensOption();
    }

    public function saveLensOption()
    {
        $validated = Validator::make($this->lensState, [
            'family' => ['required', Rule::in(LensOption::FAMILIES)],
            'display_name' => [
                'required', 'string', 'max:150',
                Rule::unique('lens_options', 'display_name')
                    ->where(fn ($query) => $query
                        ->where('clinic_id', LensOption::clinicIdForWrite())
                        ->where('family', $this->lensState['family'] ?? ''))
                    ->ignore($this->editingLensOption?->id),
            ],
        ])->validate();

        if ($this->editingLensOption) {
            $this->editingLensOption->update($validated);
            $message = 'Lens option updated successfully.';
        } else {
            LensOption::create($validated);
            $message = 'Lens option added successfully.';
        }

        $this->cancelLensOption();
        $this->dispatch('notify', ...['type' => 'success', 'message' => $message]);
    }

    public function editLensOption($id)
    {
        $this->editingLensOption = LensOption::findOrFail($id);
        $this->lensState = $this->editingLensOption->only(['family', 'display_name']);
        $this->resetValidation(['family', 'display_name']);
    }

    public function cancelLensOption()
    {
        $this->editingLensOption = null;
        $this->lensState = ['family' => '', 'display_name' => ''];
        $this->resetValidation(['family', 'display_name']);
    }

    public function deleteLensOption($id)
    {
        LensOption::findOrFail($id)->delete();
        $this->cancelLensOption();
        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Lens option removed. Existing refraction records are unchanged.',
        ]);
    }

    public function resetForm()
    {
        $this->state = [
            'name'             => '',
            'batch_number'     => '',
            'category_id'      => null,
            'optical_category_id' => null,
            'expiry_date'      => '',
            'manufacture_date' => '',
            'quantity'         => '',
            'made_to_order'    => false,
            'cost_price'       => '',
            'selling_price'    => '',
            'profit_margin'    => '',
        ];
        $this->editingProduct = null;
    }

    /** Quantity is only asked for products kept in stock; made-to-order products are never counted. */
    private function quantityRule(int $min): string
    {
        return ! empty($this->state['made_to_order']) ? 'nullable' : "required|integer|min:{$min}|max:10000";
    }

    /**
     * Stock is no longer counted once a product is made to order: clear the quantities
     * typed in before, keeping the old numbers in the audit trail.
     */
    private function clearCountedStock(Product $product): void
    {
        $branchStock = $product->branchInventory()->withoutGlobalScopes()->where('quantity', '!=', 0)->pluck('quantity', 'branch_id')->all();
        $old = ['quantity' => (int) $product->quantity, 'branch_quantities' => $branchStock];

        $product->branchInventory()->withoutGlobalScopes()->update(['quantity' => 0]);
        $product->inventoryLots()->withoutGlobalScopes()->update(['quantity' => 0]);
        $product->forceFill(['quantity' => 0, 'made_to_order' => true])->save();

        AuditTrail::record('product.made_to_order', "{$product->name} set to made to order; stock of {$old['quantity']} cleared", $product,
            $old, ['quantity' => 0, 'made_to_order' => true], null, true);
    }

    public function updatedStateCostPrice()
    {
        $this->recalculateProfitMargin();
    }

    public function updatedStateCategoryId(): void
    {
        if (! empty($this->state['category_id'])) $this->state['optical_category_id'] = null;
    }

    public function updatedStateOpticalCategoryId(): void
    {
        $this->state['optical_category_id'] = null;
    }


    public function updatedStateSellingPrice()
    {
        $this->recalculateProfitMargin();
    }

    public function updatedStateProfitMargin()
    {
        $this->recalculateSellingPrice();
    }

    private function recalculateProfitMargin()
    {
        $cost = (float) ($this->state['cost_price'] ?? 0);
        $sell = (float) ($this->state['selling_price'] ?? 0);
        $this->state['profit_margin'] = $cost > 0
            ? round((($sell - $cost) / $cost) * 100, 2)
            : '';
    }

    private function recalculateSellingPrice()
    {
        $cost   = (float) ($this->state['cost_price'] ?? 0);
        $margin = (float) ($this->state['profit_margin'] ?? 0);
        if ($cost > 0) {
            $this->state['selling_price'] = round($cost * (1 + $margin / 100), 2);
        }
    }

    public function createProduct()
    {
        $clinicId = Product::clinicIdForWrite();
        $validatedData = Validator::make($this->state, [
            'name' => ['required', 'string', Rule::unique('products', 'name')->where('clinic_id', $clinicId)],
            'batch_number' => ['required', 'string', 'min:4', 'max:15', Rule::unique('products', 'batch_number')->where('clinic_id', $clinicId)],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('clinic_id', $clinicId)],
            'expiry_date' => 'required|date|date_format:Y-m-d|after:manufacture_date',
            'manufacture_date' => 'required|date|date_format:Y-m-d|before:expiry_date',
            'quantity' => $this->quantityRule(1),
            'made_to_order' => 'boolean',
            'cost_price' => 'required|numeric|min:0.01',
            'selling_price' => 'required|numeric|min:0.01|gte:cost_price',
        ])->validate();

        $validatedData['user_id'] = Auth::id();
        $validatedData['optical_category_id'] = null;
        $validatedData['made_to_order'] = (bool) ($validatedData['made_to_order'] ?? false);
        if ($validatedData['made_to_order']) $validatedData['quantity'] = 0;

        Product::create($validatedData);

        $this->cancelForm();
        
        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Product added successfully!'
        ]);
    }

    public function editProduct($id)
    {
        $this->resetValidation();
        $this->editingProduct = Product::findOrFail($id);
        $cost = (float) $this->editingProduct->cost_price;
        $sell = (float) $this->editingProduct->selling_price;

        $this->state = [
            'name'             => $this->editingProduct->name,
            'batch_number'     => $this->editingProduct->batch_number,
            'category_id'      => $this->editingProduct->category_id,
            'optical_category_id' => null,
            'manufacture_date' => $this->editingProduct->manufacture_date->format('Y-m-d'),
            'expiry_date'      => $this->editingProduct->expiry_date->format('Y-m-d'),
            'quantity'         => $this->editingProduct->quantity,
            'made_to_order'    => (bool) $this->editingProduct->made_to_order,
            'cost_price'       => $cost,
            'selling_price'    => $sell,
            'profit_margin'    => $cost > 0 ? round((($sell - $cost) / $cost) * 100, 2) : '',
        ];
        $this->showForm = true;
    }

    public function updateProduct()
    {
        if (!$this->editingProduct) {
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'No product selected for update.'
            ]);
            return;
        }

        $clinicId = Product::clinicIdForWrite();
        $validatedData = Validator::make($this->state, [
            'name' => ['required', 'string', Rule::unique('products', 'name')->where('clinic_id', $clinicId)->ignore($this->editingProduct->id)],
            'batch_number' => ['required', 'string', 'min:4', 'max:15', Rule::unique('products', 'batch_number')->where('clinic_id', $clinicId)->ignore($this->editingProduct->id)],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('clinic_id', $clinicId)],
            'expiry_date' => 'required|date|date_format:Y-m-d|after:manufacture_date',
            'manufacture_date' => 'required|date|date_format:Y-m-d|before:expiry_date',
            'quantity' => $this->quantityRule(0),
            'made_to_order' => 'boolean',
            'cost_price' => 'required|numeric|min:0.01',
            'selling_price' => 'required|numeric|min:0.01|gte:cost_price',
        ])->validate();

        $validatedData['optical_category_id'] = null;
        $madeToOrder = (bool) ($validatedData['made_to_order'] ?? false);
        unset($validatedData['made_to_order']);

        DB::transaction(function () use ($validatedData, $madeToOrder) {
            if ($madeToOrder) {
                // Stock is cleared (and recorded) once, when the product becomes made to order.
                unset($validatedData['quantity']);
                if (! $this->editingProduct->made_to_order) $this->clearCountedStock($this->editingProduct);
            } else {
                $wasMadeToOrder = (bool) $this->editingProduct->made_to_order;
                $validatedData['made_to_order'] = false;
                // Back to counted stock: the quantity typed now is this branch's starting stock.
                if ($wasMadeToOrder && ($branchId = app(\App\Support\Tenancy\TenantContext::class)->branchId())) {
                    $this->editingProduct->branchInventory()->withoutGlobalScopes()->where('branch_id', $branchId)
                        ->update(['quantity' => (int) $validatedData['quantity']]);
                }
            }
            $this->editingProduct->update($validatedData);
        });

        $this->cancelForm();
        
        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Product updated successfully!'
        ]);
    }

    public function confirmDelete($id)
    {
        $this->dispatch('show-delete-confirmation', ...[
            'id' => $id,
            'method' => 'confirmProductDelete'
        ]);
    }

    public function confirmProductDelete($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();
        $this->clearSelection();

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Product deleted successfully!'
        ]);
    }

    public function deleteSelected()
    {
        $ids = collect($this->selectedProductIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'Select at least one product first.'
            ]);
            return;
        }

        $deleted = Product::whereIn('id', $ids)->delete();
        $this->clearSelection();

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => $deleted . ' product(s) deleted successfully.'
        ]);
    }

    public function exportCsv()
    {
        $products = $this->getFilteredQuery()->get();
        
        $filename = 'products_' . $this->activeTab . '_' . now()->format('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($products) {
            $file = fopen('php://output', 'w');
            
            // Add headers
            fputcsv($file, [
                'ID', 'Name', 'Category', 'Batch Number', 'Quantity', 
                'Cost Price', 'Selling Price', 'Manufacture Date', 
                'Expiry Date', 'Days to Expiry', 'Status'
            ]);

            // Add data
            foreach ($products as $product) {
                $expiryDate = $product->expiry_date->startOfDay();
                $today = Carbon::today();
                $isExpired = $expiryDate->lt($today);
                $daysToExpiry = $isExpired ? 0 : $today->diffInDays($expiryDate);
                $status = $this->getProductStatus($product);
                
                fputcsv($file, [
                    $product->id,
                    $product->name,
                    $product->category->name ?? 'N/A',
                    $product->batch_number,
                    $product->quantity,
                    $product->cost_price,
                    $product->selling_price,
                    $product->manufacture_date,
                    $product->expiry_date,
                    $isExpired ? 'Expired' : $daysToExpiry,
                    $status
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importCsv()
    {
        $this->validate([
            'importFile' => 'required|file|max:4096|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
        ], [
            'importFile.required' => 'Please choose a CSV file.',
            'importFile.mimetypes' => 'The file must be a valid CSV file.',
            'importFile.max' => 'CSV file must be under 4 MB.',
        ]);

        $handle = fopen($this->importFile->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $data = $this->mapImportRow($header, $row);

            $name = trim((string) ($data['name'] ?? ''));
            $categoryValue = trim((string) ($data['category'] ?? $data['category_id'] ?? ''));
            $batchNumber = trim((string) ($data['batch_number'] ?? $data['batch'] ?? ''));

            if ($name === '' && $categoryValue === '' && $batchNumber === '') {
                $skipped++;
                continue;
            }

            $category = $this->resolveImportCategory($categoryValue);
            if (!$category) {
                $errors[] = "Row {$rowNum}: category \"{$categoryValue}\" was not found.";
                $skipped++;
                continue;
            }

            $payload = [
                'name' => $name,
                'category_id' => $category->id,
                'batch_number' => $batchNumber,
                'quantity' => $data['quantity'] ?? null,
                'cost_price' => $data['cost_price'] ?? null,
                'selling_price' => $data['selling_price'] ?? null,
                'manufacture_date' => $this->normalizeImportDate($data['manufacture_date'] ?? $data['manufactured_at'] ?? null),
                'expiry_date' => $this->normalizeImportDate($data['expiry_date'] ?? $data['expires_at'] ?? null),
                'user_id' => Auth::id(),
            ];

            $clinicId = Product::clinicIdForWrite();
            $validator = Validator::make($payload, [
                'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')->where('clinic_id', $clinicId)],
                'batch_number' => ['required', 'string', 'min:4', 'max:15', Rule::unique('products', 'batch_number')->where('clinic_id', $clinicId)],
                'category_id' => ['required', Rule::exists('categories', 'id')->where('clinic_id', $clinicId)],
                'expiry_date' => 'required|date|date_format:Y-m-d|after:manufacture_date',
                'manufacture_date' => 'required|date|date_format:Y-m-d|before:expiry_date',
                'quantity' => 'required|integer|min:0|max:10000',
                'cost_price' => 'required|numeric|min:0.01',
                'selling_price' => 'required|numeric|min:0.01|gte:cost_price',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: " . $validator->errors()->first();
                $skipped++;
                continue;
            }

            Product::create(array_merge($validator->validated(), ['user_id' => Auth::id()]));
            $imported++;
        }

        fclose($handle);

        $this->importFile = null;
        $this->importResults = compact('imported', 'skipped', 'errors');
        $this->showImportPanel = true;
        $this->clearSelection();
        $this->resetPage();
    }

    public function clearImport()
    {
        $this->importFile = null;
        $this->importResults = null;
        $this->showImportPanel = false;
        $this->resetValidation('importFile');
    }

    public function downloadTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="products_import_template.csv"',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'name',
                'category',
                'batch_number',
                'quantity',
                'cost_price',
                'selling_price',
                'manufacture_date',
                'expiry_date',
            ]);
            fputcsv($file, [
                'Sample Eye Drop',
                'Drugs',
                'BTH001',
                '20',
                '25.00',
                '40.00',
                now()->format('Y-m-d'),
                now()->addYear()->format('Y-m-d'),
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function getProductStatus($product)
    {
        if ($product->made_to_order) {
            return 'Made to order';
        }

        $expiryDate = $product->expiry_date->startOfDay();
        $today = Carbon::today();

        if ($expiryDate->lt($today)) {
            return 'Expired';
        }

        $daysToExpiry = $today->diffInDays($expiryDate);

        if ($daysToExpiry <= 120) {
            return 'Expiring Soon';
        }

        if ($product->quantity < 10) {
            return 'Low Stock';
        }

        return 'Active';
    }

    private function getFilteredQuery()
    {
        $search = $this->searchTerm;
        $query = Product::query()->with('category');

        // Apply search
        if ($search) {
            if ($this->searchByCategory && $this->selectedCategoryFilter) {
                $query->where('category_id', $this->selectedCategoryFilter)
                      ->where(function($q) use ($search) {
                          $like = "%{$search}%";
                          $q->where('name', 'like', $like)
                            ->orWhere('batch_number', 'like', $like);
                      });
            } else {
                $like = "%{$search}%";
                $query->where(function($q) use ($like) {
                    $q->where('name', 'like', $like)
                      ->orWhere('batch_number', 'like', $like)
                      ->orWhereHas('category', function ($query) use ($like) {
                          $query->where('name', 'like', $like);
                      });
                });
            }
        } elseif ($this->searchByCategory && $this->selectedCategoryFilter) {
            $query->where('category_id', $this->selectedCategoryFilter);
        }

        // Apply tab filters
        switch ($this->activeTab) {
            // Made-to-order products are never on the shelf, so they have no stock or expiry alerts.
            case 'low-stock':
                $query->stocked()->where('quantity', '<', 10);
                break;

            case 'expiring':
                $today = Carbon::today();
                $fourMonthsFromNow = Carbon::today()->addMonths(4);
                // Products that expire from today up to 4 months from now
                $query->stocked()->whereDateIndexed('expiry_date', '>=', $today)
                      ->whereDateIndexed('expiry_date', '<=', $fourMonthsFromNow);
                break;

            case 'expired':
                // Products that expired before today
                $query->stocked()->whereDateIndexed('expiry_date', '<', Carbon::today());
                break;
        }

        return $query->latest();
    }

    private function currentPageProductIds(): array
    {
        return $this->getFilteredQuery()
            ->paginate(10)
            ->getCollection()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    private function mapImportRow($header, array $row): array
    {
        if (!is_array($header) || empty($header)) {
            return [];
        }

        $mapped = [];
        foreach ($header as $index => $column) {
            $key = strtolower(trim((string) $column));
            $key = str_replace([' ', '-', '.'], '_', $key);
            $mapped[$key] = $row[$index] ?? null;
        }

        return $mapped;
    }

    private function resolveImportCategory($value): ?Category
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Category::find((int) $value);
        }

        return Category::whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->first();
    }

    private function normalizeImportDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function render()
    {
        $products = $this->getFilteredQuery()->paginate(10);
        $categories = Category::orderBy('name')->get();
        $lensOptions = LensOption::orderByRaw("CASE family WHEN 'Single Vision' THEN 1 WHEN 'Bifocal' THEN 2 WHEN 'Progressive' THEN 3 ELSE 4 END")
            ->orderBy('display_name')
            ->get();
        
        $today = Carbon::today();
        $fourMonths = Carbon::today()->addMonths(4);
        $allProducts = Product::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN made_to_order = 0 AND quantity < 10 THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN made_to_order = 0 AND DATE(expiry_date) >= ? AND DATE(expiry_date) <= ? THEN 1 ELSE 0 END) as expiring,
            SUM(CASE WHEN made_to_order = 0 AND DATE(expiry_date) < ? THEN 1 ELSE 0 END) as expired
        ", [$today, $fourMonths, $today])->first();

        $stats = [
            'total'     => (int) $allProducts->total,
            'low_stock' => (int) $allProducts->low_stock,
            'expiring'  => (int) $allProducts->expiring,
            'expired'   => (int) $allProducts->expired,
        ];

        return view('livewire.admin.products-component', compact('products', 'categories', 'stats', 'lensOptions'))
            ->layout('layouts.admin.admin-layout');
    }
}
