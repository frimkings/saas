<div>
    <!-- ADD PRODUCT TO CART -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-end">
                <!-- Product Select -->
                <div class="col-md-5">
                    <label class="small font-weight-bold">Product <span class="text-danger">*</span></label>
                    <select wire:model.live="selectedProductId" class="form-control form-control-sm">
                        <option value="">-- Select Product --</option>
                        @foreach($productsList as $product)
                            <option value="{{ $product->id }}">
                                {{ $product->name }} (Stock: {{ $product->quantity }})
                            </option>
                        @endforeach
                    </select>
                    @error('selectedProductId') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <!-- Quantity -->
                <div class="col-md-2">
                    <label class="small font-weight-bold">Quantity <span class="text-danger">*</span></label>
                    <input type="number" wire:model.live="productQuantity" min="1" class="form-control form-control-sm">
                    @error('productQuantity') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <!-- Price (auto-fill) -->
                <div class="col-md-2">
                    <label class="small font-weight-bold">Price ({{ currency() }})</label>
                    <input type="text" wire:model.live="productPrice" class="form-control form-control-sm" readonly>
                </div>

                <!-- Add Button -->
                <div class="col-md-3 text-right">
                    <button wire:click.prevent="addToCart" class="btn btn-sm btn-success mt-2">
                        <i class="fas fa-plus-circle mr-1"></i>Add to Cart
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CART TABLE -->
    <div class="card">
        <div class="card-body p-0">
            @if(count($cartItems) > 0)
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
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
                                        <button wire:click.prevent="removeFromCart({{ $item['id'] }})" class="btn btn-sm btn-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-light">
                            <tr>
                                <th colspan="4" class="text-right">Grand Total:</th>
                                <th class="text-right">{{ currency() }} {{ number_format($grandTotal, 2) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <div class="p-3 text-center text-muted">
                    <i class="fas fa-shopping-cart fa-2x mb-2"></i>
                    <p class="mb-0">No products in cart</p>
                </div>
            @endif
        </div>

        @if(count($cartItems) > 0)
            <div class="card-footer text-right">
                <button wire:click.prevent="$dispatch('cartUpdated')" class="btn btn-sm btn-primary">
                    <i class="fas fa-save mr-1"></i>Save Cart
                </button>
            </div>
        @endif
    </div>
</div>
