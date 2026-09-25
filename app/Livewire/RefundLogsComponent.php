<?php

namespace App\Livewire;

use App\Models\RefundLog;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class RefundLogsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $fromDate  = '';
    public string $toDate    = '';
    public string $staffId   = '';
    public string $search    = '';
    public int    $perPage   = 15;

    protected $queryString = [
        'fromDate' => ['except' => ''],
        'toDate'   => ['except' => ''],
        'staffId'  => ['except' => ''],
        'search'   => ['except' => ''],
        'page'     => ['except' => 1],
    ];

    public function mount(): void
    {
        abort_if(
            !auth()->user()?->hasRole(['Secretary', 'Cashier', 'Manager', 'Super Admin']),
            403
        );

        if (!$this->fromDate) {
            $this->fromDate = Carbon::now()->subMonth()->toDateString();
        }
        if (!$this->toDate) {
            $this->toDate = Carbon::now()->toDateString();
        }
    }

    public function updatedFromDate(): void  { $this->resetPage(); }
    public function updatedToDate(): void    { $this->resetPage(); }
    public function updatedStaffId(): void   { $this->resetPage(); }
    public function updatedSearch(): void    { $this->resetPage(); }
    public function updatedPerPage(): void   { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->fromDate = Carbon::now()->subMonth()->toDateString();
        $this->toDate   = Carbon::now()->toDateString();
        $this->staffId  = '';
        $this->search   = '';
        $this->perPage  = 15;
        $this->resetPage();
    }

    public function exportCsv()
    {
        [$from, $to] = $this->dateRange();

        $fileName = 'Refund_Logs_' . $from->toDateString() . '_to_' . $to->toDateString() . '.csv';

        $query = $this->buildQuery();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($query) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($file, ['#', 'Transaction ID', 'Initiated By', 'Approved By', 'Processed By', 'Status', 'Reason', 'Date']);

            $query->with(['sale', 'initiator', 'approvedBy', 'processedBy'])->chunk(200, function ($logs) use ($file) {
                foreach ($logs as $log) {
                    fputcsv($file, [
                        $log->id,
                        optional($log->sale)->transaction_id ?? 'N/A',
                        optional($log->initiator)->name ?? '—',
                        optional($log->approvedBy)->name ?? '—',
                        optional($log->processedBy)->name ?? '—',
                        $log->status,
                        $log->reason,
                        $log->created_at->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function dateRange(): array
    {
        $from = Carbon::parse($this->fromDate ?: now()->subMonth())->startOfDay();
        $to   = Carbon::parse($this->toDate   ?: now())->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    private function buildQuery()
    {
        [$from, $to] = $this->dateRange();

        return RefundLog::with(['sale:id,transaction_id', 'initiator:id,name', 'approvedBy:id,name', 'processedBy:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->when($this->staffId, function ($q) {
                $q->where(function ($inner) {
                    $inner->where('initiated_by', $this->staffId)
                          ->orWhere('approved_by', $this->staffId)
                          ->orWhere('processed_by', $this->staffId);
                });
            })
            ->when($this->search, function ($q) {
                $q->whereHas('sale', fn ($s) => $s->where('transaction_id', 'like', '%' . $this->search . '%'))
                  ->orWhere('reason', 'like', '%' . $this->search . '%');
            })
            ->orderBy('created_at', 'desc');
    }

    public function render()
    {
        $logs  = $this->buildQuery()->paginate($this->perPage);
        $staff = User::orderBy('name')->get(['id', 'name']);

        return view('livewire.refund-logs-component', compact('logs', 'staff'))
            ->layout('layouts.secretary.secretary-layout');
    }
}
