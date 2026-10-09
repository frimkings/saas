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
    /** The lens form shown ('' = Standard); a form other than Standard is its own stock. */
    public string $matrixForm = '';
    public $matrixDiameter = 0;
    public string $matrixEye = 'R';
    /** The stocked lens range shown; choosing it sets design, range, index, coating and diameter together. */
    public string $matrixRangeKey = '';
    /** pairs or lenses for single vision; pairs, R or L for progressive and bifocal. */
    public string $matrixView = 'pairs';
    // Picking powers to order happens in the browser (resources/js/lens-grid.js); the server
    // is only asked to build the purchase order or order sheet from the powers chosen.
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
        if (in_array($property, ['matrixRange', 'matrixDesign', 'matrixIndex', 'matrixCoating', 'matrixDiameter', 'matrixEye', 'matrixRangeKey', 'matrixView'], true)) {
            $this->showReorderSettings = false;
            $this->reorderPairs = $this->targetPairs = [];
            // A different range or eye: powers picked in the browser no longer apply.
            if ($property !== 'matrixView') $this->dispatch('lens-grid-reset');
            $this->resetValidation();
        }
    }

    private function replenishmentSpecs(): array
    {
        $specs = ['range' => trim((string) $this->matrixRange), 'design' => $this->matrixDesign,
            'index' => $this->matrixIndex, 'coating' => $this->matrixCoating, 'diameter' => (int) $this->matrixDiameter];
        if ($this->matrixForm !== '') $specs['form'] = $this->matrixForm;
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

    /** Stocked lens items: loaded once per request, with only what the grid and range picker use. */
    private $matrixProductsCache = null;

    private function matrixProducts()
    {
        return $this->matrixProductsCache ??= OpticalProduct::select(['id', 'lens_specs', 'cost_price', 'selling_price', 'is_active'])
            ->with('stocks:id,optical_product_id,quantity,lens_reorder_pairs')
            ->where('is_active', true)->whereNotNull('lens_specs')->whereHas('stocks')->get();
    }

    private function selectMatrixSpecification($product): void
    {
        if (! $product) return;
        $specs = $product->lens_specs;
        $this->matrixRange = $specs['range'];
        $this->matrixDesign = $specs['design'];
        $this->matrixForm = (string) ($specs['form'] ?? '');
        $this->matrixIndex = $specs['index'];
        $this->matrixCoating = $specs['coating'];
        $this->matrixDiameter = $specs['diameter'];
        if (isset($specs['eye'])) $this->matrixEye = $specs['eye'];
    }

    public function updatedMatrixRange(): void
    {
        $this->selectMatrixSpecification($this->matrixProducts()->first(fn ($p) => data_get($p->lens_specs, 'range') === $this->matrixRange));
    }

    public function updatedMatrixRangeKey(): void
    {
        $range = $this->stockedRanges($this->matrixProducts())->firstWhere('key', $this->matrixRangeKey);
        if (! $range) return;
        $this->matrixRange = $range['specs']['range'];
        $this->matrixDesign = $range['specs']['design'];
        $this->matrixForm = (string) ($range['specs']['form'] ?? '');
        $this->matrixIndex = $range['specs']['index'];
        $this->matrixCoating = $range['specs']['coating'];
        $this->matrixDiameter = $range['specs']['diameter'];
        $this->matrixView = 'pairs';
        $this->matrixEye = 'R';
    }

    public function updatedMatrixView(): void
    {
        if (in_array($this->matrixView, ['R', 'L'], true)) $this->matrixEye = $this->matrixView;
    }

    /** Pairs and lenses suit single vision; progressive and bifocal show pairs or one eye. */
    private function normalizeMatrixView(bool $eyeSpecific): void
    {
        $allowed = $eyeSpecific ? ['pairs', 'R', 'L'] : ['pairs', 'lenses'];
        if (! in_array($this->matrixView, $allowed, true)) $this->matrixView = $eyeSpecific ? $this->matrixEye : 'lenses';
    }

    // ── Ordering powers picked on the grid ─────────────────────────────────────

    /** Open Purchasing with a draft order for the powers picked in the browser, each in $pairs pairs. */
    public function createOrderFromSelection(array $keys = [], $pairs = 10)
    {
        $this->assertCanManageServices();
        $cells = $this->validatedSelection($keys, $pairs);
        if ($cells === null) return null;
        return redirect()->route('optical.purchasing', ['lensorder' => base64_encode(json_encode([
            'specs' => $this->matrixRangeSpecs(), 'cells' => $cells,
        ]))]);
    }

    /** The picked powers as a manufacturer order sheet, in pairs. */
    public function downloadSelectionSheet(array $keys = [], $pairs = 10)
    {
        $this->assertCanManageServices();
        $cells = $this->validatedSelection($keys, $pairs);
        if ($cells === null) return null;
        $rows = collect($cells)->map(function ($pairs, $key) {
            [$sphere, $power] = explode('|', $key);
            return ['sphere' => $sphere, 'power' => $power, 'pairs' => $pairs];
        })->values();
        $content = app(\App\Services\OpticalLensReplenishmentService::class)->workbook($this->matrixRangeSpecs(), $rows);
        $filename = \Illuminate\Support\Str::slug(trim($this->matrixRange.' '.$this->matrixDesign.' '.$this->matrixCoating) ?: 'lens').'-order.xlsx';
        return response()->streamDownload(fn () => print($content), $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * The browser's selection, checked again: real powers for this design only, in whole pairs.
     *
     * @return array<string, int>|null "sphere|power" => pairs
     */
    private function validatedSelection(array $keys, $pairs): ?array
    {
        if (! is_numeric($pairs) || (int) $pairs != $pairs || $pairs < 1 || $pairs > 5000) {
            $this->addError('orderPairsEach', 'Enter a whole number of pairs per power, from 1 to 5000.');
            return null;
        }
        $planner = app(\App\Services\OpticalLensOrderPlanner::class);
        $keys = array_values(array_unique(array_filter(array_slice($keys, 0, 3000), fn ($key) => is_string($key) && $planner->validKey($key, $this->matrixDesign))));
        if ($keys === []) {
            $this->addError('selectedCells', 'Select at least one power to order.');
            return null;
        }
        return array_fill_keys($keys, (int) $pairs);
    }

    /**
     * What the browser needs to pick powers without asking the server: for each power stocked
     * before, [pairs on hand, spare lenses, spare eye, reorder level (pairs), pairs on order,
     * cost of one pair], and the typical pair cost for a power the range has never stocked.
     */
    private function selectionGrid($inRange, $pairCells): array
    {
        $byKey = $inRange->groupBy(fn ($p) => sprintf('%.2f|%.2f', (float) $p->lens_specs['sphere'], (float) $p->lens_specs['power']));
        $onOrder = app(\App\Services\OpticalLensOrderPlanner::class)->onOrderPairs($this->matrixRangeSpecs());
        $cells = $pairCells->map(function ($cell, $key) use ($byKey, $onOrder) {
            $products = $byKey->get($key, collect());
            // One lens per eye for progressive/bifocal, two of the same lens for single vision.
            $pairCost = $products->count() > 1 ? $products->sum(fn ($p) => (float) $p->cost_price) : 2 * (float) $products->first()?->cost_price;
            return [$cell['value'], $cell['extra'], $cell['extraEye'], (int) ($products->max(fn ($p) => $p->stocks->first()?->lens_reorder_pairs ?? 5) ?? 5), (int) ($onOrder[$key] ?? 0), round($pairCost, 2)];
        });

        return ['cells' => (object) $cells->all(), 'typical' => 2 * app(\App\Services\OpticalLensOrderPlanner::class)->typicalLensCost($byKey)['cost']];
    }

    private function matrixRangeSpecs(): array
    {
        return app(\App\Services\OpticalLensPriceList::class)->rangeSpecs([
            'range' => $this->matrixRange, 'design' => $this->matrixDesign, 'index' => $this->matrixIndex,
            'coating' => $this->matrixCoating, 'diameter' => $this->matrixDiameter, 'form' => $this->matrixForm,
        ]);
    }

    /** Stock for the selected range, shared by the grid and its ordering actions. */
    private function matrixState(): array
    {
        $matrixProducts = $this->matrixProducts();
        if (trim((string) $this->matrixRange) === '' && $matrixProducts->isNotEmpty()) {
            $default = $matrixProducts->first(fn ($p) => $p->stocks->sum('quantity') > 0) ?? $matrixProducts->first();
            $this->selectMatrixSpecification($default);
        }
        $stockedRanges = $this->stockedRanges($matrixProducts);
        $this->completeMatrixSpecification($stockedRanges);

        $matchingProducts = $matrixProducts
            ->filter(fn ($product) => data_get($product->lens_specs, 'design') === $this->matrixDesign
                && trim((string) data_get($product->lens_specs, 'range')) === trim((string) $this->matrixRange)
                && data_get($product->lens_specs, 'index') === $this->matrixIndex
                && data_get($product->lens_specs, 'coating') === $this->matrixCoating
                && (string) data_get($product->lens_specs, 'form') === $this->matrixForm);

        // The chosen range is never swapped for another: an empty selection shows an empty state.
        if ($this->matrixDiameter == 0 && $matchingProducts->isNotEmpty()) {
            $this->matrixDiameter = (int) data_get($matchingProducts->first()->lens_specs, 'diameter', 65);
        }

        $eyeSpecific = \App\Support\LensDesign::isEyeSpecific($this->matrixDesign);
        $this->normalizeMatrixView($eyeSpecific);
        $inRange = $matchingProducts->filter(fn ($product) => (int) data_get($product->lens_specs, 'diameter') === (int) $this->matrixDiameter);
        $lensesByPower = fn ($products) => $products
            ->groupBy(fn ($product) => sprintf('%.2f|%.2f', (float) $product->lens_specs['sphere'], (float) $product->lens_specs['power']))
            ->map(fn ($products) => (int) $products->sum(fn ($product) => $product->stocks->sum('quantity')));
        // Individual lenses for single vision, or for the selected eye of a progressive/bifocal range.
        $lensBlankStock = $lensesByPower($inRange->filter(fn ($product) => ! $eyeSpecific || data_get($product->lens_specs, 'eye') === $this->matrixEye));
        $eyes = $eyeSpecific ? [$lensesByPower($inRange->where('lens_specs.eye', 'R')), $lensesByPower($inRange->where('lens_specs.eye', 'L'))] : null;
        // Pairs are kept alongside whatever is displayed: ordering always works in pairs.
        $allLenses = $eyeSpecific ? $lensesByPower($inRange) : $lensBlankStock;

        return compact('matrixProducts', 'stockedRanges', 'eyeSpecific', 'inRange', 'lensBlankStock') + [
            'matrixCells' => $this->matrixCells($lensBlankStock, $eyes),
            'pairCells' => $this->matrixCells($allLenses, $eyes, 'pairs'),
        ];
    }

    /**
     * Cell values in the chosen unit, keyed "sphere|power". Single vision pairs are two lenses
     * of one power, with an odd lens shown as +1. Progressive/bifocal pairs are the matched
     * right and left lenses; unmatched lenses are shown with their eye, e.g. +2 R.
     *
     * @param  array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}|null  $eyes
     */
    private function matrixCells($lenses, ?array $eyes, ?string $view = null)
    {
        if (($view ?? $this->matrixView) !== 'pairs') {
            return $lenses->map(fn ($quantity) => ['value' => $quantity, 'extra' => 0, 'extraEye' => null]);
        }
        if ($eyes === null) {
            return $lenses->map(fn ($quantity) => ['value' => intdiv($quantity, 2), 'extra' => $quantity % 2, 'extraEye' => null]);
        }
        [$right, $left] = $eyes;
        return $right->keys()->merge($left->keys())->unique()->mapWithKeys(function ($key) use ($right, $left) {
            $r = (int) ($right[$key] ?? 0);
            $l = (int) ($left[$key] ?? 0);
            return [$key => ['value' => min($r, $l), 'extra' => abs($r - $l), 'extraEye' => $r === $l ? null : ($r > $l ? 'R' : 'L')]];
        });
    }

    /**
     * A link or filter may name only the range and design (e.g. after a receipt). Fill in the
     * index, coating and diameter from that stocked range. The chosen design is never changed.
     */
    private function completeMatrixSpecification($stockedRanges): void
    {
        $priceList = app(\App\Services\OpticalLensPriceList::class);
        $current = ['range' => $this->matrixRange, 'design' => $this->matrixDesign, 'index' => $this->matrixIndex, 'coating' => $this->matrixCoating, 'diameter' => $this->matrixDiameter, 'form' => $this->matrixForm];
        if ($stockedRanges->contains('key', $priceList->rangeKey($current))) return;
        $sameRange = $stockedRanges->filter(fn ($r) => $r['specs']['range'] === trim((string) $this->matrixRange) && $r['specs']['design'] === $this->matrixDesign
            && (string) ($r['specs']['form'] ?? '') === $this->matrixForm);
        $match = $sameRange->first(fn ($r) => $r['specs']['index'] === $this->matrixIndex && $r['specs']['coating'] === $this->matrixCoating)
            ?? $sameRange->first(fn ($r) => $r['specs']['coating'] === $this->matrixCoating)
            ?? $sameRange->first();
        if (! $match) return;
        $this->matrixIndex = $match['specs']['index'];
        $this->matrixCoating = $match['specs']['coating'];
        $this->matrixDiameter = $match['specs']['diameter'];
    }

    /** Every stocked lens range with its stock in lenses and pairs, for the range picker. */
    private function stockedRanges($products)
    {
        $priceList = app(\App\Services\OpticalLensPriceList::class);
        $designOrder = ['Single Vision' => 0, 'Progressive' => 1, 'Bifocal' => 2];
        return $products->groupBy(fn ($product) => $priceList->rangeKey($product->lens_specs))
            ->map(function ($group, $key) use ($priceList) {
                $specs = $priceList->rangeSpecs($group->first()->lens_specs);
                $byPower = $group->groupBy(fn ($p) => sprintf('%.2f|%.2f', (float) data_get($p->lens_specs, 'sphere'), (float) data_get($p->lens_specs, 'power')));
                $pairs = 0; $extra = 0;
                foreach ($byPower as $powerProducts) {
                    if (\App\Support\LensDesign::isEyeSpecific($specs['design'])) {
                        $r = (int) $powerProducts->where('lens_specs.eye', 'R')->sum(fn ($p) => $p->stocks->sum('quantity'));
                        $l = (int) $powerProducts->where('lens_specs.eye', 'L')->sum(fn ($p) => $p->stocks->sum('quantity'));
                        $pairs += min($r, $l); $extra += abs($r - $l);
                    } else {
                        $q = (int) $powerProducts->sum(fn ($p) => $p->stocks->sum('quantity'));
                        $pairs += intdiv($q, 2); $extra += $q % 2;
                    }
                }
                return ['key' => $key, 'specs' => $specs, 'pairs' => $pairs, 'extra' => $extra,
                    'lenses' => (int) $group->sum(fn ($p) => $p->stocks->sum('quantity'))];
            })
            ->sortBy(fn ($range) => ($designOrder[$range['specs']['design']] ?? 9).' '.strtolower($range['specs']['range']))->values();
    }

    /** Receive a design this branch has no stock of yet (pairs for both eyes). */
    public function receiveDesign(string $design)
    {
        $this->assertCanManageServices();
        abort_unless(in_array($design, ['Single Vision', 'Bifocal', 'Progressive'], true), 422);
        return redirect()->route('optical.stock', [
            'receive' => 'lens', 'lensDesign' => $design,
            'lensPower' => $design === 'Single Vision' ? '0.00' : '1.00',
            'lensEye' => \App\Support\LensDesign::isEyeSpecific($design) ? 'B' : '',
        ]);
    }

    public function openIntake($sphere = null, $power = null)
    {
        $this->assertCanManageServices();
        return redirect()->route('optical.stock', [
            'receive' => 'lens', 'lensRange' => $this->matrixRange, 'lensDesign' => $this->matrixDesign,
            'lensIndex' => $this->matrixIndex, 'lensCoating' => $this->matrixCoating,
            'lensDiameter' => $this->matrixDiameter ?: 65, 'lensSphere' => $sphere ?? '0.00',
            'lensPower' => $power ?? ($this->matrixDesign === 'Single Vision' ? '0.00' : '1.00'),
            'lensEye' => \App\Support\LensDesign::isEyeSpecific($this->matrixDesign) ? ($this->matrixView === 'pairs' ? 'B' : $this->matrixEye) : '',
        ]);
    }

    public bool $showServiceForm = false;
    public ?int $editingServiceId = null;
    public string $serviceName = '';
    public string $servicePrice = '';
    public bool $serviceRequiresRx = false;
    public bool $serviceRequiresFrame = false;
    public bool $serviceActive = true;

    protected $queryString = ['stockFilter' => ['except' => ''], 'activeTab', 'searchTerm', 'matrixRange', 'matrixDesign', 'matrixForm', 'matrixDiameter', 'matrixIndex', 'matrixCoating', 'matrixEye'];

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
        abort_unless(in_array($tab, ['frames', 'lenses', 'options', 'lens-matrix', 'lens-prices', 'services'], true), 422);
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
        \App\Models\AuditTrail::recordSave($service, 'optical.service', 'optical service '.$service->name);
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

    /** Only what the open tab shows is loaded: the product list, the lens grid, options or services. */
    public function render()
    {
        $viewProduct = $this->viewProductId ? OpticalProduct::with(['category', 'stocks'])->find($this->viewProductId) : null;
        $data = [
            'viewProduct' => $viewProduct,
            'viewMovements' => $viewProduct ? \App\Models\OpticalProductStockMovement::with('user')->where('optical_product_id', $viewProduct->id)->latest()->limit(8)->get() : collect(),
            'lensOptions' => $this->activeTab === 'options' ? LensOption::all() : collect(),
            'opticalServices' => $this->activeTab === 'services' ? OpticalService::orderBy('name')->get() : collect(),
        ];
        if (in_array($this->activeTab, ['frames', 'lenses'], true)) $data += $this->productListData();
        if ($this->activeTab === 'lens-matrix') $data += $this->lensGridData();

        return view('livewire.optical.optical-catalogue-component', $data)->layout('layouts.optical');
    }

    private function productListData(): array
    {
        $categories = OpticalCategory::all();
        $type = $this->activeTab === 'frames' ? 'frame' : 'lens';
        $productsQuery = OpticalProduct::with(['category', 'stocks'])->where('is_active', true)
            ->whereIn('optical_category_id', $categories->where('type', $type)->pluck('id'));
        if (! empty($this->searchTerm)) {
            $productsQuery->where(function ($q) {
                $q->where('name', 'like', '%'.$this->searchTerm.'%')
                    ->orWhere('sku', 'like', '%'.$this->searchTerm.'%')
                    ->orWhere('brand', 'like', '%'.$this->searchTerm.'%');
            });
        }
        $products = $this->applyStockFilter(clone $productsQuery, $this->stockFilter)->latest()->paginate(10);

        return [
            'products' => $products,
            'stockCounts' => collect(['' => null, 'low' => null, 'out' => null])->map(fn ($v, $key) => $this->applyStockFilter(clone $productsQuery, (string) $key)->count()),
            'stockByProduct' => $products->getCollection()->mapWithKeys(fn ($product) => [$product->id => $product->stocks->first()?->quantity ?? 0]),
            'reorderByProduct' => $products->getCollection()->mapWithKeys(fn ($product) => [$product->id => (int) ($product->stocks->first()?->reorder_level ?? 5)]),
        ];
    }

    private function lensGridData(): array
    {
        ['stockedRanges' => $stockedRanges, 'eyeSpecific' => $eyeSpecific, 'inRange' => $inRange, 'lensBlankStock' => $lensBlankStock,
            'matrixCells' => $matrixCells, 'pairCells' => $pairCells] = $this->matrixState();
        $matrixSpheres = collect(array_merge(range(0, 15, 0.25), range(-0.25, -15, -0.25)))->filter(fn ($sphere) => ! $this->matrixStockedOnly || $matrixCells->contains(fn ($cell, $key) => str_starts_with($key, sprintf('%.2f|', $sphere))))->values();
        $matrixColumns = $this->matrixDesign === 'Single Vision'
            ? ($this->matrixFullPowers ? range(0, -6, -0.25) : range(0, -2, -0.25))
            : ($this->matrixFullPowers ? range(.25, 4, .25) : range(1, 3, .25));
        foreach ($matrixCells as $key => $cell) {
            $power = (float) explode('|', $key)[1];
            if (! in_array($power, $matrixColumns)) $matrixColumns[] = $power;
        }
        if ($this->matrixDesign === 'Single Vision') rsort($matrixColumns); else sort($matrixColumns);
        // Single vision switches between pairs and lenses in the browser, so both are sent.
        // Progressive/bifocal Right/Left stays on the server: it sets the eye the reorder alerts cover.
        $views = $eyeSpecific ? [$this->matrixView => $matrixCells] : ['pairs' => $this->matrixCells($lensBlankStock, null, 'pairs'), 'lenses' => $this->matrixCells($lensBlankStock, null, 'lenses')];
        $totals = fn ($cells) => [
            'rows' => $cells->groupBy(fn ($cell, $key) => explode('|', $key)[0])->map(fn ($c) => ['value' => $c->sum('value'), 'extra' => $c->sum('extra')]),
            'columns' => $cells->groupBy(fn ($cell, $key) => explode('|', $key)[1])->map(fn ($c) => ['value' => $c->sum('value'), 'extra' => $c->sum('extra')]),
            'all' => ['value' => $cells->sum('value'), 'extra' => $cells->sum('extra')],
        ];
        $priceList = app(\App\Services\OpticalLensPriceList::class);
        $currentSpecs = ['range' => $this->matrixRange, 'design' => $this->matrixDesign, 'index' => $this->matrixIndex, 'coating' => $this->matrixCoating, 'diameter' => $this->matrixDiameter, 'form' => $this->matrixForm];
        $this->matrixRangeKey = $priceList->rangeKey($currentSpecs);
        $selectionGrid = $this->selectionGrid($inRange, $pairCells);

        return [
            'stockedRanges' => $stockedRanges,
            'missingDesigns' => collect(['Single Vision', 'Bifocal', 'Progressive'])->diff($stockedRanges->pluck('specs.design'))->values(),
            'matrixCells' => $matrixCells,
            'matrixViews' => $views,
            'matrixViewTotals' => array_map($totals, $views),
            'matrixCellTotal' => ['value' => $matrixCells->sum('value'), 'extra' => $matrixCells->sum('extra')],
            'matrixEyeSpecific' => $eyeSpecific,
            'matrixOnOrder' => array_filter(array_map(fn ($cell) => $cell[4], (array) $selectionGrid['cells'])),
            'matrixTrackedKeys' => $pairCells->keys()->flip(),
            'selectionGrid' => $selectionGrid,
            'matrixPrice' => $priceList->find($currentSpecs),
            'matrixRefreshedAt' => now(),
            'replenishmentRows' => app(\App\Services\OpticalLensReplenishmentService::class)->rows($this->replenishmentSpecs()),
            'matrixSpheres' => $matrixSpheres,
            'matrixColumns' => $matrixColumns,
            'matrixTotal' => $lensBlankStock->sum(),
            'matrixRowTotals' => $lensBlankStock->groupBy(fn ($quantity, $key) => explode('|', $key)[0])->map(fn ($cells) => $cells->sum()),
            'matrixColumnTotals' => $lensBlankStock->groupBy(fn ($quantity, $key) => explode('|', $key)[1])->map(fn ($cells) => $cells->sum()),
            'lensBlankStock' => $lensBlankStock,
        ];
    }
}
