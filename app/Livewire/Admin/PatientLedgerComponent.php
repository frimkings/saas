<?php

namespace App\Livewire\Admin;

use App\Models\Patient;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Models\Sales;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Livewire\Component;

class PatientLedgerComponent extends Component
{
    public string  $patientSearch  = '';
    public array   $patientResults = [];
    public ?int    $patientId      = null;
    public ?object $patient        = null;

    public string $fromDate = '';
    public string $toDate   = '';

    public function updatedPatientSearch(): void
    {
        if (strlen($this->patientSearch) < 2) {
            $this->patientResults = [];
            return;
        }
        $this->patientResults = Patient::where('name', 'like', "%{$this->patientSearch}%")
            ->orWhere('pxnumber', 'like', "%{$this->patientSearch}%")
            ->limit(10)
            ->get(['id', 'name', 'pxnumber', 'contact'])
            ->toArray();
    }

    public function selectPatient(int $id): void
    {
        $p = Patient::find($id);
        if (!$p) return;
        $this->patient        = $p;
        $this->patientId      = $p->id;
        $this->patientSearch  = $p->name;
        $this->patientResults = [];
    }

    public function clearPatient(): void
    {
        $this->patient        = null;
        $this->patientId      = null;
        $this->patientSearch  = '';
        $this->patientResults = [];
    }

    private function buildLedger(): Collection
    {
        if (!$this->patientId) return collect();

        $entries = collect();

        // 1. Sales (charges)
        $salesQuery = Sales::where('patient_id', $this->patientId)->with('items');
        if ($this->fromDate) $salesQuery->whereDate('created_at', '>=', $this->fromDate);
        if ($this->toDate)   $salesQuery->whereDate('created_at', '<=', $this->toDate);

        foreach ($salesQuery->get() as $sale) {
            $entries->push([
                'date'        => $sale->created_at,
                'type'        => 'charge',
                'label'       => 'Sale #' . $sale->transaction_id,
                'reference'   => $sale->transaction_id,
                'debit'       => (float) $sale->total_amount,
                'credit'      => 0.0,
                'sale_id'     => $sale->id,
            ]);
        }

        // 2. Payments (credits)
        $payQuery = PaymentTransaction::whereHas('sale', fn ($q) => $q->where('patient_id', $this->patientId));
        if ($this->fromDate) $payQuery->whereDate('created_at', '>=', $this->fromDate);
        if ($this->toDate)   $payQuery->whereDate('created_at', '<=', $this->toDate);

        foreach ($payQuery->get() as $pt) {
            $entries->push([
                'date'        => $pt->created_at,
                'type'        => 'payment',
                'label'       => 'Payment (' . ucfirst(str_replace('_', ' ', $pt->payment_method)) . ')',
                'reference'   => $pt->sale_id,
                'debit'       => 0.0,
                'credit'      => (float) $pt->amount,
                'sale_id'     => $pt->sale_id,
            ]);
        }

        // 3. Approved refunds (credits — money returned to patient)
        $refundQuery = RefundLog::where('status', RefundLog::STATUS_APPROVED)
            ->whereHas('sale', fn ($q) => $q->where('patient_id', $this->patientId));
        if ($this->fromDate) $refundQuery->whereDate('approved_at', '>=', $this->fromDate);
        if ($this->toDate)   $refundQuery->whereDate('approved_at', '<=', $this->toDate);

        foreach ($refundQuery->with('sale')->get() as $refund) {
            $entries->push([
                'date'      => $refund->approved_at ?? $refund->created_at,
                'type'      => 'refund',
                'label'     => 'Refund — ' . ($refund->reason ?? 'approved refund'),
                'reference' => $refund->sale_id,
                'debit'     => 0.0,
                'credit'    => (float) ($refund->sale?->total_amount ?? 0),
                'sale_id'   => $refund->sale_id,
            ]);
        }

        // Sort by date, compute running balance
        $sorted  = $entries->sortBy('date')->values();
        $balance = 0.0;

        return $sorted->map(function ($entry) use (&$balance) {
            $balance += $entry['debit'] - $entry['credit'];
            $entry['balance'] = round($balance, 2);
            return $entry;
        });
    }

    public function getSummaryProperty(): array
    {
        $entries = $this->buildLedger();
        return [
            'total_charges'  => $entries->sum('debit'),
            'total_payments' => $entries->sum('credit'),
            'balance'        => $entries->last()['balance'] ?? 0.0,
        ];
    }

    public function printPdf(): mixed
    {
        if (!$this->patientId) return null;

        $patient = $this->patient ?? Patient::find($this->patientId);
        $entries = $this->buildLedger();
        $summary = $this->summary;
        $setting = \App\Models\Setting::getSettings();
        $fromDate = $this->fromDate;
        $toDate   = $this->toDate;

        $pdf = Pdf::loadView('pdf.patient-ledger', compact(
            'patient', 'entries', 'summary', 'setting', 'fromDate', 'toDate'
        ))->setPaper('a4', 'portrait');

        return Response::streamDownload(
            fn () => print($pdf->output()),
            "Ledger-{$patient->pxnumber}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function render()
    {
        $entries = $this->buildLedger();
        return view('livewire.admin.patient-ledger-component', [
            'entries' => $entries,
        ])->layout('layouts.admin.admin-layout');
    }
}
