<?php

namespace App\Services;

use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Models\OpticalProductStockMovement;
use App\Models\OpticalStockCount;
use App\Support\LensDesign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts: a manager starts a count (a lens range, frames, other items or everything),
 * staff enter what is on the shelf, and on approval each difference is posted to the ledger
 * as a count correction. Expected quantities are frozen when the count starts.
 */
class OpticalStockCountService
{
    public const SCOPES = ['lens_range' => 'One lens range', 'frames' => 'Frames', 'other' => 'Other items', 'all' => 'All optical stock'];
    private const SPEC_KEYS = ['range', 'design', 'index', 'coating', 'diameter', 'eye'];

    public function start(string $scope, ?array $specs = null, ?string $notes = null): OpticalStockCount
    {
        $this->assertManager();
        if (! array_key_exists($scope, self::SCOPES)) throw ValidationException::withMessages(['countScope' => 'Choose what to count.']);
        $specs = $scope === 'lens_range' ? $this->normaliseSpecs($specs ?? []) : null;
        $products = $this->productsInScope($scope, $specs);
        if ($products->isEmpty()) throw ValidationException::withMessages(['countScope' => 'There is no stock of that kind to count.']);

        return DB::transaction(function () use ($scope, $specs, $notes, $products) {
            $count = OpticalStockCount::create([
                'count_number' => 'OSC-'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
                'scope' => $scope, 'scope_specs' => $specs, 'title' => $this->title($scope, $specs),
                'status' => 'counting', 'notes' => $notes ? trim($notes) : null, 'created_by' => auth()->id(),
                'ledger_position' => (int) OpticalProductStockMovement::withoutGlobalScopes()->max('id'),
            ]);
            $quantities = OpticalProductStock::whereIn('optical_product_id', $products->pluck('id'))->pluck('quantity', 'optical_product_id');
            foreach ($products as $product) {
                $count->lines()->create([
                    'optical_product_id' => $product->id,
                    'expected_quantity' => (int) ($quantities[$product->id] ?? 0),
                    'unit_cost' => round((float) $product->cost_price, 2),
                ]);
            }
            return $count;
        });
    }

    /** @param array<int, int|string|null> $counts line id => counted quantity ('' = not counted yet) */
    public function saveCounts(int $countId, array $counts): OpticalStockCount
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $count = OpticalStockCount::with('lines')->findOrFail($countId);
        if ($count->status !== 'counting') throw ValidationException::withMessages(['counts' => 'This count is no longer open for counting.']);
        DB::transaction(function () use ($count, $counts) {
            $moved = $this->movedSinceStart($count);
            foreach ($count->lines as $line) {
                if (! array_key_exists($line->id, $counts)) continue;
                $value = trim((string) $counts[$line->id]);
                if ($value !== '' && (! ctype_digit($value) || (int) $value > 100000)) {
                    throw ValidationException::withMessages(['counts' => 'Counts must be whole numbers from 0 to 100000.']);
                }
                $counted = $value === '' ? null : (int) $value;
                if ($counted === $line->counted_quantity) continue;
                // Sales or glazing since the count started are already off the books.
                $line->update(['counted_quantity' => $counted, 'moved_before_count' => $counted === null ? 0 : (int) ($moved[$line->optical_product_id] ?? 0)]);
            }
        });
        return $count;
    }

    public function submit(int $countId): OpticalStockCount
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $count = OpticalStockCount::withCount(['lines as counted_lines' => fn ($q) => $q->whereNotNull('counted_quantity')])->findOrFail($countId);
        if ($count->status !== 'counting') throw ValidationException::withMessages(['counts' => 'This count has already been submitted.']);
        if ($count->counted_lines === 0) throw ValidationException::withMessages(['counts' => 'Enter at least one counted quantity before submitting.']);
        $count->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now()]);
        return $count;
    }

    /** Send a submitted count back for recounting. */
    public function reopen(int $countId): OpticalStockCount
    {
        $this->assertManager();
        $count = OpticalStockCount::findOrFail($countId);
        if ($count->status !== 'submitted') throw ValidationException::withMessages(['counts' => 'Only a submitted count can be sent back.']);
        $count->update(['status' => 'counting', 'submitted_by' => null, 'submitted_at' => null]);
        return $count;
    }

    /**
     * Post each counted difference (counted − what the system expected when that item was
     * counted) to the ledger. Uncounted lines are left unchanged.
     */
    public function approve(int $countId): OpticalStockCount
    {
        $this->assertManager();
        $count = DB::transaction(function () use ($countId) {
            $count = OpticalStockCount::with('lines.product')->lockForUpdate()->findOrFail($countId);
            if ($count->status !== 'submitted') throw ValidationException::withMessages(['counts' => 'Only a submitted count can be approved.']);
            foreach ($count->lines as $line) {
                $variance = $line->variance();
                if (! $variance) continue;
                try {
                    app(OpticalStockLedgerService::class)->countCorrection($line->product, $variance, [
                        'reference' => $count->count_number, 'unit_cost' => (float) $line->unit_cost,
                        'optical_stock_count_id' => $count->id,
                    ]);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['counts' => "{$line->product->name}: stock has fallen since the count started, so the correction would go below zero. Send the count back and recount it."]);
                }
            }
            $count->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            return $count;
        });
        // The owner hears about stock found missing (or extra) once it has been corrected.
        app(OwnerAlerts::class)->stockCountApproved($count);

        return $count;
    }

    public function cancel(int $countId): OpticalStockCount
    {
        $this->assertManager();
        $count = OpticalStockCount::findOrFail($countId);
        if (in_array($count->status, ['approved', 'cancelled'], true)) throw ValidationException::withMessages(['counts' => 'This count is already closed.']);
        $count->update(['status' => 'cancelled']);
        return $count;
    }

    /** Products whose stock moved after the count started (sales, glazing, receipts): recheck these. */
    public function movedSinceStart(OpticalStockCount $count): Collection
    {
        return OpticalProductStockMovement::whereIn('optical_product_id', $count->lines()->pluck('optical_product_id'))
            ->where('id', '>', $count->ledger_position)->whereNull('optical_stock_count_id')
            ->selectRaw('optical_product_id, SUM(quantity_change) as moved')->groupBy('optical_product_id')
            ->pluck('moved', 'optical_product_id')->map(fn ($moved) => (int) $moved);
    }

    /** Distinct lens specifications that can be counted as one range (eye-specific designs per eye). */
    public function lensRanges(): Collection
    {
        return OpticalProduct::where('is_active', true)->whereNotNull('lens_specs')->get()
            ->map(fn ($product) => $this->normaliseSpecs((array) $product->lens_specs))
            ->unique(fn ($specs) => json_encode($specs))
            ->sortBy(fn ($specs) => implode('|', $specs))->values();
    }

    public function title(string $scope, ?array $specs): string
    {
        if ($scope !== 'lens_range') return self::SCOPES[$scope];
        $eye = isset($specs['eye']) ? ' · '.($specs['eye'] === 'R' ? 'Right' : 'Left') : '';
        return trim(($specs['range'] ?? '').' '.($specs['design'] ?? '').' '.($specs['index'] ?? '').' '.($specs['coating'] ?? '').' '.($specs['diameter'] ?? '').'mm'.$eye);
    }

    private function productsInScope(string $scope, ?array $specs): Collection
    {
        $products = OpticalProduct::with('category')->where('is_active', true)->get();
        return (match ($scope) {
            'lens_range' => $products->filter(fn ($product) => $product->lens_specs && $this->normaliseSpecs((array) $product->lens_specs) == $specs),
            'frames' => $products->filter(fn ($product) => ! $product->lens_specs && $product->category?->group === 'frames'),
            'other' => $products->filter(fn ($product) => ! $product->lens_specs && $product->category?->group !== 'frames'),
            default => $products,
        })->sortBy(fn ($product) => $product->lens_specs
            ? sprintf('%08.2f|%08.2f', 100 + (float) data_get($product->lens_specs, 'sphere'), 100 + (float) data_get($product->lens_specs, 'power'))
            : $product->name)->values();
    }

    private function normaliseSpecs(array $specs): array
    {
        $normal = [
            'range' => trim((string) ($specs['range'] ?? '')), 'design' => (string) ($specs['design'] ?? ''),
            'index' => (string) ($specs['index'] ?? ''), 'coating' => (string) ($specs['coating'] ?? ''),
            'diameter' => (int) ($specs['diameter'] ?? 0),
        ];
        if (LensDesign::isEyeSpecific($normal['design'])) $normal['eye'] = (string) ($specs['eye'] ?? '');
        return $normal;
    }

    private function assertManager(): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'Only a manager can start, approve or cancel stock counts.');
    }
}
