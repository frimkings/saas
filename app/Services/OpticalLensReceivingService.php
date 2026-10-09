<?php

namespace App\Services;

use App\Models\OpticalCategory;
use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Models\OpticalProductStockMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class OpticalLensReceivingService
{
    public function receive(array $specs, int $quantity, array $details, bool $updatePrice = false, ?int $existingProductId = null): OpticalProduct
    {
        $specs = $this->normalizedSpecs($specs);
        return DB::transaction(function () use ($specs, $quantity, $details, $updatePrice, $existingProductId) {
            $key = hash('sha256', json_encode($specs));
            if ($existingProductId) {
                $existing = OpticalProduct::lockForUpdate()->findOrFail($existingProductId);
                if (($existing->lens_key && $existing->lens_key !== $key) || OpticalProduct::where('lens_key', $key)->where('id', '!=', $existingProductId)->exists()) {
                    throw ValidationException::withMessages(['productId' => 'This specification is already linked to another product, or the selected SKU has different lens specifications.']);
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
     * Receive many lens lines at once (a power grid or an Excel order). Each line is
     * ['specs' => [...], 'quantity' => int, 'details' => [...]] and is recorded as receive()
     * records it, but in a fixed number of queries: a full sheet is over a thousand lines,
     * which line by line ran past the hosted request timeout.
     */
    public function receiveMany(array $lines, bool $updatePrice = false): void
    {
        $prepared = [];
        foreach ($lines as $line) {
            if ((int) $line['quantity'] <= 0) throw ValidationException::withMessages(['quantity' => 'Quantity received must be greater than zero.']);
            $specs = $this->normalizedSpecs($line['specs']);
            $prepared[] = ['specs' => $specs, 'key' => hash('sha256', json_encode($specs)), 'quantity' => (int) $line['quantity'], 'details' => $line['details']];
        }
        if (! $prepared) return;

        DB::transaction(function () use ($prepared, $updatePrice) {
            $products = $this->itemsFor($prepared);
            $clinicId = (int) app(TenantContext::class)->clinicId();
            foreach ($products as $product) {
                abort_unless((int) $product->clinic_id === $clinicId, 404);
                abort_unless($product->is_active, 422, 'This lens stock item is inactive. Reactivate it before receiving.');
            }

            // Last purchase cost, and the price list (or the typed price when asked) as the selling price.
            $priceList = app(OpticalLensPriceList::class);
            foreach ($prepared as $i => $line) {
                $product = $products[$line['key']];
                $listPrice = $priceList->lensPrice($line['specs']);
                $product->cost_price = $line['details']['unit_cost'];
                if ($listPrice !== null) $product->selling_price = $listPrice;
                elseif ($updatePrice) $product->selling_price = $line['details']['unit_price'];
                $prepared[$i]['details']['unit_price'] = $product->selling_price;
            }
            // Items getting the same new prices are updated together.
            $changes = [];
            foreach ($products as $product) if ($product->isDirty()) $changes[json_encode($product->getDirty())][] = $product->id;
            foreach ($changes as $values => $ids) foreach (array_chunk($ids, 500) as $chunk) {
                OpticalProduct::whereKey($chunk)->update(json_decode($values, true));
            }

            $stocks = $this->stocksFor($products->pluck('id')->all());
            $balances = [];
            $movements = [];
            $now = now();
            foreach ($prepared as $line) {
                $stock = $stocks[$products[$line['key']]->id];
                $balances[$stock->id] = ($balances[$stock->id] ?? $stock->quantity) + $line['quantity'];
                $movements[] = array_merge([
                    'clinic_id' => $stock->clinic_id, 'branch_id' => $stock->branch_id,
                    'optical_product_id' => $stock->optical_product_id, 'user_id' => auth()->id(),
                    'quantity_change' => $line['quantity'], 'balance_after' => $balances[$stock->id],
                    'reason' => 'Stock received', 'movement_type' => 'receipt',
                ], $line['details'], ['created_at' => $now, 'updated_at' => $now]);
            }
            foreach (array_chunk($balances, 500, true) as $chunk) {
                $cases = implode(' ', array_map(fn ($id, $quantity) => 'WHEN '.(int) $id.' THEN '.(int) $quantity, array_keys($chunk), $chunk));
                OpticalProductStock::whereKey(array_keys($chunk))->update(['quantity' => DB::raw('CASE id '.$cases.' END')]);
            }
            // One insert needs the same columns on every row.
            $columns = array_fill_keys(array_keys(array_merge(...$movements)), null);
            foreach (array_chunk($movements, 500) as $chunk) {
                OpticalProductStockMovement::insert(array_map(fn ($row) => array_merge($columns, $row), $chunk));
            }
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

    /** @return Collection<string, OpticalProduct> the stock items for these lines by lens key, created if new, locked */
    private function itemsFor(array $prepared): Collection
    {
        $keys = array_values(array_unique(array_column($prepared, 'key')));
        $find = fn (bool $lock) => collect(array_chunk($keys, 500))->flatMap(fn ($chunk) => OpticalProduct::whereIn('lens_key', $chunk)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get())->keyBy('lens_key');
        $existing = $find(false);
        $new = [];
        foreach ($prepared as $line) if (! $existing->has($line['key']) && ! isset($new[$line['key']])) $new[$line['key']] = $line;
        if ($new) {
            $clinicId = OpticalProduct::clinicIdForWrite();
            if ($clinicId === null) throw new LogicException('A clinic context is required to create OpticalProduct.');
            $bases = array_map(fn ($line) => $this->skuBase($line['specs']), $new);
            $taken = collect(array_chunk(array_values(array_unique($bases)), 500))
                ->flatMap(fn ($chunk) => OpticalProduct::withTrashed()->whereIn('sku', $chunk)->pluck('sku'))->flip()->all();
            $priceList = app(OpticalLensPriceList::class);
            $categories = [];
            $now = now();
            $rows = [];
            foreach ($new as $key => $line) {
                $specs = $line['specs'];
                $categories[$specs['design']] ??= $this->category($specs['design'])->id;
                $sku = isset($taken[$bases[$key]]) ? $bases[$key].'-'.strtoupper(substr($key, 0, 6)) : $bases[$key];
                $taken[$sku] = true;
                $rows[] = ['clinic_id' => $clinicId, 'lens_key' => $key, 'optical_category_id' => $categories[$specs['design']],
                    'sku' => $sku, 'name' => $this->itemName($specs), 'lens_specs' => json_encode($specs),
                    'cost_price' => (float) $line['details']['unit_cost'],
                    'selling_price' => (float) ($priceList->lensPrice($specs) ?? $line['details']['unit_price']),
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) OpticalProduct::insert($chunk);
        }
        return $find(true);
    }

    /** @return Collection<int, OpticalProductStock> this branch's stock rows by product id, created if missing, locked */
    private function stocksFor(array $productIds): Collection
    {
        $find = fn () => collect(array_chunk($productIds, 500))->flatMap(fn ($chunk) => OpticalProductStock::whereIn('optical_product_id', $chunk)
            ->lockForUpdate()->get())->keyBy('optical_product_id');
        $stocks = $find();
        $missing = array_values(array_diff($productIds, $stocks->keys()->all()));
        if (! $missing) return $stocks;
        $clinicId = OpticalProductStock::clinicIdForWrite();
        $branchId = OpticalProductStock::branchIdForWrite($clinicId);
        if ($clinicId === null || $branchId === null) throw new LogicException('A branch context is required to create OpticalProductStock.');
        $now = now();
        $rows = array_map(fn ($id) => ['clinic_id' => $clinicId, 'branch_id' => $branchId, 'optical_product_id' => $id,
            'quantity' => 0, 'reorder_level' => 10, 'created_at' => $now, 'updated_at' => $now], $missing);
        foreach (array_chunk($rows, 500) as $chunk) OpticalProductStock::insert($chunk);
        return $find();
    }

    /** Eye-specific designs need R or L; other designs carry no eye. Keys are sorted for the lens key. */
    private function normalizedSpecs(array $specs): array
    {
        if (\App\Support\LensDesign::isEyeSpecific($specs['design'] ?? null)) {
            if (! in_array($specs['eye'] ?? null, ['R', 'L'], true)) {
                throw ValidationException::withMessages(['lensEye' => 'Choose whether these '.strtolower($specs['design']).' lenses are for the right or left eye.']);
            }
        } else {
            unset($specs['eye']);
        }
        ksort($specs);
        return $specs;
    }

    private function firstOrCreateItem(array $specs, string $key, float $unitCost, float $unitPrice): OpticalProduct
    {
        $category = $this->category($specs['design']);
        $existing = OpticalProduct::where('lens_key', $key)->first();
        if ($existing) return $existing;
        return OpticalProduct::create(['lens_key' => $key,
            'optical_category_id' => $category->id, 'sku' => $this->readableSku($specs, $key),
            'name' => $this->itemName($specs),
            'lens_specs' => $specs, 'cost_price' => $unitCost, 'selling_price' => $unitPrice, 'is_active' => true,
        ]);
    }

    private function category(string $design): OpticalCategory
    {
        return OpticalCategory::forStockLenses($design);
    }

    private function itemName(array $specs): string
    {
        return substr($specs['range'].' '.$specs['design'].' '.$specs['coating'].' '.$specs['sphere'].' / '.$specs['power'].(isset($specs['eye']) ? ' '.$specs['eye'] : ''), 0, 180);
    }

    /**
     * A SKU staff can read off a label: range, design, index, coating, SPH, CYL/ADD and eye,
     * with P/M for plus and minus, e.g. STPATBLU-SV-1.56-BLUECUT-M1.25-M0.50. A short hash is
     * added only when two ranges would otherwise share the same SKU.
     */
    private function readableSku(array $specs, string $key): string
    {
        $sku = $this->skuBase($specs);
        return OpticalProduct::withTrashed()->where('sku', $sku)->exists() ? $sku.'-'.strtoupper(substr($key, 0, 6)) : $sku;
    }

    private function skuBase(array $specs): string
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
        return substr($sku, 0, 72);
    }
}
