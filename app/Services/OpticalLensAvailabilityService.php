<?php

namespace App\Services;

use App\Models\LensOrder;
use App\Models\OpticalLensBlank;
use App\Models\OpticalOrderLensLine;
use App\Support\LensDesign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpticalLensAvailabilityService
{
    private const COATINGS = ['AR', 'BlueCut', 'Transitions', 'HC'];
    private const INDICES = ['1.56', '1.61', '1.67', '1.74'];

    /**
     * Stock options priced and sourced per eye. Each lens is one stock unit
     * and is sold at its own per-lens selling price. An eye that the branch
     * cannot supply is special-ordered in the same design at the catalogue
     * price for that power, so a half pair can still be dispensed.
     *
     * A prescription with an ADD is filled from progressive/bifocal stock,
     * matched per eye on SPH + ADD, and only when that eye has no CYL (stock
     * multifocals are spherical). Without an ADD, single vision stock is
     * matched on SPH + CYL.
     */
    public function stockOptions(array $measurements, ?int $ignoreHoldsForOrderId = null): array
    {
        $powers = $this->powers($measurements);
        $multifocal = collect($powers)->contains(fn ($rx) => $rx[2] > 0);

        $products = \App\Models\OpticalProduct::with('stocks')
            ->where('is_active', true)->whereNotNull('lens_specs')->get()
            ->filter(fn ($product) => data_get($product->lens_specs, 'sphere') !== null && data_get($product->lens_specs, 'power') !== null
                && LensDesign::isEyeSpecific(data_get($product->lens_specs, 'design')) === $multifocal);
        $matchesPower = function ($product, string $eye, array $rx) use ($multifocal) {
            if (round((float) data_get($product->lens_specs, 'sphere'), 2) !== $rx[0]) return false;
            $power = round((float) data_get($product->lens_specs, 'power'), 2);
            if (! $multifocal) return $power === $rx[1];
            return $rx[1] == 0 && data_get($product->lens_specs, 'eye') === LensDesign::stockEye($eye) && $power === $rx[2];
        };

        if ($products->contains(fn ($product) => collect($powers)->contains(fn ($rx, $eye) => $matchesPower($product, $eye, $rx)))) {
            $held = $this->heldQuantities($products->pluck('id')->all(), $ignoreHoldsForOrderId);

            return $products->groupBy(function ($product) {
                $specs = collect($product->lens_specs)->only(['range', 'design', 'index', 'coating', 'diameter'])->all();
                ksort($specs);
                return base64_encode(json_encode($specs));
            })->map(function ($group, $key) use ($powers, $held, $matchesPower, $multifocal) {
                $first = $group->first();
                $freeByProduct = [];
                $eyes = [];
                foreach ($powers as $eye => $rx) {
                    $matches = $group->filter(fn ($product) => $matchesPower($product, $eye, $rx));
                    foreach ($matches as $product) {
                        $freeByProduct[$product->id] ??= max(0, (int) $product->stocks->sum('quantity') - ($held[$product->id] ?? 0));
                    }
                    // Same power in both eyes draws on the same lenses: take
                    // one lens per eye from whatever is still free.
                    $stocked = $matches->sortByDesc(fn ($product) => $freeByProduct[$product->id])->first(fn ($product) => $freeByProduct[$product->id] > 0);
                    $priced = $stocked ?? $matches->sortByDesc('selling_price')->first();
                    $eyes[$eye] = [
                        'sphere' => $rx[0], 'power' => $multifocal ? $rx[2] : $rx[1],
                        'power_type' => $multifocal ? 'add' : 'cyl',
                        // Stock multifocals are spherical; astigmatism needs a made-to-order lens.
                        'reason' => $multifocal && $rx[1] != 0 ? 'CYL '.sprintf('%+.2f', $rx[1]).' needs a made-to-order lens' : null,
                        'source' => $stocked ? 'stock' : 'special_order',
                        'product_id' => $stocked?->id,
                        'free' => (int) $matches->sum(fn ($product) => $freeByProduct[$product->id]),
                        // A power the range has never stocked is priced from the range's price list when there is one.
                        'unit_price' => (float) ($priced ? $priced->selling_price
                            : (app(OpticalLensPriceList::class)->lensPrice(array_merge($first->lens_specs, ['sphere' => $rx[0], 'power' => $multifocal ? $rx[2] : $rx[1]])) ?? $group->max('selling_price'))),
                        'price_estimated' => $priced === null,
                    ];
                    if ($stocked) $freeByProduct[$stocked->id]--;
                }
                $stockedEyes = collect($eyes)->where('source', 'stock')->count();

                return [
                    'key' => $key,
                    'design' => trim((string) data_get($first->lens_specs, 'range').' '.data_get($first->lens_specs, 'design')),
                    'lens_type' => (string) data_get($first->lens_specs, 'design', 'Single Vision'),
                    'index' => (string) data_get($first->lens_specs, 'index'),
                    'coating' => (string) data_get($first->lens_specs, 'coating'),
                    'price' => round($eyes['od']['unit_price'] + $eyes['os']['unit_price'], 2),
                    'od_quantity' => $eyes['od']['free'], 'os_quantity' => $eyes['os']['free'],
                    'od_product_id' => $eyes['od']['product_id'], 'os_product_id' => $eyes['os']['product_id'],
                    'eyes' => $eyes,
                    'split' => ['od' => $eyes['od']['source'], 'os' => $eyes['os']['source']],
                    'status' => $stockedEyes === 2 ? 'available' : ($stockedEyes === 1 ? 'partial' : 'none'),
                    'available' => $stockedEyes === 2,
                ];
            })
                // Options with no stocked eye are plain special orders.
                ->reject(fn ($option) => $option['status'] === 'none')
                ->sortBy([['available', 'desc'], ['design', 'asc']])->values()->all();
        }

        // The legacy lens blank table only ever held single vision stock.
        if ($multifocal) return [];

        $rows = OpticalLensBlank::query()
            ->where('design', 'Single Vision')->where('product_range', '')->where('diameter', 0)->where('addition', 0)
            ->where(function ($query) use ($powers) {
                foreach ($powers as [$sphere, $cylinder]) {
                    $query->orWhere(fn ($power) => $power->where('sphere', $sphere)->where('cylinder', $cylinder));
                }
            })->get();

        $products = \App\Models\OpticalProduct::whereIn('id', $rows->pluck('optical_product_id')->filter()->unique())
            ->get()->keyBy('id');

        return $rows->groupBy(fn ($row) => implode('|', [$row->design, $row->lens_index, $row->coating, $row->optical_product_id ?: 0]))
            ->map(function ($group, $key) use ($powers, $products) {
                $first = $group->first();
                $quantities = [];
                foreach ($powers as $eye => [$sphere, $cylinder]) {
                    $blank = $group->first(fn ($row) => (float) $row->sphere === $sphere && (float) $row->cylinder === $cylinder);
                    $quantities[$eye] = $blank ? $this->quantity($blank) : 0;
                }
                $samePower = $powers['od'] === $powers['os'];
                $product = $first->optical_product_id ? $products->get($first->optical_product_id) : null;
                return [
                    'key' => $key,
                    'design' => $product?->name ?: $first->design,
                    'lens_type' => $first->design,
                    'index' => $first->lens_index,
                    'coating' => $first->coating,
                    // Selling prices are per lens; a pair is two lenses.
                    'price' => round(2 * (float) ($product?->selling_price ?? $first->selling_price ?? 0), 2),
                    'od_quantity' => $quantities['od'],
                    'os_quantity' => $quantities['os'],
                    'available' => $samePower
                        ? $quantities['od'] >= 2
                        : $quantities['od'] >= 1 && $quantities['os'] >= 1,
                    // Legacy blank rows are all-or-nothing pairs.
                    'split' => null,
                ];
            })->map(function ($option) {
                $option['status'] = $option['available'] ? 'available' : 'none';
                return $option;
            })->sortBy([['available', 'desc'], ['design', 'asc']])->values()->all();
    }

    public function check(array $measurements, string $index, string $coating, string $design, string $color = 'White'): array
    {
        $powers = [];
        foreach (['od', 'os'] as $eye) {
            $sphere = data_get($measurements, "$eye.sph");
            $cylinder = data_get($measurements, "$eye.cyl", 0);
            if (! is_numeric($sphere) || ($cylinder !== '' && $cylinder !== null && ! is_numeric($cylinder))) {
                throw ValidationException::withMessages(['measurements' => 'Enter valid sphere and cylinder values for both eyes before checking stock.']);
            }
            $cylinder = $cylinder === '' || $cylinder === null ? 0 : (float) $cylinder;
            $powers[$eye] = ['sphere' => round((float) $sphere, 2), 'cylinder' => round($cylinder, 2)];
        }
        if ($design !== 'Single Vision') {
            return ['status' => 'not_verifiable', 'message' => 'This stock matrix does not identify bifocal or progressive designs. Use outside sourcing.', 'eyes' => []];
        }
        if (($coating === 'Transitions' && ! in_array($color, ['Photochromic', 'Transitions'], true))
            || ($coating !== 'Transitions' && $color !== 'White')) {
            return ['status' => 'not_verifiable', 'message' => 'This color and coating combination cannot be verified in the lens blank matrix. Use outside sourcing.', 'eyes' => []];
        }
        if (! in_array($index, self::INDICES, true) || ! in_array($coating, self::COATINGS, true)) {
            return ['status' => 'not_verifiable', 'message' => 'This index or coating is not tracked in the lens blank stock matrix. Use outside sourcing.', 'eyes' => []];
        }

        $rows = [];
        $allAvailable = true;
        foreach ($powers as $eye => $power) {
            $blank = OpticalLensBlank::where('design', 'Single Vision')->where('product_range', '')->where('diameter', 0)->where('addition', 0)->where('lens_index', $index)->where('coating', $coating)
                ->where('sphere', $power['sphere'])->where('cylinder', $power['cylinder'])->first();
            $needed = count(array_filter($powers, fn ($other) => $other === $power));
            $available = (int) ($blank ? $this->quantity($blank) : 0);
            $match = $available >= $needed;
            $allAvailable = $allAvailable && $match;
            $rows[$eye] = [
                'sphere' => $power['sphere'], 'cylinder' => $power['cylinder'],
                'available' => $available, 'required' => $needed,
                'status' => $match ? 'available' : ($blank ? 'insufficient' : 'not_stocked'),
            ];
        }

        $availableEyes = collect($rows)->filter(fn ($row) => $row['status'] === 'available')->keys()->all();
        $status = $allAvailable ? 'available' : (count($availableEyes) === 1 ? 'partial' : 'outside_sourcing');
        $message = match ($status) {
            'available' => 'Both lens blanks are currently available in this branch.',
            'partial' => strtoupper($availableEyes[0]).' is available; the other eye must be sourced separately.',
            default => 'Neither required lens blank is currently available in this branch.',
        };

        return [
            'status' => $status,
            'message' => $message,
            'eyes' => $rows,
        ];
    }

    public function reserveForOrder(LensOrder $order): void
    {
        if ($order->lens_supply_source !== 'stock') return;
        $details = json_decode($order->notes ?? '', true) ?: [];
        $stockKey = (string) data_get($details, 'lens_details.stock_key', '');
        if ($stockKey !== '') {
            // Stocked lenses are only held here; they leave the branch ledger
            // when glazing starts (consumeForGlazing).
            $this->holdLensLines($order, $stockKey, (array) data_get($details, 'lens_details.stock_split', []), (array) data_get($details, 'lens_details.special_prices', []));
            return;
        }
        $design = (string) data_get($details, 'lens_details.type', '');
        $index = (string) data_get($details, 'lens_details.index', '');
        $coating = (string) data_get($details, 'lens_details.stock_coating', '');
        $check = $this->check($order->prescription_snapshot ?? [], $index, $coating, $design, (string) data_get($details, 'lens_details.color', 'White'));
        if ($check['status'] !== 'available') {
            throw ValidationException::withMessages(['lens_stock' => 'Lens blank stock changed or cannot be verified. Check availability again or choose outside sourcing.']);
        }

        $required = [];
        foreach ($check['eyes'] as $eye) {
            $key = sprintf('%.2f|%.2f', $eye['sphere'], $eye['cylinder']);
            $required[$key] = ['sphere' => $eye['sphere'], 'cylinder' => $eye['cylinder'], 'quantity' => ($required[$key]['quantity'] ?? 0) + 1];
        }
        ksort($required);
        $allocation = [];
        foreach ($required as $power) {
            $blank = OpticalLensBlank::where('design', 'Single Vision')->where('product_range', '')->where('diameter', 0)->where('addition', 0)->where('lens_index', $index)->where('coating', $coating)
                ->where('sphere', $power['sphere'])->where('cylinder', $power['cylinder'])
                ->lockForUpdate()->first();
            if (! $blank || $this->quantity($blank) < $power['quantity']) {
                throw ValidationException::withMessages(['lens_stock' => 'Lens blank stock changed. Check availability again or choose outside sourcing.']);
            }
            $this->change($blank, -$power['quantity'], $order->order_id);
            $allocation[] = ['blank_id' => $blank->id, 'quantity' => $power['quantity']];
        }
        $order->lens_blank_allocations = $allocation;
        $order->stock_reserved_at = now();
        $order->save();
    }

    /**
     * Resolve a stock option chosen in the order form. When the form showed a
     * per-eye split, it must still hold so the customer is charged what they
     * were quoted.
     */
    public function resolveStockOption(array $measurements, string $key, array $expectedSplit = [], ?int $orderId = null, array $specialPrices = []): array
    {
        $option = collect($this->stockOptions($measurements, $orderId))->firstWhere('key', $key);
        if (! $option || $option['status'] === 'none' || empty($option['split'])) {
            throw ValidationException::withMessages(['lens_stock' => 'Neither lens is in stock for this design any more. Check availability again or choose special order.']);
        }
        if ($expectedSplit && array_intersect_key($expectedSplit, ['od' => 1, 'os' => 1]) != $option['split']) {
            throw ValidationException::withMessages(['lens_stock' => 'Lens stock changed since it was checked. Check availability again before continuing.']);
        }
        return $this->withSpecialPrices($option, $specialPrices);
    }

    /**
     * Charge the price staff typed for each special-order eye (a made-to-order lens has no
     * stock price), and total the pair again. Eyes without a typed price keep the range price.
     */
    public function withSpecialPrices(array $option, array $prices): array
    {
        foreach ($option['eyes'] ?? [] as $eye => $line) {
            if ($line['source'] !== 'special_order' || ! is_numeric($prices[$eye] ?? null)) continue;
            $option['eyes'][$eye]['unit_price'] = round((float) $prices[$eye], 2);
            $option['eyes'][$eye]['price_estimated'] = false;
            $option['eyes'][$eye]['price_typed'] = true;
        }
        if (isset($option['eyes']['od'], $option['eyes']['os'])) {
            $option['price'] = round($option['eyes']['od']['unit_price'] + $option['eyes']['os']['unit_price'], 2);
        }
        return $option;
    }

    /**
     * Special-order lenses priced by staff (made-to-order, or no price for that power) go to
     * the audit trail and the owner, so a mistyped or generous price is seen.
     */
    public function reportTypedPrices(LensOrder $order): void
    {
        $details = json_decode($order->notes ?? '', true) ?: [];
        $typed = (array) data_get($details, 'lens_details.special_prices', []);
        $lines = OpticalOrderLensLine::where('lens_order_id', $order->id)->where('source', 'special_order')->orderBy('eye')->get()
            ->filter(fn ($line) => is_numeric($typed[$line->eye] ?? null));
        if ($lines->isEmpty()) return;
        $powerLabel = data_get($details, 'lens_details.type') === 'Single Vision' ? 'CYL' : 'ADD';
        $eyes = $lines->mapWithKeys(fn ($line) => [strtoupper($line->eye).' lens' => sprintf('SPH %+.2f, %s %+.2f · %s %s', $line->sphere, $powerLabel, $line->power, currency(), number_format((float) $line->unit_price, 2))])->all();
        $lens = trim(data_get($details, 'lens_details.type').' '.data_get($details, 'lens_details.stock_coating'));
        \App\Models\AuditTrail::record('optical.special_lens_priced', "Special-order lens price typed on order {$order->order_id}: ".implode('; ', array_map(fn ($eye, $text) => "{$eye} {$text}", array_keys($eyes), $eyes)),
            $order, [], ['lens' => $lens, 'eyes' => $eyes], $order->patient_id);
        // Only once the order is really saved.
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\OwnerAlerts::class)->specialLensPriced($order, $eyes, $lens));
    }

    /** Record the per-eye plan without holding stock (quotations). */
    public function writeLensLines(LensOrder $order, array $option, bool $active): void
    {
        OpticalOrderLensLine::where('lens_order_id', $order->id)->delete();
        foreach ($option['eyes'] as $eye => $line) {
            OpticalOrderLensLine::create([
                'lens_order_id' => $order->id, 'eye' => $eye, 'source' => $line['source'],
                'optical_product_id' => $line['source'] === 'stock'
                    ? $line['product_id']
                    : $this->catalogueProductId($option['key'], $eye, $line['sphere'], $line['power']),
                'sphere' => $line['sphere'], 'power' => $line['power'],
                'unit_price' => $line['unit_price'], 'price_estimated' => $line['price_estimated'],
                'status' => $active ? ($line['source'] === 'stock' ? 'held' : 'ordered') : 'quoted',
            ]);
        }
    }

    private function holdLensLines(LensOrder $order, string $key, array $expectedSplit, array $specialPrices = []): void
    {
        $option = $this->resolveStockOption($order->prescription_snapshot ?? [], $key, $expectedSplit, $order->id, $specialPrices);
        // Serialise competing orders for the same lenses, then re-check the
        // free quantity under the lock.
        $productIds = collect($option['eyes'])->where('source', 'stock')->pluck('product_id')->unique()->all();
        \App\Models\OpticalProductStock::whereIn('optical_product_id', $productIds)->lockForUpdate()->get();
        $option = $this->resolveStockOption($order->prescription_snapshot ?? [], $key, $option['split'], $order->id, $specialPrices);
        $this->writeLensLines($order, $option, true);
        $order->lens_blank_allocations = null;
        $order->stock_reserved_at = now();
        $order->save();
    }

    /** Deduct held lenses from branch stock when the job goes to glazing. */
    public function consumeForGlazing(LensOrder $order): void
    {
        // Released lines belong to a job whose hold was freed while it was stuck: take a
        // lens now if one is still free, otherwise the job needs new lenses.
        $lines = OpticalOrderLensLine::where('lens_order_id', $order->id)->where('source', 'stock')->whereIn('status', ['held', 'released'])->get();
        foreach ($lines as $line) {
            $available = (int) \App\Models\OpticalProductStock::where('optical_product_id', $line->optical_product_id)->lockForUpdate()->value('quantity');
            if ($line->status === 'released') {
                $product = \App\Models\OpticalProduct::withTrashed()->find($line->optical_product_id);
                if (! $product || app(OpticalProductInventoryService::class)->available($product) < 1) {
                    throw ValidationException::withMessages(['status' => 'The '.strtoupper($line->eye).' lens reserved for this job was released and none is free now. Receive more stock, or cancel and re-order the job with a special-order lens.']);
                }
            }
            if ($available < 1) {
                throw ValidationException::withMessages(['status' => 'The '.strtoupper($line->eye).' lens is no longer on the shelf. Recount lens stock before glazing.']);
            }
            app(OpticalStockLedgerService::class)->recordLensOrder(\App\Models\OpticalProduct::withTrashed()->findOrFail($line->optical_product_id), -1, $order->order_id.' '.strtoupper($line->eye));
            $line->update(['status' => 'consumed', 'consumed_at' => now()]);
        }
    }

    /** @return string[] eyes whose special-order lens has not arrived */
    public function awaitedEyes(LensOrder $order): array
    {
        return OpticalOrderLensLine::where('lens_order_id', $order->id)->where('source', 'special_order')
            ->where('status', 'ordered')->orderBy('eye')->pluck('eye')->all();
    }

    public function receiveSpecialOrderLens(LensOrder $order, string $eye): void
    {
        $line = OpticalOrderLensLine::where('lens_order_id', $order->id)->where('eye', $eye)
            ->where('source', 'special_order')->where('status', 'ordered')->first();
        if (! $line) throw ValidationException::withMessages(['lens' => 'This lens is not awaiting delivery.']);
        $line->update(['status' => 'received', 'received_at' => now()]);
    }

    private function heldQuantities(array $productIds, ?int $ignoreOrderId): array
    {
        if (! $productIds) return [];
        return OpticalOrderLensLine::query()
            ->where('source', 'stock')->whereIn('status', OpticalOrderLensLine::HOLDING)
            ->whereIn('optical_product_id', $productIds)
            ->when($ignoreOrderId, fn ($query) => $query->where('lens_order_id', '!=', $ignoreOrderId))
            ->selectRaw('optical_product_id, COUNT(*) as held')->groupBy('optical_product_id')
            ->pluck('held', 'optical_product_id')->map(fn ($held) => (int) $held)->all();
    }

    private function catalogueProductId(string $key, string $eye, float $sphere, float $power): ?int
    {
        $specs = json_decode(base64_decode($key), true) ?: [];
        return \App\Models\OpticalProduct::where('is_active', true)->whereNotNull('lens_specs')->get()
            ->first(function ($product) use ($specs, $eye, $sphere, $power) {
                $candidate = collect($product->lens_specs)->only(['range', 'design', 'index', 'coating', 'diameter'])->all();
                ksort($candidate);
                return $candidate == $specs
                    && (! LensDesign::isEyeSpecific($specs['design'] ?? null) || data_get($product->lens_specs, 'eye') === LensDesign::stockEye($eye))
                    && round((float) data_get($product->lens_specs, 'sphere'), 2) === $sphere
                    && round((float) data_get($product->lens_specs, 'power'), 2) === $power;
            })?->id;
    }

    private function powers(array $measurements): array
    {
        $powers = [];
        foreach (['od', 'os'] as $eye) {
            $sphere = data_get($measurements, "$eye.sph");
            $cylinder = data_get($measurements, "$eye.cyl", 0);
            $addition = data_get($measurements, "$eye.add", 0);
            if (! is_numeric($sphere) || ($cylinder !== '' && $cylinder !== null && ! is_numeric($cylinder))
                || ($addition !== '' && $addition !== null && ! is_numeric($addition))) {
                throw ValidationException::withMessages(['measurements' => 'Enter valid sphere, cylinder and ADD values for both eyes before checking stock.']);
            }
            $powers[$eye] = [
                round((float) $sphere, 2),
                round((float) ($cylinder === '' || $cylinder === null ? 0 : $cylinder), 2),
                round((float) ($addition === '' || $addition === null ? 0 : $addition), 2),
            ];
        }
        return $powers;
    }

    public function releaseForOrder(LensOrder $order): void
    {
        // Held lenses never left the ledger. Consumed ones were cut for this
        // job and are not returned to stock. Special-order lenses still awaited
        // are no longer wanted (one already on a placed supplier order goes to
        // stock when it arrives).
        OpticalOrderLensLine::where('lens_order_id', $order->id)->whereIn('status', ['held', 'quoted', 'ordered'])
            ->update(['status' => 'released']);
        foreach ($order->lens_blank_allocations ?? [] as $allocation) {
            if (! empty($allocation['optical_product_id'])) {
                // A product deleted since must not block cancelling the order.
                $product = \App\Models\OpticalProduct::withTrashed()->findOrFail($allocation['optical_product_id']);
                app(OpticalStockLedgerService::class)->recordLensOrder($product, (int) $allocation['quantity'], 'Cancel '.$order->order_id);
                continue;
            }
            $blank = OpticalLensBlank::lockForUpdate()->findOrFail($allocation['blank_id']);
            $this->change($blank, (int) $allocation['quantity'], 'Cancel '.$order->order_id);
        }
        $order->lens_blank_allocations = null;
        $order->save();
    }

    private function quantity(OpticalLensBlank $blank): int
    {
        if (! $blank->optical_product_id) return (int) $blank->quantity;
        return (int) \App\Models\OpticalProductStock::where('optical_product_id', $blank->optical_product_id)->value('quantity');
    }

    private function change(OpticalLensBlank $blank, int $change, string $reference): void
    {
        if ($blank->optical_product_id) {
            $product = \App\Models\OpticalProduct::findOrFail($blank->optical_product_id);
            app(OpticalStockLedgerService::class)->recordLensOrder($product, $change, $reference);
            return;
        }
        $blank->quantity += $change;
        $blank->save();
        $this->movement($blank, $change, $reference);
    }

    private function movement(OpticalLensBlank $blank, int $change, string $reference): void
    {
        DB::table('optical_lens_blank_movements')->insert([
            'clinic_id' => app(TenantContext::class)->clinicId(),
            'branch_id' => app(TenantContext::class)->branchId(),
            'optical_lens_blank_id' => $blank->id,
            'user_id' => auth()->id(),
            'quantity_change' => $change,
            'balance_after' => $blank->quantity,
            'reference' => $reference,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
