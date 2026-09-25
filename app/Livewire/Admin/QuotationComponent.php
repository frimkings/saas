<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class QuotationComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // List filters
    public string $search    = '';
    public string $status    = '';
    public int    $perPage   = 15;

    // Modal state
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $quotationId = null;

    // Patient search
    public string $patientSearch  = '';
    public array  $patientResults = [];

    // Product search per-line
    public string $productSearch  = '';
    public int    $productLineIdx = -1;
    public array  $productResults = [];

    // Form fields
    public string $patient_name  = '';
    public string $patient_phone = '';
    public ?int   $patient_id    = null;
    public string $issue_date    = '';
    public string $valid_until   = '';
    public string $notes         = '';
    public string $status_field  = 'draft';
    public float  $discount_amount = 0;

    public array $items = [];

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->issue_date  = now()->toDateString();
        $this->valid_until = now()->addDays(30)->toDateString();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    // Patient type-ahead
    public function updatedPatientSearch(): void
    {
        if (strlen($this->patientSearch) < 2) {
            $this->patientResults = [];
            return;
        }
        $this->patientResults = Patient::where('name', 'like', "%{$this->patientSearch}%")
            ->orWhere('pxnumber', 'like', "%{$this->patientSearch}%")
            ->limit(8)
            ->get(['id', 'name', 'pxnumber', 'contact'])
            ->toArray();
    }

    public function selectPatient(int $id): void
    {
        $p = Patient::find($id);
        if (!$p) return;
        $this->patient_id     = $p->id;
        $this->patient_name   = $p->name;
        $this->patient_phone  = $p->contact ?? '';
        $this->patientSearch  = $p->name;
        $this->patientResults = [];
    }

    public function clearPatient(): void
    {
        $this->patient_id     = null;
        $this->patient_name   = '';
        $this->patient_phone  = '';
        $this->patientSearch  = '';
        $this->patientResults = [];
    }

    public function selectProduct(int $productId): void
    {
        $p = Product::find($productId);
        if (!$p || $this->productLineIdx < 0) return;

        $idx = $this->productLineIdx;
        $this->items[$idx]['product_id']  = $p->id;
        $this->items[$idx]['description'] = $p->name;
        $this->items[$idx]['unit_price']  = (float) $p->selling_price;
        $this->productResults = [];
        $this->productLineIdx = -1;
        $this->recalcLine($idx);
    }

    public function addLine(): void
    {
        $this->items[] = [
            'product_id'  => null,
            'description' => '',
            'quantity'    => 1,
            'unit_price'  => 0,
            'subtotal'    => 0,
        ];
    }

    public function removeLine(int $index): void
    {
        array_splice($this->items, $index, 1);
        $this->items = array_values($this->items);
    }

    public function updatedItems($value, $key): void
    {
        $parts = explode('.', $key);
        if (count($parts) !== 2) return;

        $idx   = (int) $parts[0];
        $field = $parts[1];

        if ($field === 'description') {
            if (strlen((string) $value) >= 2) {
                $this->productLineIdx = $idx;
                $this->productResults = Product::where('name', 'like', "%{$value}%")
                    ->limit(8)->get(['id', 'name', 'selling_price'])->toArray();
            } else {
                $this->productResults = [];
                $this->productLineIdx = -1;
            }
        } elseif (in_array($field, ['quantity', 'unit_price'])) {
            $this->recalcLine($idx);
        }
    }

    private function recalcLine(int $idx): void
    {
        if (!isset($this->items[$idx])) return;
        $qty   = max(0, (float) ($this->items[$idx]['quantity']  ?? 0));
        $price = max(0, (float) ($this->items[$idx]['unit_price'] ?? 0));
        $this->items[$idx]['subtotal'] = round($qty * $price, 2);
    }

    private function calcSubtotal(): float
    {
        return round(array_sum(array_column($this->items, 'subtotal')), 2);
    }

    private function calcTotal(): float
    {
        return round(max(0, $this->calcSubtotal() - (float) $this->discount_amount), 2);
    }

    public function openCreate(): void
    {
        $this->reset([
            'quotationId', 'patient_id', 'patient_name', 'patient_phone',
            'patientSearch', 'patientResults', 'notes', 'discount_amount',
            'items', 'productResults',
        ]);
        $this->status_field = 'draft';
        $this->issue_date   = now()->toDateString();
        $this->valid_until  = now()->addDays(30)->toDateString();
        $this->addLine();
        $this->isEditing  = false;
        $this->showModal  = true;
    }

    public function openEdit(int $id): void
    {
        $q = Quotation::with('items')->findOrFail($id);

        $this->quotationId    = $q->id;
        $this->patient_id     = $q->patient_id;
        $this->patient_name   = $q->patient_name;
        $this->patient_phone  = $q->patient_phone ?? '';
        $this->patientSearch  = $q->patient_name;
        $this->issue_date     = $q->issue_date->toDateString();
        $this->valid_until    = $q->valid_until->toDateString();
        $this->notes          = $q->notes ?? '';
        $this->status_field   = $q->status;
        $this->discount_amount = $q->discount_amount;

        $this->items = $q->items->map(fn ($i) => [
            'product_id'  => $i->product_id,
            'description' => $i->description,
            'quantity'    => $i->quantity,
            'unit_price'  => $i->unit_price,
            'subtotal'    => $i->subtotal,
        ])->toArray();

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'patient_name'    => 'required|string|max:150',
            'issue_date'      => 'required|date',
            'valid_until'     => 'required|date|after_or_equal:issue_date',
            'discount_amount' => 'nullable|numeric|min:0',
            'items'           => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        DB::transaction(function () {
            $data = [
                'patient_id'      => $this->patient_id,
                'patient_name'    => $this->patient_name,
                'patient_phone'   => $this->patient_phone ?: null,
                'status'          => $this->status_field,
                'issue_date'      => $this->issue_date,
                'valid_until'     => $this->valid_until,
                'notes'           => $this->notes ?: null,
                'subtotal'        => $this->calcSubtotal(),
                'discount_amount' => (float) $this->discount_amount,
                'total_amount'    => $this->calcTotal(),
                'created_by'      => auth()->id(),
            ];

            if ($this->isEditing) {
                $q = Quotation::findOrFail($this->quotationId);
                $q->update($data);
                $q->items()->delete();
                $event = 'quotation.updated';
            } else {
                $data['quotation_number'] = Quotation::nextNumber();
                $q = Quotation::create($data);
                $event = 'quotation.created';
            }

            foreach ($this->items as $line) {
                $q->items()->create([
                    'product_id'  => $line['product_id'] ?: null,
                    'description' => $line['description'],
                    'quantity'    => $line['quantity'],
                    'unit_price'  => $line['unit_price'],
                    'subtotal'    => $line['subtotal'],
                ]);
            }

            AuditTrail::record(
                $event,
                ($this->isEditing ? 'Updated' : 'Created') . " quotation {$q->quotation_number} for {$q->patient_name}",
                $q,
                force: true
            );
        });

        $this->showModal = false;
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => $this->isEditing ? 'Quotation updated.' : 'Quotation created.',
        ]);
    }

    public function updateStatus(int $id, string $newStatus): void
    {
        $allowed = ['draft', 'sent', 'accepted', 'expired', 'cancelled'];
        abort_unless(in_array($newStatus, $allowed), 422);

        $q = Quotation::findOrFail($id);
        $old = $q->status;
        $q->update(['status' => $newStatus]);

        AuditTrail::record(
            'quotation.status_changed',
            "Quotation {$q->quotation_number} status changed from {$old} to {$newStatus}",
            $q,
            force: true
        );

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Status updated.']);
    }

    public function confirmDelete(int $id): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
        $q = Quotation::findOrFail($id);
        $this->dispatch('show-confirm', ...[
            'id'      => $id,
            'action'  => 'deleteQuotation',
            'message' => "Delete quotation {$q->quotation_number}? It will be soft-deleted and can be recovered.",
        ]);
    }

    public function deleteQuotation(int $id): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
        $q = Quotation::findOrFail($id);
        AuditTrail::record('quotation.deleted', "Soft-deleted quotation {$q->quotation_number} for {$q->patient_name}", $q, force: true);
        $q->delete(); // soft delete — model uses SoftDeletes
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Quotation deleted.' ]);
    }

    public function render()
    {
        $quotations = Quotation::query()
            ->when($this->search, fn ($q) =>
                $q->where('quotation_number', 'like', "%{$this->search}%")
                  ->orWhere('patient_name', 'like', "%{$this->search}%")
            )
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.admin.quotation-component', [
            'quotations' => $quotations,
            'subtotal'   => $this->calcSubtotal(),
            'total'      => $this->calcTotal(),
        ])->layout('layouts.admin.admin-layout');
    }
}
