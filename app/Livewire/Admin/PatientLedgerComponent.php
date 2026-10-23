<?php

namespace App\Livewire\Admin;

use App\Models\InsuranceClaim;
use App\Models\Patient;
use App\Models\RefundLog;
use App\Models\SaleAdjustment;
use App\Models\Sales;
use App\Support\PaymentMethods;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Livewire\Component;

/**
 * A patient's account: what they were charged (at full price), what came off it
 * (discounts, the insurer's share, returned items) and what they paid or got back.
 * Debits raise what the patient owes; credits lower it. A refund returns items
 * (credit) and the money paid for them (debit), so it leaves the balance unchanged.
 */
class PatientLedgerComponent extends Component
{
    public string  $patientSearch  = '';
    public array   $patientResults = [];
    public ?int    $patientId      = null;

    public string $fromDate = '';
    public string $toDate   = '';

    /** ?patient=ID opens the ledger on that patient (links from other pages). */
    public function mount(): void
    {
        $id = request()->query('patient');
        if (is_numeric($id)) $this->selectPatient((int) $id);
    }

    public function updatedPatientSearch(): void
    {
        $term = trim($this->patientSearch);
        if (mb_strlen($term) < 2) {
            $this->patientResults = [];
            return;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $this->patientResults = Patient::where(fn ($q) => $q->where('name', 'like', $like)->orWhere('pxnumber', 'like', $like))
            ->limit(10)
            ->get(['id', 'name', 'pxnumber', 'contact'])
            ->toArray();
    }

    public function selectPatient(int $id): void
    {
        $p = Patient::find($id);
        if (!$p) return;
        $this->patientId      = $p->id;
        $this->patientSearch  = $p->name;
        $this->patientResults = [];
    }

    public function clearPatient(): void
    {
        $this->patientId      = null;
        $this->patientSearch  = '';
        $this->patientResults = [];
    }

    private function patient(): ?Patient
    {
        return $this->patientId ? Patient::find($this->patientId) : null;
    }

    /** Every entry on the patient's account, oldest first, without a running balance. */
    private function allEntries(): Collection
    {
        $sales = Sales::where('patient_id', $this->patientId)
            ->with(['items.product', 'items.opticalProduct', 'insurer', 'paymentTransactions'])
            ->get();
        if ($sales->isEmpty()) return collect();

        $saleIds  = $sales->pluck('id');
        $refunds  = RefundLog::where('status', RefundLog::STATUS_PROCESSED)->whereIn('sale_id', $saleIds)->get()->groupBy('sale_id');
        $writeOffs = SaleAdjustment::where('type', 'insurance_write_off')->whereIn('sale_id', $saleIds)
            ->selectRaw('sale_id, SUM(amount) as total')->groupBy('sale_id')->pluck('total', 'sale_id');
        $shortfalls = InsuranceClaim::whereIn('sale_id', $saleIds)
            ->where('shortfall_action', 'bill_patient')->where('shortfall_amount', '>', 0)->get()->keyBy('sale_id');

        $entries = collect();
        $add = function (Sales $sale, $date, string $type, string $label, float $debit, float $credit) use ($entries) {
            if (round($debit, 2) == 0 && round($credit, 2) == 0) return;
            $entries->push([
                'date'      => Carbon::parse($date),
                'type'      => $type,
                'label'     => $label,
                'reference' => $sale->transaction_id,
                'debit'     => round($debit, 2),
                'credit'    => round($credit, 2),
                'sale_id'   => $sale->id,
            ]);
        };

        foreach ($sales as $sale) {
            $writeOff  = (float) ($writeOffs[$sale->id] ?? 0);
            $shortfall = $shortfalls->get($sale->id);
            $saleRefunds = $refunds->get($sale->id, collect());
            $saleGross = max(0.01, (float) $sale->items->sum('subtotal'));
            $insurer   = $sale->insurer?->name ?? 'insurer';

            // A write-off lowers the bill; the full price is what was charged at the till.
            $gross    = (float) $sale->total_amount + (float) $sale->discount_amount + $writeOff;
            $discount = (float) $sale->discount_amount;
            $itemNames = $sale->items->pluck('display_product_name')->filter()->take(3)->implode(', ');
            $add($sale, $sale->created_at, 'charge', 'Sale' . ($itemNames ? " — {$itemNames}" . ($sale->items->count() > 3 ? '…' : '') : ''), $gross, 0);

            if ($discount > 0) {
                $label = 'Discount' . ($sale->discount_type === 'percentage' ? ' (' . rtrim(rtrim(number_format((float) $sale->discount_value, 2), '0'), '.') . '%)' : '');
                $add($sale, $sale->created_at, 'discount', $label, 0, $discount);
            }

            // The insurer's share at the time of sale; later refunds and shortfalls move it back.
            $refundedInsurer = 0.0;
            foreach ($saleRefunds as $refund) {
                $refundedInsurer += (float) $sale->items->whereIn('id', $refund->sale_item_ids ?: [])->sum('insurer_amount');
            }
            $shortfallBilled = (float) ($shortfall?->shortfall_amount ?? 0);
            $insurerShare = (float) $sale->insurer_amount + $writeOff + $shortfallBilled + $refundedInsurer;
            $add($sale, $sale->created_at, 'insurance', "Billed to {$insurer}", 0, $insurerShare);

            if ($shortfallBilled > 0) {
                $add($sale, $shortfall->payment_date ?? $shortfall->updated_at, 'shortfall', "Not paid by {$insurer} — moved to patient", $shortfallBilled, 0);
            }

            foreach ($sale->paymentTransactions as $payment) {
                $add($sale, $payment->created_at, 'payment', 'Payment (' . PaymentMethods::label($payment->payment_method) . ')', 0, (float) $payment->amount);
            }

            foreach ($saleRefunds as $refund) {
                $items = $sale->items->whereIn('id', $refund->sale_item_ids ?: []);
                $selectedGross = (float) $items->sum('subtotal');
                $netValue = $selectedGross - $discount * ($selectedGross / $saleGross);
                $returnedInsurer = (float) $items->sum('insurer_amount');
                $date = $refund->processed_at ?? $refund->updated_at;
                $kind = $refund->request_type === RefundLog::TYPE_VOID ? 'Voided' : 'Returned';
                $method = $refund->original_payment_references[0]['payment_method'] ?? null;

                $add($sale, $date, 'refund', "{$kind} — {$refund->refund_number}", 0, $netValue);
                $add($sale, $date, 'refund', "Insurer's share of returned items", $returnedInsurer, 0);
                $add($sale, $date, 'refund', 'Money returned' . ($method && $method !== 'legacy' ? ' (' . PaymentMethods::label($method) . ')' : ''), (float) $refund->refunded_amount, 0);
            }
        }

        return $entries->sortBy([['date', 'asc'], ['sale_id', 'asc']])->values();
    }

    /** Entries in the chosen dates with a running balance, opening with the balance brought forward. */
    private function buildLedger(): Collection
    {
        if (!$this->patientId) return collect();

        $from = $this->fromDate ? Carbon::parse($this->fromDate)->startOfDay() : null;
        $to   = $this->toDate ? Carbon::parse($this->toDate)->endOfDay() : null;
        $all  = $this->allEntries();

        $before  = $from ? $all->filter(fn ($e) => $e['date']->lt($from)) : collect();
        $visible = $all->filter(fn ($e) => (!$from || $e['date']->gte($from)) && (!$to || $e['date']->lte($to)))->values();

        $balance = round($before->sum('debit') - $before->sum('credit'), 2);
        $rows = collect();
        if ($before->isNotEmpty()) {
            $rows->push(['date' => $from, 'type' => 'opening', 'label' => 'Balance brought forward', 'reference' => '',
                'debit' => 0.0, 'credit' => 0.0, 'sale_id' => null, 'balance' => $balance]);
        }

        return $rows->concat($visible->map(function ($entry) use (&$balance) {
            $balance += $entry['debit'] - $entry['credit'];
            $entry['balance'] = round($balance, 2);
            return $entry;
        }));
    }

    private function summarise(Collection $entries): array
    {
        return [
            'total_charges'  => round($entries->sum('debit'), 2),
            'total_payments' => round($entries->where('type', 'payment')->sum('credit'), 2),
            'total_discounts' => round($entries->where('type', 'discount')->sum('credit'), 2),
            'total_credits'  => round($entries->sum('credit'), 2),
            'balance'        => $entries->last()['balance'] ?? 0.0,
        ];
    }

    public function printPdf(): mixed
    {
        $patient = $this->patient();
        if (!$patient) return null;

        $entries  = $this->buildLedger();
        $summary  = $this->summarise($entries);
        $setting  = \App\Models\Setting::getSettings();
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
        $patient = $this->patient();
        // Payments from the previous software are not on this account; say they exist and where to see them.
        $legacy = $patient ? \App\Models\PatientLegacyPayment::where('patient_id', $patient->id)->selectRaw('COUNT(*) as n, SUM(amount) as total')->first() : null;

        return view('livewire.admin.patient-ledger-component', [
            'patient' => $patient,
            'entries' => $entries,
            'summary' => $this->summarise($entries),
            'legacyPayments' => $legacy && $legacy->n ? ['count' => (int) $legacy->n, 'total' => (float) $legacy->total] : null,
        ])->layout('layouts.admin.admin-layout');
    }
}
