<?php

namespace App\Livewire\Secretary;

use App\Models\OpticalLinkPayment;
use App\Models\OpticalOrderRequest;
use App\Models\OpticalPartnerPayment;
use App\Services\OpticalLinks\OpticalLinkPaymentService;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;

/**
 * What the clinic owes its linked optical centres: jobs billed, what was paid, payments the optical
 * centre still has to confirm, and recording a new payment (booked as the clinic's expense).
 */
class OpticalOwedComponent extends Component
{
    public ?int $payPartnerId = null;
    public bool $showPay = false;
    public string $payAmount = '';
    public string $payMethod = 'momo';
    public string $payReference = '';
    public string $payNotes = '';
    public bool $splitByJob = false;
    /** @var array<int|string, string> request id => amount */
    public array $allocations = [];
    public ?int $historyPartnerId = null;

    public function mount(): void
    {
        abort_unless(\App\Support\Access\Access::allows(auth()->user(), 'optical_centres.manage'), 403);
    }

    public function openPay(int $partnerId): void
    {
        $row = $this->service()->summary()->first(fn ($row) => (int) $row['link']->id === $partnerId);
        abort_unless($row, 404);
        $this->payPartnerId = $partnerId;
        $this->showPay = true;
        $this->payAmount = $row['to_pay'] > 0 ? number_format($row['to_pay'], 2, '.', '') : '';
        $this->payMethod = 'momo';
        $this->reset(['payReference', 'payNotes', 'splitByJob', 'allocations']);
        $this->resetErrorBag();
    }

    public function closePay(): void
    {
        $this->reset(['payPartnerId', 'showPay', 'payAmount', 'payReference', 'payNotes', 'splitByJob', 'allocations']);
        $this->resetErrorBag();
    }

    public function recordPayment(): void
    {
        $this->validate([
            'payAmount' => 'required|numeric|gt:0|max:99999999',
            'payMethod' => 'required|in:'.implode(',', array_keys(OpticalPartnerPayment::METHODS)),
            'payReference' => 'nullable|string|max:100',
            'payNotes' => 'nullable|string|max:1000',
            'allocations.*' => 'nullable|numeric|min:0',
        ], [], ['payAmount' => 'amount', 'payMethod' => 'payment method']);
        $allocations = $this->splitByJob ? array_filter($this->allocations, fn ($value) => (float) $value > 0) : null;
        $payment = $this->service()->record((int) $this->payPartnerId, (float) $this->payAmount, $this->payMethod,
            $this->payReference, $this->payNotes, $allocations, auth()->user());
        $this->closePay();
        session()->flash('success', 'Payment '.$payment->voucher_number.' recorded and added to your expenses. The optical centre confirms it once the money arrives.');
    }

    public function withdraw(int $id): void
    {
        $payment = OpticalLinkPayment::forSender($this->clinicId())->findOrFail($id);
        $this->service()->withdraw($payment, auth()->user());
        session()->flash('success', 'Payment withdrawn and its expense removed.');
    }

    public function bookReceipt(int $partnerId, int $receiptId): void
    {
        $this->service()->bookReceipt($partnerId, $receiptId, auth()->user());
        session()->flash('success', 'Added to your expenses.');
    }

    public function toggleHistory(int $partnerId): void
    {
        $this->historyPartnerId = $this->historyPartnerId === $partnerId ? null : $partnerId;
    }

    /**
     * Account history with one optical centre: jobs charged and payments confirmed, with a running balance.
     *
     * @return array<int, array{date: \Carbon\CarbonInterface, description: string, charge: float, payment: float, balance: float}>
     */
    private function history(int $partnerId): array
    {
        $entries = collect();
        foreach ($this->service()->billedJobs($partnerId) as $job) {
            $entries->push(['date' => $job->approved_at ?? $job->created_at, 'description' => 'Job '.$job->order_number.' · '.$job->patient_name, 'charge' => (float) $job->order_total, 'payment' => 0.0]);
        }
        OpticalLinkPayment::forSender($this->clinicId())->where('optical_partner_clinic_id', $partnerId)->where('status', 'confirmed')->get()
            ->each(fn ($payment) => $entries->push(['date' => $payment->confirmed_at ?? $payment->created_at,
                'description' => 'Payment '.$payment->voucher_number.' · '.$payment->methodLabel(), 'charge' => 0.0, 'payment' => (float) $payment->amount]));
        foreach ($this->service()->unbookedReceipts($partnerId) as $row) {
            $entries->push(['date' => $row['payment']->created_at, 'description' => 'Receipt '.$row['payment']->receipt_number.' (recorded by the optical centre)', 'charge' => 0.0, 'payment' => $row['amount']]);
        }
        $balance = 0.0;

        return $entries->sortBy(fn ($entry) => [$entry['date']->timestamp, $entry['charge'] > 0 ? 0 : 1])->values()
            ->map(function ($entry) use (&$balance) {
                $balance = round($balance + $entry['charge'] - $entry['payment'], 2);

                return $entry + ['balance' => $balance];
            })->all();
    }

    private function service(): OpticalLinkPaymentService
    {
        return app(OpticalLinkPaymentService::class);
    }

    private function clinicId(): int
    {
        return (int) app(TenantContext::class)->clinicId();
    }

    public function render()
    {
        $summary = $this->service()->summary();
        $payRow = $this->showPay && $this->payPartnerId ? $summary->first(fn ($row) => (int) $row['link']->id === $this->payPartnerId) : null;
        $waiting = $payRow ? $this->service()->waitingByJob($this->payPartnerId) : [];

        return view('livewire.secretary.optical-owed-component', [
            'summary' => $summary,
            'payRow' => $payRow,
            'payJobs' => $payRow ? $payRow['open']->map(fn (OpticalOrderRequest $job) => ['job' => $job, 'left' => round(max(0, $job->balance() - ($waiting[$job->id] ?? 0)), 2)])
                ->filter(fn ($row) => $row['left'] > 0)->values() : collect(),
            'payments' => OpticalLinkPayment::forSender($this->clinicId())->with('opticalClinic:id,name', 'recorder:id,name')->latest('id')->limit(30)->get(),
            'unbooked' => $summary->mapWithKeys(fn ($row) => [$row['link']->id => $this->service()->unbookedReceipts($row['link']->id)]),
            'history' => $this->historyPartnerId ? $this->history($this->historyPartnerId) : [],
            'methods' => OpticalPartnerPayment::METHODS,
        ])->layout('layouts.clinic', ['menu' => 'reception']);
    }
}
