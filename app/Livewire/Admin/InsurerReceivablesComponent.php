<?php

namespace App\Livewire\Admin;

use App\Models\Insurer;
use App\Models\Sales;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What insurers still owe the clinic: the insurer's share of each insured bill until
 * its claim is marked paid. Rejected claims are listed separately for follow-up.
 */
class InsurerReceivablesComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $insurerFilter = '';
    public string $search        = '';
    public string $view          = 'open'; // open | rejected

    protected $queryString = [
        'insurerFilter' => ['except' => ''],
        'view'          => ['except' => 'open'],
    ];

    public function updatingInsurerFilter(): void { $this->resetPage(); }
    public function updatingSearch(): void        { $this->resetPage(); }

    public function switchView(string $view): void
    {
        $this->view = in_array($view, ['open', 'rejected'], true) ? $view : 'open';
        $this->resetPage();
    }

    /** Insured bills the insurer has not paid and has not rejected. */
    private function openQuery(): Builder
    {
        return Sales::query()->awaitingInsurer();
    }

    /** Bills whose claim the insurer rejected (the shortfall has already been billed or written off). */
    private function rejectedQuery(): Builder
    {
        return Sales::query()
            ->where('is_refunded', false)
            ->whereHas('insuranceClaim', fn ($claim) => $claim->where('status', 'rejected'));
    }

    private function filtered(Builder $query): Builder
    {
        return $query
            ->when($this->insurerFilter, fn ($q) => $q->where('insurer_id', $this->insurerFilter))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(fn ($inner) => $inner
                    ->where('transaction_id', 'like', $term)
                    ->orWhereHas('patient', fn ($patient) => $patient
                        ->where('name', 'like', $term)
                        ->orWhere('pxnumber', 'like', $term)));
            });
    }

    public function render()
    {
        $ageDays = 'DATEDIFF(CURDATE(), created_at)';
        $owed = Sales::INSURER_OWED_SQL;
        $aging = $this->openQuery()
            ->selectRaw('insurer_id')
            ->selectRaw('COUNT(*) AS bills')
            ->selectRaw("COALESCE(SUM({$owed}), 0) AS total")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$ageDays} <= 30 THEN {$owed} END), 0) AS d0_30")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$ageDays} BETWEEN 31 AND 60 THEN {$owed} END), 0) AS d31_60")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$ageDays} BETWEEN 61 AND 90 THEN {$owed} END), 0) AS d61_90")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$ageDays} > 90 THEN {$owed} END), 0) AS d90_plus")
            ->groupBy('insurer_id')
            ->get();

        $insurers = Insurer::withTrashed()->whereIn('id', $aging->pluck('insurer_id'))->pluck('name', 'id');
        $aging->each(fn ($row) => $row->insurer_name = $insurers[$row->insurer_id] ?? 'Unknown insurer');
        $aging = $aging->sortByDesc('total')->values();

        $rejectedCount = $this->rejectedQuery()->count();

        $bills = $this->filtered($this->view === 'rejected' ? $this->rejectedQuery() : $this->openQuery())
            ->with(['patient:id,name,pxnumber', 'insurer:id,name', 'insuranceClaim:id,sale_id,status,claim_amount,amount_received,submission_date,rejection_reason,shortfall_amount,shortfall_action'])
            ->oldest()
            ->paginate(20);

        return view('livewire.admin.insurer-receivables-component', [
            'aging'         => $aging,
            'bills'         => $bills,
            'rejectedCount' => $rejectedCount,
            'insurerList'   => Insurer::orderBy('name')->pluck('name', 'id'),
        ])->layout('layouts.admin.admin-layout');
    }
}
