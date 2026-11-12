<?php

namespace App\Services\Scans;

use App\Models\Category;
use App\Models\DiagnosticScan;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Each scan is sold as a hidden made-to-order service product, so sales, receipts, refunds and
 * reports work as for anything else. The products sit in an inactive "Diagnostic Scans"
 * category (Product::SCAN_CATEGORY_TYPE) that the till, prescribing and the Products page leave out.
 */
class ScanCatalogue
{
    public function category(): Category
    {
        $category = Category::withTrashed()->where('type', Product::SCAN_CATEGORY_TYPE)->first();
        if ($category?->trashed()) $category->restore();

        // Category names are unique per clinic; a clinic may already have its own "Diagnostic Scans".
        $name = 'Diagnostic Scans';
        for ($n = 2; ! $category && Category::withTrashed()->where('name', $name)->exists(); $n++) $name = "Diagnostic Scans (Scan Desk {$n})";

        return $category ?? Category::create([
            'user_id' => Auth::id(), 'name' => $name, 'type' => Product::SCAN_CATEGORY_TYPE,
            'description' => 'Scans sold from Front Desk → Diagnostic Scans. Managed there, not here.', 'is_active' => false,
        ]);
    }

    /** The scan's product, created or brought in line with the scan's name and price. */
    public function productFor(DiagnosticScan $scan): Product
    {
        $product = $scan->product_id ? Product::withTrashed()->find($scan->product_id) : null;
        $name = 'Scan: '.$scan->name;

        if (! $product) {
            $product = new Product();
            $product->forceFill([
                'user_id' => Auth::id(), 'category_id' => $this->category()->id, 'quantity' => 0, 'made_to_order' => true, 'cost_price' => 0,
                'batch_number' => $this->freeBatchNumber(),
            ]);
        }
        if ($product->trashed()) $product->restore();
        $product->forceFill(['name' => $this->freeName($name, $product->id), 'selling_price' => $scan->price])->save();

        if ((int) $scan->product_id !== (int) $product->id) {
            $scan->forceFill(['product_id' => $product->id])->saveQuietly();
        }

        return $product;
    }

    private function freeBatchNumber(): string
    {
        do {
            $batch = 'SCN-'.strtoupper(Str::random(6));
        } while (Product::withTrashed()->where('batch_number', $batch)->exists());

        return $batch;
    }

    /** Product names are unique per clinic: add a number if a real product already has this one. */
    private function freeName(string $name, ?int $ignoreId): string
    {
        $candidate = $name;
        for ($n = 2; Product::withTrashed()->where('name', $candidate)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $candidate = "{$name} ({$n})";
        }

        return $candidate;
    }
}
