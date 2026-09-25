<div class="bg-gray-50 min-h-screen p-4 md:p-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-3xl font-extrabold text-gray-900">
            Small Shop POS Desk
        </h1>
        <div class="text-sm font-medium text-gray-600">
            User: <span class="text-indigo-600">Cashier Jane Doe</span>
        </div>
    </div>

    <!-- Main POS Grid Layout -->
    <div class="flex flex-col lg:flex-row gap-6">

        <!-- Left Column: Product Search and Cart (Takes 2/3 width on large screens) -->
        <div class="lg:w-2/3 space-y-6">

            <!-- 1. Product Search/Scanner Input -->
            <div class="bg-white p-4 shadow-lg rounded-xl border border-gray-100">
                <label for="product_search" class="sr-only">Search Product by Name or Scan Barcode</label>
                <div class="flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input
                        type="text"
                        id="product_search"
                        placeholder="Scan Barcode or Search Product Name..."
                        wire:model.live.debounce.300ms="searchQuery"
                        class="flex-grow p-3 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150"
                        autocomplete="off"
                    />
                    <button wire:click="addProduct" class="p-3 bg-indigo-600 text-white rounded-lg shadow-md hover:bg-indigo-700 transition duration-150 font-semibold text-sm whitespace-nowrap">
                        Add Item
                    </button>
                </div>
                <!-- Search Results Placeholder (e.g., if Livewire finds results) -->
                @if (isset($searchResults) && count($searchResults) > 0)
                    <div class="mt-2 border-t pt-2">
                        <p class="text-sm text-gray-500">Showing {{ count($searchResults) }} results...</p>
                        <!-- List of search results would go here, each with a wire:click="selectProduct(id)" -->
                    </div>
                @endif
            </div>

            <!-- 2. Cart Items Table -->
            <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 overflow-x-auto">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Current Transaction (Cart)</h2>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Qty</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">Price</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            <th scope="col" class="relative px-6 py-3"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <!-- Example Cart Item -->
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                Organic Coffee Beans
                                <p class="text-xs text-gray-500">SKU: COF001</p>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <input type="number" wire:model.blur="cart.item_id_1.qty" value="2" min="1" class="w-16 border-gray-300 rounded-md text-sm text-center p-1">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 hidden sm:table-cell">$12.50</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">$25.00</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button wire:click="removeItem('item_id_1')" class="text-red-600 hover:text-red-900 transition duration-150">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 10-2 0v6a1 1 0 102 0V8z" clip-rule="evenodd" /></svg>
                                </button>
                            </td>
                        </tr>
                        <!-- More cart items... -->
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                Whole Milk (1 Gallon)
                                <p class="text-xs text-gray-500">SKU: MLK003</p>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <input type="number" wire:model.blur="cart.item_id_2.qty" value="1" min="1" class="w-16 border-gray-300 rounded-md text-sm text-center p-1">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 hidden sm:table-cell">$4.99</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">$4.99</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button wire:click="removeItem('item_id_2')" class="text-red-600 hover:text-red-900 transition duration-150">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 10-2 0v6a1 1 0 102 0V8z" clip-rule="evenodd" /></svg>
                                </button>
                            </td>
                        </tr>
                        <!-- End Example Cart Item -->
                    </tbody>
                </table>

                <!-- Empty State Example -->
                @if (empty($cartItems))
                    <div class="text-center py-12 text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <h3 class="mt-2 text-lg font-medium">Cart is Empty</h3>
                        <p class="mt-1 text-sm">Start by scanning a product or using the search bar above.</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Right Column: Summary and Payment (Takes 1/3 width on large screens) -->
        <div class="lg:w-1/3 space-y-6">

            <!-- 3. Transaction Summary -->
            <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100">
                <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">Order Summary</h2>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Subtotal (3 Items)</span>
                        <span class="font-medium text-gray-800">$29.99</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Tax (8%)</span>
                        <span class="font-medium text-red-500">$2.40</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Discount Applied</span>
                        <span class="font-medium text-green-600">-$5.00</span>
                    </div>
                </div>

                <div class="mt-4 pt-4 border-t-2 border-dashed border-gray-300 flex justify-between items-center">
                    <span class="text-2xl font-extrabold text-gray-900">GRAND TOTAL</span>
                    <span class="text-3xl font-extrabold text-indigo-600">$27.39</span>
                </div>
            </div>

            <!-- 4. Payment & Action Buttons -->
            <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 space-y-4">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Payment Options</h2>
                
                <div class="grid grid-cols-2 gap-3">
                    <button wire:click="pay('cash')" class="py-3 px-4 bg-green-500 text-white font-bold rounded-lg shadow-md hover:bg-green-600 transition duration-150 text-center text-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        Cash
                    </button>
                    <button wire:click="pay('card')" class="py-3 px-4 bg-indigo-500 text-white font-bold rounded-lg shadow-md hover:bg-indigo-600 transition duration-150 text-center text-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                        Card
                    </button>
                </div>

                <button wire:click="finalizeTransaction" class="w-full py-3 bg-blue-700 text-white font-extrabold rounded-lg shadow-xl hover:bg-blue-800 transition duration-150 text-lg">
                    FINALIZE (Total: $27.39)
                </button>

                <div class="grid grid-cols-2 gap-3 text-sm">
                    <button wire:click="cancelTransaction" class="py-2 bg-red-100 text-red-600 font-semibold rounded-lg hover:bg-red-200 transition duration-150">
                        Cancel Sale
                    </button>
                    <button wire:click="holdTransaction" class="py-2 bg-yellow-100 text-yellow-600 font-semibold rounded-lg hover:bg-yellow-200 transition duration-150">
                        Hold Order
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>