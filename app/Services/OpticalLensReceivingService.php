<?php

namespace App\Services;

use App\Models\OpticalCategory;
use App\Models\OpticalProduct;
use Illuminate\Support\Facades\DB;

class OpticalLensReceivingService
{
    public function receive(array $specs, int $quantity, array $details, bool $updatePrice = false, ?int $existingProductId = null): OpticalProduct
    {
        if (\App\Support\LensDesign::isEyeSpecific($specs['design'] ?? null)) {
            if (! in_array($specs['eye'] ?? null, ['R', 'L'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['lensEye' => 'Choose whether these '.strtolower($specs['design']).' lenses are for the right or left eye.']);
            }
        } else {
            unset($specs['eye']);
        }
        return DB::transaction(function () use ($specs, $quantity, $details, $updatePrice, $existingProductId) {
            ksort($specs);
            $key = hash('sha256', json_encode($specs));
            if ($existingProductId) {
                $existing = OpticalProduct::lockForUpdate()->findOrFail($existingProductId);
                if (($existing->lens_key && $existing->lens_key !== $key) || OpticalProduct::where('lens_key', $key)->where('id', '!=', $existingProductId)->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['productId' => 'This specification is already linked to another product, or the selected SKU has different lens specifications.']);
                }
                $existing->update(['lens_key' => $key, 'lens_specs' => $specs]);
            }
            // A range with a price list is always priced from it; receipts only record stock and cost.
            $listPrice = app(OpticalLensPriceList::class)->lensPrice($specs);
            $product = $this->firstOrCreateItem($specs, $key, (float) $details['unit_cost'], (float) ($listPrice ?? $details['unit_price']));
            $product = OpticalProduct::lockForUpdate()->findOrFail($product->id);
            abort_unless($product->is_active, 422, 'This lens stock item is inactive. Reactivate it before receiving.');
            // Last purchase cost: new lens orders record this cost; existing orders keep theirs.
            $product->update(['cost_price' => $details['unit_cost']]);
            if ($listPrice !== null) $product->update(['selling_price' => $listPrice]);
            elseif ($updatePrice) $product->update(['selling_price' => $details['unit_price']]);
            $details['unit_price'] = $product->selling_price;
            app(OpticalStockLedgerService::class)->receive($product, $quantity, $details);
            return $product;
        });
    }

    /**
     * The stock item for these lens specs, created without stock if needed. Used when a
     * progressive/bifocal pair is ordered for a power only one eye has been stocked in.
     */
    public function stockItem(array $specs, float $unitCost, float $unitPrice): OpticalProduct
    {
        if (! \App\Support\LensDesign::isEyeSpecific($specs['design'] ?? null)) unset($specs['eye']);
        ksort($specs);
        $listPrice = app(OpticalLensPriceList::class)->lensPrice($specs);
        return $this->firstOrCreateItem($specs, hash('sha256', json_encode($specs)), $unitCost, $listPrice ?? $unitPrice);
    }

    private function firstOrCreateItem(array $specs, string $key, float $unitCost, float $unitPrice): OpticalProduct
    {
        $category = OpticalCategory::firstOrCreate(['code' => 'stock-'.strtolower(str_replace(' ', '-', $specs['design']))], [
            'name' => $specs['design'].' Stock Lenses', 'is_active' => true, 'default_markup' => 0,
        ]);
        $existing = OpticalProduct::where('lens_key', $key)->first();
        if ($existing) return $existing;
        return OpticalProduct::create(['lens_key' => $key,
            'optical_category_id' => $category->id, 'sku' => $this->readableSku($specs, $key),
            'name' => substr($specs['range'].' '.$specs['design'].' '.$specs['coating'].' '.$specs['sphere'].' / '.$specs['power'].(isset($specs['eye']) ? ' '.$specs['eye'] : ''), 0, 180),
            'lens_specs' => $specs, 'cost_price' => $unitCost, 'selling_price' => $unitPrice, 'is_active' => true,
        ]);
    }

    /**
     * A SKU staff can read off a label: range, design, index, coating, SPH, CYL/ADD and eye,
     * with P/M for plus and minus, e.g. STPATBLU-SV-1.56-BLUECUT-M1.25-M0.50. A short hash is
     * added only when two ranges would otherwise share the same SKU.
     */
    private function readableSku(array $specs, string $key): string
    {
        $code = fn ($value, $length) => substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)), 0, $length);
        $signed = fn ($value) => ((float) $value > 0 ? 'P' : ((float) $value < 0 ? 'M' : '')).number_format(abs((float) $value), 2, '.', '');
        $design = ['Single Vision' => 'SV', 'Progressive' => 'PR', 'Bifocal' => 'BF'][$specs['design']] ?? $code(preg_replace('/\b(\w)\w*\s*/', '$1', $specs['design']), 4);
        $parts = array_filter([
            $code($specs['range'] ?? '', 8), $design ?: 'LENS', preg_replace('/[^0-9.]/', '', (string) ($specs['index'] ?? '')),
            $code($specs['coating'] ?? '', 8), $signed($specs['sphere']), $signed($specs['power']), $code($specs['eye'] ?? '', 1),
        ], fn ($part) => $part !== '');
        $sku = ltrim(implode('-', $parts), '.-');
        if (! preg_match('/^[A-Z0-9]/', $sku)) $sku = 'LENS-'.$sku;
        $sku = substr($sku, 0, 72);
        return OpticalProduct::withTrashed()->where('sku', $sku)->exists() ? $sku.'-'.strtoupper(substr($key, 0, 6)) : $sku;
    }
}
