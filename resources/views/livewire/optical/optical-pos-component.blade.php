<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading">
        <div>
            <h1>Optical Retail POS (Walk-in Counter)</h1>
            <p class="ui-muted">Walk-in retail checkout for accessories, contact lens solution, ready readers, and cleaning sprays (No Rx needed).</p>
        </div>
        <span class="ui-badge" style="background:#dcfce7; color:#166534;">POS Register Active</span>
    </div>

    <x-ui.flash :link="$lastSaleId ? route('optical.receipt', $lastSaleId) : null" link-label="Print receipt" link-new-tab />
    @error('cart') <div class="ui-panel p-3 text-red-700" role="alert">{{ $message }}</div> @enderror
    @error('discount') <div class="ui-panel p-3 text-red-700" role="alert">{{ $message }}</div> @enderror

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Products & Search -->
        <div class="lg:col-span-2 space-y-4">
            <div class="ui-panel p-3">
                <input autocomplete="off" type="search" wire:model.live.debounce.250ms="searchTerm" placeholder="Scan SKU or search optical products..." class="ui-input">
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @forelse($products as $prod)
                    @php $isOpticalSku = $prod instanceof \App\Models\OpticalProduct; $available = $isOpticalSku ? app(\App\Services\OpticalProductInventoryService::class)->available($prod) : app(\App\Services\Inventory\BranchInventoryService::class)->quantity($prod); @endphp
                    <div class="ui-panel p-3 flex flex-col justify-between space-y-2" wire:key="pos-product-{{ $isOpticalSku ? 'o' : 'legacy' }}-{{ $prod->id }}">
                        <div>
                            <h3 class="font-bold text-slate-800 text-xs">{{ $prod->name }}</h3>
                            <p class="ui-muted text-[11px]">{{ $isOpticalSku ? $prod->category?->name : ($prod->opticalCategory?->name ?? $prod->category?->name) }} · {{ $available }} in stock</p>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                            <span class="font-bold text-slate-900 text-sm">{{ currency() }} {{ number_format($prod->selling_price, 2) }}</span>
                            <button wire:click="addToCart({{ $isOpticalSku ? "'o:{$prod->id}'" : $prod->id }})" class="ui-button ui-button-secondary text-xs py-1 px-2" @disabled($available < 1)>
                                + Add
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="ui-panel p-6 text-center text-slate-500 text-sm">No optical products found. Add products in Catalogue &amp; Stock.</div>
                @endforelse
            </div>
        </div>

        <!-- POS Cart Drawer -->
        <div class="ui-panel p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900 border-b border-slate-200 pb-2">POS Cart</h2>

            <div class="space-y-2 text-xs">
                @forelse($cart as $id => $item)
                    <div class="flex items-center justify-between bg-slate-50 p-2 rounded border border-slate-200">
                        <div>
                            <p class="font-semibold text-slate-800">{{ $item['name'] }}</p>
                            <p class="ui-muted">{{ $item['qty'] }} x {{ currency() }} {{ number_format($item['price'], 2) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-900">{{ currency() }} {{ number_format($item['price'] * $item['qty'], 2) }}</span>
                            <button wire:click="removeFromCart('{{ $id }}')" class="text-red-600 font-bold hover:underline">✕</button>
                        </div>
                    </div>
                @empty
                    <div class="ui-empty py-8">
                        <p class="ui-muted">Cart is empty. Click items to add.</p>
                    </div>
                @endforelse
            </div>

            <div class="border-t border-slate-200 pt-3 space-y-2 text-xs">
                <div class="grid grid-cols-2 gap-2">
                    <label class="font-semibold text-slate-700">Customer (optional)<input autocomplete="off" type="text" wire:model="customerName" maxlength="255" placeholder="Walk-in" class="ui-input mt-1 text-xs"></label>
                    <label class="font-semibold text-slate-700">Phone<input autocomplete="off" type="tel" wire:model="customerPhone" maxlength="30" class="ui-input mt-1 text-xs"></label>
                </div>
                @error('customerName')<p class="text-red-700">{{ $message }}</p>@enderror
                @error('customerPhone')<p class="text-red-700">{{ $message }}</p>@enderror
                <div class="flex justify-between text-slate-700">
                    <span>Subtotal:</span>
                    <span class="font-bold">{{ currency() }} {{ number_format($subtotal, 2) }}</span>
                </div>
                <label class="flex items-center justify-between gap-2 text-slate-700">
                    <span>Discount ({{ currency() }})<span class="block text-[10px] text-slate-500">Up to {{ $discountLimit }}% without a manager</span></span>
                    <input autocomplete="off" type="number" min="0" step="0.01" wire:model="discount" class="ui-input w-28 text-right text-xs">
                </label>
                <div class="flex justify-between text-slate-900 text-sm font-bold border-t border-slate-200 pt-2">
                    <span>Total Amount:</span>
                    {{-- The discount is taken off in the browser; keyed on the subtotal, which changes with the cart (a server call). Checkout checks the discount again. --}}
                    <span class="text-teal-800 text-base" wire:key="pos-total-{{ $subtotal }}" x-data="{ subtotal: {{ (float) $subtotal }} }" x-text="@js(currency()) + ' ' + money(subtotal - Math.min(subtotal, Math.max(0, Math.round(num($wire.discount) * 100) / 100)))">{{ currency() }} {{ number_format($total, 2) }}</span>
                </div>
            </div>

            <div class="space-y-3 border-t border-slate-200 pt-3">
                <label class="block text-xs font-semibold text-slate-700">Payment Method</label>
                <div class="grid grid-cols-2 gap-1">
                    @foreach($methods as $method => $methodLabel)
                        {{-- Picking a method is kept in the browser; Complete checkout sends it. --}}
                        <button type="button" x-on:click="$wire.$set('paymentMethod', @js($method), false)" class="ui-button text-xs py-1" :class="$wire.paymentMethod === @js($method) ? 'ui-button-primary' : 'ui-button-secondary'" :aria-pressed="($wire.paymentMethod === @js($method)).toString()">{{ $methodLabel }}</button>
                    @endforeach
                </div>
                <button wire:click="completeSale" class="ui-button ui-button-primary w-full py-2.5 text-sm font-bold shadow" {{ empty($cart) ? 'disabled' : '' }}>
                    Complete POS Checkout
                </button>
            </div>
        </div>
    </div>
</div>
