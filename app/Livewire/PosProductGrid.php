<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Product;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The POS product search, category pills and grid. A child of POSComponent so cart, payment
 * and discount actions don't re-run the product queries; it re-renders only when its own
 * search, category or page changes, or after a sale changes stock.
 */
class PosProductGrid extends Component
{
    use WithPagination;

    public $productSearchTerm = '';
    public $selectedCategoryId = '';

    public function updatingProductSearchTerm(): void
    {
        $this->resetPage();
    }

    public function updatingSelectedCategoryId(): void
    {
        $this->resetPage();
    }

    /** A sale went through: show the new stock levels. */
    #[On('pos-stock-changed')]
    public function refreshStock(): void
    {
    }

    public function render()
    {
        $products = Product::with('category')
            ->searchNameOrBatch($this->productSearchTerm)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDateIndexed('expiry_date', '>=', now()))
            ->when($this->selectedCategoryId, fn($q) => $q->where('category_id', $this->selectedCategoryId))
            ->paginate(12);

        return view('livewire.pos-product-grid', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }
}
