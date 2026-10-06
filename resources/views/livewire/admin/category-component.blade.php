<div class="clinic-ui ui-page">
    <div class="content-header">
        <div class="w-full">
            <div class="flex flex-wrap -mx-2 mb-2 items-center">
                <div class="w-full sm:w-6/12 px-2">
                    <h1 class="m-0">Categories <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $categories->total() }}</span></h1>
                    <small class="text-slate-500">Manage POS, inventory, reporting, and clinical product groups.</small>
                </div>
                <div class="w-full sm:w-6/12 px-2">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
                        <li class="breadcrumb-item active">Categories</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="w-full">
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white category-admin-card">
                <div class="card-header border-b border-slate-200 px-4 py-2 bg-white flex justify-between items-center">
                    <div>
                        <h5 class="mb-0 font-semibold">Category Directory</h5>
                        <small class="text-slate-500">Use clear category types for POS filtering and income reports.</small>
                    </div>
                    <button wire:click.prevent="openCategoryModal" class="btn ui-button ui-button-primary">
                        <i class="fa fa-plus-circle mr-1"></i> Add Category
                    </button>
                </div>

                <div class="card-body p-4">
                    <div class="flex flex-wrap -mx-2 mb-4">
                        <div class="w-full md:w-5/12 px-2">
                            <div class="flex items-stretch">
                                <div class="flex">
                                    <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fa fa-search"></i></span>
                                </div>
                                <input type="text"
                                    wire:model.live.debounce.300ms="searchTerm"
                                    class="form-control ui-input"
                                    placeholder="Search category, type, or description...">
                            </div>
                        </div>
                    </div>

                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0">
                            <thead class="">
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
                                            <div class="font-semibold">{{ $category->name }}</div>
                                            <small class="text-slate-500">{{ $category->description ?: 'No description provided' }}</small>
                                        </td>
                                        <td>
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200">
                                                {{ $categoryTypes[$category->type ?? 'product'] ?? ucfirst($category->type ?? 'Product') }}
                                            </span>
                                        </td>
                                        <td class="text-center font-semibold">{{ $category->products_count }}</td>
                                        <td class="text-center text-green-700 font-semibold">{{ $category->in_stock_products_count }}</td>
                                        <td class="text-center {{ $category->low_stock_products_count > 0 ? 'text-amber-600 font-semibold' : 'text-slate-500' }}">
                                            {{ $category->low_stock_products_count }}
                                        </td>
                                        <td>
                                            <button type="button"
                                                wire:click="toggleCategoryStatus({{ $category->id }})"
                                                class="btn ui-button ui-button-sm {{ $category->is_active ? 'ui-button-secondary' : 'ui-button-secondary' }}">
                                                <i class="fa {{ $category->is_active ? 'fa-check-circle' : 'fa-pause-circle' }} mr-1"></i>
                                                {{ $category->is_active ? 'Active' : 'Inactive' }}
                                            </button>
                                        </td>
                                        <td class="text-right">
                                            <button type="button"
                                                wire:click.prevent="editCategoryModal({{ $category->id }})"
                                                class="btn ui-button ui-button-sm ui-button-secondary"
                                                title="Edit category">
                                                <i class="fa fa-edit"></i>
                                            </button>

                                            <button type="button"
                                                wire:click.prevent="confirmCategoryDeletion({{ $category->id }})"
                                                class="btn ui-button ui-button-sm {{ $category->products_count > 0 ? 'ui-button-secondary' : 'ui-button-danger' }}"
                                                title="{{ $category->products_count > 0 ? 'Move products before deleting this category' : 'Delete category' }}">
                                                <i class="fa {{ $category->products_count > 0 ? 'fa-lock' : 'fa-trash' }}"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-slate-500 py-12">
                                            <i class="fa fa-folder-open fa-3x mb-4 block"></i>
                                            No categories found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="border-t border-slate-200 bg-slate-50 px-4 py-2 flex justify-end">
                    {{ $categories->links() }}
                </div>
            </div>
        </div>
    </div>

    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="addCategoryModal" tabindex="-1" role="dialog" aria-labelledby="categoryModalLabel"
        aria-hidden="true" wire:ignore.self>
        <div class="mx-auto my-8 w-full max-w-lg" role="document">
            <form autocomplete="off" wire:submit="{{ $showEditModal ? 'updateCategory' : 'createCategory' }}">
                <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <h5 class="text-base font-semibold" id="categoryModalLabel">
                            {{ $showEditModal ? 'Edit Category' : 'Add New Category' }}
                        </h5>
                        <button type="button" class="text-xl leading-none text-slate-500 hover:text-slate-800" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="p-4">
                        <div class="mb-4">
                            <label for="category-name">Name <span class="text-red-700">*</span></label>
                            <input type="text"
                                wire:model="state.name"
                                class="form-control ui-input @error('state.name') is-invalid @enderror"
                                id="category-name"
                                placeholder="e.g. Drugs, Frames, Lenses">
                            @error('state.name')
                                <div class="ui-error">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="category-type">Category Type <span class="text-red-700">*</span></label>
                            <select wire:model="state.type"
                                class="form-control ui-input @error('state.type') is-invalid @enderror"
                                id="category-type">
                                @foreach($categoryTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('state.type')
                                <div class="ui-error">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="category-description">Description</label>
                            <textarea wire:model="state.description"
                                class="form-control ui-input @error('state.description') is-invalid @enderror"
                                id="category-description"
                                rows="3"
                                placeholder="Optional note for reporting, POS, or inventory use"></textarea>
                            @error('state.description')
                                <div class="ui-error">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="flex items-center gap-2">
                            <input type="checkbox" wire:model="state.is_active" class="rounded border-slate-300 text-teal-700" id="category-active">
                            <label class="" for="category-active">Active in POS and inventory workflows</label>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                        <button type="button" class="btn ui-button ui-button-secondary" data-dismiss="modal">
                            <i class="fa fa-times mr-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn ui-button ui-button-primary">
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
            uiModal('addCategoryModal', true);
        });

        window.addEventListener('hide-addCategoryModal-form', event => {
            uiModal('addCategoryModal', false);
        });

        window.addEventListener('show-category-delete-confirmation', event => {
            window.appConfirm(event.detail?.message || 'This category will be archived.', { title: 'Archive category?', confirmText: 'Yes, archive it', danger: true })
                .then(ok => ok && @this.call('confirmCategoryDelete'));
        });
    </script>
</div>
