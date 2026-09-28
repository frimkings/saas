<?php

namespace App\Livewire\Optical;

use App\Models\OpticalStockCount;
use App\Services\OpticalStockCountService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stock counts: start a count sheet, enter shelf quantities (blind by default), then a
 * manager reviews the differences and approves them into the ledger.
 */
class OpticalStockCountsComponent extends Component
{
    use WithPagination;

    public bool $showStartForm = false;
    public string $countScope = 'lens_range';
    public string $countRange = '';
    public string $countNotes = '';

    #[Locked]
    public ?int $viewCountId = null;
    public array $counts = [];
    public bool $showExpected = false;

    private function isManager(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
    }

    public function openStartForm(): void
    {
        abort_unless($this->isManager(), 403);
        $this->reset(['countScope', 'countRange', 'countNotes']);
        $this->closeCount();
        $this->showStartForm = true;
    }

    public function startCount(): void
    {
        $this->validate(['countScope' => 'required|in:'.implode(',', array_keys(OpticalStockCountService::SCOPES)), 'countNotes' => 'nullable|string|max:1000']);
        $service = app(OpticalStockCountService::class);
        $specs = null;
        if ($this->countScope === 'lens_range') {
            $specs = $service->lensRanges()->get((int) $this->countRange);
            if ($this->countRange === '' || ! $specs) { $this->addError('countRange', 'Choose the lens range to count.'); return; }
        }
        $count = $service->start($this->countScope, $specs, $this->countNotes);
        $this->showStartForm = false;
        $this->openCount($count->id);
        session()->flash('success', "Count {$count->count_number} started for {$count->title}. Expected quantities are fixed as of now.");
    }

    public function openCount(int $id): void
    {
        $count = OpticalStockCount::with('lines')->findOrFail($id);
        $this->viewCountId = $count->id;
        $this->counts = $count->lines->mapWithKeys(fn ($line) => [$line->id => $line->counted_quantity === null ? '' : (string) $line->counted_quantity])->all();
        $this->showExpected = false;
        $this->resetValidation();
    }

    /** Renderless: the browser has already closed the panel (dismissCall). */
    #[\Livewire\Attributes\Renderless]
    public function closeCount(): void
    {
        $this->viewCountId = null;
        $this->counts = [];
        $this->resetValidation();
    }

    public function toggleExpected(): void
    {
        abort_unless($this->isManager(), 403);
        $this->showExpected = ! $this->showExpected;
    }

    public function saveCounts(bool $submit = false): void
    {
        $service = app(OpticalStockCountService::class);
        $service->saveCounts((int) $this->viewCountId, $this->counts);
        if ($submit) {
            $service->submit((int) $this->viewCountId);
            session()->flash('success', 'Count submitted for manager approval.');
        } else {
            session()->flash('success', 'Counts saved. You can carry on later.');
        }
        $this->openCount((int) $this->viewCountId);
    }

    public function reopen(): void
    {
        app(OpticalStockCountService::class)->reopen((int) $this->viewCountId);
        $this->openCount((int) $this->viewCountId);
        session()->flash('success', 'Count sent back for recounting.');
    }

    public function approve(): void
    {
        $count = app(OpticalStockCountService::class)->approve((int) $this->viewCountId);
        $this->openCount($count->id);
        session()->flash('success', "Count {$count->count_number} approved. Stock corrected for every counted difference.");
    }

    public function cancel(): void
    {
        $count = app(OpticalStockCountService::class)->cancel((int) $this->viewCountId);
        $this->openCount($count->id);
        session()->flash('success', "Count {$count->count_number} cancelled. No stock was changed.");
    }

    public function render()
    {
        $service = app(OpticalStockCountService::class);
        $count = $this->viewCountId ? OpticalStockCount::with(['lines.product', 'creator', 'approver'])->find($this->viewCountId) : null;
        $grid = null;
        if ($count && $count->scope === 'lens_range') {
            // Lay lens lines out like the stock grid: SPH rows × CYL/ADD columns.
            $cells = $count->lines->mapWithKeys(fn ($line) => [sprintf('%.2f|%.2f', (float) data_get($line->product?->lens_specs, 'sphere'), (float) data_get($line->product?->lens_specs, 'power')) => $line]);
            $spheres = $cells->keys()->map(fn ($key) => (float) explode('|', $key)[0])->unique()->sortDesc()->values();
            $powers = $cells->keys()->map(fn ($key) => (float) explode('|', $key)[1])->unique()->sort()->values();
            if (($count->scope_specs['design'] ?? '') === 'Single Vision') $powers = $powers->sortDesc()->values();
            $grid = ['cells' => $cells, 'spheres' => $spheres, 'powers' => $powers, 'powerLabel' => ($count->scope_specs['design'] ?? '') === 'Single Vision' ? 'CYL' : 'ADD'];
        }

        return view('livewire.optical.optical-stock-counts-component', [
            'countsList' => OpticalStockCount::withCount(['lines', 'lines as counted_lines' => fn ($q) => $q->whereNotNull('counted_quantity')])->latest('id')->paginate(15),
            'count' => $count,
            'grid' => $grid,
            'moved' => $count && in_array($count->status, ['counting', 'submitted'], true) ? $service->movedSinceStart($count) : collect(),
            'lensRanges' => $this->showStartForm ? $service->lensRanges() : collect(),
            'isManager' => $this->isManager(),
            'rangeTitle' => fn (array $specs) => $service->title('lens_range', $specs),
        ])->layout('layouts.optical');
    }
}
