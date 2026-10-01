<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Models\SmsLog;
use App\Models\SmsLogArchive;
use Livewire\Component;
use Livewire\WithPagination;

class SmsLogsComponent extends Component
{
    use WithPagination;

    public bool   $showArchive    = false;
    public string $search         = '';
    public string $filterStatus   = '';
    public string $filterTemplate = '';
    public string $filterChannel  = '';
    public string $filterBranch   = '';
    public string $dateFrom       = '';
    public string $dateTo         = '';

    protected $queryString = ['search', 'filterStatus', 'filterTemplate', 'filterChannel', 'filterBranch', 'dateFrom', 'dateTo'];

    public function updatingSearch(): void         { $this->resetPage(); }
    public function updatingFilterStatus(): void   { $this->resetPage(); }
    public function updatingFilterTemplate(): void { $this->resetPage(); }
    public function updatingFilterChannel(): void  { $this->resetPage(); }
    public function updatingFilterBranch(): void   { $this->resetPage(); }
    public function updatingDateFrom(): void       { $this->resetPage(); }
    public function updatingDateTo(): void         { $this->resetPage(); }

    public function toggleArchive(): void
    {
        $this->showArchive = !$this->showArchive;
        $this->search = $this->filterStatus = $this->filterTemplate = $this->filterChannel = $this->filterBranch = $this->dateFrom = $this->dateTo = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterTemplate = $this->filterChannel = $this->filterBranch = $this->dateFrom = $this->dateTo = '';
        $this->resetPage();
    }

    public function render()
    {
        $model = $this->showArchive ? new SmsLogArchive : new SmsLog;

        $query = $model::with(['patient', 'branch'])
            ->when($this->search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('recipient', 'like', "%{$this->search}%")
                       ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))
                       ->orWhere('message', 'like', "%{$this->search}%")
                )
            )
            ->when($this->filterStatus !== '', fn ($q) =>
                $q->where('status', $this->filterStatus)
            )
            ->when($this->filterChannel, fn ($q) =>
                $q->where('channel', $this->filterChannel)
            )
            ->when($this->filterBranch, fn ($q) =>
                $q->where('branch_id', $this->filterBranch)
            )
            ->when($this->filterTemplate, fn ($q) =>
                $q->where('template_key', $this->filterTemplate)
            )
            ->when($this->dateFrom, fn ($q) =>
                $q->whereDateIndexed('created_at', '>=', $this->dateFrom)
            )
            ->when($this->dateTo, fn ($q) =>
                $q->whereDateIndexed('created_at', '<=', $this->dateTo)
            )
            ->latest('created_at');

        $templates = $model::selectRaw('template_key')
            ->whereNotNull('template_key')
            ->distinct()
            ->orderBy('template_key')
            ->pluck('template_key');

        $totals = $this->showArchive ? null : [
            'total'   => SmsLog::count(),
            'success' => SmsLog::where('status', 'sent')->count(),
            'failed'  => SmsLog::where('status', 'failed')->count(),
        ];

        return view('livewire.admin.sms-logs-component', [
            'logs'        => $query->paginate(25),
            'templates'   => $templates,
            'branches'    => Branch::where('clinic_id', SmsLog::clinicIdForWrite())->orderBy('name')->pluck('name', 'id'),
            'totals'      => $totals,
            'showArchive' => $this->showArchive,
        ])->layout('layouts.admin.admin-layout');
    }
}
