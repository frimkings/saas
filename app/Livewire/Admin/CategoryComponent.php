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

    protected $paginationTheme = 'bootstrap';

    public $searchTerm = '';
    public $state = [
        'name' => '',
        'type' => 'product',
        'description' => '',
        'is_active' => true,
    ];

    public $category;
    public $showEditModal = false;
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

    public function openCategoryModal()
    {
        $this->resetCategoryForm();
        $this->dispatch('show-addCategoryModal-form');
    }

    public function createCategory()
    {
        $validatedData = $this->validateCategory();

        Category::create(array_merge($validatedData, [
            'user_id' => Auth::id(),
        ]));

        $this->resetCategoryForm();
        $this->dispatch('hide-addCategoryModal-form');
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
        ];
        $this->showEditModal = true;
        $this->dispatch('show-addCategoryModal-form');
    }

    public function updateCategory()
    {
        if (!$this->category) {
            return;
        }

        $validatedData = $this->validateCategory($this->category->id);
        $validatedData['user_id'] = Auth::id();

        $this->category->update($validatedData);

        $this->resetCategoryForm();
        $this->dispatch('hide-addCategoryModal-form');
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
        $this->categoryIdBeingRemoved = null;

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Category deleted successfully!']);
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
        ], [], [
            'state.name' => 'category name',
            'state.type' => 'category type',
            'state.description' => 'description',
            'state.is_active' => 'active status',
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
        ];
    }

    public function render()
    {
        $categories = Category::withCount([
                'products',
                'products as in_stock_products_count' => fn ($query) => $query->where('quantity', '>', 0),
                'products as low_stock_products_count' => fn ($query) => $query->where('quantity', '>', 0)->where('quantity', '<=', 10),
            ])
            ->when($this->searchTerm, function ($query) {
                $query->where(function ($searchQuery) {
                    $searchQuery->where('name', 'like', '%' . $this->searchTerm . '%')
                        ->orWhere('type', 'like', '%' . $this->searchTerm . '%')
                        ->orWhere('description', 'like', '%' . $this->searchTerm . '%');
                });
            })
            ->latest()
            ->paginate(10);

        return view('livewire.admin.category-component', compact('categories'))
            ->layout('layouts.admin.admin-layout');
    }
}
