<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class CategoryComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    public $searchTerm = '';
    public $state = [
        'name' => '',
        'type' => 'product',
        'description' => '',
        'is_active' => true,
        'part_payment' => false,
        'part_payment_min_percent' => 0,
    ];

    public $category;
    public $showEditModal = false;
    /** Side panel, as on Partner Clinics: the category being looked at, and whether the add/edit form is open. */
    public ?int $viewCategoryId = null;
    public bool $showForm = false;
    public $categoryIdBeingRemoved;

    public array $categoryTypes = [
        'product' => 'Product',
        'drug' => 'Drug',
        'lens' => 'Lens',
        'frame' => 'Frame',
        'service' => 'Service',
        'consumable' => 'Consumable',
    ];

    public function updatedSearchTerm()
    {
        $this->resetPage();
    }

    /** A new frames or lenses category starts with part payment on, as the till always allowed. */
    public function updatedStateType($type): void
    {
        if (!$this->showEditModal) {
            $this->state['part_payment'] = Category::isFrameOrLens($type, $this->state['name'] ?? '');
        }
    }

    public function openCategory(int $id): void
    {
        $this->viewCategoryId = Category::findOrFail($id)->id;
        $this->showForm = false;
    }

    public function closeCategory(): void
    {
        $this->viewCategoryId = null;
        $this->showForm = false;
        $this->resetCategoryForm();
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->viewCategoryId = null;
        $this->resetCategoryForm();
    }

    public function openCategoryModal()
    {
        $this->resetCategoryForm();
        $this->viewCategoryId = null;
        $this->showForm = true;
    }

    public function createCategory()
    {
        $validatedData = $this->validateCategory();

        $partPayment = $this->partPaymentFields($validatedData);
        $created = Category::create(array_merge($validatedData, [
            'user_id' => Auth::id(),
        ], $partPayment ?? []));

        $this->resetCategoryForm();
        $this->showForm = false;
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Category added.']);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Category added successfully!']);
    }

    public function editCategoryModal(Category $category)
    {
        $this->resetValidation();
        $this->category = $category;
        $this->state = [
            'name' => $category->name,
            'type' => $category->type ?? 'product',
            'description' => $category->description,
            'is_active' => (bool) $category->is_active,
            'part_payment' => (bool) $category->part_payment,
            'part_payment_min_percent' => (int) $category->part_payment_min_percent,
        ];
        $this->showEditModal = true;
        $this->viewCategoryId = $category->id;
        $this->showForm = true;
    }

    public function updateCategory()
    {
        if (!$this->category) {
            return;
        }

        $validatedData = $this->validateCategory($this->category->id);
        $validatedData['user_id'] = Auth::id();
        $before = ['part_payment' => (bool) $this->category->part_payment, 'part_payment_min_percent' => (int) $this->category->part_payment_min_percent];
        $partPayment = $this->partPaymentFields($validatedData);

        $this->category->update(array_merge($validatedData, $partPayment ?? []));
        if ($partPayment !== null && $partPayment !== $before) {
            \App\Models\AuditTrail::record('category.part_payment', "Part payment for {$this->category->name}: "
                . ($partPayment['part_payment'] ? 'on, at least ' . $partPayment['part_payment_min_percent'] . '% now' : 'off'), $this->category, $before, $partPayment);
        }

        $this->resetCategoryForm();
        $this->showForm = false;
        $this->viewCategoryId = null;
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Category updated successfully!']);
    }

    public function toggleCategoryStatus($categoryId)
    {
        $category = Category::findOrFail($categoryId);
        $category->update(['is_active' => !$category->is_active]);

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => $category->is_active ? 'Category activated.' : 'Category deactivated.',
        ]);
    }

    public function confirmCategoryDeletion($categoryId)
    {
        $category = Category::withCount('products')->findOrFail($categoryId);

        if ($category->products_count > 0) {
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Cannot delete a category that has products. Move or reassign products first.',
            ]);
            return;
        }

        $this->categoryIdBeingRemoved = $categoryId;
        $this->dispatch('show-category-delete-confirmation', ...[
            'message' => 'This will archive the category.',
        ]);
    }

    public function confirmCategoryDelete()
    {
        if (!$this->categoryIdBeingRemoved) {
            return;
        }

        $category = Category::withCount('products')->findOrFail($this->categoryIdBeingRemoved);

        if ($category->products_count > 0) {
            $this->categoryIdBeingRemoved = null;
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Cannot delete a category that has products.',
            ]);
            return;
        }

        $category->delete();
        if ($this->viewCategoryId === $category->id) $this->closeCategory();
        $this->categoryIdBeingRemoved = null;

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Category deleted successfully!']);
    }

    /** Only the owner (settings.manage) sets part payment; others keep what is there. */
    public function getCanSetPartPaymentProperty(): bool
    {
        return \App\Support\Access\Access::can('settings.manage');
    }

    private function partPaymentFields(array &$validated): ?array
    {
        $on = (bool) ($validated['part_payment'] ?? false);
        $fields = ['part_payment' => $on, 'part_payment_min_percent' => $on ? (int) ($validated['part_payment_min_percent'] ?? 0) : 0];
        unset($validated['part_payment'], $validated['part_payment_min_percent']);

        return $this->canSetPartPayment ? $fields : null;
    }

    private function validateCategory($ignoreId = null): array
    {
        return $this->validate([
            'state.name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query->where('clinic_id', Category::clinicIdForWrite()))
                    ->ignore($ignoreId),
            ],
            'state.type' => ['required', Rule::in(array_keys($this->categoryTypes))],
            'state.description' => 'nullable|string|max:500',
            'state.is_active' => 'boolean',
            'state.part_payment' => 'boolean',
            'state.part_payment_min_percent' => 'nullable|integer|min:0|max:100',
        ], [], [
            'state.name' => 'category name',
            'state.type' => 'category type',
            'state.description' => 'description',
            'state.is_active' => 'active status',
            'state.part_payment_min_percent' => 'minimum to pay now',
        ])['state'];
    }

    private function resetCategoryForm(): void
    {
        $this->resetValidation();
        $this->category = null;
        $this->showEditModal = false;
        $this->state = [
            'name' => '',
            'type' => 'product',
            'description' => '',
            'is_active' => true,
            'part_payment' => false,
            'part_payment_min_percent' => 0,
        ];
    }

    public function render()
    {
        $categories = Category::withCount([
                'products',
                'products as in_stock_products_count' => fn ($query) => $query->where('quantity', '>', 0),
                'products as low_stock_products_count' => fn ($query) => $query->lowStock(),
            ])
            // The diagnostic scans category belongs to the Scan Desk, not the clinic's stock.
            ->where('type', '!=', \App\Models\Product::SCAN_CATEGORY_TYPE)
            ->when($this->searchTerm, function ($query) {
                $query->where(function ($searchQuery) {
                    $searchQuery->where('name', 'like', '%' . $this->searchTerm . '%')
                        ->orWhere('type', 'like', '%' . $this->searchTerm . '%')
                        ->orWhere('description', 'like', '%' . $this->searchTerm . '%');
                });
            })
            ->latest()
            ->paginate(10);

        $canSetPartPayment = $this->canSetPartPayment;
        $viewCategory = $this->viewCategoryId ? Category::withCount('products')->find($this->viewCategoryId) : null;

        return view('livewire.admin.category-component', compact('categories', 'canSetPartPayment', 'viewCategory'))
            ->layout('layouts.admin.admin-layout');
    }
}
