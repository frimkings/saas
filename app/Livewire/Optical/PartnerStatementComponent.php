<?php

namespace App\Livewire\Optical;

use App\Models\OpticalPartnerClinic;
use App\Models\OpticalPartnerPayment;
use App\Services\OpticalPartnerAccountService;
use App\Support\Messaging\WhatsAppLink;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A partner clinic's account: balance and aging, the jobs still owed, one payment settling
 * many jobs, and a statement for any period.
 */
class PartnerStatementComponent extends Component
{
    #[Locked]
    public int $partnerId;
    public string $from = '';
    public string $to = '';

    public bool $showPaymentForm = false;
    public string $paymentAmount = '';
    public string $paymentMethod = 'bank_transfer';
    public string $paymentReference = '';
    public string $paymentNotes = '';
    public bool $allocateManually = false;
    public array $allocations = [];

    protected $queryString = ['from', 'to'];

    public function mount(int $partner): void
    {
        $this->partnerId = OpticalPartnerClinic::findOrFail($partner)->id;
        $this->from = $this->validDate($this->from) ?? now()->startOfMonth()->toDateString();
        $this->to = $this->validDate($this->to) ?? now()->toDateString();
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }

    private function partner(): OpticalPartnerClinic
    {
        return OpticalPartnerClinic::findOrFail($this->partnerId);
    }

    public function openPaymentForm(): void
    {
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'allocateManually', 'allocations']);
        $this->paymentMethod = 'bank_transfer';
        $this->showPaymentForm = true;
        $this->resetValidation();
    }

    public function updatedAllocateManually(bool $manual): void
    {
        $this->allocations = [];
        if (! $manual) return;
        // Start from the oldest-first split of the amount entered, then let staff change it.
        $left = round((float) $this->paymentAmount, 2);
        $service = app(OpticalPartnerAccountService::class);
        foreach ($service->openOrders($this->partner()) as $order) {
            $share = max(0, min($left, $service->balanceOf($order)));
            $this->allocations[$order->id] = $share > 0 ? number_format($share, 2, '.', '') : '';
            $left = round($left - $share, 2);
        }
    }

    public function recordPayment(): void
    {
        $this->validate([
            'paymentAmount' => 'required|numeric|gt:0|max:99999999',
            'paymentMethod' => 'required|in:'.implode(',', array_keys(OpticalPartnerPayment::METHODS)),
            'paymentReference' => 'nullable|string|max:100',
            'paymentNotes' => 'nullable|string|max:1000',
            'allocations.*' => 'nullable|numeric|min:0',
        ]);
        $payment = app(OpticalPartnerAccountService::class)->recordPayment(
            $this->partner(), (float) $this->paymentAmount, $this->paymentMethod, $this->paymentReference, $this->paymentNotes,
            $this->allocateManually ? $this->allocations : null,
        );
        $this->showPaymentForm = false;
        session()->flash('success', "Payment {$payment->receipt_number} of ".currency()." ".number_format((float) $payment->amount, 2).' recorded against '.$payment->allocations->count().' '.\Illuminate\Support\Str::plural('job', $payment->allocations->count()).'.');
    }

    public function render()
    {
        $partner = $this->partner();
        $service = app(OpticalPartnerAccountService::class);
        $from = Carbon::parse($this->validDate($this->from) ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->validDate($this->to) ?? now()->toDateString());
        if ($from->gt($to)) [$from, $to] = [$to, $from];
        $statement = $service->statement($partner, $from, $to);
        $balance = $service->balance($partner);
        $message = "Hello {$partner->name}, your account statement from ".$from->format('d M Y').' to '.$to->format('d M Y').': opening '.currency().' '.number_format($statement['opening'], 2)
            .', jobs '.currency().' '.number_format($statement['charges'], 2).', payments '.currency().' '.number_format($statement['payments'], 2).'. Balance due: '.currency().' '.number_format($balance, 2).'. Thank you.';

        return view('livewire.optical.partner-statement-component', [
            'partner' => $partner,
            'balance' => $balance,
            'aging' => $service->aging($partner),
            'openOrders' => $service->openOrders($partner),
            'service' => $service,
            'statement' => $statement,
            'fromDate' => $from, 'toDate' => $to,
            'payments' => OpticalPartnerPayment::with(['allocations.order', 'receiver'])->where('optical_partner_clinic_id', $partner->id)->latest('id')->limit(20)->get(),
            'whatsApp' => $partner->notify_via !== 'none' ? WhatsAppLink::to($partner->messagingPhone(), $message) : null,
        ])->layout('layouts.optical');
    }
}
