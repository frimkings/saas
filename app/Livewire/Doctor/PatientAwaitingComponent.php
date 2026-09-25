<?php

namespace App\Livewire\Doctor;

use App\Models\CashierPatientClearance;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Response;

class PatientAwaitingComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $searchTerm = '';
    public $showSeen   = false;
    public $fromDate;
    public $toDate;

    public $lastCount = 0;

    public function mount()
    {
        $this->fromDate  = now()->format('Y-m-d');
        $this->toDate    = now()->format('Y-m-d');
        $this->lastCount = $this->getUnseenCount();
    }

    public function syncQueue()
    {
        $currentCount = $this->getUnseenCount();
        if ($currentCount > $this->lastCount) {
            $this->dispatch('play-notification-sound');
        }
        $this->lastCount = $currentCount;
    }

    private function getUnseenCount()
    {
        return CashierPatientClearance::where('doctor_status', 0)
            ->where('clearance_date', now()->format('Y-m-d'))
            ->count();
    }

    public function updated($propertyName)
    {
        $this->resetPage();
    }

    public function resetFilters()
    {
        $this->reset(['searchTerm', 'showSeen']);
        $this->fromDate = now()->format('Y-m-d');
        $this->toDate   = now()->format('Y-m-d');
        $this->resetPage();
    }

    public function exportCSV()
    {
        $fileName = 'patient_export_' . now()->format('Y-m-d_H-i') . '.csv';

        $query   = $this->buildQuery();
        $records = $query->get();

        $headers = [
            'Content-type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($records) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Patient Name', 'Folder Number', 'Contact', 'Clearance Date', 'Service', 'Doctor Status']);
            foreach ($records as $row) {
                fputcsv($file, [
                    $row->patient->name     ?? '',
                    $row->patient->pxnumber ?? '',
                    $row->patient->contact  ?? '',
                    $row->clearance_date,
                    $row->service->name     ?? '—',
                    $row->doctor_status ? 'Attended' : 'Awaiting',
                ]);
            }
            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    private function buildQuery()
    {
        $query = CashierPatientClearance::query()
            ->with(['patient', 'service'])
            ->where('doctor_status', $this->showSeen ? 1 : 0);

        if ($this->fromDate && $this->toDate) {
            $query->whereBetween('clearance_date', [$this->fromDate, $this->toDate]);
        }

        if (!empty($this->searchTerm)) {
            $search = $this->searchTerm;
            $query->whereHas('patient', function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('pxnumber', 'like', '%' . $search . '%');
            });
        }

        return $query;
    }

    public function render()
    {
        $patients = $this->buildQuery()->latest('created_at')->paginate(10);

        return view('livewire.doctor.patient-awaiting-component', compact('patients'))
            ->layout('layouts.doctor.doctor-layout');
    }
}
