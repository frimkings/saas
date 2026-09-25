<div>
<div>
    {{-- Header --}}
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Products Management</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
                        <li class="breadcrumb-item active">Products</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            {{-- Action Buttons --}}
            <div class="mb-3 d-flex justify-content-between align-items-center">
                <div>
                    @if(!$showForm)
                        <button wire:click="showAddForm" class="btn btn-primary">
                            <i class="fa fa-plus-circle mr-1"></i> Add New Product
                        </button>
                    @endif
                    <button wire:click="exportCsv" class="btn btn-success"
                            wire:loading.attr="disabled" wire:target="exportCsv">
                        <span wire:loading.remove wire:target="exportCsv">
                            <i class="fa fa-download mr-1"></i> Export CSV
                        </span>
                        <span wire:loading wire:target="exportCsv">
                            <i class="fa fa-spinner fa-spin mr-1"></i> Exporting...
                        </span>
                    </button>
                    <button wire:click="$toggle('showImportPanel')" class="btn btn-outline-primary">
                        <i class="fa fa-file-import mr-1"></i> Import CSV
                    </button>
                    <button wire:click="downloadTemplate" class="btn btn-outline-secondary">
                        <i class="fa fa-file-download mr-1"></i> Template
                    </button>
                    <button wire:click="toggleLensCatalog" class="btn {{ $showLensCatalog ? 'btn-info' : 'btn-outline-info' }}">
                        <i class="fa fa-glasses mr-1"></i> Lens Catalog
                    </button>
                </div>
            </div>

            @if($showLensCatalog)
                <div class="card card-outline card-info mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-glasses mr-2"></i>Lens Catalog</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" wire:click="toggleLensCatalog"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form wire:submit="saveLensOption" class="row align-items-end mb-4">
                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label>Lens Family <span class="text-danger">*</span></label>
                                    <select wire:model="lensState.family" class="form-control @error('family') is-invalid @enderror">
                                        <option value="">Select family...</option>
                                        @foreach(\App\Models\LensOption::FAMILIES as $family)
                                            <option value="{{ $family }}">{{ $family }}</option>
                                        @endforeach
                                    </select>
                                    @error('family') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="form-group mb-md-0">
                                    <label>Display Name <span class="text-danger">*</span></label>
                                    <input type="text" wire:model="lensState.display_name"
                                           class="form-control @error('display_name') is-invalid @enderror"
                                           placeholder="e.g. SV Blue Block">
                                    @error('display_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-info">
                                    <i class="fa fa-save mr-1"></i>{{ $editingLensOption ? 'Update' : 'Add' }} Option
                                </button>
                                @if($editingLensOption)
                                    <button type="button" wire:click="cancelLensOption" class="btn btn-default">Cancel</button>
                                @endif
                            </div>
                        </form>

                        <div class="row">
                            @foreach(\App\Models\LensOption::FAMILIES as $family)
                                <div class="col-lg-4 mb-3">
                                    <div class="card h-100 mb-0">
                                        <div class="card-header py-2"><strong>{{ $family }}</strong></div>
                                        <div class="list-group list-group-flush">
                                            @forelse($lensOptions->where('family', $family) as $lensOption)
                                                <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                                    <span>{{ $lensOption->display_name }}</span>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" wire:click="editLensOption({{ $lensOption->id }})" class="btn btn-outline-info" title="Edit">
                                                            <i class="fa fa-edit"></i>
                                                        </button>
                                                        <button type="button" wire:click="deleteLensOption({{ $lensOption->id }})"
                                                                onclick="return confirm('Remove this lens option? Existing refraction records will remain unchanged.')"
                                                                class="btn btn-outline-danger" title="Delete">
                                                            <i class="fa fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            @empty
                                                <div class="list-group-item text-muted">No options configured.</div>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <small class="text-muted">Changes affect future selections only. Existing refraction values remain stored in the current lensType field.</small>
                    </div>
                </div>
            @endif

            @if($showImportPanel)
                <div class="card card-outline card-primary mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-file-import mr-2"></i>Import Products from CSV
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" wire:click="clearImport">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($importResults)
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="small-box bg-success">
                                        <div class="inner">
                                            <h3>{{ $importResults['imported'] }}</h3>
                                            <p>Imported</p>
                                        </div>
                                        <div class="icon"><i class="fas fa-check-circle"></i></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="small-box bg-secondary">
                                        <div class="inner">
                                            <h3>{{ $importResults['skipped'] }}</h3>
                                            <p>Skipped</p>
                                        </div>
                                        <div class="icon"><i class="fas fa-forward"></i></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="small-box bg-danger">
                                        <div class="inner">
                                            <h3>{{ count($importResults['errors']) }}</h3>
                                            <p>Errors</p>
                                        </div>
                                        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                                    </div>
                                </div>
                            </div>
                            @if(count($importResults['errors']) > 0)
                                <div class="alert alert-warning">
                                    <strong>Rows needing attention:</strong>
                                    <ul class="mb-0 mt-2">
                                        @foreach($importResults['errors'] as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <button class="btn btn-primary btn-sm" wire:click="clearImport">
                                <i class="fa fa-check mr-1"></i>Done
                            </button>
                        @else
                            <div class="row">
                                <div class="col-md-7">
                                    <p class="text-muted mb-2">
                                        Required columns: <code>name</code>, <code>category</code>, <code>batch_number</code>,
                                        <code>quantity</code>, <code>cost_price</code>, <code>selling_price</code>,
                                        <code>manufacture_date</code>, <code>expiry_date</code>.
                                    </p>
                                    <p class="text-muted mb-0">
                                        Category may be the category name or ID. Dates should be <code>YYYY-MM-DD</code>.
                                        Existing product names or batch numbers are skipped with row errors.
                                    </p>
                                </div>
                                <div class="col-md-5">
                                    <div class="custom-file">
                                        <input type="file"
                                               class="custom-file-input @error('importFile') is-invalid @enderror"
                                               id="productImportFile"
                                               accept=".csv,text/csv,text/plain"
                                               wire:model.live="importFile">
                                        <label class="custom-file-label" for="productImportFile">
                                            {{ $importFile ? $importFile->getClientOriginalName() : 'Choose CSV file' }}
                                        </label>
                                        @error('importFile') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mt-3 d-flex" style="gap:.5rem;">
                                        <button class="btn btn-primary"
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
                                        <button class="btn btn-outline-secondary" wire:click="downloadTemplate">
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
                <div class="card card-primary mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-{{ $editingProduct ? 'edit' : 'plus-circle' }} mr-2"></i>
                            {{ $editingProduct ? 'Edit Product' : 'Add New Product' }}
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" wire:click="cancelForm">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <form wire:submit="{{ $editingProduct ? 'updateProduct' : 'createProduct' }}">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Name <span class="text-danger">*</span></label>
                                        <input type="text" wire:model="state.name"
                                               class="form-control @error('name') is-invalid @enderror"
                                               placeholder="Product name">
                                        @error('name') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Batch Number <span class="text-danger">*</span></label>
                                        <input type="text" wire:model="state.batch_number"
                                               class="form-control @error('batch_number') is-invalid @enderror"
                                               placeholder="e.g., BTH001">
                                        @error('batch_number') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Clinic Category</label>
                                        <select wire:model.live="state.category_id"
                                                class="form-control @error('category_id') is-invalid @enderror">
                                            <option value="">-- Select clinic category --</option>
                                            @foreach($categories as $cat)
                                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('category_id') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Quantity <span class="text-danger">*</span></label>
                                        <input type="number" min="0" wire:model="state.quantity"
                                               class="form-control @error('quantity') is-invalid @enderror"
                                               placeholder="0">
                                        @error('quantity') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Cost Price <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" min="0" wire:model.blur="state.cost_price"
                                               class="form-control @error('cost_price') is-invalid @enderror"
                                               placeholder="0.00">
                                        @error('cost_price') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Selling Price <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" min="0" wire:model.blur="state.selling_price"
                                               class="form-control @error('selling_price') is-invalid @enderror"
                                               placeholder="0.00">
                                        @error('selling_price') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Profit Margin
                                            <small class="text-muted">(or enter to set selling price)</small>
                                        </label>
                                        <div class="input-group">
                                            <input type="number" step="0.01" min="0"
                                                   wire:model.blur="state.profit_margin"
                                                   class="form-control"
                                                   placeholder="e.g. 20">
                                            <div class="input-group-append">
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </div>
                                        <small class="text-muted">Updates selling price when cost price is set.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Manufacture Date <span class="text-danger">*</span></label>
                                        <input type="date" wire:model="state.manufacture_date"
                                               class="form-control @error('manufacture_date') is-invalid @enderror">
                                        @error('manufacture_date') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Expiry Date <span class="text-danger">*</span></label>
                                        <input type="date" wire:model="state.expiry_date"
                                               class="form-control @error('expiry_date') is-invalid @enderror">
                                        @error('expiry_date') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save mr-1"></i>
                                {{ $editingProduct ? 'Update Product' : 'Save Product' }}
                            </button>
                            <button type="button" wire:click="cancelForm" class="btn btn-default">
                                <i class="fa fa-times mr-1"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Tabs Card --}}
            <div class="card card-primary card-outline card-outline-tabs">
                <div class="card-header p-0 border-bottom-0">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'all' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'all')" 
                               href="#" role="tab">
                                <i class="fa fa-boxes mr-1"></i>
                                All Products
                                <span class="badge badge-primary ml-2">{{ $stats['total'] }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'low-stock' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'low-stock')" 
                               href="#" role="tab">
                                <i class="fa fa-exclamation-triangle mr-1"></i>
                                Low Stock
                                <span class="badge badge-warning ml-2">{{ $stats['low_stock'] }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'expiring' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'expiring')" 
                               href="#" role="tab">
                                <i class="fa fa-clock mr-1"></i>
                                Expiring Soon (4 Months)
                                <span class="badge badge-warning ml-2">{{ $stats['expiring'] }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'expired' ? 'active' : '' }}" 
                               wire:click.prevent="$set('activeTab', 'expired')" 
                               href="#" role="tab">
                                <i class="fa fa-times-circle mr-1"></i>
                                Expired
                                <span class="badge badge-danger ml-2">{{ $stats['expired'] }}</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="card-body">
                    @if(count($selectedProductIds) > 0)
                        <div class="alert alert-info d-flex align-items-center justify-content-between">
                            <div>
                                <i class="fa fa-check-square mr-1"></i>
                                <strong>{{ count($selectedProductIds) }}</strong> product(s) selected
                            </div>
                            <div>
                                <button class="btn btn-sm btn-danger"
                                        wire:click="deleteSelected"
                                        onclick="return confirm('Delete selected products? This cannot be undone.')">
                                    <i class="fa fa-trash mr-1"></i>Delete Selected
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" wire:click="clearSelection">
                                    Clear
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Search Form --}}
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" 
                                       wire:model.live.debounce.500ms="searchTerm"
                                       class="form-control" 
                                       placeholder="Search by name, batch number{{ $searchByCategory ? '' : ' or category' }}...">
                                <div class="input-group-append">
                                    <span class="input-group-text">
                                        <i class="fa fa-search"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="custom-control custom-switch d-inline-block mr-3">
                                <input type="checkbox" 
                                       class="custom-control-input" 
                                       id="searchByCategoryToggle" 
                                       wire:model.live="searchByCategory">
                                <label class="custom-control-label" for="searchByCategoryToggle">
                                    Search by Category
                                </label>
                            </div>

                            @if($searchByCategory)
                                <div class="d-inline-block" style="width: 250px;">
                                    <select wire:model.live="selectedCategoryFilter" class="form-control form-control-sm">
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
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="thead-light">
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
                                        $isLowStock = $product->quantity < 10;
                                    @endphp
                                    <tr class="{{ $isExpired ? 'table-danger' : ($isExpiringSoon ? 'table-warning' : '') }}">
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
                                            <span class="badge badge-info">{{ $product->category->name ?? 'N/A' }}</span>
                                        </td>
                                        <td>
                                            <code>{{ $product->batch_number }}</code>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge {{ $isLowStock ? 'badge-warning' : 'badge-success' }}">
                                                {{ $product->quantity }}
                                            </span>
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
                                                <span class="badge badge-danger">
                                                    <i class="fa fa-times-circle"></i> Expired
                                                </span>
                                            @elseif($isExpiringSoon)
                                                <span class="badge badge-warning">
                                                    <i class="fa fa-clock"></i> {{ $daysToExpiry }}d left
                                                </span>
                                            @elseif($isLowStock)
                                                <span class="badge badge-warning">
                                                    <i class="fa fa-exclamation-triangle"></i> Low Stock
                                                </span>
                                            @else
                                                <span class="badge badge-success">
                                                    <i class="fa fa-check-circle"></i> Active
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button wire:click="editProduct({{ $product->id }})" 
                                                        class="btn btn-info" 
                                                        title="Edit">
                                                    <i class="fa fa-edit"></i>
                                                </button>
                                                <button wire:click="confirmDelete({{ $product->id }})" 
                                                        class="btn btn-danger" 
                                                        title="Delete">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="text-center py-4">
                                            <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                            <p class="text-muted">No products found</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($products->hasPages())
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <div class="text-muted">
                            Showing {{ $products->firstItem() }} to {{ $products->lastItem() }} of {{ $products->total() }} products
                        </div>
                        <div>
                            {{ $products->links() }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Loading Indicator (scoped to heavy actions only) --}}
    <div wire:loading.delay wire:target="createProduct,updateProduct,confirmProductDelete"
         class="position-fixed" style="top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999;">
        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
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
