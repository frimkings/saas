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
            $category = OpticalCategory::firstOrCreate(['code' => 'stock-'.strtolower(str_replace(' ', '-', $specs['design']))], [
                'name' => $specs['design'].' Stock Lenses', 'is_active' => true, 'default_markup' => 0,
            ]);
            $product = OpticalProduct::firstOrCreate(['lens_key' => $key], [
                'optical_category_id' => $category->id, 'sku' => 'LENS-'.substr($key, 0, 24),
                'name' => substr($specs['range'].' '.$specs['design'].' '.$specs['coating'].' '.$specs['sphere'].' / '.$specs['power'].(isset($specs['eye']) ? ' '.$specs['eye'] : ''), 0, 180),
                'lens_specs' => $specs, 'cost_price' => $details['unit_cost'],
                'selling_price' => $details['unit_price'], 'is_active' => true,
            ]);
            $product = OpticalProduct::lockForUpdate()->findOrFail($product->id);
            abort_unless($product->is_active, 422, 'This lens stock item is inactive. Reactivate it before receiving.');
            if ($updatePrice) $product->update(['selling_price' => $details['unit_price']]);
            $details['unit_price'] = $product->selling_price;
            app(OpticalStockLedgerService::class)->receive($product, $quantity, $details);
            return $product;
        });
    }
}
