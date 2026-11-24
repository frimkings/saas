<div class="clinic-ui ui-page">
@include('livewire.optical.partials.order-ui')
    <div class="content-header">
        <div class="w-full">
            <div class="flex flex-wrap -mx-2 mb-2 items-center">
                <div class="w-full sm:w-6/12 px-2">
                    <h1 class="flex flex-wrap items-center gap-2">Categories <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $categories->total() }}</span></h1>
                    <p class="ui-muted">Groups for the things you charge for, used in reports and the income statement.</p>
                </div>
                <div class="w-full sm:w-6/12 px-2">
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="w-full">
            <div class="ui-panel">
                <div class="ui-section-heading">
                    <div>
                        <h5 class="mb-0 font-semibold">Category Directory</h5>
                        <small class="text-slate-500">Use clear category types for POS filtering and income reports.</small>
                    </div>
                    <button wire:click.prevent="openCategoryModal" class="ui-button ui-button-primary">
                        <i class="fa fa-plus-circle mr-1"></i> Add Category
                    </button>
                </div>

                <div class="p-5">
                    <div class="flex flex-wrap -mx-2 mb-4">
                        <div class="w-full md:w-5/12 px-2">
                            <div class="flex items-stretch">
                                <div class="flex">
                                    <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fa fa-search"></i></span>
                                </div>
                                <input aria-label="Search" type="text"
                                    wire:model.live.debounce.300ms="searchTerm"
                                    class="ui-input"
                                    placeholder="Search category, type, or description...">
                            </div>
                        </div>
                    </div>

                    <div class="ui-table-wrap">
                        <table class="ui-table mb-0">
                            <thead class="">
                                <tr>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th class="text-center">Products</th>
                                    <th class="text-center">In Stock</th>
                                    <th class="text-center">Low Stock</th>
                                    <th title="Clinic till: may a patient pay part now and owe the rest?">Part payment</th>
                                    <th>Status</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="text-muted">
                                @forelse ($categories as $category)
                                    <tr wire:key="category-{{ $category->id }}" wire:click="editCategoryModal({{ $category->id }})"
                                        class="cursor-pointer hover:bg-slate-50 {{ $viewCategoryId === $category->id ? 'bg-teal-50' : '' }}">
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
                                            @if($category->part_payment)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-teal-50 text-teal-800 border border-teal-200">
                                                    On · {{ (int) $category->part_payment_min_percent > 0 ? $category->part_payment_min_percent.'% now' : 'any amount' }}
                                                </span>
                                            @else
                                                <span class="text-xs text-slate-500">Off · pay in full</span>
                                            @endif
                                        </td>
                                        <td>
                                            <button type="button"
                                                wire:click.stop="toggleCategoryStatus({{ $category->id }})"
                                                class="ui-button ui-button-sm {{ $category->is_active ? 'ui-button-secondary' : 'ui-button-secondary' }}">
                                                <i class="fa {{ $category->is_active ? 'fa-check-circle' : 'fa-pause-circle' }} mr-1"></i>
                                                {{ $category->is_active ? 'Active' : 'Inactive' }}
                                            </button>
                                        </td>
                                        <td class="text-right">
                                            <button type="button"
                                                wire:click.prevent.stop="editCategoryModal({{ $category->id }})"
                                                class="ui-button ui-button-sm ui-button-secondary"
                                                title="Edit category">
                                                <i class="fa fa-edit"></i>
                                            </button>

                                            <button type="button"
                                                wire:click.prevent.stop="confirmCategoryDeletion({{ $category->id }})"
                                                class="ui-button ui-button-sm {{ $category->products_count > 0 ? 'ui-button-secondary' : 'ui-button-danger' }}"
                                                title="{{ $category->products_count > 0 ? 'Move products before deleting this category' : 'Delete category' }}">
                                                <i class="fa {{ $category->products_count > 0 ? 'fa-lock' : 'fa-trash' }}"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-slate-500 py-12">
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

    {{-- ADD / EDIT PANEL --}}
    @if($showForm)
        <div class="oo-overlay" wire:click="closeForm" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="categoryModalLabel" tabindex="-1" x-data x-init="$el.focus()"
               x-on:keydown.escape.window="$wire.closeForm()">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="categoryModalLabel">{{ $showEditModal ? 'Edit '.($state['name'] ?: 'category') : 'Add a category' }}</h2>
                    @if($showEditModal && $viewCategory)
                        <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                            <span class="oo-badge oo-b-grey">{{ $categoryTypes[$viewCategory->type ?? 'product'] ?? ucfirst($viewCategory->type ?? 'Product') }}</span>
                            <span class="oo-badge oo-b-teal">{{ $viewCategory->products_count }} {{ \Illuminate\Support\Str::plural('product', $viewCategory->products_count) }}</span>
                        </div>
                    @endif
                </div>
                <button type="button" class="oo-x" wire:click="closeForm" aria-label="Close">&times;</button>
            </header>
            <form autocomplete="off" wire:submit="{{ $showEditModal ? 'updateCategory' : 'createCategory' }}" class="oo-drawer-body" id="category-form">
                        <div class="mb-4">
                            <label for="category-name">Name <span class="text-red-700">*</span></label>
                            <input type="text"
                                wire:model="state.name"
                                class="ui-input @error('state.name') is-invalid @enderror"
                                id="category-name"
                                placeholder="e.g. Drugs, Frames, Lenses">
                            @error('state.name')
                                <div class="ui-error">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="category-type">Category Type <span class="text-red-700">*</span></label>
                            <select wire:model="state.type"
                                class="ui-input @error('state.type') is-invalid @enderror"
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
                                class="ui-input @error('state.description') is-invalid @enderror"
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

                        <fieldset class="rounded-lg border border-slate-200 p-3" data-tour="category-part-payment" @disabled(! $canSetPartPayment)>
                            <legend class="px-1 text-sm font-semibold">Part payment at the clinic till</legend>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" wire:model.live="state.part_payment" class="rounded border-slate-300 text-teal-700" id="category-part-payment">
                                <label for="category-part-payment">A registered patient may pay part now and owe the rest</label>
                            </div>
                            @if($state['part_payment'] ?? false)
                                <div class="mt-2 flex items-center gap-2">
                                    <label for="category-part-min" class="text-sm">Pay at least</label>
                                    <input type="number" min="0" max="100" step="1" id="category-part-min" wire:model="state.part_payment_min_percent" class="ui-input !w-24 @error('state.part_payment_min_percent') is-invalid @enderror">
                                    <span class="text-sm text-slate-600">% of these items now (0 = any amount)</span>
                                </div>
                                @error('state.part_payment_min_percent')<div class="ui-error">{{ $message }}</div>@enderror
                            @endif
                            <small class="mt-2 block text-slate-500">
                                Off: items in this category are paid in full. The balance of a part payment waits under Outstanding Balances.
                                @unless($canSetPartPayment) Only the clinic owner can change this. @endunless
                            </small>
                        </fieldset>
            </form>
            <footer class="oo-drawer-foot">
                @if($showEditModal && $viewCategory && $viewCategory->products_count === 0)
                    <button type="button" class="oo-btn danger" style="margin-right:auto" wire:click="confirmCategoryDeletion({{ $viewCategory->id }})">Delete</button>
                @endif
                <button type="button" class="oo-btn" wire:click="closeForm">Cancel</button>
                <button type="submit" form="category-form" class="oo-btn primary" wire:loading.attr="disabled" wire:target="createCategory,updateCategory">
                    {{ $showEditModal ? 'Save changes' : 'Add category' }}
                </button>
            </footer>
        </aside>
    @endif

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
        window.addEventListener('show-category-delete-confirmation', event => {
            window.appConfirm(event.detail?.message || 'This category will be archived.', { title: 'Archive category?', confirmText: 'Yes, archive it', danger: true })
                .then(ok => ok && @this.call('confirmCategoryDelete'));
        });
    </script>
</div>
