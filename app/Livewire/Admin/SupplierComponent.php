<?php

namespace App\Livewire\Admin;

use App\Models\Supplier;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $searchTerm = '';
    public $showModal  = false;
    public $isEditing  = false;
    public $supplierId = null;

    public $state = [
        'name'           => '',
        'contact_person' => '',
        'phone'          => '',
        'email'          => '',
        'address'        => '',
        'lead_time_days' => '',
        'notes'          => '',
        'is_active'      => true,
    ];

    public function updatedSearchTerm()
    {
        $this->resetPage();
    }

    public function openCreate()
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit($id)
    {
        $supplier = Supplier::findOrFail($id);
        $this->supplierId = $id;
        $this->isEditing  = true;
        $this->state = [
            'name'           => $supplier->name,
            'contact_person' => $supplier->contact_person ?? '',
            'phone'          => $supplier->phone ?? '',
            'email'          => $supplier->email ?? '',
            'address'        => $supplier->address ?? '',
            'lead_time_days' => $supplier->lead_time_days ?? '',
            'notes'          => $supplier->notes ?? '',
            'is_active'      => (bool) $supplier->is_active,
        ];
        $this->showModal = true;
    }

    public function save()
    {
        $data = $this->validateForm();

        if ($this->isEditing) {
            Supplier::findOrFail($this->supplierId)->update($data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Supplier updated.']);
        } else {
            Supplier::create($data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Supplier added.']);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function toggleActive($id)
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update(['is_active' => !$supplier->is_active]);
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => $supplier->is_active ? 'Supplier activated.' : 'Supplier deactivated.',
        ]);
    }

    public function confirmDelete($id)
    {
        $this->dispatch('show-delete-confirmation', ...[
            'id'     => $id,
            'method' => 'deleteSupplier',
        ]);
    }

    public function deleteSupplier($id)
    {
        Supplier::findOrFail($id)->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Supplier deleted.']);
    }

    private function validateForm(): array
    {
        return $this->validate([
            'state.name'           => ['required', 'string', 'max:150',
                Rule::unique('suppliers', 'name')
                    ->where(fn ($query) => $query->where('clinic_id', Supplier::clinicIdForWrite()))
                    ->ignore($this->supplierId)],
            'state.contact_person' => 'nullable|string|max:100',
            'state.phone'          => 'nullable|string|max:30',
            'state.email'          => 'nullable|email|max:150',
            'state.address'        => 'nullable|string|max:255',
            'state.lead_time_days' => 'nullable|integer|min:1|max:365',
            'state.notes'          => 'nullable|string|max:1000',
            'state.is_active'      => 'boolean',
        ], [], [
            'state.name'           => 'supplier name',
            'state.contact_person' => 'contact person',
            'state.phone'          => 'phone number',
            'state.email'          => 'email address',
            'state.lead_time_days' => 'lead time (days)',
        ])['state'];
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->supplierId = null;
        $this->isEditing  = false;
        $this->state = [
            'name'           => '',
            'contact_person' => '',
            'phone'          => '',
            'email'          => '',
            'address'        => '',
            'lead_time_days' => '',
            'notes'          => '',
            'is_active'      => true,
        ];
    }

    public function render()
    {
        $suppliers = Supplier::when($this->searchTerm, function ($q) {
                $q->where(function ($s) {
                    $s->where('name', 'like', '%' . $this->searchTerm . '%')
                      ->orWhere('contact_person', 'like', '%' . $this->searchTerm . '%')
                      ->orWhere('phone', 'like', '%' . $this->searchTerm . '%')
                      ->orWhere('email', 'like', '%' . $this->searchTerm . '%');
                });
            })
            ->latest()
            ->paginate(15);

        return view('livewire.admin.supplier-component', compact('suppliers'))
            ->layout('layouts.admin.admin-layout');
    }
}
