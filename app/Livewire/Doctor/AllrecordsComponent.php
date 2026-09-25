<?php

namespace App\Livewire\Doctor;

use App\Models\Consultations;
use App\Models\Diagnosis;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AllrecordsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // VA values ordered best → worst
    public const VA_ORDER = [
        '6/4', '6/5', '6/6', '6/9', '6/12',
        '6/18', '6/24', '6/36', '6/60',
        '3/60', '1/60', 'CF', 'HM', 'PL', 'NPL',
    ];

    // Row 1
    public $searchTerm = null;
    public $startDate  = null;
    public $endDate    = null;

    // Row 2
    public $genderFilter    = '';
    public $diagnosisFilter = [];

    // Row 3 — clinical ranges
    public $ageMin = '';
    public $ageMax = '';
    public $iopMin = '';
    public $iopMax = '';
    public $cdrMin = '';
    public $cdrMax = '';
    public $vaMin  = '';
    public $vaMax  = '';

    // Multi-select export
    public $selectedIds = [];
    public $selectAll   = false;

    public function mount(): void
    {
        $this->startDate = now()->subDays(30)->format('Y-m-d');
        $this->endDate   = now()->format('Y-m-d');
    }

    public function updated($propertyName): void
    {
        // Only reset pagination for actual filter properties, not checkbox state
        if (!in_array($propertyName, ['selectedIds', 'selectAll'])) {
            $this->resetPage();
        }
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selectedIds = $value
            ? $this->buildQuery()->pluck('id')->map(fn ($id) => (string) $id)->toArray()
            : [];
    }

    public function updatedSelectedIds(): void
    {
        // Guard: only unset selectAll when it's currently true to avoid
        // a re-trigger loop (updatedSelectedIds → selectAll=false →
        // updatedSelectAll(false) → selectedIds=[] → loop)
        if ($this->selectAll) {
            $this->selectAll = false;
        }
    }

    public function exportCsv(): StreamedResponse
    {
        $records = $this->getExportRecords();

        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Patient', 'Folder No.', 'Gender', 'Age', 'Date', 'Chief Complaint',
                           'Diagnoses', 'IOP OD', 'IOP OS', 'VA OD', 'VA OS', 'CDR OD', 'CDR OS', 'Notes']);
            foreach ($records as $i => $r) {
                $patient = $r->patient;
                $age     = $patient?->dob ? Carbon::parse($patient->dob)->age : '';
                fputcsv($out, [
                    $i + 1,
                    $patient?->name ?? '',
                    $patient?->pxnumber ?? '',
                    $patient?->gender ?? '',
                    $age,
                    $r->created_at->format('d M Y'),
                    $r->chiefComplaint,
                    $r->diagnoses->pluck('name')->implode('; '),
                    $r->IOPOD ?? '',
                    $r->IOPOS ?? '',
                    $r->vaOD6m ?? '',
                    $r->vaOS6m ?? '',
                    $r->cdrOD ?? '',
                    $r->cdrOS ?? '',
                    $r->notes ?? '',
                ]);
            }
            fclose($out);
        }, 'consultations_' . now()->format('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportPdf()
    {
        $records = $this->getExportRecords();
        $pdf     = Pdf::loadView('exports.consultations-pdf', [
            'records'    => $records,
            'exportedAt' => now()->format('d M Y H:i'),
        ])->setPaper('a3', 'landscape');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'consultations_' . now()->format('Ymd_His') . '.pdf'
        );
    }

    private function getExportRecords()
    {
        $query = $this->buildQuery()->with(['patient', 'diagnoses']);
        if (!empty($this->selectedIds)) {
            $query->whereIn('id', $this->selectedIds);
        }
        return $query->latest()->get();
    }

    private function buildQuery()
    {
        $query = Consultations::query();

        if (!empty($this->startDate) && !empty($this->endDate)) {
            $query->whereDate('created_at', '>=', $this->startDate)
                  ->whereDate('created_at', '<=', $this->endDate);
        }
        if (!empty($this->searchTerm)) {
            $term = $this->searchTerm;
            $query->whereHas('patient', fn ($q) =>
                $q->where('name', 'like', "%{$term}%")->orWhere('pxnumber', 'like', "%{$term}%")
            );
        }
        if (!empty($this->genderFilter)) {
            $gender = $this->genderFilter;
            $query->whereHas('patient', fn ($q) => $q->where('gender', $gender));
        }
        if (!empty($this->diagnosisFilter)) {
            $diagIds = $this->diagnosisFilter;
            $query->whereHas('diagnoses', fn ($q) => $q->whereIn('diagnoses.id', $diagIds));
        }
        if ($this->ageMin !== '') {
            $maxDob = Carbon::now()->subYears((int) $this->ageMin)->format('Y-m-d');
            $query->whereHas('patient', fn ($q) => $q->where('dob', '<=', $maxDob));
        }
        if ($this->ageMax !== '') {
            $minDob = Carbon::now()->subYears((int) $this->ageMax + 1)->addDay()->format('Y-m-d');
            $query->whereHas('patient', fn ($q) => $q->where('dob', '>=', $minDob));
        }
        $hasIopMin = $this->iopMin !== '';
        $hasIopMax = $this->iopMax !== '';
        if ($hasIopMin || $hasIopMax) {
            $iopMin = $hasIopMin ? (float) $this->iopMin : null;
            $iopMax = $hasIopMax ? (float) $this->iopMax : null;
            $query->where(function ($q) use ($iopMin, $iopMax) {
                if ($iopMin !== null && $iopMax !== null) {
                    $q->whereBetween('IOPOD', [$iopMin, $iopMax])->orWhereBetween('IOPOS', [$iopMin, $iopMax]);
                } elseif ($iopMin !== null) {
                    $q->where('IOPOD', '>=', $iopMin)->orWhere('IOPOS', '>=', $iopMin);
                } else {
                    $q->where('IOPOD', '<=', $iopMax)->orWhere('IOPOS', '<=', $iopMax);
                }
            });
        }
        $hasCdrMin = $this->cdrMin !== '';
        $hasCdrMax = $this->cdrMax !== '';
        if ($hasCdrMin || $hasCdrMax) {
            $cdrMin = $hasCdrMin ? (float) $this->cdrMin : null;
            $cdrMax = $hasCdrMax ? (float) $this->cdrMax : null;
            $query->where(function ($q) use ($cdrMin, $cdrMax) {
                $q->where(function ($od) use ($cdrMin, $cdrMax) {
                    $od->whereNotNull('cdrOD')->where('cdrOD', '!=', '');
                    if ($cdrMin !== null) $od->whereRaw('CAST(`cdrOD` AS DECIMAL(4,2)) >= ?', [$cdrMin]);
                    if ($cdrMax !== null) $od->whereRaw('CAST(`cdrOD` AS DECIMAL(4,2)) <= ?', [$cdrMax]);
                })->orWhere(function ($os) use ($cdrMin, $cdrMax) {
                    $os->whereNotNull('cdrOS')->where('cdrOS', '!=', '');
                    if ($cdrMin !== null) $os->whereRaw('CAST(`cdrOS` AS DECIMAL(4,2)) >= ?', [$cdrMin]);
                    if ($cdrMax !== null) $os->whereRaw('CAST(`cdrOS` AS DECIMAL(4,2)) <= ?', [$cdrMax]);
                });
            });
        }
        if (!empty($this->vaMin) || !empty($this->vaMax)) {
            $order  = self::VA_ORDER;
            $minIdx = !empty($this->vaMin) ? array_search($this->vaMin, $order) : 0;
            $maxIdx = !empty($this->vaMax) ? array_search($this->vaMax, $order) : count($order) - 1;
            if ($minIdx !== false && $maxIdx !== false && $minIdx <= $maxIdx) {
                $validVAs = array_values(array_slice($order, $minIdx, $maxIdx - $minIdx + 1));
                $query->where(fn ($q) => $q->whereIn('vaOD6m', $validVAs)->orWhereIn('vaOS6m', $validVAs));
            }
        }
        return $query;
    }

    public function clearClinicalFilters(): void
    {
        $this->reset(['ageMin', 'ageMax', 'iopMin', 'iopMax', 'cdrMin', 'cdrMax', 'vaMin', 'vaMax']);
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'searchTerm', 'genderFilter', 'diagnosisFilter',
            'ageMin', 'ageMax', 'iopMin', 'iopMax', 'cdrMin', 'cdrMax', 'vaMin', 'vaMax',
        ]);
        $this->startDate = now()->subDays(30)->format('Y-m-d');
        $this->endDate   = now()->format('Y-m-d');
        $this->resetPage();
    }

    public function render()
    {
        $allrecords = $this->buildQuery()->with(['patient', 'diagnoses', 'clearance:id,uuid'])->latest()->paginate(10);
        $diagnoses  = Diagnosis::orderBy('name')->get(['id', 'name']);
        $vaOptions  = self::VA_ORDER;

        return view('livewire.doctor.allrecords-component', compact('allrecords', 'diagnoses', 'vaOptions'))
            ->layout('layouts.doctor.doctor-layout');
    }
}
