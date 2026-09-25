<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\Insurer;
use Livewire\Component;
use Livewire\WithPagination;

class InsurersComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $search      = '';
    public string $schemeFilter = '';
    public int    $perPage     = 15;

    public bool   $showModal   = false;
    public bool   $isEditing   = false;
    public ?int   $insurerId   = null;

    public array $state = [
        'name'           => '',
        'code'           => '',
        'scheme_type'    => 'NHIS',
        'contact_person' => '',
        'contact_phone'  => '',
        'notes'          => '',
        'active'         => true,
    ];

    protected $queryString = [
        'search'       => ['except' => ''],
        'schemeFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingSchemeFilter(): void { $this->resetPage(); }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $insurer = Insurer::findOrFail($id);
        $this->insurerId = $id;
        $this->state = [
            'name'           => $insurer->name,
            'code'           => $insurer->code ?? '',
            'scheme_type'    => $insurer->scheme_type,
            'contact_person' => $insurer->contact_person ?? '',
            'contact_phone'  => $insurer->contact_phone ?? '',
            'notes'          => $insurer->notes ?? '',
            'active'         => $insurer->active,
        ];
        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validateForm();

        if ($this->isEditing) {
            $insurer = Insurer::findOrFail($this->insurerId);
            $old = $insurer->only(array_keys($data));
            $insurer->update($data);
            AuditTrail::record('insurer.updated', "Updated insurer: {$insurer->name}", $insurer, $old, $data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Insurer updated.']);
        } else {
            $insurer = Insurer::create($data);
            AuditTrail::record('insurer.created', "Created insurer: {$insurer->name}", $insurer, [], $data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Insurer added.']);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $insurer = Insurer::findOrFail($id);
        $insurer->update(['active' => !$insurer->active]);
        $status = $insurer->active ? 'activated' : 'deactivated';
        AuditTrail::record('insurer.toggled', "Insurer {$insurer->name} {$status}", $insurer);
        $this->dispatch('notify', ...['type' => 'info', 'message' => "Insurer {$status}."]);
    }

    public function delete(int $id): void
    {
        $insurer = Insurer::withCount('claims')->findOrFail($id);
        if ($insurer->claims_count > 0) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => "Cannot delete — {$insurer->claims_count} claim(s) exist for this insurer.",
            ]);
            return;
        }
        AuditTrail::record('insurer.deleted', "Deleted insurer: {$insurer->name}", $insurer, $insurer->toArray(), []);
        $insurer->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Insurer deleted.']);
    }

    public function render()
    {
        $insurers = Insurer::withCount('claims')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%"))
            ->when($this->schemeFilter, fn ($q) => $q->where('scheme_type', $this->schemeFilter))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.admin.insurers-component', compact('insurers'))
            ->layout('layouts.admin.admin-layout');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function validateForm(): array
    {
        return $this->validate([
            'state.name'        => 'required|string|max:120',
            'state.code'        => 'nullable|string|max:30',
            'state.scheme_type' => 'required|in:NHIS,Private,Corporate',
            'state.contact_person' => 'nullable|string|max:120',
            'state.contact_phone'  => 'nullable|string|max:30',
            'state.notes'       => 'nullable|string|max:500',
            'state.active'      => 'boolean',
        ], [], [
            'state.name'        => 'Insurer Name',
            'state.code'        => 'Code',
            'state.scheme_type' => 'Scheme Type',
        ])['state'];
    }

    private function resetForm(): void
    {
        $this->insurerId = null;
        $this->state = [
            'name'           => '',
            'code'           => '',
            'scheme_type'    => 'NHIS',
            'contact_person' => '',
            'contact_phone'  => '',
            'notes'          => '',
            'active'         => true,
        ];
        $this->resetValidation();
    }
}
