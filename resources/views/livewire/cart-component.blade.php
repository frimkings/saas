<div class="clinic-ui ui-page">
    <!-- ADD PRODUCT TO CART -->
    <div class="ui-panel mb-4">
        <div class="p-5">
            <div class="flex flex-wrap -mx-2 items-end">
                <!-- Product Select -->
                <div class="w-full md:w-5/12 px-2">
                    <label for="cart-selectedproductid" class="text-sm font-semibold">Product <span class="text-red-700">*</span></label>
                    <select id="cart-selectedproductid" wire:model.live="selectedProductId" class="ui-input ui-input-sm">
                        <option value="">-- Select Product --</option>
                        @foreach($productsList as $product)
                            <option value="{{ $product->id }}">
                                {{ $product->name }} (Stock: {{ $product->made_to_order ? 'Made to order' : $product->quantity }})
                            </option>
                        @endforeach
                    </select>
                    @error('selectedProductId') <small class="text-red-700">{{ $message }}</small> @enderror
                </div>

                <!-- Quantity -->
                <div class="w-full md:w-2/12 px-2">
                    <label for="cart-productquantity" class="text-sm font-semibold">Quantity <span class="text-red-700">*</span></label>
                    <input id="cart-productquantity" type="number" wire:model.live.debounce.400ms="productQuantity" min="1" class="ui-input ui-input-sm">
                    @error('productQuantity') <small class="text-red-700">{{ $message }}</small> @enderror
                </div>

                <!-- Price (auto-fill) -->
                <div class="w-full md:w-2/12 px-2">
                    <label for="cart-productprice" class="text-sm font-semibold">Price ({{ currency() }})</label>
                    <input id="cart-productprice" type="text" wire:model.live="productPrice" class="ui-input ui-input-sm" readonly>
                </div>

                <!-- Add Button -->
                <div class="w-full md:w-3/12 px-2 text-right">
                    <button wire:click.prevent="addToCart" class="ui-button ui-button-sm ui-button-primary mt-2">
                        <i class="fas fa-plus-circle mr-1"></i>Add to Cart
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CART TABLE -->
    <div class="ui-panel">
        <div class="">
            @if(count($cartItems) > 0)
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-sm mb-0">
                        <thead class="">
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">Total</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $grandTotal = 0; @endphp
                            @foreach($cartItems as $index => $item)
                                @php
                                    $total = $item['quantity'] * $item['price'];
                                    $grandTotal += $total;
                                @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $item['name'] }}</td>
                                    <td class="text-center">{{ $item['quantity'] }}</td>
                                    <td class="text-right">{{ currency() }} {{ number_format($item['price'], 2) }}</td>
                                    <td class="text-right">{{ currency() }} {{ number_format($total, 2) }}</td>
                                    <td class="text-center">
                                        <button wire:click.prevent="removeFromCart({{ $item['id'] }})" class="ui-button ui-button-sm ui-button-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-slate-50">
                            <tr>
                                <th colspan="4" class="text-right">Grand Total:</th>
                                <th class="text-right">{{ currency() }} {{ number_format($grandTotal, 2) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <div class="p-4 text-center text-slate-500">
                    <i class="fas fa-shopping-cart fa-2x mb-2"></i>
                    <p class="mb-0">No products in cart</p>
                </div>
            @endif
        </div>

        @if(count($cartItems) > 0)
            <div class="border-t border-slate-200 bg-slate-50 px-4 py-2 text-right">
                <button wire:click.prevent="$dispatch('cartUpdated')" class="ui-button ui-button-sm ui-button-primary">
                    <i class="fas fa-save mr-1"></i>Save Cart
                </button>
            </div>
        @endif
    </div>
</div>
