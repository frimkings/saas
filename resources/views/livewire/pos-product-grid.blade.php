{{-- POS product search and grid (App\Livewire\PosProductGrid); a click adds the product to the POS cart. --}}
<div class="pos-products">

    {{-- Search --}}
    <div class="pos-search-bar">
        <i class="fas fa-search pos-search-icon"></i>
        <input class="pos-search-input"
               type="text"
               placeholder="Search by name or batch no…"
               wire:model.live.debounce.300ms="productSearchTerm">
        <span class="pos-search-count">{{ $products->total() }} items</span>
    </div>

    {{-- Category pills --}}
    <div class="pos-cats">
        <button class="pos-cat {{ !$selectedCategoryId ? 'pos-cat--active' : '' }}"
                wire:click="$set('selectedCategoryId', '')">
            All
        </button>
        @foreach($categories as $cat)
            <button class="pos-cat {{ $selectedCategoryId == $cat->id ? 'pos-cat--active' : '' }}"
                    wire:click="$set('selectedCategoryId', {{ $cat->id }})">
                {{ $cat->name }}
            </button>
        @endforeach
    </div>

    {{-- Product grid --}}
    <div class="pos-grid">
        @forelse($products as $product)
            <div class="pos-product {{ $product->canSupply() ? '' : 'pos-product--oos' }}"
                 wire:click="$dispatch('pos-add-product', { productId: {{ $product->id }} })">
                <div class="pos-product__icon">
                    <i class="fas fa-pills"></i>
                </div>
                <div class="pos-product__name">{{ Str::limit($product->name, 28) }}</div>
                <div class="pos-product__price">{{ currency() }} {{ number_format($product->selling_price, 2) }}</div>
                <div class="pos-product__stock">
                    @if($product->made_to_order)
                        <span class="pos-stock pos-stock--ok">Made to order</span>
                    @elseif($product->quantity > 10)
                        <span class="pos-stock pos-stock--ok">{{ $product->quantity }}</span>
                    @elseif($product->quantity > 0)
                        <span class="pos-stock pos-stock--low">Low {{ $product->quantity }}</span>
                    @else
                        <span class="pos-stock pos-stock--out">Out of stock</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="pos-empty-state">
                <i class="fas fa-box-open"></i>
                <p>No products found</p>
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    <div class="pos-pagination">{{ $products->links() }}</div>

</div>
