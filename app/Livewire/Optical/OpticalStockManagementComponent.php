<?php

namespace App\Livewire\Optical;

use App\Models\OpticalProduct;
use App\Models\OpticalProductStockMovement;
use App\Services\ClinicAccessService;
use App\Services\OpticalStockLedgerService;
use Livewire\Component;
use Livewire\WithPagination;

class OpticalStockManagementComponent extends Component
{
    use WithPagination, \Livewire\WithFileUploads;

    public $excelFile;
    public array $excelSheets = [];
    public int $excelSheet = 0;
    public string $excelLayout = 'manual';
    public string $excelUnit = '';
    public bool $excelWarningsAccepted = false;
    public bool $templateGrid = true;
    public string $bulkSection = 'positive';
    #[\Livewire\Attributes\Locked]
    public string $importedSummary = '';
    #[\Livewire\Attributes\Locked]
    public array $excelOriginalSpecs = [];
    #[\Livewire\Attributes\Locked]
    public array $importSource = [];
    public string $repeatDeliveryReason = '';
    public string $importSearch = '';
    #[\Livewire\Attributes\Locked]
    public ?int $viewImportId = null;

    /** Renderless: the browser has already closed the details (dismissCall). */
    #[\Livewire\Attributes\Renderless]
    public function closeImport(): void { $this->viewImportId = null; }

    public function viewImport(int $id): void
    {
        \App\Models\OpticalLensImport::findOrFail($id);
        $this->viewImportId = $id;
        $this->showForm = false;
    }

    public function reverseImport(int $id): void
    {
        $this->assertManager();
        app(\App\Services\OpticalLensImportReceiptService::class)->reverse($id);
        session()->flash('success', 'Import reversed. Stock balances and the power matrix have been updated.');
    }

    public function updatedImportSearch(): void { $this->resetPage('importsPage'); }

    public function updatedExcelSheet(): void
    {
        if (! $this->excelFile) return;
        $sheets = app(\App\Services\OpticalLensExcelImportService::class)->readUpload($this->excelFile);
        abort_unless(isset($sheets[$this->excelSheet]), 422);
        $this->configureExcelSheet($sheets[$this->excelSheet]);
    }

    private function configureExcelSheet(array $sheet): void
    {
        $specs = app(\App\Services\OpticalLensExcelImportService::class)->specifications($sheet);
        $this->excelLayout = isset($specs['design']) ? 'template' : 'manual';
        foreach (['range' => 'lensRange', 'design' => 'lensDesign', 'coating' => 'lensCoating', 'index' => 'lensIndex', 'diameter' => 'lensDiameter', 'eye' => 'lensEye'] as $key => $property) {
            if (isset($specs[$key])) $this->$property = $specs[$key];
        }
        $this->excelPreview = [];
        $this->excelWarningsAccepted = false;
        // Manufacturer lens orders are in pairs unless the workbook states otherwise. A
        // progressive/bifocal sheet titled for one eye holds that eye's individual lenses.
        $this->excelUnit = ($specs['unit'] ?? '') ?: (in_array($specs['eye'] ?? '', ['R', 'L'], true) ? 'pieces' : 'pairs');
        if (\App\Support\LensDesign::isEyeSpecific($this->lensDesign)) $this->syncEyeToUnit();
    }
    public int $excelHeaderRow = 2;
    public int $excelSphereColumn = 1;
    #[\Livewire\Attributes\Locked]
    public array $excelPreview = [];

    public function updatedExcelFile(): void
    {
        $this->assertManager();
        if (! $this->excelOriginalSpecs) {
            foreach (['lensRange', 'lensDesign', 'lensCoating', 'lensIndex', 'lensDiameter', 'lensEye', 'unitCost', 'unitPrice'] as $field) $this->excelOriginalSpecs[$field] = $this->$field;
        }
        $this->excelPreview = [];
        $this->excelSheets = [];
        $this->validate(['excelFile' => 'required|file|mimes:xlsx,zip|max:5120']);
        $sheets = app(\App\Services\OpticalLensExcelImportService::class)->readUpload($this->excelFile);
        $this->excelSheets = array_column($sheets, 'name');
        $this->excelSheet = 0;
        $this->configureExcelSheet($sheets[0]);
        $this->resetValidation();
    }

    public function previewExcel(): void
    {
        $this->assertManager();
        $this->excelPreview = [];
        $this->validate(['excelFile' => 'required|file|mimes:xlsx,zip|max:5120', 'excelSheet' => 'required|integer|min:0',
            'excelHeaderRow' => 'required|integer|between:1,2000', 'excelSphereColumn' => 'required|integer|between:1,100',
            'lensDesign' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Optical\LensOptions::choices('design')))],
            'lensEye' => $this->eyeRule(),
            'excelUnit' => 'required|in:pieces,pairs', 'excelLayout' => 'required|in:template,manual']);
        if (! $this->eyeMatchesUnit($this->excelUnit)) return;
        $service = app(\App\Services\OpticalLensExcelImportService::class);
        $sheets = $service->readUpload($this->excelFile);
        abort_unless(isset($sheets[$this->excelSheet]), 422);
        if ($this->excelLayout === 'template') {
            $this->excelPreview = $service->previewTemplate($sheets[$this->excelSheet], $this->lensDesign, $this->excelUnit);
        } else {
            $this->excelPreview = $service->preview($sheets[$this->excelSheet]['rows'], $this->excelHeaderRow, $this->excelSphereColumn, $this->lensDesign);
            $this->excelPreview['sourceTotal'] = $this->excelPreview['total'];
            $this->excelPreview['perEye'] = $this->lensEye === 'B';
            if ($this->excelUnit === 'pairs') {
                $this->excelPreview['total'] *= 2;
                // Eye-specific pairs keep the pair count per cell: one right and one left lens each.
                if (! $this->excelPreview['perEye']) {
                    foreach ($this->excelPreview['quantities'] as &$cells) foreach ($cells as &$quantity) $quantity *= 2;
                    unset($cells, $quantity);
                    foreach ($this->excelPreview['lines'] as &$line) $line['quantity'] *= 2;
                    unset($line);
                }
            }
        }
        $this->excelPreview['sheet'] = $sheets[$this->excelSheet]['name'];
        $this->resetValidation();
    }

    public function applyExcel(): void
    {
        $this->assertManager();
        if (! $this->excelPreview) { $this->addError('excelFile', 'Preview the workbook before applying it.'); return; }
        $this->previewExcel();
        if (! empty($this->excelPreview['warnings']) && ! $this->excelWarningsAccepted) { $this->addError('excelFile', 'Review and acknowledge the workbook warnings before applying.'); return; }
        $this->importedSummary = $this->excelPreview['sheet'].'; '.$this->excelPreview['sourceTotal'].' '.$this->excelUnit.' = '.$this->excelPreview['total'].' pieces';
        $this->importSource = [
            'filename' => mb_substr(basename($this->excelFile->getClientOriginalName()), 0, 255),
            'worksheet' => $this->excelPreview['sheet'], 'unit' => $this->excelUnit,
            'source_quantity' => $this->excelPreview['sourceTotal'], 'quantities' => $this->excelPreview['quantities'],
        ];
        $this->repeatDeliveryReason = '';
        $this->bulkQuantities = $this->excelPreview['quantities'];
        $this->bulkCosts = [];
        $this->bulkPrices = [];
        $this->bulkStart = (string) (-15 + min(array_keys($this->bulkQuantities)) / 4);
        $this->entryMode = 'bulk';
        $this->reset(['excelFile', 'excelPreview', 'excelSheets', 'excelOriginalSpecs']);
    }

    public function cancelExcel(): void
    {
        foreach ($this->excelOriginalSpecs as $field => $value) $this->$field = $value;
        $this->reset(['excelFile', 'excelPreview', 'excelSheets', 'excelOriginalSpecs']);
        $this->resetValidation();
    }

    public function downloadLensTemplate()
    {
        $this->assertManager();
        $design = in_array($this->lensDesign, ['Single Vision', 'Bifocal', 'Progressive'], true) ? $this->lensDesign : 'Single Vision';
        $content = app(\App\Services\OpticalLensExcelImportService::class)->generateTemplate($design);
        $type = $design === 'Single Vision' ? 'SV' : strtoupper($design);
        $treatment = match ($this->lensCoating) {
            'BlueCut' => 'BLUE BLOCK', 'Blue AR' => 'BLUE AR',
            'Photo AR' => 'PHOTO AR', 'Photo Gray', 'Photochromic' => 'PHOTO',
            'AR' => 'CLEAR AR', 'HC' => 'HARD COAT', 'Transitions' => 'TRANSITIONS',
            default => 'CLEAR',
        };
        $filename = $type.' '.$treatment.' LENS ORDER.xlsx';
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public string $stockType = 'other';
    public string $lensRange = '';
    public string $lensDesign = 'Single Vision';
    public string $lensIndex = '1.56';
    public string $lensCoating = 'AR';
    public string $lensDiameter = '65';
    public string $lensSphere = '0.00';
    public string $lensPower = '0.00';
    public string $lensEye = '';
    public bool $priceFromProduct = false;
    public bool $updateSellingPrice = false;
    public string $entryMode = 'single';
    public array $bulkQuantities = [];
    public array $bulkCosts = [];
    public array $bulkPrices = [];
    public string $bulkPaste = '';
    public string $bulkStart = '0';

    /** The full-page lens receiving screen reuses this component; the modal sends bulk entry there. */
    protected bool $fullPage = false;

    public function mount(): void
    {
        if (request()->query('receive') === 'lens') {
            $this->openReceipt();
            $this->stockType = 'lens';
            $this->fillLensSpecsFromQuery();
        }
        if (ctype_digit((string) request()->query('viewImport'))) $this->viewImport((int) request()->query('viewImport'));
    }

    protected function fillLensSpecsFromQuery(): void
    {
        foreach (['lensRange', 'lensDesign', 'lensIndex', 'lensCoating', 'lensDiameter', 'lensSphere', 'lensPower', 'lensEye'] as $field) {
            if (is_string(request()->query($field))) $this->$field = request()->query($field);
        }
        if (! request()->has('lensPower')) $this->lensPower = $this->lensDesign === 'Single Vision' ? '0.00' : '1.00';
    }

    /** Bulk grids and Excel orders are too large for the modal, so they open on their own page. */
    public function updatedEntryMode(): void
    {
        if ($this->fullPage || $this->entryMode !== 'bulk' || $this->stockType !== 'lens' || $this->formType !== 'receipt') return;
        $this->redirectRoute('optical.stock.receive-lenses', array_filter([
            'lensRange' => trim($this->lensRange), 'lensDesign' => $this->lensDesign, 'lensIndex' => $this->lensIndex,
            'lensCoating' => $this->lensCoating, 'lensDiameter' => $this->lensDiameter, 'lensEye' => $this->lensEye,
        ], fn ($value) => $value !== ''));
    }

    /** Lenses and cost of the receipt being entered, counting both-eye pairs as two lenses. */
    public function receiptTotals(): array
    {
        $pairFactor = $this->lensEye === 'B' && \App\Support\LensDesign::isEyeSpecific($this->lensDesign) ? 2 : 1;
        $amount = fn ($value) => is_numeric($value) ? (float) $value : 0;
        if ($this->entryMode !== 'bulk') {
            $pieces = (is_numeric($this->quantity) ? (int) $this->quantity : 0) * $pairFactor;
            return ['pieces' => $pieces, 'cost' => $pieces * $amount($this->unitCost)];
        }
        $pieces = 0; $cost = 0;
        foreach ($this->bulkQuantities as $r => $cells) foreach ($cells as $c => $quantity) {
            $quantity = is_numeric($quantity) ? (int) $quantity : 0;
            $pieces += $quantity;
            $cost += $quantity * $amount(($this->bulkCosts[$r][$c] ?? '') !== '' ? $this->bulkCosts[$r][$c] : $this->unitCost);
        }
        return ['pieces' => $pieces * $pairFactor, 'cost' => $cost * $pairFactor];
    }

    /** @return \Illuminate\Support\Collection<int, string> the lens ranges already received, as spelled on their stock */
    private function knownRanges()
    {
        // The manufacturer list (Lens options), which starts with every range already on stock.
        return $this->rangesThisRequest ??= \App\Support\Optical\LensOptions::all('manufacturer')->pluck('code')
            ->map(fn ($range) => trim((string) $range))->filter()->unique()->sort()->values();
    }

    /** Ranges for this request only; a private property is not kept between requests. */
    private ?\Illuminate\Support\Collection $rangesThisRequest = null;

    /**
     * A typed range in the spelling its stock already uses: "canada  opticals" is the
     * existing "CANADA OPTICALS", so one range never becomes two stocks by capitals or spaces.
     */
    private function canonicalRange(string $typed): string
    {
        $typed = trim(preg_replace('/\s+/', ' ', $typed));
        return $this->knownRanges()->first(fn ($range) => mb_strtolower($range) === mb_strtolower($typed)) ?? $typed;
    }

    /** Shown under the range box when the name is new: it will be separate stock, and the closest existing name. */
    public function rangeHint(): ?array
    {
        $typed = trim($this->lensRange);
        if ($typed === '' || $this->knownRanges()->contains($typed)) return null;
        $lower = mb_strtolower($typed);
        $closest = $this->knownRanges()
            ->map(fn ($range) => ['range' => $range, 'distance' => levenshtein(mb_strtolower($range), $lower), 'contains' => str_contains(mb_strtolower($range), $lower) || str_contains($lower, mb_strtolower($range))])
            ->filter(fn ($match) => $match['contains'] || $match['distance'] <= max(2, (int) floor(mb_strlen($typed) / 4)))
            ->sortBy('distance')->first();
        return ['typed' => $typed, 'suggest' => $closest['range'] ?? null];
    }

    public function useRange(string $range): void
    {
        abort_unless($this->knownRanges()->contains($range), 422);
        $this->lensRange = $range;
        $this->updatedLensRange();
    }

    public function updatedLensRange(): void
    {
        $this->lensRange = $this->canonicalRange($this->lensRange);
        $this->resetErrorBag('lensRange');
        // Lens type, form and treatment are chosen first; the manufacturer's last delivery of
        // that lens (or any of its lenses) fills the index and diameter.
        $saved = OpticalProduct::whereNotNull('lens_specs')->latest('id')->get()->filter(fn ($p) => data_get($p->lens_specs, 'range') === $this->lensRange);
        $same = $saved->first(fn ($p) => data_get($p->lens_specs, 'design') === $this->lensDesign && data_get($p->lens_specs, 'coating') === $this->lensCoating
            && (string) data_get($p->lens_specs, 'form') === (string) \App\Support\Optical\LensOptions::storedForm($this->lensForm)) ?? $saved->first();
        if ($same) {
            $this->lensIndex = (string) $same->lens_specs['index'];
            $this->lensDiameter = (string) $same->lens_specs['diameter'];
        }
    }

    /** The lens form (Optical → Settings → Lens options); Standard is not written on stock. */
    public string $lensForm = \App\Support\Optical\LensOptions::STANDARD_FORM;

    /** A manufacturer added while receiving (managers). */
    public string $newManufacturer = '';

    public function addManufacturer(): void
    {
        $this->assertManager();
        $name = trim(preg_replace('/\s+/', ' ', $this->newManufacturer !== '' ? $this->newManufacturer : $this->lensRange));
        $this->resetErrorBag('newManufacturer');
        if ($name === '' || mb_strlen($name) > 100) { $this->addError('newManufacturer', 'Enter the manufacturer name, up to 100 characters.'); return; }
        $existing = \App\Models\OpticalLensOption::where('kind', 'manufacturer')->get()->first(fn ($option) => mb_strtolower($option->code) === mb_strtolower($name) || mb_strtolower($option->name) === mb_strtolower($name));
        if ($existing && ! $existing->is_active) $existing->update(['is_active' => true]);
        $option = $existing ?? \App\Models\OpticalLensOption::create(['kind' => 'manufacturer', 'code' => $name, 'name' => $name, 'is_active' => true,
            'sort_order' => (int) \App\Models\OpticalLensOption::where('kind', 'manufacturer')->max('sort_order') + 1]);
        if (! $existing) \App\Models\AuditTrail::record('optical.lens_manufacturer_created', 'Added lens manufacturer '.$name, $option, [], ['name' => $name]);
        \App\Support\Optical\LensOptions::forget();
        $this->rangesThisRequest = null;
        $this->newManufacturer = '';
        $this->lensRange = $option->code;
        $this->updatedLensRange();
    }

    public function updatedLensForm(): void
    {
        $this->updatedLensRange();
    }

    public function updatedLensDesign(): void
    {
        $this->lensPower = $this->lensDesign === 'Single Vision' ? '0.00' : '1.00';
        if (! \App\Support\LensDesign::isEyeSpecific($this->lensDesign)) $this->lensEye = '';
        // Forms belong to a lens type: one that doesn't fit the new type goes back to Standard.
        if (! array_key_exists($this->lensForm, \App\Support\Optical\LensOptions::choices('form', null, $this->lensDesign))) $this->lensForm = \App\Support\Optical\LensOptions::STANDARD_FORM;
        $this->importedSummary = '';
        $this->importSource = [];
        $this->bulkQuantities = [];
        $this->bulkCosts = [];
        $this->bulkPrices = [];
    }

    private function lensSpecs($sphere, $power, ?string $eye = null): array
    {
        $specs = ['range' => trim($this->lensRange), 'design' => $this->lensDesign,
            'index' => $this->lensIndex, 'coating' => $this->lensCoating,
            'diameter' => (int) $this->lensDiameter,
            'sphere' => number_format((float) $sphere, 2, '.', ''),
            'power' => number_format((float) $power, 2, '.', '')];
        // A form other than Standard (e.g. invisible bifocal) is separate stock.
        if ($form = \App\Support\Optical\LensOptions::storedForm($this->lensForm)) $specs['form'] = $form;
        // Progressive and bifocal lenses are made per eye and stocked per eye.
        if (\App\Support\LensDesign::isEyeSpecific($this->lensDesign)) $specs['eye'] = $eye ?? $this->lensEye;
        return $specs;
    }

    private function eyeRule(): string
    {
        // B = both eyes: quantities are pairs, each adding one right and one left lens.
        return \App\Support\LensDesign::isEyeSpecific($this->lensDesign) ? 'required|in:R,L,B' : 'nullable';
    }

    /** The price list for the range being received, if the clinic has set one. */
    public function rangePriceList(): ?\App\Models\OpticalLensPrice
    {
        if (trim($this->lensRange) === '') return null;
        return app(\App\Services\OpticalLensPriceList::class)->find($this->lensSpecs(0, 0));
    }

    /** A progressive or bifocal pair is one right and one left lens, so pairs need "Both eyes". */
    private function eyeMatchesUnit(string $unit): bool
    {
        if (! \App\Support\LensDesign::isEyeSpecific($this->lensDesign)) return true;
        if ($unit === 'pairs' && $this->lensEye !== 'B') {
            $this->addError('lensEye', 'A '.strtolower($this->lensDesign).' pair is one right and one left lens. Choose "Both eyes (pairs)", or switch to individual pieces for a single-eye sheet.');
            return false;
        }
        if ($unit === 'pieces' && $this->lensEye === 'B') {
            $this->addError('lensEye', 'Choose Right or Left for individual pieces, or switch the quantities to pairs for both eyes.');
            return false;
        }
        return true;
    }

    /** Receipt lines for one grid cell: both-eye pairs become a right and a left line. */
    private function eyeLines(array $line): array
    {
        if ($this->lensEye !== 'B' || ! \App\Support\LensDesign::isEyeSpecific($this->lensDesign)) return [$line];
        return [array_replace($line, [5 => 'R']), array_replace($line, [5 => 'L'])];
    }

    /** Pairs of an eye-specific design always mean both eyes; pieces need a chosen eye. */
    private function syncEyeToUnit(): void
    {
        if ($this->excelUnit === 'pairs') $this->lensEye = 'B';
        elseif ($this->excelUnit === 'pieces' && $this->lensEye === 'B') $this->lensEye = '';
    }

    public function updatedExcelUnit(): void
    {
        if (\App\Support\LensDesign::isEyeSpecific($this->lensDesign)) $this->syncEyeToUnit();
    }

    public function updatedLensEye(): void
    {
        if (! $this->excelSheets || ! \App\Support\LensDesign::isEyeSpecific($this->lensDesign)) return;
        $this->excelUnit = $this->lensEye === 'B' ? 'pairs' : 'pieces';
    }

    public function updated($property): void
    {
        if (in_array($property, ['excelSheet', 'excelHeaderRow', 'excelSphereColumn', 'lensDesign', 'excelUnit', 'excelLayout'], true)) $this->excelPreview = [];
        if (in_array($property, ['excelSheet', 'excelUnit', 'excelLayout', 'lensDesign'], true)) $this->excelWarningsAccepted = false;
        if (in_array($property, ['lensRange', 'lensDesign', 'lensIndex', 'lensCoating', 'lensDiameter', 'lensSphere', 'lensPower', 'lensEye'], true)) {
            $specs = $this->lensSpecs($this->lensSphere, $this->lensPower);
            ksort($specs);
            $product = OpticalProduct::where('lens_key', hash('sha256', json_encode($specs)))->first();
            // Show an existing SKU's price; clear it only if it was filled from
            // another SKU, so a price typed for a new power is kept.
            if ($product) $this->unitPrice = (string) $product->selling_price;
            elseif ($this->priceFromProduct) $this->unitPrice = '';
            $this->priceFromProduct = (bool) $product;
            $this->updateSellingPrice = false;
        }
        if ($property === 'unitPrice') $this->priceFromMarkup = '';
        if (in_array($property, ['unitCost', 'lensDesign'], true)) $this->suggestPriceFromMarkup();
    }

    /** Set when the selling price came from the lens category's markup: "+40% (Single Vision Lenses)". */
    public string $priceFromMarkup = '';

    /**
     * An empty selling price (or one this filled in) follows the cost plus the markup of the
     * category these lenses are filed in. A price typed by staff is never replaced.
     */
    private function suggestPriceFromMarkup(): void
    {
        if ($this->stockType !== 'lens' || ($this->unitPrice !== '' && $this->priceFromMarkup === '') || ! is_numeric($this->unitCost)) return;
        $category = \App\Models\OpticalCategory::stockLensCategory($this->lensDesign);
        $price = $category?->suggestedPrice((float) $this->unitCost);
        if ($price === null) return;
        $this->unitPrice = number_format($price, 2, '.', '');
        $this->priceFromMarkup = '+'.rtrim(rtrim(number_format((float) $category->default_markup, 2, '.', ''), '0'), '.').'% ('.$category->name.')';
    }

    public function pasteGrid(): void
    {
        $this->assertManager();
        $this->validate(['bulkStart' => 'required|numeric|between:-15,15|multiple_of:0.25', 'bulkPaste' => 'required|string|max:50000']);
        $rows = preg_split('/\r\n|\n|\r/', trim($this->bulkPaste));
        $columns = $this->lensDesign === 'Single Vision' ? range(0, -6, -0.25) : range(0.25, 4, 0.25);
        $pending = [];
        foreach ($rows as $r => $row) {
            $sphere = (float) $this->bulkStart + $r * 0.25;
            if ($sphere > 15) { $this->addError('bulkPaste', 'Pasted rows exceed SPH +15.00.'); return; }
            $cells = explode("\t", $row);
            if (count($cells) > count($columns)) { $this->addError('bulkPaste', 'Too many columns. Paste quantities only, aligned with the grid headings.'); return; }
            foreach ($cells as $c => $value) {
                $value = trim($value);
                if ($value !== '' && (! ctype_digit($value) || (int) $value > 100000)) { $this->addError('bulkPaste', 'Use whole quantities from 0 to 100000.'); return; }
                $pending[(int) round(($sphere + 15) * 4)][$c] = $value;
            }
        }
        foreach ($pending as $r => $cells) foreach ($cells as $c => $value) $this->bulkQuantities[$r][$c] = $value;
        $this->bulkPaste = '';
    }

    private function saveLensReceipt(): void
    {
        if ($this->excelFile) { $this->addError('excelFile', 'Preview and apply the uploaded workbook, or cancel the import before receiving stock.'); return; }
        // Also covers a range filled from a workbook or a link, which skips updatedLensRange.
        $this->lensRange = $this->canonicalRange($this->lensRange);
        $this->validate([
            // Lens type, form, treatment and manufacturer come from Optical → Settings → Lens options.
            'lensRange' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Optical\LensOptions::choices('manufacturer')))],
            'lensDesign' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Optical\LensOptions::choices('design')))],
            'lensForm' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Optical\LensOptions::choices('form', null, $this->lensDesign)))],
            'lensEye' => $this->eyeRule(),
            'lensIndex' => 'required|in:1.50,1.56,1.60,1.61,1.67,1.74',
            // Designs and treatments shown under Optical → Settings → Lens options.
            'lensCoating' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Optical\LensOptions::choices('treatment')))],
            'lensDiameter' => 'required|integer|between:40,100',
            'unitCost' => 'required|numeric|min:0|max:99999999',
            'unitPrice' => ($this->rangePriceList() ? 'nullable' : 'required').'|numeric|min:0|max:99999999',
            'supplier' => 'required|string|max:180', 'reference' => 'nullable|string|max:100',
            'batchNumber' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000',
            'bulkCosts.*.*' => 'nullable|numeric|min:0|max:99999999', 'bulkPrices.*.*' => 'nullable|numeric|min:0|max:99999999',
            'productId' => 'nullable|integer',
            'entryMode' => 'required|in:single,bulk', 'updateSellingPrice' => 'boolean',
            'repeatDeliveryReason' => 'nullable|string|max:1000',
        ], [
            'lensRange.required' => 'Choose the manufacturer.',
            'lensRange.in' => '“'.$this->lensRange.'” is not in your manufacturers. Add it as a new manufacturer, or choose one from the list.',
            'lensForm.in' => 'Choose a form for this lens type.',
        ]);
        if ($this->importSource && $this->entryMode === 'bulk' && ! $this->eyeMatchesUnit($this->importSource['unit'])) return;
        $lines = [];
        if ($this->entryMode === 'single') {
            $this->validate(['lensSphere' => 'required|numeric|between:-15,15|multiple_of:0.25',
                'lensPower' => $this->lensDesign === 'Single Vision' ? 'required|numeric|between:-6,0|multiple_of:0.25' : 'required|numeric|between:0.25,4|multiple_of:0.25',
                'quantity' => 'required|integer|min:1|max:100000']);
            array_push($lines, ...$this->eyeLines([$this->lensSphere, $this->lensPower, (int) $this->quantity, $this->unitCost, $this->unitPrice]));
        } else {
            $this->validate(['bulkQuantities' => 'required|array', 'bulkQuantities.*' => 'array', 'bulkQuantities.*.*' => 'nullable|integer|min:0|max:100000']);
            $columns = $this->lensDesign === 'Single Vision' ? range(0, -6, -0.25) : range(0.25, 4, 0.25);
            foreach ($this->bulkQuantities as $r => $cells) {
                abort_unless(ctype_digit((string) $r) && $r <= 120, 422);
                foreach ($cells as $c => $quantity) {
                    abort_unless(ctype_digit((string) $c) && array_key_exists($c, $columns), 422);
                    if ((int) $quantity > 0) array_push($lines, ...$this->eyeLines([-15 + $r * 0.25, $columns[$c], (int) $quantity, ($this->bulkCosts[$r][$c] ?? '') !== '' ? $this->bulkCosts[$r][$c] : $this->unitCost, ($this->bulkPrices[$r][$c] ?? '') !== '' ? $this->bulkPrices[$r][$c] : $this->unitPrice]));
                }
            }
            if (! $lines) { $this->addError('bulkQuantities', 'Enter at least one received quantity.'); return; }
        }
        if ($this->importSource && $this->entryMode === 'bulk') {
            app(\App\Services\OpticalLensImportReceiptService::class)->receive($this->importSource, $this->lensSpecs(0, 0), $lines, [
                'supplier' => trim($this->supplier), 'reference' => trim($this->reference) ?: null,
                'batch_number' => trim($this->batchNumber) ?: null,
                'notes' => trim($this->notes."\nExcel: ".$this->importedSummary),
            ], $this->updateSellingPrice, $this->repeatDeliveryReason);
        } else {
            $details = fn ($cost, $price) => [
                'unit_cost' => round((float) $cost, 2), 'unit_price' => round((float) $price, 2),
                'supplier' => trim($this->supplier), 'reference' => trim($this->reference) ?: null,
                'batch_number' => trim($this->batchNumber) ?: null, 'notes' => trim($this->notes.($this->importedSummary ? "\nExcel: ".$this->importedSummary : '')) ?: null,
            ];
            if ($this->entryMode === 'single') \Illuminate\Support\Facades\DB::transaction(function () use ($lines, $details) {
                foreach ($lines as $line) {
                    [$sphere, $power, $quantity, $cost, $price] = $line;
                    app(\App\Services\OpticalLensReceivingService::class)->receive($this->lensSpecs($sphere, $power, $line[5] ?? null), $quantity, $details($cost, $price), $this->updateSellingPrice, $this->productId);
                }
            });
            // A grid can hold a whole supplier sheet, so it is saved in one batch.
            else app(\App\Services\OpticalLensReceivingService::class)->receiveMany(array_map(fn ($line) => [
                'specs' => $this->lensSpecs($line[0], $line[1], $line[5] ?? null), 'quantity' => $line[2], 'details' => $details($line[3], $line[4]),
            ], $lines), $this->updateSellingPrice);
        }
        $this->showForm = false;
        $this->resetPage();
        session()->flash('success', 'Lens stock received. The power matrix and stock ledger now show the same inventory.');
    }

    public string $search = '';
    public string $typeFilter = '';
    public bool $showForm = false;
    public string $formType = 'receipt';
    public string $productSearch = '';
    public ?int $productId = null;
    public string $quantity = '';
    public string $unitCost = '';
    public string $unitPrice = '';
    public string $supplier = '';
    public string $batchNumber = '';
    /** For stock that expires (contact lenses, solutions): feeds the expiry reminders. */
    public string $expiryDate = '';
    public string $reference = '';
    public string $notes = '';
    public string $adjustmentDirection = 'add';
    public string $adjustmentReason = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedProductSearch(): void { $this->productId = null; }

    private function assertManager(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        app(ClinicAccessService::class)->assertWritable('optical');
    }

    public function openReceipt(): void { $this->openForm('receipt'); }
    public function openAdjustment(): void { $this->openForm('adjustment'); }

    private function openForm(string $type): void
    {
        $this->assertManager();
        $this->reset(['productSearch', 'productId', 'quantity', 'unitCost', 'unitPrice', 'supplier', 'batchNumber', 'expiryDate', 'reference', 'notes', 'adjustmentReason']);
        $this->reset(['excelFile', 'excelPreview', 'excelSheets', 'excelOriginalSpecs', 'excelUnit', 'excelWarningsAccepted', 'importedSummary', 'stockType', 'entryMode', 'bulkQuantities', 'bulkCosts', 'bulkPrices', 'bulkPaste', 'updateSellingPrice']);
        $this->reset(['importSource', 'repeatDeliveryReason']);
        $this->formType = $type;
        $this->adjustmentDirection = 'add';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function selectProduct(int $id): void
    {
        $this->assertManager();
        $product = OpticalProduct::where('is_active', true)->findOrFail($id);
        $this->productId = $product->id;
        $this->productSearch = $product->sku.' — '.$product->name;
        if ($this->stockType === 'lens' && $product->lens_specs) {
            $specs = $product->lens_specs;
            $this->lensRange = $specs['range']; $this->lensDesign = $specs['design'];
            $this->lensIndex = $specs['index']; $this->lensCoating = $specs['coating'];
            $this->lensDiameter = (string) $specs['diameter'];
            $this->lensSphere = $specs['sphere']; $this->lensPower = $specs['power'];
            $this->lensEye = (string) ($specs['eye'] ?? '');
        }
        $this->unitCost = (string) $product->cost_price;
        $this->unitPrice = (string) $product->selling_price;
        $this->priceFromProduct = true;
        $this->resetValidation('productId');
    }

    public function save(): void
    {
        $this->assertManager();
        $this->validate(['stockType' => 'in:lens,frame,other', 'formType' => 'in:receipt,adjustment']);
        if ($this->stockType === 'lens' && $this->formType === 'receipt') { $this->saveLensReceipt(); return; }
        $this->validate([
            'productId' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:100000000',
            'notes' => 'nullable|string|max:2000',
            'formType' => 'required|in:receipt,adjustment',
        ]);
        $product = OpticalProduct::where('is_active', true)->findOrFail($this->productId);
        if ($this->formType === 'receipt') {
            $this->validate([
                'unitCost' => 'required|numeric|min:0|max:9999999999',
                'unitPrice' => 'required|numeric|min:0|max:9999999999|gte:unitCost',
                'supplier' => 'nullable|string|max:180',
                'batchNumber' => 'nullable|string|max:100',
                'expiryDate' => 'nullable|date|after:2000-01-01',
                'reference' => 'nullable|string|max:100',
            ]);
            app(OpticalStockLedgerService::class)->receive($product, (int) $this->quantity, [
                'expiry_date' => $this->expiryDate ?: null,
                'unit_cost' => round((float) $this->unitCost, 2),
                'unit_price' => round((float) $this->unitPrice, 2),
                'supplier' => trim($this->supplier) ?: null,
                'batch_number' => trim($this->batchNumber) ?: null,
                'reference' => trim($this->reference) ?: null,
                'notes' => trim($this->notes) ?: null,
            ]);
        } else {
            $this->validate([
                'adjustmentDirection' => 'required|in:add,remove',
                'adjustmentReason' => 'required|string|min:3|max:120',
            ]);
            $change = (int) $this->quantity * ($this->adjustmentDirection === 'add' ? 1 : -1);
            app(OpticalStockLedgerService::class)->adjust($product, $change, trim($this->adjustmentReason), trim($this->notes) ?: null);
        }
        $this->showForm = false;
        $this->resetPage();
        session()->flash('success', 'Optical stock movement recorded.');
    }

    public function reverse(int $id): void
    {
        $this->assertManager();
        app(OpticalStockLedgerService::class)->reverse($id);
        session()->flash('success', 'Stock movement reversed with a new ledger entry.');
    }

    /** What the receipt form needs, shared by the modal and the full lens receiving page. */
    protected function receiptViewData(): array
    {
        return [
            'fullPage' => $this->fullPage,
            'duplicateImport' => $this->importSource ? app(\App\Services\OpticalLensImportReceiptService::class)
                ->matches(app(\App\Services\OpticalLensImportReceiptService::class)->fingerprint($this->lensSpecs(0, 0), $this->importSource['quantities']))->latest('id')->first() : null,
            'lensRanges' => $this->knownRanges(),
            'productMatches' => $this->showForm && $this->productId === null
                ? OpticalProduct::where('is_active', true)
                    ->when(trim($this->productSearch) !== '', fn ($query) => $query->where(fn ($q) => $q
                        ->where('sku', 'like', '%'.trim($this->productSearch).'%')
                        ->orWhere('name', 'like', '%'.trim($this->productSearch).'%')))
                    ->orderBy('name')->limit(8)->get()
                : collect(),
            'selectedProduct' => $this->productId ? OpticalProduct::find($this->productId) : null,
        ];
    }

    public function render()
    {
        $term = trim($this->search);
        $movements = OpticalProductStockMovement::with(['product', 'user', 'reversedBy'])
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('reference', 'like', '%'.$term.'%')
                ->orWhere('supplier', 'like', '%'.$term.'%')
                ->orWhere('batch_number', 'like', '%'.$term.'%')
                ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('movement_type', $this->typeFilter))
            ->latest('id')->paginate(15);

        $products = OpticalProduct::where('is_active', true)->with('stocks')->get();
        $recentReceipt = OpticalProductStockMovement::where('movement_type', 'receipt')->whereDoesntHave('reversedBy')->latest('id')->first();

        return view('livewire.optical.optical-stock-management-component', $this->receiptViewData() + [
            'imports' => \App\Models\OpticalLensImport::with('user')->withCount([
                'movements as receipt_lines' => fn ($q) => $q->where('movement_type', 'receipt'),
                'movements as active_lines' => fn ($q) => $q->where('movement_type', 'receipt')->whereDoesntHave('reversedBy'),
            ])->when(trim($this->importSearch) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('filename', 'like', '%'.trim($this->importSearch).'%')
                ->orWhere('worksheet', 'like', '%'.trim($this->importSearch).'%')
                ->orWhere('reference', 'like', '%'.trim($this->importSearch).'%')
                ->orWhere('supplier', 'like', '%'.trim($this->importSearch).'%')))->latest('id')->paginate(10, ['*'], 'importsPage'),
            'importDetail' => $this->viewImportId ? \App\Models\OpticalLensImport::with(['user', 'movements.product', 'movements.reversedBy'])->findOrFail($this->viewImportId) : null,
            'movements' => $movements,
            'totalStock' => $products->sum(fn ($p) => $p->stocks->first()?->quantity ?? 0),
            'receivedToday' => OpticalProductStockMovement::where('movement_type', 'receipt')->whereDoesntHave('reversedBy')->whereDateIndexed('created_at', today())->sum('quantity_change'),
            'recentReceipt' => $recentReceipt,
            'lowStockCount' => $products->filter(fn ($p) => ($p->stocks->first()?->quantity ?? 0) <= ($p->lens_specs ? ($p->stocks->first()?->lens_reorder_pairs ?? 5) * 2 : ($p->stocks->first()?->reorder_level ?? 5)))->count(),
        ])->layout('layouts.optical');
    }
}
