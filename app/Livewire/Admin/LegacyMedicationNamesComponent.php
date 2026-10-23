<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\LegacyMedicationMatch;
use App\Models\PatientLegacyMedication;
use App\Models\Product;
use App\Support\LegacyMedicationProducts;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Drug names from the clinic's previous software, each matched once to one of the clinic's products,
 * so "Add again" in the Prescription tab works for every patient who was prescribed it.
 */
class LegacyMedicationNamesComponent extends Component
{
    use WithPagination;

    public string $search = '';
    public bool $unmatchedOnly = true;

    public function mount(): void
    {
        abort_if(! auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        // ?search= opens on one name (the Prescription tab links here).
        $this->search = mb_substr(trim((string) request()->query('search', '')), 0, 100);
        if ($this->search !== '') $this->unmatchedOnly = false;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'unmatchedOnly'], true)) $this->resetPage();
    }

    public function match(string $key, $productId): void
    {
        $key = LegacyMedicationProducts::key($key);
        if ($key === '' || ! PatientLegacyMedication::where(DB::raw('LOWER(TRIM(item))'), $key)->exists()) return;
        if ($productId === '' || $productId === null) { $this->clear($key); return; }

        $product = Product::find((int) $productId);
        if (! $product) return;
        LegacyMedicationMatch::updateOrCreate(['name_key' => $key], ['product_id' => $product->id, 'matched_by' => auth()->id()]);
        AuditTrail::record('legacy_medication.matched', 'Matched previous software drug "'.$key.'" to '.$product->name, $product, [], ['name' => $key]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Matched to '.$product->name.'.']);
    }

    public function clear(string $key): void
    {
        LegacyMedicationMatch::where('name_key', LegacyMedicationProducts::key($key))->delete();
    }

    public function render()
    {
        $nameKey = DB::raw('LOWER(TRIM(item))');
        $matched = LegacyMedicationMatch::whereHas('product')->pluck('name_key');
        $sameName = Product::query()->pluck(DB::raw('LOWER(TRIM(name)) as k'));

        $names = PatientLegacyMedication::query()
            ->selectRaw('LOWER(TRIM(item)) as name_key, MIN(item) as item, COUNT(*) as times, COUNT(DISTINCT patient_id) as patients, MAX(prescribed_on) as last_on')
            ->when(trim($this->search) !== '', fn ($q) => $q->where('item', 'like', '%'.trim($this->search).'%'))
            ->when($this->unmatchedOnly, fn ($q) => $q->whereNotIn($nameKey, $matched->merge($sameName)->unique()->values()))
            ->groupBy('name_key')->orderByDesc('times')->orderBy('name_key')
            ->paginate(25);

        $resolved = LegacyMedicationProducts::forNames($names->pluck('item'));
        $manual = LegacyMedicationMatch::whereIn('name_key', $names->pluck('name_key'))->whereHas('product')->pluck('product_id', 'name_key');
        $totalNames = PatientLegacyMedication::query()->distinct()->count(DB::raw('LOWER(TRIM(item))'));
        $unmatched = PatientLegacyMedication::query()->whereNotIn($nameKey, $matched->merge($sameName)->unique()->values())->distinct()->count(DB::raw('LOWER(TRIM(item))'));

        return view('livewire.admin.legacy-medication-names-component', [
            'names' => $names, 'resolved' => $resolved, 'manual' => $manual,
            'products' => Product::orderBy('name')->get(['id', 'name', 'quantity']),
            'totalNames' => $totalNames, 'unmatchedCount' => $unmatched,
        ])->layout('layouts.admin.admin-layout');
    }
}
