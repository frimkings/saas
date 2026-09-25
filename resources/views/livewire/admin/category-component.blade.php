<div>
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0">Categories <span class="badge badge-secondary">{{ $categories->total() }}</span></h1>
                    <small class="text-muted">Manage POS, inventory, reporting, and clinical product groups.</small>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
                        <li class="breadcrumb-item active">Categories</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            <div class="card category-admin-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 font-weight-bold">Category Directory</h5>
                        <small class="text-muted">Use clear category types for POS filtering and income reports.</small>
                    </div>
                    <button wire:click.prevent="openCategoryModal" class="btn btn-primary">
                        <i class="fa fa-plus-circle mr-1"></i> Add Category
                    </button>
                </div>

                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-5">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                                </div>
                                <input type="text"
                                    wire:model.live.debounce.300ms="searchTerm"
                                    class="form-control"
                                    placeholder="Search category, type, or description...">
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th class="text-center">Products</th>
                                    <th class="text-center">In Stock</th>
                                    <th class="text-center">Low Stock</th>
                                    <th>Status</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="text-muted">
                                @forelse ($categories as $category)
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold">{{ $category->name }}</div>
                                            <small class="text-muted">{{ $category->description ?: 'No description provided' }}</small>
                                        </td>
                                        <td>
                                            <span class="badge badge-light border">
                                                {{ $categoryTypes[$category->type ?? 'product'] ?? ucfirst($category->type ?? 'Product') }}
                                            </span>
                                        </td>
                                        <td class="text-center font-weight-bold">{{ $category->products_count }}</td>
                                        <td class="text-center text-success font-weight-bold">{{ $category->in_stock_products_count }}</td>
                                        <td class="text-center {{ $category->low_stock_products_count > 0 ? 'text-warning font-weight-bold' : 'text-muted' }}">
                                            {{ $category->low_stock_products_count }}
                                        </td>
                                        <td>
                                            <button type="button"
                                                wire:click="toggleCategoryStatus({{ $category->id }})"
                                                class="btn btn-xs {{ $category->is_active ? 'btn-outline-success' : 'btn-outline-secondary' }}">
                                                <i class="fa {{ $category->is_active ? 'fa-check-circle' : 'fa-pause-circle' }} mr-1"></i>
                                                {{ $category->is_active ? 'Active' : 'Inactive' }}
                                            </button>
                                        </td>
                                        <td class="text-right">
                                            <button type="button"
                                                wire:click.prevent="editCategoryModal({{ $category->id }})"
                                                class="btn btn-sm btn-outline-primary"
                                                title="Edit category">
                                                <i class="fa fa-edit"></i>
                                            </button>

                                            <button type="button"
                                                wire:click.prevent="confirmCategoryDeletion({{ $category->id }})"
                                                class="btn btn-sm {{ $category->products_count > 0 ? 'btn-outline-secondary' : 'btn-outline-danger' }}"
                                                title="{{ $category->products_count > 0 ? 'Move products before deleting this category' : 'Delete category' }}">
                                                <i class="fa {{ $category->products_count > 0 ? 'fa-lock' : 'fa-trash' }}"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">
                                            <i class="fa fa-folder-open fa-3x mb-3 d-block"></i>
                                            No categories found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end">
                    {{ $categories->links() }}
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addCategoryModal" tabindex="-1" role="dialog" aria-labelledby="categoryModalLabel"
        aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog" role="document">
            <form autocomplete="off" wire:submit="{{ $showEditModal ? 'updateCategory' : 'createCategory' }}">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="categoryModalLabel">
                            {{ $showEditModal ? 'Edit Category' : 'Add New Category' }}
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        <div class="form-group">
                            <label for="category-name">Name <span class="text-danger">*</span></label>
                            <input type="text"
                                wire:model="state.name"
                                class="form-control @error('state.name') is-invalid @enderror"
                                id="category-name"
                                placeholder="e.g. Drugs, Frames, Lenses">
                            @error('state.name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="category-type">Category Type <span class="text-danger">*</span></label>
                            <select wire:model="state.type"
                                class="form-control @error('state.type') is-invalid @enderror"
                                id="category-type">
                                @foreach($categoryTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('state.type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="category-description">Description</label>
                            <textarea wire:model="state.description"
                                class="form-control @error('state.description') is-invalid @enderror"
                                id="category-description"
                                rows="3"
                                placeholder="Optional note for reporting, POS, or inventory use"></textarea>
                            @error('state.description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="custom-control custom-switch">
                            <input type="checkbox" wire:model="state.is_active" class="custom-control-input" id="category-active">
                            <label class="custom-control-label" for="category-active">Active in POS and inventory workflows</label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                            <i class="fa fa-times mr-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save mr-1"></i>
                            {{ $showEditModal ? 'Update Category' : 'Save Category' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
        .category-admin-card {
            border: 1px solid #dfe5ee;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .06);
        }

        .category-admin-card th {
            font-size: .75rem;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .btn-xs {
            font-size: .75rem;
            padding: .15rem .45rem;
        }
    </style>

    <script>
        window.addEventListener('show-addCategoryModal-form', event => {
            $('#addCategoryModal').modal('show');
        });

        window.addEventListener('hide-addCategoryModal-form', event => {
            $('#addCategoryModal').modal('hide');
        });

        window.addEventListener('show-category-delete-confirmation', event => {
            Swal.fire({
                title: 'Archive category?',
                text: event.detail?.message || 'This category will be archived.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, archive it',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (result.isConfirmed) {
                    @this.call('confirmCategoryDelete');
                }
            });
        });
    </script>
</div>
