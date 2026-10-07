<?php

namespace App\Livewire\Optical;

use App\Models\OpticalCategory;
use App\Services\ClinicAccessService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class OpticalCategoriesComponent extends Component
{
    use WithPagination;

    public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public string $description = '';
    public string $markup = '';
    public bool $active = true;

    public function updatedSearch(): void { $this->resetPage(); }

    private function assertManager(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
    }

    public function add(): void
    {
        $this->assertManager();
        $this->reset(['editingId', 'code', 'name', 'description', 'markup']);
        $this->active = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->assertManager();
        $category = OpticalCategory::findOrFail($id);
        $this->editingId = $category->id;
        $this->code = $category->code ?? '';
        $this->name = $category->name;
        $this->description = $category->description ?? '';
        $this->markup = $category->default_markup === null ? '' : (string) $category->default_markup;
        $this->active = (bool) $category->is_active;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        $clinicId = OpticalCategory::clinicIdForWrite();
        $this->code = strtoupper(trim($this->code));
        $this->name = trim($this->name);
        $this->validate([
            'code' => ['required', 'regex:/^[A-Z0-9-]+$/', 'max:40', Rule::unique('optical_categories', 'code')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:100', Rule::unique('optical_categories', 'name')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'description' => 'nullable|string|max:500',
            'markup' => 'nullable|numeric|between:0,500',
            'active' => 'boolean',
        ]);
        $category = $this->editingId ? OpticalCategory::findOrFail($this->editingId) : new OpticalCategory();
        $category->fill([
            'code' => $this->code,
            'name' => $this->name,
            'description' => trim($this->description) ?: null,
            'is_active' => $this->active,
            'default_markup' => $this->markup === '' ? null : round((float) $this->markup, 2),
        ]);
        \App\Models\AuditTrail::recordSave($category, 'optical.category', 'optical category '.$category->name);
        $this->showForm = false;
        session()->flash('success', 'Optical category saved. Existing order details and product prices are unchanged.');
    }

    public function toggleActive(int $id): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        $category = OpticalCategory::findOrFail($id);
        $category->is_active = ! $category->is_active;
        \App\Models\AuditTrail::recordSave($category, 'optical.category', 'optical category '.$category->name.($category->is_active ? ' (reactivated)' : ' (deactivated)'));
    }

    public function delete(int $id): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable('optical');
        $category = OpticalCategory::findOrFail($id);
        if ($category->products()->exists() || $category->legacyProducts()->exists()) {
            throw ValidationException::withMessages(['category' => 'Reassign the mapped products before deleting this category.']);
        }
        $category->delete();
        \App\Models\AuditTrail::record('optical.category_archived', 'Archived optical category '.$category->name, $category, $category->only(['code', 'name']), []);
        session()->flash('success', 'Optical category archived.');
    }

    public function render()
    {
        $base = OpticalCategory::query();
        $categories = (clone $base)->withCount('products')
            ->when(trim($this->search), fn ($q) => $q->where(fn ($search) => $search
                ->where('name', 'like', '%'.trim($this->search).'%')
                ->orWhere('code', 'like', '%'.trim($this->search).'%')
                ->orWhere('description', 'like', '%'.trim($this->search).'%')))
            ->orderBy('name')->paginate(15);
        $summary = (clone $base)->withCount('products')->get();
        return view('livewire.optical.optical-categories-component', [
            'categories' => $categories,
            'activeCount' => $summary->where('is_active', true)->count(),
            'singleVisionCount' => $summary->filter(fn ($category) => $category->group === 'single_vision')->count(),
            'frameSkuCount' => $summary->filter(fn ($category) => $category->group === 'frames')->sum('products_count'),
            'multifocalCount' => $summary->filter(fn ($category) => in_array($category->group, ['progressive', 'bifocal'], true))->count(),
        ])->layout('layouts.optical');
    }
}
