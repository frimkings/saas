<?php

namespace App\Services;

use App\Models\OpticalProduct;
use App\Models\OpticalPurchaseOrderLine;
use App\Support\LensDesign;
use Illuminate\Support\Collection;

/**
 * Turns lens powers picked on the stock grid into supplier order lines, always in pairs.
 * A single vision pair is two lenses of one power; a progressive or bifocal pair is one
 * right and one left lens. Only powers that have been stocked before can be ordered.
 */
class OpticalLensOrderPlanner
{
    public function key(float $sphere, float $power): string
    {
        return sprintf('%.2f|%.2f', $sphere, $power);
    }

    /** @return Collection<string, Collection<int, OpticalProduct>> active stock items in the range, grouped by "sphere|power" */
    public function itemsByPower(array $rangeSpecs): Collection
    {
        return app(OpticalLensPriceList::class)->rangeProducts($rangeSpecs)
            ->where('is_active', true)
            ->groupBy(fn ($product) => $this->key((float) data_get($product->lens_specs, 'sphere'), (float) data_get($product->lens_specs, 'power')));
    }

    /**
     * Pairs still due on sent purchase orders, per "sphere|power". Eye-specific lenses count as
     * pairs only where both eyes are due, so a lone eye still shows as its lens count.
     *
     * @return array<string, int>
     */
    public function onOrderPairs(array $rangeSpecs): array
    {
        $items = $this->itemsByPower($rangeSpecs);
        $productIds = $items->flatten()->pluck('id');
        if ($productIds->isEmpty()) return [];
        $due = OpticalPurchaseOrderLine::whereIn('optical_product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', ['ordered', 'partially_received']))
            ->get()->groupBy('optical_product_id')
            ->map(fn ($lines) => (int) $lines->sum(fn ($line) => max(0, $line->quantity_ordered - $line->quantity_received)));
        $eyeSpecific = LensDesign::isEyeSpecific($rangeSpecs['design'] ?? null);
        $result = [];
        foreach ($items as $key => $products) {
            if ($eyeSpecific) {
                $byEye = $products->groupBy(fn ($p) => data_get($p->lens_specs, 'eye'))->map(fn ($eyeProducts) => $eyeProducts->sum(fn ($p) => $due[$p->id] ?? 0));
                $pairs = max($byEye->get('R', 0), $byEye->get('L', 0));
            } else {
                $pairs = intdiv((int) $products->sum(fn ($p) => $due[$p->id] ?? 0) + 1, 2);
            }
            if ($pairs > 0) $result[$key] = $pairs;
        }
        return $result;
    }

    /**
     * Draft order lines for the chosen powers: one line per power, quantity in pairs, cost per
     * pair from the last purchase cost. A power never stocked in this range is costed at the
     * range's typical lens cost; its product is created only when the order is saved (expand).
     *
     * @param  array<string, int>  $pairsByKey  "sphere|power" => pairs to order
     * @return array<int, array{lens_pairs: true, key: string, product_ids: int[], label: string, pairs: int, pair_cost: string, new_power?: array}>
     */
    public function draftLines(array $rangeSpecs, array $pairsByKey): array
    {
        $items = $this->itemsByPower($rangeSpecs);
        $eyeSpecific = LensDesign::isEyeSpecific($rangeSpecs['design'] ?? null);
        $range = app(OpticalLensPriceList::class)->rangeSpecs($rangeSpecs);
        $lines = [];
        foreach ($pairsByKey as $key => $pairs) {
            $pairs = (int) $pairs;
            if ($pairs < 1 || ! $this->validKey($key, $range['design'] ?? null)) continue;
            $products = $items->get($key);
            [$sphere, $power] = explode('|', $key);
            if (! $products || $products->isEmpty()) {
                $typical = $this->typicalLensCost($items);
                $lines[] = [
                    'lens_pairs' => true, 'key' => $key, 'product_ids' => [], 'pairs' => $pairs,
                    'pair_cost' => number_format($typical['cost'] * 2, 2, '.', ''),
                    'new_power' => ['range' => $range, 'sphere' => $sphere, 'power' => $power, 'cost' => $typical['cost'], 'price' => $typical['price']],
                    'label' => $this->label($range, (float) $sphere, (float) $power).' · new power',
                ];
                continue;
            }
            if ($eyeSpecific) {
                $eyes = [];
                foreach (['R', 'L'] as $eye) {
                    $eyes[$eye] = $products->first(fn ($p) => data_get($p->lens_specs, 'eye') === $eye)
                        ?? app(OpticalLensReceivingService::class)->stockItem($range + ['sphere' => $sphere, 'power' => $power, 'eye' => $eye],
                            (float) $products->first()->cost_price, (float) $products->first()->selling_price);
                }
                $ids = [$eyes['R']->id, $eyes['L']->id];
                $pairCost = (float) $eyes['R']->cost_price + (float) $eyes['L']->cost_price;
            } else {
                $item = $products->first();
                $ids = [$item->id];
                $pairCost = 2 * (float) $item->cost_price;
            }
            $lines[] = [
                'lens_pairs' => true, 'key' => $key, 'product_ids' => $ids, 'pairs' => $pairs, 'pair_cost' => number_format($pairCost, 2, '.', ''),
                'label' => $this->label($range, (float) $sphere, (float) $power),
            ];
        }
        return $lines;
    }

    /** Supplier order lines (in lenses) for a pair line: 2 lenses per single vision pair, 1 per eye otherwise. */
    public function expand(array $line): array
    {
        $ids = array_values(array_map('intval', $line['product_ids'] ?? []));
        // A power new to this range gets its stock item(s) now that the order is being saved.
        if ($ids === [] && is_array($new = $line['new_power'] ?? null)) {
            $range = app(OpticalLensPriceList::class)->rangeSpecs((array) ($new['range'] ?? []));
            abort_unless($this->validKey(((string) ($new['sphere'] ?? '')).'|'.((string) ($new['power'] ?? '')), $range['design'] ?? null), 422);
            $eyes = LensDesign::isEyeSpecific($range['design'] ?? null) ? ['R', 'L'] : [null];
            $ids = array_map(fn ($eye) => app(OpticalLensReceivingService::class)->stockItem(
                $range + ['sphere' => sprintf('%.2f', (float) $new['sphere']), 'power' => sprintf('%.2f', (float) $new['power'])] + ($eye ? ['eye' => $eye] : []),
                (float) ($new['cost'] ?? 0), (float) ($new['price'] ?? 0),
            )->id, $eyes);
        }
        abort_if($ids === [], 422);
        $pairs = (int) $line['pairs'];
        $lensCost = round((float) $line['pair_cost'] / 2, 2);
        if (count($ids) === 1) return [['product_id' => $ids[0], 'quantity' => $pairs * 2, 'unit_cost' => $lensCost]];
        return array_map(fn ($id) => ['product_id' => $id, 'quantity' => $pairs, 'unit_cost' => $lensCost], $ids);
    }

    /** A real lens power: SPH -15 to +15 and CYL 0 to -6 (single vision) or ADD +0.25 to +4, in 0.25 steps. */
    public function validKey(string $key, ?string $design): bool
    {
        $parts = explode('|', $key);
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) return false;
        [$sphere, $power] = array_map('floatval', $parts);
        $quarter = fn ($value) => abs($value * 4 - round($value * 4)) < 0.00001;
        $powerOk = $design === 'Single Vision' ? $power <= 0 && $power >= -6 : $power >= 0.25 && $power <= 4;

        return $quarter($sphere) && $quarter($power) && $sphere >= -15 && $sphere <= 15 && $powerOk;
    }

    /** Typical cost and price of one lens in the range, for a power it has not stocked before. */
    public function typicalLensCost(Collection $itemsByPower): array
    {
        $products = $itemsByPower->flatten();
        $median = function (Collection $values): float {
            $sorted = $values->filter(fn ($v) => $v > 0)->sort()->values();
            if ($sorted->isEmpty()) return 0.0;
            $mid = intdiv($sorted->count(), 2);
            return round($sorted->count() % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2, 2);
        };

        return ['cost' => $median($products->map(fn ($p) => (float) $p->cost_price)), 'price' => $median($products->map(fn ($p) => (float) $p->selling_price))];
    }

    public function label(array $range, float $sphere, float $power): string
    {
        $powerLabel = ($range['design'] ?? '') === 'Single Vision' ? 'CYL' : 'ADD';
        return trim(($range['range'] ?? '').' '.$range['design'].' '.$range['index'].' '.$range['coating'])
            .' · SPH '.sprintf('%+.2f', $sphere).' '.$powerLabel.' '.sprintf('%+.2f', $power)
            .(LensDesign::isEyeSpecific($range['design'] ?? null) ? ' · R + L' : '');
    }
}
