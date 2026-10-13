<?php

namespace App\Livewire\Optical;

use App\Models\OpticalLensPrice;
use App\Models\OpticalProduct;
use App\Services\ClinicAccessService;
use App\Services\OpticalLensPriceList;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Lens Catalogue → Lens prices. One price per pair for each stock lens range, with
 * higher prices for strong powers. Saving copies the prices onto every lens item in the
 * range; orders and quotes already created keep their prices.
 */
class LensPriceListComponent extends Component
{
    public ?string $editingKey = null;
    public string $pairPrice = '';
    /** @var array<int, array{min_sphere: string, min_power: string, pair_price: string}> */
    public array $rules = [];

    public function edit(string $key): void
    {
        $this->assertManager();
        $range = $this->ranges()->firstWhere('key', $key);
        abort_unless($range, 404);
        $this->editingKey = $key;
        $this->pairPrice = $range['list'] ? (string) $range['list']->pair_price : '';
        $this->rules = $range['list']
            ? $range['list']->rules->map(fn ($rule) => [
                'min_sphere' => $rule->min_sphere === null ? '' : (string) $rule->min_sphere,
                'min_power' => $rule->min_power === null ? '' : (string) $rule->min_power,
                'pair_price' => (string) $rule->pair_price,
            ])->all()
            : [];
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingKey', 'pairPrice', 'rules']);
        $this->resetValidation();
    }

    /** The form is closed in the browser (dismissLocal); tidy up when the server hears of it. */
    public function updatedEditingKey($value): void
    {
        if ($value === null) $this->cancel();
    }

    public function addRule(): void
    {
        $this->rules[] = ['min_sphere' => '', 'min_power' => '', 'pair_price' => ''];
    }

    public function removeRule(int $index): void
    {
        unset($this->rules[$index]);
        $this->rules = array_values($this->rules);
    }

    public function save(): void
    {
        $this->assertManager();
        $range = $this->ranges()->firstWhere('key', (string) $this->editingKey);
        abort_unless($range, 404);
        $this->validate([
            'pairPrice' => 'required|numeric|min:0|max:99999999',
            'rules' => 'array|max:20',
            'rules.*.min_sphere' => 'nullable|numeric|between:0,15|multiple_of:0.25',
            'rules.*.min_power' => 'nullable|numeric|between:0,6|multiple_of:0.25',
            'rules.*.pair_price' => 'required|numeric|min:0|max:99999999',
        ], [], ['pairPrice' => 'price per pair', 'rules.*.pair_price' => 'exception price', 'rules.*.min_sphere' => 'SPH', 'rules.*.min_power' => $this->powerLabel($range['specs'])]);
        foreach ($this->rules as $i => $rule) {
            if ($rule['min_sphere'] === '' && $rule['min_power'] === '') {
                $this->addError("rules.$i.min_sphere", 'Enter a SPH and/or '.$this->powerLabel($range['specs']).' from which this price applies.');
                return;
            }
        }

        $service = app(OpticalLensPriceList::class);
        $changed = DB::transaction(function () use ($range, $service) {
            $describe = fn ($list) => $list ? ['pair_price' => round((float) $list->pair_price, 2), 'exceptions' => $list->rules->map(fn ($rule) => $this->ruleLabel($rule))->all()] : [];
            $before = $describe($range['list']);
            $list = OpticalLensPrice::updateOrCreate(['range_key' => $range['key']], [
                'specs' => $range['specs'], 'pair_price' => round((float) $this->pairPrice, 2), 'updated_by' => auth()->id(),
            ]);
            $list->rules()->delete();
            foreach ($this->rules as $rule) {
                $list->rules()->create([
                    'min_sphere' => $rule['min_sphere'] === '' ? null : abs((float) $rule['min_sphere']),
                    'min_power' => $rule['min_power'] === '' ? null : abs((float) $rule['min_power']),
                    'pair_price' => round((float) $rule['pair_price'], 2),
                ]);
            }
            $service->forget();
            $changed = $service->apply($list->fresh('rules'));
            \App\Models\AuditTrail::record('optical.lens_price_list_saved', 'Lens price list for '.implode(' ', array_filter([$range['specs']['range'] ?? '', $range['specs']['design'] ?? '', $range['specs']['index'] ?? '', $range['specs']['coating'] ?? '']))
                ." set, {$changed} lens ".($changed === 1 ? 'power' : 'powers').' repriced', $list, $before, $describe($list->fresh('rules')) + ['powers_repriced' => $changed]);
            return $changed;
        });

        $this->cancel();
        session()->flash('lens_price_message', "Saved. {$changed} lens ".($changed === 1 ? 'power' : 'powers').' repriced. Orders and quotes already created keep their prices.');
    }

    /** An exception as staff read it, e.g. "SPH 4.00+ / power 2.00+: 300.00". */
    private function ruleLabel($rule): string
    {
        $from = array_filter([$rule->min_sphere !== null ? 'SPH '.number_format((float) $rule->min_sphere, 2).'+' : null,
            $rule->min_power !== null ? 'power '.number_format((float) $rule->min_power, 2).'+' : null]);
        return implode(' / ', $from).': '.number_format((float) $rule->pair_price, 2);
    }

    /** Stock lens ranges with their price list, if any. */
    private function ranges()
    {
        $service = app(OpticalLensPriceList::class);
        $lists = OpticalLensPrice::with('rules')->get()->keyBy('range_key');
        return OpticalProduct::with('stocks')->whereNotNull('lens_specs')->get()
            ->filter(fn ($product) => data_get($product->lens_specs, 'sphere') !== null)
            ->groupBy(fn ($product) => $service->rangeKey($product->lens_specs))
            ->map(function ($products, $key) use ($service, $lists) {
                $specs = $service->rangeSpecs($products->first()->lens_specs);
                $prices = $products->pluck('selling_price')->map(fn ($p) => (float) $p);
                return [
                    'key' => $key, 'specs' => $specs, 'list' => $lists->get($key),
                    'powers' => $products->count(),
                    'stock' => (int) $products->sum(fn ($product) => $product->stocks->sum('quantity')),
                    'min' => $prices->min() * 2, 'max' => $prices->max() * 2,
                ];
            })
            ->sortBy(fn ($range) => $range['specs']['range'].' '.$range['specs']['design'])->values();
    }

    private function powerLabel(array $specs): string
    {
        return ($specs['design'] ?? '') === 'Single Vision' ? 'CYL' : 'ADD';
    }

    private function assertManager(): void
    {
        abort_unless($this->canManage(), 403);
        app(ClinicAccessService::class)->assertWritable('optical');
    }

    private function canManage(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
    }

    public function render()
    {
        $ranges = $this->ranges();
        return view('livewire.optical.lens-price-list-component', [
            'ranges' => $ranges, 'canManage' => $this->canManage(),
            'editing' => $this->editingKey ? $ranges->firstWhere('key', $this->editingKey) : null,
        ]);
    }
}
