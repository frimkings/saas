<?php

namespace App\Livewire\Optical;

use App\Models\OpticalProduct;
use App\Models\OpticalCategory;
use App\Models\LensOption;
use App\Models\OpticalService;
use App\Services\ClinicAccessService;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;

class OpticalCatalogueComponent extends Component
{
    use WithPagination;

    public $activeTab = 'frames';
    public $searchTerm = '';
    public $matrixIndex = '1.56';
    public $matrixCoating = 'AR';
    public $matrixDesign = 'Single Vision';
    public $matrixRange = '';
    public $matrixDiameter = 0;
    public string $matrixEye = 'R';
    public bool $matrixStockedOnly = true;
    public bool $matrixFullPowers = false;
    public bool $showReorderSettings = false;
    public array $reorderPairs = [];
    public array $targetPairs = [];
    public string $stockFilter = '';
    public ?int $viewProductId = null;

    public function updated($property): void
    {
        if ($property === 'matrixEye' && ! in_array($this->matrixEye, ['R', 'L'], true)) $this->matrixEye = 'R';
        if (in_array($property, ['matrixRange', 'matrixDesign', 'matrixIndex', 'matrixCoating', 'matrixDiameter', 'matrixEye'], true)) {
            $this->showReorderSettings = false;
            $this->reorderPairs = $this->targetPairs = [];
            $this->resetValidation();
        }
    }

    private function replenishmentSpecs(): array
    {
        $specs = ['range' => trim((string) $this->matrixRange), 'design' => $this->matrixDesign,
            'index' => $this->matrixIndex, 'coating' => $this->matrixCoating, 'diameter' => (int) $this->matrixDiameter];
        if (\App\Support\LensDesign::isEyeSpecific($this->matrixDesign)) $specs['eye'] = $this->matrixEye;
        return $specs;
    }

    public function editReorderLevels(): void
    {
        $this->assertCanManageServices();
        $rows = app(\App\Services\OpticalLensReplenishmentService::class)->rows($this->replenishmentSpecs());
        $this->reorderPairs = $rows->pluck('reorder', 'id')->all();
        $this->targetPairs = $rows->pluck('target', 'id')->all();
        $this->showReorderSettings = true;
    }

    public function saveReorderLevels(): void
    {
        $this->assertCanManageServices();
        app(ClinicAccessService::class)->assertWritable('optical');
        $this->validate(['reorderPairs' => 'required|array', 'targetPairs' => 'required|array',
            'reorderPairs.*' => 'required|integer|min:0|max:49999', 'targetPairs.*' => 'required|integer|min:1|max:50000']);
        $rows = app(\App\Services\OpticalLensReplenishmentService::class)->rows($this->replenishmentSpecs())->keyBy('id');
        foreach ($this->reorderPairs as $id => $pairs) {
            abort_unless($rows->has($id), 422);
            if (! isset($this->targetPairs[$id]) || $this->targetPairs[$id] <= $pairs) {
                $this->addError('replenishment', 'Every target must be greater than its reorder threshold.'); return;
            }
        }
        \Illuminate\Support\Facades\DB::transaction(function () {
            foreach ($this->reorderPairs as $id => $pairs) \App\Models\OpticalProductStock::where('optical_product_id', $id)->update([
                'lens_reorder_pairs' => (int) $pairs, 'lens_target_pairs' => (int) $this->targetPairs[$id], 'reorder_level' => (int) $pairs * 2,
            ]);
        });
        $this->showReorderSettings = false;
        session()->flash('success', 'Reorder levels saved for this branch.');
    }

    public function downloadReplenishment()
    {
        $this->assertCanManageServices();
        $content = app(\App\Services\OpticalLensReplenishmentService::class)->workbook($this->replenishmentSpecs());
        $eye = \App\Support\LensDesign::isEyeSpecific($this->matrixDesign) ? ($this->matrixEye === 'R' ? ' RIGHT' : ' LEFT') : '';
        $name = Str::slug(($this->matrixDesign === 'Single Vision' ? 'SV' : $this->matrixDesign).$eye.' '.$this->matrixCoating.' '.$this->matrixDiameter.'mm LENS REORDER');
        return response()->streamDownload(fn () => print($content), $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Open Purchasing with a draft order holding this range's reorder quantities. */
    public function orderFromReorderList()
    {
        $this->assertCanManageServices();
        if (app(\App\Services\OpticalLensReplenishmentService::class)->rows($this->replenishmentSpecs())->where('pairs', '>', 0)->isEmpty()) {
            $this->addError('replenishment', 'No powers in this range are below their reorder level.');
            return null;
        }
        return redirect()->route('optical.purchasing', ['reorder' => base64_encode(json_encode($this->replenishmentSpecs()))]);
    }

    private function matrixProducts()
    {
        return OpticalProduct::with('stocks')->where('is_active', true)->whereNotNull('lens_specs')->whereHas('stocks')->get();
    }

    private function selectMatrixSpecification($product): void
    {
        if (! $product) return;
        $specs = $product->lens_specs;
        $this->matrixRange = $specs['range'];
        $this->matrixDesign = $specs['design'];
        $this->matrixIndex = $specs['index'];
        $this->matrixCoating = $specs['coating'];
        $this->matrixDiameter = $specs['diameter'];
        if (isset($specs['eye'])) $this->matrixEye = $specs['eye'];
    }

    public function updatedMatrixRange(): void
    {
        $this->selectMatrixSpecification($this->matrixProducts()->first(fn ($p) => data_get($p->lens_specs, 'range') === $this->matrixRange));
    }

    public function openIntake($sphere = null, $power = null)
    {
        $this->assertCanManageServices();
        return redirect()->route('optical.stock', [
            'receive' => 'lens', 'lensRange' => $this->matrixRange, 'lensDesign' => $this->matrixDesign,
            'lensIndex' => $this->matrixIndex, 'lensCoating' => $this->matrixCoating,
            'lensDiameter' => $this->matrixDiameter ?: 65, 'lensSphere' => $sphere ?? '0.00',
            'lensPower' => $power ?? ($this->matrixDesign === 'Single Vision' ? '0.00' : '1.00'),
            'lensEye' => \App\Support\LensDesign::isEyeSpecific($this->matrixDesign) ? $this->matrixEye : '',
        ]);
    }

    public bool $showServiceForm = false;
    public ?int $editingServiceId = null;
    public string $serviceName = '';
    public string $servicePrice = '';
    public bool $serviceRequiresRx = false;
    public bool $serviceRequiresFrame = false;
    public bool $serviceActive = true;

    protected $queryString = ['stockFilter' => ['except' => ''], 'activeTab', 'searchTerm', 'matrixRange', 'matrixDesign', 'matrixDiameter', 'matrixIndex', 'matrixCoating', 'matrixEye'];

    public function mount(): void
    {
        OpticalService::ensureTemplates();
        if (! request()->hasAny(['matrixRange', 'matrixDesign', 'matrixDiameter', 'matrixIndex', 'matrixCoating'])) {
            $products = $this->matrixProducts();
            $this->selectMatrixSpecification($products->first(fn ($p) => $p->stocks->sum('quantity') > 0) ?? $products->first());
        }
    }

    public function setTab($tab)
    {
        abort_unless(in_array($tab, ['frames', 'lenses', 'options', 'lens-matrix', 'services'], true), 422);
        $this->activeTab = $tab;
        $this->viewProductId = null;
        $this->showServiceForm = false;
        $this->resetPage();
    }

    public function setStockFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['', 'low', 'out'], true), 422);
        $this->stockFilter = $filter;
        $this->resetPage();
    }

    public function updatedSearchTerm(): void
    {
        $this->resetPage();
    }

    public function openProduct(int $id): void
    {
        $this->viewProductId = OpticalProduct::findOrFail($id)->id;
    }

    public function closeProduct(): void
    {
        $this->viewProductId = null;
    }

    /** Stock at this branch: low = at or below the reorder level, out = none. */
    private function applyStockFilter($query, string $filter)
    {
        return match ($filter) {
            'out' => $query->where(fn ($q) => $q->whereDoesntHave('stocks')->orWhereHas('stocks', fn ($s) => $s->where('quantity', '<=', 0))),
            'low' => $query->whereHas('stocks', fn ($s) => $s->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'reorder_level')),
            default => $query,
        };
    }

    public function addService(): void
    {
        $this->assertCanManageServices();
        $this->reset(['editingServiceId', 'serviceName', 'servicePrice', 'serviceRequiresRx', 'serviceRequiresFrame']);
        $this->serviceActive = true;
        $this->showServiceForm = true;
    }

    public function editService(int $id): void
    {
        $this->assertCanManageServices();
        $service = OpticalService::findOrFail($id);
        $this->editingServiceId = $service->id;
        $this->serviceName = $service->name;
        $this->servicePrice = (string) $service->price;
        $this->serviceRequiresRx = $service->requires_rx;
        $this->serviceRequiresFrame = $service->requires_frame;
        $this->serviceActive = $service->is_active;
        $this->showServiceForm = true;
    }

    public function saveService(): void
    {
        $this->assertCanManageServices();
        $this->validate([
            'serviceName' => 'required|string|max:255',
            'servicePrice' => 'required|numeric|min:0|max:1000000',
            'serviceRequiresRx' => 'boolean',
            'serviceRequiresFrame' => 'boolean',
            'serviceActive' => 'boolean',
        ]);
        app(ClinicAccessService::class)->assertWritable('optical');
        $service = $this->editingServiceId ? OpticalService::findOrFail($this->editingServiceId) : new OpticalService();
        if (! $service->exists) $service->code = 'custom-'.Str::lower(Str::random(16));
        $service->name = trim($this->serviceName);
        $service->price = round((float) $this->servicePrice, 2);
        $service->requires_rx = $this->serviceRequiresRx;
        $service->requires_frame = $this->serviceRequiresFrame;
        $service->is_active = $this->serviceActive;
        $service->save();
        $this->showServiceForm = false;
        session()->flash('success', 'Optical service saved. Existing order prices remain unchanged.');
    }

    private function assertCanManageServices(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
    }

    public function receiveLensBlanks(): void
    {
        abort(403, 'Receive lens stock through Stock Management.');
    }

    public function render()
    {
        $productsQuery = OpticalProduct::with(['category', 'stocks'])->where('is_active', true);
        $opticalCategories = OpticalCategory::all();
        $frameIds = $opticalCategories->filter(fn ($category) => $category->group === 'frames')->pluck('id');
        $lensIds = $opticalCategories->filter(fn ($category) => in_array($category->group, ['single_vision', 'progressive', 'bifocal'], true))->pluck('id');

        if ($this->activeTab === 'frames') {
            $productsQuery->whereIn('optical_category_id', $frameIds);
        } elseif ($this->activeTab === 'lenses') {
            $productsQuery->whereIn('optical_category_id', $lensIds);
        }

        if (!empty($this->searchTerm)) {
            $productsQuery->where(function($q) {
                $q->where('name', 'like', '%'.$this->searchTerm.'%')
                  ->orWhere('sku', 'like', '%'.$this->searchTerm.'%')
                  ->orWhere('brand', 'like', '%'.$this->searchTerm.'%');
            });
        }

        $stockCounts = collect(['' => null, 'low' => null, 'out' => null])->map(fn ($v, $key) => $this->applyStockFilter(clone $productsQuery, (string) $key)->count());
        $products = $this->applyStockFilter($productsQuery, $this->stockFilter)->latest()->paginate(10);
        $lensOptions = LensOption::all();
        $matrixProducts = $this->matrixProducts();
        if (trim((string) $this->matrixRange) === '' && $matrixProducts->isNotEmpty()) {
            $default = $matrixProducts->first(fn ($p) => $p->stocks->sum('quantity') > 0) ?? $matrixProducts->first();
            $this->selectMatrixSpecification($default);
        }

        $matchingProducts = $matrixProducts
            ->filter(fn ($product) => data_get($product->lens_specs, 'design') === $this->matrixDesign
                && trim((string) data_get($product->lens_specs, 'range')) === trim((string) $this->matrixRange)
                && data_get($product->lens_specs, 'index') === $this->matrixIndex
                && data_get($product->lens_specs, 'coating') === $this->matrixCoating);

        $rangeProducts = $matrixProducts->filter(fn ($p) => trim((string) data_get($p->lens_specs, 'range')) === trim((string) $this->matrixRange));
        if ($matchingProducts->isEmpty() && $rangeProducts->isNotEmpty()) {
            $stockedProduct = $rangeProducts->first(fn ($p) => $p->stocks->sum('quantity') > 0) ?? $rangeProducts->first();
            if ($stockedProduct) {
                $this->selectMatrixSpecification($stockedProduct);
                $matchingProducts = $matrixProducts
                    ->filter(fn ($product) => data_get($product->lens_specs, 'design') === $this->matrixDesign
                        && trim((string) data_get($product->lens_specs, 'range')) === trim((string) $this->matrixRange)
                        && data_get($product->lens_specs, 'index') === $this->matrixIndex
                        && data_get($product->lens_specs, 'coating') === $this->matrixCoating);
            }
        }

        if ($this->matrixDiameter == 0 && $matchingProducts->isNotEmpty()) {
            $this->matrixDiameter = (int) data_get($matchingProducts->first()->lens_specs, 'diameter', 65);
        }

        $lensBlankStock = $matchingProducts
            ->filter(fn ($product) => (int) data_get($product->lens_specs, 'diameter') === (int) $this->matrixDiameter
                && (! \App\Support\LensDesign::isEyeSpecific($this->matrixDesign) || data_get($product->lens_specs, 'eye') === $this->matrixEye))
            ->groupBy(fn ($product) => sprintf('%.2f|%.2f', (float) $product->lens_specs['sphere'], (float) $product->lens_specs['power']))
            ->map(fn ($products) => (int) $products->sum(fn ($product) => $product->stocks->sum('quantity')));
        $matrixSpheres = collect(array_merge(range(0, 15, 0.25), range(-0.25, -15, -0.25)))->filter(fn ($sphere) => ! $this->matrixStockedOnly || $lensBlankStock->contains(fn ($quantity, $key) => str_starts_with($key, sprintf('%.2f|', $sphere))))->values();
        $matrixColumns = $this->matrixDesign === 'Single Vision'
            ? ($this->matrixFullPowers ? range(0, -6, -0.25) : range(0, -2, -0.25))
            : ($this->matrixFullPowers ? range(.25, 4, .25) : range(1, 3, .25));
        foreach ($lensBlankStock as $key => $quantity) {
            $power = (float) explode('|', $key)[1];
            if (! in_array($power, $matrixColumns)) $matrixColumns[] = $power;
        }
        if ($this->matrixDesign === 'Single Vision') rsort($matrixColumns); else sort($matrixColumns);
        $matrixRowTotals = $lensBlankStock->groupBy(fn ($quantity, $key) => explode('|', $key)[0])->map(fn ($cells) => $cells->sum());
        $matrixColumnTotals = $lensBlankStock->groupBy(fn ($quantity, $key) => explode('|', $key)[1])->map(fn ($cells) => $cells->sum());
        $stockByProduct = $products->getCollection()->mapWithKeys(fn ($product) => [
            $product->id => $product->stocks->first()?->quantity ?? 0,
        ]);
        $reorderByProduct = $products->getCollection()->mapWithKeys(fn ($product) => [
            $product->id => (int) ($product->stocks->first()?->reorder_level ?? 5),
        ]);
        $viewProduct = $this->viewProductId ? OpticalProduct::with(['category', 'stocks'])->find($this->viewProductId) : null;

        return view('livewire.optical.optical-catalogue-component', [
            'replenishmentRows' => app(\App\Services\OpticalLensReplenishmentService::class)->rows($this->replenishmentSpecs()),
            'products' => $products,
            'lensOptions' => $lensOptions,
            'opticalServices' => OpticalService::orderBy('name')->get(),
            'stockByProduct' => $stockByProduct,
            'reorderByProduct' => $reorderByProduct,
            'stockCounts' => $stockCounts,
            'viewProduct' => $viewProduct,
            'viewMovements' => $viewProduct ? \App\Models\OpticalProductStockMovement::with('user')->where('optical_product_id', $viewProduct->id)->latest()->limit(8)->get() : collect(),
            'lensRanges' => $matrixProducts->pluck('lens_specs.range')->unique()->sort()->values(),
            'matrixSpheres' => $matrixSpheres,
            'matrixColumns' => $matrixColumns, 'matrixRowTotals' => $matrixRowTotals, 'matrixColumnTotals' => $matrixColumnTotals,
            'matrixTotal' => $lensBlankStock->sum(),
            'lensBlankStock' => $lensBlankStock,
        ])->layout('layouts.optical');
    }
}
