<?php

namespace App\Livewire\Admin;

use App\Models\InsuranceClaim;
use App\Models\Insurer;
use App\Models\InsurerPayment;
use App\Services\Insurance\ClaimSettlement;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Insurer remittances: record one payment from an insurer and split it across the
 * claims it settles. Claims fully paid (or closed as "insurer won't pay more") are
 * marked paid, and any shortfall is billed to the patient or written off.
 */
class InsurerPaymentsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'insurer', except: '')]
    public string $insurerFilter = '';

    public ?int $viewPaymentId = null;

    // Record form
    public bool   $showForm   = false;
    public string $insurerId  = '';
    public array  $payment    = [];
    public array  $allocations = []; // claim id => ['amount' => '', 'settle' => false]

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->resetPaymentForm();

        if ($this->insurerFilter !== '' && Insurer::whereKey($this->insurerFilter)->exists()) {
            $this->openForm($this->insurerFilter);
        }
    }

    public function updatingInsurerFilter(): void { $this->resetPage(); }

    public function openForm(string $insurerId = ''): void
    {
        $this->authorizeAccess();
        $this->resetPaymentForm();
        $this->insurerId = $insurerId;
        $this->loadClaims();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetPaymentForm();
    }

    public function updatedInsurerId(): void
    {
        $this->loadClaims();
    }

    /** Fill the claims oldest first until the payment is used up. */
    public function autoAllocate(): void
    {
        $left = round((float) ($this->payment['amount'] ?? 0), 2);
        foreach ($this->openClaims() as $claim) {
            $give = min($left, $claim->outstandingAmount());
            $this->allocations[$claim->id]['amount'] = $give > 0 ? number_format($give, 2, '.', '') : '';
            $left = round($left - $give, 2);
        }
    }

    public function save(ClaimSettlement $settlement): void
    {
        $this->authorizeAccess();

        $data = $this->validate([
            'insurerId'              => ['required', Rule::exists('insurers', 'id')],
            'payment.amount'         => 'required|numeric|min:0.01',
            'payment.payment_method' => ['required', Rule::in(array_keys(InsurerPayment::METHODS))],
            'payment.paid_on'        => 'required|date|before_or_equal:today',
            'payment.reference'      => 'nullable|string|max:100',
            'payment.notes'          => 'nullable|string|max:500',
            'allocations.*.amount'   => 'nullable|numeric|min:0',
        ], [], [
            'insurerId'              => 'insurer',
            'payment.amount'         => 'amount',
            'payment.payment_method' => 'payment method',
            'payment.paid_on'        => 'date paid',
            'allocations.*.amount'   => 'claim amount',
        ]);

        $insurer = Insurer::findOrFail($data['insurerId']);
        $payment = $settlement->recordPayment($insurer, $this->payment, $this->allocations);

        $this->closeForm();
        $this->viewPaymentId = $payment->id;
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => "Payment {$payment->receipt_number} recorded.",
        ]);
    }

    public function viewPayment(int $id): void
    {
        $this->viewPaymentId = $this->viewPaymentId === $id ? null : $id;
    }

    private function loadClaims(): void
    {
        $this->allocations = $this->openClaims()
            ->mapWithKeys(fn (InsuranceClaim $claim) => [$claim->id => ['amount' => '', 'settle' => false]])
            ->all();
    }

    private function openClaims()
    {
        if ($this->insurerId === '') {
            return collect();
        }

        return InsuranceClaim::with(['patient:id,name,pxnumber', 'sale:id,transaction_id'])
            ->where('insurer_id', $this->insurerId)
            ->whereIn('status', InsuranceClaim::PAYABLE_STATUSES)
            ->orderByRaw('COALESCE(submission_date, created_at)')
            ->orderBy('id')
            ->get();
    }

    private function resetPaymentForm(): void
    {
        $this->insurerId = '';
        $this->payment = [
            'amount'         => '',
            'payment_method' => 'bank_transfer',
            'paid_on'        => today()->toDateString(),
            'reference'      => '',
            'notes'          => '',
        ];
        $this->allocations = [];
        $this->resetValidation();
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless($user?->hasRole('Super Admin') || $user?->can(InsurerPayment::PERMISSION), 403);
    }

    public function render()
    {
        $payments = InsurerPayment::with(['insurer:id,name', 'receiver:id,name'])
            ->withCount('allocations')
            ->when($this->insurerFilter !== '', fn ($q) => $q->where('insurer_id', $this->insurerFilter))
            ->latest('paid_on')
            ->latest('id')
            ->paginate(15);

        $viewPayment = $this->viewPaymentId
            ? InsurerPayment::with(['allocations.claim.patient:id,name,pxnumber', 'allocations.claim.sale:id,transaction_id'])->find($this->viewPaymentId)
            : null;

        $claims = $this->showForm ? $this->openClaims() : collect();
        $allocated = round(collect($this->allocations)->sum(fn ($row) => (float) ($row['amount'] ?: 0)), 2);

        return view('livewire.admin.insurer-payments-component', [
            'payments'    => $payments,
            'viewPayment' => $viewPayment,
            'claims'      => $claims,
            'allocated'   => $allocated,
            'insurerList' => Insurer::orderBy('name')->pluck('name', 'id'),
            'methods'     => InsurerPayment::METHODS,
        ])->layout('layouts.admin.admin-layout');
    }
}
