<?php

namespace App\Livewire\Admin;

use App\Models\PaymentCorrection;
use App\Services\Finance\DayCloseService;
use App\Services\Finance\PaymentCorrectionService;
use Livewire\Component;

/** Payments recorded under the wrong method, waiting for the owner (the Approvals inbox tab). */
class PaymentCorrectionApprovalsComponent extends Component
{
    public ?int $rejectingId = null;
    public string $rejectReason = '';

    public function mount(): void
    {
        abort_unless(DayCloseService::isOwner(), 403);
    }

    public function approve(int $id): void
    {
        try {
            app(PaymentCorrectionService::class)->approve(PaymentCorrection::findOrFail($id));
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
            return;
        }
        $this->dispatch('notify', type: 'success', message: 'Payment moved to the right method.');
    }

    public function reject(): void
    {
        $this->validate(['rejectReason' => 'required|string|min:5|max:500'], [], ['rejectReason' => 'reason']);
        try {
            app(PaymentCorrectionService::class)->reject(PaymentCorrection::findOrFail($this->rejectingId), $this->rejectReason);
        } catch (\RuntimeException $e) {
            $this->addError('rejectReason', $e->getMessage());
            return;
        }
        $this->reset('rejectingId', 'rejectReason');
        $this->dispatch('notify', type: 'info', message: 'Correction rejected.');
    }

    public function render()
    {
        return view('livewire.admin.payment-correction-approvals-component', [
            'corrections' => PaymentCorrection::with(['payment:id,created_at', 'sale' => fn ($q) => $q->withTrashed()->select('id', 'transaction_id'), 'requester:id,name'])
                ->where('status', PaymentCorrection::PENDING)->oldest('id')->get(),
        ]);
    }
}
