<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Optical Categories Management <span class="ml-2 rounded-full bg-teal-50 border border-teal-200 px-2 py-1 text-xs text-teal-800">Catalog Master Data</span></h1>
            <p class="ui-muted text-xs">Classify frames, lenses, and coatings and set a reference markup for product pricing.</p>
        </div>
        @hasanyrole('Manager|Super Admin')<button type="button" x-on:click="openLocal($wire, { editingId: null, code: '', name: '', description: '', markup: '', active: true, showForm: true }, $root.querySelector('[data-category-form]'))" class="ui-button ui-button-primary text-xs">+ Add Optical Category</button>@endhasanyrole
    </div>

    <x-ui.flash />
    @error('category')<div class="ui-panel p-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Active categories</p><p class="text-2xl font-bold text-teal-800">{{ $activeCount }}</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Single vision categories</p><p class="text-2xl font-bold text-teal-800">{{ $singleVisionCount }}</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Frames &amp; eyewear SKUs</p><p class="text-2xl font-bold text-violet-800">{{ $frameSkuCount }}</p></div>
        <div class="ui-panel p-4"><p class="text-xs uppercase text-slate-500">Progressive &amp; bifocal</p><p class="text-2xl font-bold text-teal-800">{{ $multifocalCount }}</p></div>
    </div>

    <div class="ui-panel overflow-hidden">
        <div class="p-4 flex flex-col md:flex-row gap-3">
            <input autocomplete="off" type="search" wire:model.live.debounce.300ms="search" placeholder="Filter categories by name, code, or description..." class="ui-input flex-1" aria-label="Search optical categories">
        </div>
        <div class="ui-table-wrap"><table class="ui-table w-full">
            <thead><tr><th>Category Code</th><th>Category Name</th><th>Default Markup</th><th>Description / Features</th><th>Mapped SKUs</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse($categories as $category)
                    <tr wire:key="optical-category-{{ $category->id }}">
                        <td class="font-mono text-xs font-semibold">{{ $category->code }}</td>
                        <td class="font-semibold">{{ $category->name }}</td>
                        <td class="font-mono">{{ $category->default_markup === null ? '—' : number_format((float) $category->default_markup, 2).'%' }}</td>
                        <td class="text-xs text-slate-600">{{ $category->description ?: '—' }}</td>
                        <td class="font-semibold">{{ $category->products_count }} SKUs</td>
                        <td><span class="ui-badge {{ $category->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td><div class="flex flex-wrap gap-2 text-xs">
                            @hasanyrole('Manager|Super Admin')
                                <button type="button" x-on:click="openLocal($wire, {{ \Illuminate\Support\Js::from(['editingId' => $category->id, 'code' => $category->code ?? '', 'name' => $category->name, 'description' => $category->description ?? '', 'markup' => $category->default_markup === null ? '' : (string) $category->default_markup, 'active' => (bool) $category->is_active, 'showForm' => true]) }}, $root.querySelector('[data-category-form]'))" class="text-amber-700 underline">Edit</button>
                                <button type="button" wire:click="toggleActive({{ $category->id }})" class="text-teal-700 underline">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</button>
                                <button type="button" wire:click="delete({{ $category->id }})" wire:confirm="Archive this category?" class="text-red-700 underline">Delete</button>
                            @else — @endhasanyrole
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ui-empty">No optical categories found. Add one to organize optical products and lens choices.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="p-4 border-t border-slate-200">{{ $categories->links() }}</div>
    </div>

    {{-- Always in the page: Add and Edit open it in the browser (openLocal); only Save calls the server. --}}
    @hasanyrole('Manager|Super Admin')
        <div data-category-form data-keep x-show="$wire.showForm" x-cloak class="fixed inset-0 z-50 bg-slate-950/60 flex items-center justify-center p-4" role="dialog" aria-modal="true" :aria-label="$wire.editingId ? 'Edit optical category' : 'Add optical category'" x-on:keydown.escape.window="$wire.showForm && dismissLocal($el, $wire, { showForm: false })">
            <div class="optical-category-dialog rounded-xl bg-white shadow-xl">
                <div class="bg-slate-900 text-white px-5 py-4 flex items-center gap-3">
                    <span class="rounded-lg bg-teal-900/40 border border-teal-700 px-2 py-2" aria-hidden="true">🏷️</span>
                    <div class="flex-1"><h2 class="font-bold" x-text="$wire.editingId ? 'Edit Optical Category' : 'Add Optical Category'">{{ $editingId ? 'Edit Optical Category' : 'Add Optical Category' }}</h2><p class="text-xs text-slate-300">Create item category for frames, single vision, progressives, etc.</p></div>
                    <button type="button" x-on:click="dismissLocal($el, $wire, { showForm: false })" aria-label="Close" class="text-slate-300 text-lg">×</button>
                </div>
                <form wire:submit="save" class="p-6 space-y-4">
                    <div><label class="block text-xs font-semibold mb-1">Category Name <span class="text-red-600">*</span></label><input autocomplete="off" wire:model="name" maxlength="100" placeholder="E.G. SINGLE VISION BLUE AR, PROGRESSIVES, FRAMES" class="ui-input w-full">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><label class="block text-xs font-semibold mb-1">Category Code / Slug <span class="text-red-600">*</span></label><input autocomplete="off" wire:model="code" maxlength="40" placeholder="CAT-SV-190" class="ui-input w-full">@error('code')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Default Price Markup (%)</label><input autocomplete="off" type="number" min="0" max="500" step="0.01" wire:model="markup" placeholder="45" class="ui-input w-full">@error('markup')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label class="block text-xs font-semibold mb-1">Status</label><select wire:model="active" class="ui-input w-full"><option value="1">Active</option><option value="0">Inactive</option></select>@error('active')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    </div>
                    <div><label class="block text-xs font-semibold mb-1">Category Description &amp; Features</label><textarea wire:model="description" rows="2" maxlength="500" placeholder="e.g. Single vision lenses with photochromic fast-change tint and HMC anti-reflective coating..." class="ui-input w-full"></textarea>@error('description')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 pt-4"><button type="button" x-on:click="dismissLocal($el, $wire, { showForm: false })" class="ui-button">Cancel</button><button type="submit" class="ui-button ui-button-primary">Save Optical Category</button></div>
                </form>
            </div>
        </div>
    @endhasanyrole
</div>
