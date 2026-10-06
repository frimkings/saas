<div class="clinic-ui ui-page">
<div>
    {{-- Header --}}
    <div class="content-header">
        <div class="w-full">
            <div class="flex flex-wrap -mx-2 mb-2">
                <div class="w-full sm:w-6/12 px-2">
                    <h1 class="m-0">Products Management</h1>
                </div>
                <div class="w-full sm:w-6/12 px-2">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
                        <li class="breadcrumb-item active">Products</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="w-full">
            {{-- Action Buttons --}}
            <div class="mb-4 flex justify-between items-center">
                <div>
                    @if(!$showForm)
                        <button wire:click="showAddForm" class="btn ui-button ui-button-primary">
                            <i class="fa fa-plus-circle mr-1"></i> Add New Product
                        </button>
                    @endif
                    <button wire:click="exportCsv" class="btn ui-button ui-button-primary"
                            wire:loading.attr="disabled" wire:target="exportCsv">
                        <span wire:loading.remove wire:target="exportCsv">
                            <i class="fa fa-download mr-1"></i> Export CSV
                        </span>
                        <span wire:loading wire:target="exportCsv">
                            <i class="fa fa-spinner fa-spin mr-1"></i> Exporting...
                        </span>
                    </button>
                    <button wire:click="$toggle('showImportPanel')" class="btn ui-button ui-button-secondary">
                        <i class="fa fa-file-import mr-1"></i> Import CSV
                    </button>
                    <button wire:click="downloadTemplate" class="btn ui-button ui-button-secondary">
                        <i class="fa fa-file-download mr-1"></i> Template
                    </button>
                    <button wire:click="toggleLensCatalog" class="btn ui-button {{ $showLensCatalog ? 'ui-button-primary' : 'ui-button-secondary' }}">
                        <i class="fa fa-glasses mr-1"></i> Lens Catalog
                    </button>
                </div>
            </div>

            @if($showLensCatalog)
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-info mb-4">
                    <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                        <h3 class="font-semibold"><i class="fa fa-glasses mr-2"></i>Lens Catalog</h3>
                        <div class="ml-auto flex items-center gap-1">
                            <button type="button" class="btn ui-button btn-tool ui-button-secondary" wire:click="toggleLensCatalog"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form wire:submit="saveLensOption" class="flex flex-wrap -mx-2 items-end mb-6">
                            <div class="w-full md:w-4/12 px-2">
                                <div class="mb-4 md:mb-0">
                                    <label>Lens Family <span class="text-red-700">*</span></label>
                                    <select wire:model="lensState.family" class="form-control ui-input @error('family') is-invalid @enderror">
                                        <option value="">Select family...</option>
                                        @foreach(\App\Models\LensOption::FAMILIES as $family)
                                            <option value="{{ $family }}">{{ $family }}</option>
                                        @endforeach
                                    </select>
                                    @error('family') <div class="ui-error">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="w-full md:w-5/12 px-2">
                                <div class="mb-4 md:mb-0">
                                    <label>Display Name <span class="text-red-700">*</span></label>
                                    <input type="text" wire:model="lensState.display_name"
                                           class="form-control ui-input @error('display_name') is-invalid @enderror"
                                           placeholder="e.g. SV Blue Block">
                                    @error('display_name') <div class="ui-error">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="w-full md:w-3/12 px-2">
                                <button type="submit" class="btn ui-button ui-button-primary">
                                    <i class="fa fa-save mr-1"></i>{{ $editingLensOption ? 'Update' : 'Add' }} Option
                                </button>
                                @if($editingLensOption)
                                    <button type="button" wire:click="cancelLensOption" class="btn ui-button ui-button-secondary">Cancel</button>
                                @endif
                            </div>
                        </form>

                        <div class="flex flex-wrap -mx-2">
                            @foreach(\App\Models\LensOption::FAMILIES as $family)
                                <div class="w-full lg:w-4/12 px-2 mb-4">
                                    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white h-full mb-0">
                                        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2"><strong>{{ $family }}</strong></div>
                                        <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                                            @forelse($lensOptions->where('family', $family) as $lensOption)
                                                <div class="list-group-item block w-full border-b border-slate-100 px-3 text-left flex justify-between items-center py-2">
                                                    <span>{{ $lensOption->display_name }}</span>
                                                    <div class="inline-flex flex-wrap gap-1">
                                                        <button type="button" wire:click="editLensOption({{ $lensOption->id }})" class="btn ui-button ui-button-secondary" title="Edit">
                                                            <i class="fa fa-edit"></i>
                                                        </button>
                                                        <button type="button" wire:click="deleteLensOption({{ $lensOption->id }})"
                                                                wire:confirm="Remove this lens option? Existing refraction records will remain unchanged."
                                                                class="btn ui-button ui-button-danger" title="Delete">
                                                            <i class="fa fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            @empty
                                                <div class="list-group-item block w-full border-b border-slate-100 px-3 py-2 text-left text-slate-500">No options configured.</div>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <small class="text-slate-500">Changes affect future selections only. Existing refraction values remain stored in the current lensType field.</small>
                    </div>
                </div>
            @endif

            @if($showImportPanel)
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-primary mb-4">
                    <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                        <h3 class="font-semibold">
                            <i class="fa fa-file-import mr-2"></i>Import Products from CSV
                        </h3>
                        <div class="ml-auto flex items-center gap-1">
                            <button type="button" class="btn ui-button btn-tool ui-button-secondary" wire:click="clearImport">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @if($importResults)
                            <div class="flex flex-wrap -mx-2">
                                <div class="w-full md:w-4/12 px-2">
                                    <div class="small-box bg-green-600 text-white">
                                        <div class="inner">
                                            <h3>{{ $importResults['imported'] }}</h3>
                                            <p>Imported</p>
                                        </div>
                                        <div class="icon"><i class="fas fa-check-circle"></i></div>
                                    </div>
                                </div>
                                <div class="w-full md:w-4/12 px-2">
                                    <div class="small-box bg-slate-500 text-white">
                                        <div class="inner">
                                            <h3>{{ $importResults['skipped'] }}</h3>
                                            <p>Skipped</p>
                                        </div>
                                        <div class="icon"><i class="fas fa-forward"></i></div>
                                    </div>
                                </div>
                                <div class="w-full md:w-4/12 px-2">
                                    <div class="small-box bg-red-600 text-white">
                                        <div class="inner">
                                            <h3>{{ count($importResults['errors']) }}</h3>
                                            <p>Errors</p>
                                        </div>
                                        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                                    </div>
                                </div>
                            </div>
                            @if(count($importResults['errors']) > 0)
                                <div class="rounded-lg border px-3 py-2 text-sm border-amber-200 bg-amber-50 text-amber-900">
                                    <strong>Rows needing attention:</strong>
                                    <ul class="mb-0 mt-2">
                                        @foreach($importResults['errors'] as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <button class="btn ui-button ui-button-primary ui-button-sm" wire:click="clearImport">
                                <i class="fa fa-check mr-1"></i>Done
                            </button>
                        @else
                            <div class="flex flex-wrap -mx-2">
                                <div class="w-full md:w-7/12 px-2">
                                    <p class="text-slate-500 mb-2">
                                        Required columns: <code>name</code>, <code>category</code>, <code>batch_number</code>,
                                        <code>quantity</code>, <code>cost_price</code>, <code>selling_price</code>,
                                        <code>manufacture_date</code>, <code>expiry_date</code>.
                                    </p>
                                    <p class="text-slate-500 mb-0">
                                        Category may be the category name or ID. Dates should be <code>YYYY-MM-DD</code>.
                                        Existing product names or batch numbers are skipped with row errors.
                                    </p>
                                </div>
                                <div class="w-full md:w-5/12 px-2">
                                    <div class="block">
                                        <input type="file"
                                               class="block w-full text-sm @error('importFile') is-invalid @enderror"
                                               id="productImportFile"
                                               accept=".csv,text/csv,text/plain"
                                               wire:model.live="importFile">
                                        <label class="hidden" for="productImportFile">
                                            {{ $importFile ? $importFile->getClientOriginalName() : 'Choose CSV file' }}
                                        </label>
                                        @error('importFile') <span class="ui-error block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mt-4 flex" style="gap:.5rem;">
                                        <button class="btn ui-button ui-button-primary"
                                                wire:click="importCsv"
                                                wire:loading.attr="disabled"
                                                wire:target="importCsv,importFile"
                                                {{ !$importFile ? 'disabled' : '' }}>
                                            <span wire:loading.remove wire:target="importCsv">
                                                <i class="fa fa-upload mr-1"></i>Run Import
                                            </span>
                                            <span wire:loading wire:target="importCsv">
                                                <i class="fa fa-spinner fa-spin mr-1"></i>Importing...
                                            </span>
                                        </button>
                                        <button class="btn ui-button ui-button-secondary" wire:click="downloadTemplate">
                                            <i class="fa fa-file-download mr-1"></i>Template
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Inline Add/Edit Form --}}
            @if($showForm)
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-primary mb-4">
                    <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                        <h3 class="font-semibold">
                            <i class="fa fa-{{ $editingProduct ? 'edit' : 'plus-circle' }} mr-2"></i>
                            {{ $editingProduct ? 'Edit Product' : 'Add New Product' }}
                        </h3>
                        <div class="ml-auto flex items-center gap-1">
                            <button type="button" class="btn ui-button btn-tool ui-button-secondary" wire:click="cancelForm">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <form wire:submit="{{ $editingProduct ? 'updateProduct' : 'createProduct' }}">
                        <div class="card-body p-4">
                            <div class="flex flex-wrap -mx-2">
                                <div class="w-full md:w-4/12 px-2">
                                    <div class="mb-4">
                                        <label>Name <span class="text-red-700">*</span></label>
                                        <input type="text" wire:model="state.name"
                                               class="form-control ui-input @error('name') is-invalid @enderror"
                                               placeholder="Product name">
                                        @error('name') <span class="ui-error">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="w-full md:w-4/12 px-2">
                                    <div class="mb-4">
                                        <label>Batch Number <span class="text-red-700">*</span></label>
                                        <input type="text" wire:model="state.batch_number"
                                               class="form-control ui-input @error('batch_number') is-invalid @enderror"
                                               placeholder="e.g., BTH001">
                                        @error('batch_number') <span class="ui-error">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="w-full md:w-4/12 px-2">
                                    <div class="mb-4">
                                        <label>Clinic Category</label>
                                        <select wire:model.live="state.category_id"
                                                class="form-control ui-input @error('category_id') is-invalid @enderror">
                                            <option value="">-- Select clinic category --</option>
                                            @foreach($categories as $cat)
                                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('category_id') <span class="ui-error">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap -mx-2">
                                <div class="w-full md:w-3/12 px-2">
                                    <div class="mb-4">
                                        @if(empty($state['made_to_order']))
                                            <label>Quantity <span class="text-red-700">*</span></label>
                                            <input type="number" min="0" wire:model="state.quantity"
                                                   class="form-control ui-input @error('quantity') is-invalid @enderror"
                                                   placeholder="0">
                                            @error('quantity') <span class="ui-error">{{ $message }}</span> @enderror
                                        @else
                                            <label>Quantity</label>
                                            <p class="block w-full py-2 text-slate-500">Not counted</p>
                                        @endif
                                        <div class="flex items-center gap-2 mt-1">
                                            <input type="checkbox" class="rounded border-slate-300 text-teal-700" id="made-to-order" wire:model.live="state.made_to_order">
                                            <label class="" for="made-to-order">Made to order (not stocked)</label>
                                        </div>
                                        <small class="mt-1 block text-xs text-slate-500">For lenses ordered per job from a lab. Sold and billed at these prices, never counted in stock.</small>
                                        @if(!empty($state['made_to_order']) && $editingProduct && !$editingProduct->made_to_order && $editingProduct->quantity > 0)
                                            <small class="mt-1 block text-xs text-amber-600">Saving clears the current quantity of {{ $editingProduct->quantity }}. The old number is kept in the audit trail.</small>
                                        @endif
                                    </div>
                                </div>

                                <div class="w-full md:w-3/12 px-2">
                                    <div class="mb-4">
                                        <label>Cost Price <span class="text-red-700">*</span></label>
                                        <input type="number" step="0.01" min="0" wire:model.blur="state.cost_price"
                                               class="form-control ui-input @error('cost_price') is-invalid @enderror"
                                               placeholder="0.00">
                                        @error('cost_price') <span class="ui-error">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="w-full md:w-3/12 px-2">
                                    <div class="mb-4">
                                        <label>Selling Price <span class="text-red-700">*</span></label>
                                        <input type="number" step="0.01" min="0" wire:model.blur="state.selling_price"
                                               class="form-control ui-input @error('selling_price') is-invalid @enderror"
                                               placeholder="0.00">
                                        @error('selling_price') <span class="ui-error">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="w-full md:w-3/12 px-2">
                                    <div class="mb-4">
                                        <label>Profit Margin
                                            <small class="text-slate-500">(or enter to set selling price)</small>
                                        </label>
                                        <div class="flex items-stretch">
                                            <input type="number" step="0.01" min="0"
                                                   wire:model.blur="state.profit_margin"
                                                   class="form-control ui-input"
                                                   placeholder="e.g. 20">
                                            <div class="flex">
                                                <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">%</span>
                                            </div>
                                        </div>
                                        <small class="text-slate-500">Updates selling price when cost price is set.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap -mx-2">
                                <div class="w-full md:w-6/12 px-2">
                                    <div class="mb-4">
                                        <label>Manufacture Date <span class="text-red-700">*</span></label>
                                        <input type="date" wire:model="state.manufacture_date"
                                               class="form-control ui-input @error('manufacture_date') is-invalid @enderror">
                                        @error('manufacture_date') <span class="ui-error">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="w-full md:w-6/12 px-2">
                                    <div class="mb-4">
                                        <label>Expiry Date <span class="text-red-700">*</span></label>
                                        <input type="date" wire:model="state.expiry_date"
                                               class="form-control ui-input @error('expiry_date') is-invalid @enderror">
                                        @error('expiry_date') <span class="ui-error">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">
                            <button type="submit" class="btn ui-button ui-button-primary">
                                <i class="fa fa-save mr-1"></i>
                                {{ $editingProduct ? 'Update Product' : 'Save Product' }}
                            </button>
                            <button type="button" wire:click="cancelForm" class="btn ui-button ui-button-secondary">
                                <i class="fa fa-times mr-1"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Tabs Card --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-primary card-outline-tabs">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 p-0 border-b-0">
                    <ul class="flex flex-wrap border-b border-slate-200" role="tablist">
                        <li class="">
                            <a class="block px-3 py-2 {{ $activeTab === 'all' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'all')" 
                               href="#" role="tab">
                                <i class="fa fa-boxes mr-1"></i>
                                All Products
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-teal-100 text-teal-800 ml-2">{{ $stats['total'] }}</span>
                            </a>
                        </li>
                        <li class="">
                            <a class="block px-3 py-2 {{ $activeTab === 'low-stock' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'low-stock')" 
                               href="#" role="tab">
                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                Low Stock
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-2">{{ $stats['low_stock'] }}</span>
                            </a>
                        </li>
                        <li class="">
                            <a class="block px-3 py-2 {{ $activeTab === 'expiring' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'expiring')" 
                               href="#" role="tab">
                                <i class="fa fa-clock mr-1"></i>
                                Expiring Soon (4 Months)
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-2">{{ $stats['expiring'] }}</span>
                            </a>
                        </li>
                        <li class="">
                            <a class="block px-3 py-2 {{ $activeTab === 'expired' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'expired')" 
                               href="#" role="tab">
                                <i class="fa fa-times-circle mr-1"></i>
                                Expired
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 ml-2">{{ $stats['expired'] }}</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="card-body p-4">
                    @if(count($selectedProductIds) > 0)
                        <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 flex items-center justify-between">
                            <div>
                                <i class="fa fa-check-square mr-1"></i>
                                <strong>{{ count($selectedProductIds) }}</strong> product(s) selected
                            </div>
                            <div>
                                <button class="btn ui-button ui-button-sm ui-button-danger"
                                        wire:click="deleteSelected"
                                        wire:confirm="Delete selected products? This cannot be undone.">
                                    <i class="fa fa-trash mr-1"></i>Delete Selected
                                </button>
                                <button class="btn ui-button ui-button-sm ui-button-secondary" wire:click="clearSelection">
                                    Clear
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Search Form --}}
                    <div class="flex flex-wrap -mx-2 mb-4">
                        <div class="w-full md:w-6/12 px-2">
                            <div class="flex items-stretch">
                                <input type="text" 
                                       wire:model.live.debounce.500ms="searchTerm"
                                       class="form-control ui-input" 
                                       placeholder="Search by name, batch number{{ $searchByCategory ? '' : ' or category' }}...">
                                <div class="flex">
                                    <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">
                                        <i class="fa fa-search"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="w-full md:w-6/12 px-2">
                            <div class="flex items-center gap-2 inline-block mr-4">
                                <input type="checkbox" 
                                       class="rounded border-slate-300 text-teal-700" 
                                       id="searchByCategoryToggle" 
                                       wire:model.live="searchByCategory">
                                <label class="" for="searchByCategoryToggle">
                                    Search by Category
                                </label>
                            </div>

                            @if($searchByCategory)
                                <div class="inline-block" style="width: 250px;">
                                    <select wire:model.live="selectedCategoryFilter" class="form-control ui-input ui-input-sm">
                                        <option value="">-- All Categories --</option>
                                        @foreach($categories as $cat)
                                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Products Table --}}
                    <div class="ui-table-wrap">
                        <table class="table ui-table">
                            <thead class="">
                                <tr>
                                    <th style="width: 42px;" class="text-center">
                                        <input type="checkbox"
                                               class="product-row-check"
                                               wire:model.live="selectAllPage"
                                               title="Select all visible products">
                                    </th>
                                    <th style="width: 50px;">#</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Batch</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-right">Cost Price</th>
                                    <th class="text-right">Selling Price</th>
                                    <th>Manufacture Date</th>
                                    <th>Expiry Date</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center" style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="text-muted">
                                @forelse ($products as $product)
                                    @php
                                        $expiryDate = $product->expiry_date->startOfDay();
                                        $today = \Carbon\Carbon::today();
                                        $isExpired = $expiryDate->lt($today);
                                        $daysToExpiry = $isExpired ? 0 : $today->diffInDays($expiryDate);
                                        $isExpiringSoon = !$isExpired && $daysToExpiry <= 120;
                                        $isLowStock = !$product->made_to_order && $product->quantity < 10;
                                    @endphp
                                    <tr class="{{ $isExpired ? 'bg-red-50' : ($isExpiringSoon ? 'bg-amber-50' : '') }}">
                                        <td class="text-center">
                                            <input type="checkbox"
                                                   class="product-row-check"
                                                   value="{{ $product->id }}"
                                                   wire:model.live="selectedProductIds">
                                        </td>
                                        <td>{{ $loop->iteration + ($products->currentPage() - 1) * $products->perPage() }}</td>
                                        <td>
                                            <strong>{{ $product->name }}</strong>
                                        </td>
                                        <td>
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800">{{ $product->category->name ?? 'N/A' }}</span>
                                        </td>
                                        <td>
                                            <code>{{ $product->batch_number }}</code>
                                        </td>
                                        <td class="text-center">
                                            @if($product->made_to_order)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">Made to order</span>
                                            @else
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $isLowStock ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' }}">
                                                    {{ $product->quantity }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ currency() }} {{ number_format($product->cost_price, 2) }}</td>
                                        <td class="text-right">
                                            <strong>{{ currency() }} {{ number_format($product->selling_price, 2) }}</strong>
                                        </td>
                                        <td>
                                            <small>{{ $product->manufacture_date->format('M d, Y') }}</small>
                                        </td>
                                        <td>
                                            <small>{{ $product->expiry_date->format('M d, Y') }}</small>
                                        </td>
                                        <td class="text-center">
                                            @if($isExpired)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800">
                                                    <i class="fa fa-times-circle"></i> Expired
                                                </span>
                                            @elseif($isExpiringSoon)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">
                                                    <i class="fa fa-clock"></i> {{ $daysToExpiry }}d left
                                                </span>
                                            @elseif($isLowStock)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">
                                                    <i class="fa fa-exclamation-triangle"></i> Low Stock
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">
                                                    <i class="fa fa-check-circle"></i> Active
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <div class="inline-flex flex-wrap gap-1">
                                                <button wire:click="editProduct({{ $product->id }})" 
                                                        class="btn ui-button ui-button-primary" 
                                                        title="Edit">
                                                    <i class="fa fa-edit"></i>
                                                </button>
                                                <button wire:click="confirmDelete({{ $product->id }})" 
                                                        class="btn ui-button ui-button-danger" 
                                                        title="Delete">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="text-center py-6">
                                            <i class="fa fa-inbox fa-3x text-slate-500 mb-4"></i>
                                            <p class="text-slate-500">No products found</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($products->hasPages())
                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">
                        {{ $products->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Loading Indicator (scoped to heavy actions only) --}}
    <div wire:loading.delay wire:target="createProduct,updateProduct,confirmProductDelete"
         class="fixed" style="top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999;">
        <div class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-current border-r-transparent text-teal-700" role="status" style="width: 3rem; height: 3rem;">
            <span class="sr-only">Loading...</span>
        </div>
    </div>
</div>

<style>
    .nav-tabs .nav-link {
        border: none;
        border-bottom: 3px solid transparent;
        color: #6c757d;
        font-weight: 500;
    }

    .nav-tabs .nav-link:hover {
        border-color: transparent;
        border-bottom-color: #dee2e6;
        color: #495057;
    }

    .nav-tabs .nav-link.active {
        color: #007bff;
        border-color: transparent;
        border-bottom-color: #007bff;
        background-color: transparent;
    }

    .table-responsive {
        max-height: 600px;
        overflow-y: auto;
    }

    .table thead th {
        position: sticky;
        top: 0;
        background-color: #f8f9fa;
        z-index: 10;
    }

    .custom-control-label {
        cursor: pointer;
        user-select: none;
    }

    .btn-group-sm .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }

    .product-row-check {
        cursor: pointer;
        height: 16px;
        width: 16px;
    }

    .custom-file-label {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    @media (max-width: 768px) {
        .table {
            font-size: 0.875rem;
        }
        
        .nav-tabs .nav-link {
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
        }
    }
</style>
</div>
