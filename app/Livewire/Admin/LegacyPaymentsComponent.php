<?php

namespace App\Livewire\Admin;

use App\Models\PatientLegacyPayment;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only list of payments brought over from the clinic's previous software (App\Models\PatientLegacyPayment),
 * for the whole clinic. History for owners: these are not sales and are not in receipts, the cash book or finance reports.
 */
class LegacyPaymentsComponent extends Component
{
    use WithPagination;

    public string $fromDate = '';
    public string $toDate = '';
    public string $method = '';
    public string $search = '';

    public function mount(): void
    {
        abort_if(! auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        // ?search= opens the list filtered (the patient ledger links here with a PX number).
        $this->search = mb_substr(trim((string) request()->query('search', '')), 0, 100);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['fromDate', 'toDate', 'method', 'search'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('fromDate', 'toDate', 'method', 'search');
        $this->resetPage();
    }

    /** The filters other than the method, so the method split can show every method for the chosen days. */
    private function query(bool $withMethod = true)
    {
        $day = fn ($value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;

        return PatientLegacyPayment::query()
            ->when($day($this->fromDate), fn ($q, $from) => $q->where('paid_at', '>=', $from.' 00:00:00'))
            ->when($day($this->toDate), fn ($q, $to) => $q->where('paid_at', '<=', $to.' 23:59:59'))
            ->when($withMethod && $this->method !== '', fn ($q) => $this->method === '-' ? $q->whereNull('payment_method') : $q->where('payment_method', $this->method))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('reference', 'like', $term)->orWhere('invoice_number', 'like', $term)
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $term)->orWhere('pxnumber', 'like', $term)));
            });
    }

    public function render()
    {
        $payments = $this->query()->with('patient:id,name,pxnumber')->orderByDesc('paid_at')->orderByDesc('id')->paginate(25);
        $total = (float) $this->query()->sum('amount');
        $patients = (int) $this->query()->distinct()->count('patient_id');
        $byMethod = $this->query(false)->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as n')->groupBy('payment_method')->orderByDesc('total')->get();
        $span = PatientLegacyPayment::query()->selectRaw('MIN(paid_at) as first_at, MAX(paid_at) as last_at, COUNT(*) as n')->first();
        $methods = PatientLegacyPayment::query()->whereNotNull('payment_method')->distinct()->orderBy('payment_method')->pluck('payment_method');

        return view('livewire.admin.legacy-payments-component', [
            'payments' => $payments, 'total' => $total, 'patients' => $patients, 'byMethod' => $byMethod, 'methods' => $methods,
            'firstAt' => $span?->first_at ? Carbon::parse($span->first_at) : null,
            'lastAt' => $span?->last_at ? Carbon::parse($span->last_at) : null,
            'allCount' => (int) ($span?->n ?? 0),
            'hasLegacyExpenses' => \App\Models\LegacyExpense::query()->exists(),
        ])->layout('layouts.admin.admin-layout');
    }
}
