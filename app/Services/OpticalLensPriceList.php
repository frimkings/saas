<?php

namespace App\Services;

use App\Models\OpticalLensPrice;
use App\Models\OpticalProduct;

/**
 * Lens selling prices are set per pair for a range and applied to every power in it.
 * The highest matching exception wins over the range price, and one lens sells at half
 * the pair price. Prices are copied onto each lens item, so orders and quotes keep the
 * price they were created with.
 */
class OpticalLensPriceList
{
    /** @var array<string, OpticalLensPrice|null> */
    private array $cache = [];

    public function rangeSpecs(array $specs): array
    {
        $range = [
            'range' => trim((string) ($specs['range'] ?? '')), 'design' => (string) ($specs['design'] ?? ''),
            'index' => (string) ($specs['index'] ?? ''), 'coating' => (string) ($specs['coating'] ?? ''),
            'diameter' => (int) ($specs['diameter'] ?? 0),
        ];
        // A form other than Standard is its own range; Standard adds nothing, so older lists keep their key.
        if (trim((string) ($specs['form'] ?? '')) !== '') $range['form'] = trim((string) $specs['form']);
        ksort($range);
        return $range;
    }

    public function rangeKey(array $specs): string
    {
        return hash('sha256', json_encode($this->rangeSpecs($specs)));
    }

    public function find(array $specs): ?OpticalLensPrice
    {
        $key = $this->rangeKey($specs);
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = OpticalLensPrice::with('rules')->where('range_key', $key)->first();
        }
        return $this->cache[$key];
    }

    public function pairPrice(OpticalLensPrice $list, float $sphere, float $power): float
    {
        $exception = $list->rules->filter(fn ($rule) => $rule->matches($sphere, $power))->max(fn ($rule) => (float) $rule->pair_price);
        return (float) ($exception ?? $list->pair_price);
    }

    /** Selling price of one lens for these specs, or null when the range has no price list. */
    public function lensPrice(array $specs): ?float
    {
        $list = $this->find($specs);
        if (! $list) return null;
        return round($this->pairPrice($list, (float) ($specs['sphere'] ?? 0), (float) ($specs['power'] ?? 0)) / 2, 2);
    }

    /** @return \Illuminate\Support\Collection<int, OpticalProduct> lens items in the range, every power and eye */
    public function rangeProducts(array $specs)
    {
        $range = $this->rangeSpecs($specs);
        return OpticalProduct::whereNotNull('lens_specs')->get()
            ->filter(fn ($product) => $this->rangeSpecs($product->lens_specs) === $range)->values();
    }

    /** Copy the list's prices onto every lens item in its range. Returns how many changed. */
    public function apply(OpticalLensPrice $list): int
    {
        $this->cache[$list->range_key] = $list->loadMissing('rules');
        $changed = 0;
        foreach ($this->rangeProducts($list->specs) as $product) {
            $price = $this->lensPrice($product->lens_specs);
            if ($price !== null && round((float) $product->selling_price, 2) !== $price) {
                $product->update(['selling_price' => $price]);
                $changed++;
            }
        }
        return $changed;
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
