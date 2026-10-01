<?php

namespace App\Livewire;

use App\Models\PaymentTransaction;
use App\Models\AuditTrail;
use App\Models\Cart;
use App\Models\Sales;
use App\Services\Visits\PatientVisits;
use App\Support\PaymentMethods;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class OutstandingBalancesComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $searchQuery = '';
    public $perPage = 15;

    public $showModal = false;
    public $selectedSaleId = null;
    public $collectAmount = '';
    public $paymentMethod = 'cash';
    public $paymentNotes = '';
    public $paymentIdempotencyKey;

    // Payment history modal
    public $showHistoryModal = false;
    public $historyForSaleId = null;

    protected function rules(): array
    {
        return [
            'collectAmount' => 'required|numeric|min:0.01',
            'paymentMethod' => ['required', \Illuminate\Validation\Rule::in(PaymentMethods::keys(PaymentMethods::CLINIC))],
        ];
    }

    public function mount()
    {
        abort_if(!auth()->user()?->hasRole(['Secretary', 'Cashier', 'Manager', 'Super Admin']), 403);
        $this->rotatePaymentIdempotencyKey();
    }

    public function getBalancesProperty()
    {
        return Sales::with(['patient', 'user', 'items.product'])
            ->where('payment_status', 'partial')
            ->when($this->searchQuery, fn ($q) =>
                $q->where(fn ($s) =>
                    $s->where('transaction_id', 'like', '%' . $this->searchQuery . '%')
                        ->orWhereHas('patient', fn ($p) =>
                            $p->where('name', 'like', '%' . $this->searchQuery . '%')
                                ->orWhere('pxnumber', 'like', '%' . $this->searchQuery . '%')
                        )
                )
            )
            ->latest()
            ->paginate($this->perPage);
    }

    public function getSelectedSaleProperty()
    {
        if (!$this->selectedSaleId) {
            return null;
        }

        return Sales::with(['patient', 'items.product', 'paymentTransactions.collectedBy'])
            ->find($this->selectedSaleId);
    }

    public function getHistoryForSaleProperty()
    {
        if (!$this->historyForSaleId) {
            return null;
        }

        return Sales::with(['patient', 'paymentTransactions.collectedBy'])
            ->find($this->historyForSaleId);
    }

    public function openHistory(int $saleId): void
    {
        $this->historyForSaleId = $saleId;
        $this->showHistoryModal = true;
    }

    public function closeHistoryModal(): void
    {
        $this->showHistoryModal = false;
        $this->historyForSaleId = null;
    }

    public function switchToCollectFromHistory(): void
    {
        $id = $this->historyForSaleId;
        $this->closeHistoryModal();
        $this->openCollect($id);
    }

    public function openCollect($saleId): void
    {
        $sale = Sales::where('payment_status', 'partial')->findOrFail($saleId);
        $balance = $sale->remaining_balance;

        $this->selectedSaleId = $sale->id;
        $this->collectAmount = number_format($balance, 2, '.', '');
        $this->paymentMethod = PaymentMethods::first(PaymentMethods::CLINIC);
        $this->paymentNotes = '';
        $this->rotatePaymentIdempotencyKey();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->selectedSaleId = null;
        $this->collectAmount = '';
        $this->paymentNotes = '';
        $this->rotatePaymentIdempotencyKey();
        $this->resetErrorBag();
    }

    public function collectPayment(): void
    {
        $this->validate();

        $amount = (float) $this->collectAmount;
        $saleId = (int) $this->selectedSaleId;
        $this->ensurePaymentIdempotencyKey();

        $result = DB::transaction(function () use ($saleId, $amount) {
            // Serialize collections for the same sale. Once this lock is
            // acquired, re-read and validate against the latest committed
            // amount_paid value before recording another payment.
            $sale = Sales::with('items.product')
                ->whereKey($saleId)
                ->lockForUpdate()
                ->firstOrFail();

            $existingPayment = PaymentTransaction::where(
                    'idempotency_key',
                    $this->paymentIdempotencyKey
                )
                ->where('sale_id', $sale->id)
                ->first();

            if ($existingPayment) {
                return [
                    'sale_id' => $sale->id,
                    'fully_paid' => $sale->payment_status === 'paid',
                    'amount' => (float) $existingPayment->amount,
                    'duplicate' => true,
                ];
            }

            $oldAmountPaid = (float) $sale->amount_paid;
            $oldPaymentStatus = $sale->payment_status;
            $balance = $sale->remaining_balance; // the patient's part only; the insurer pays the rest

            if ($amount > $balance || $balance <= 0) {
                throw ValidationException::withMessages([
                    'collectAmount' => 'Amount exceeds remaining balance of '
                        . currency() . ' ' . number_format($balance, 2),
                ]);
            }

            PaymentTransaction::create([
                'sale_id' => $sale->id,
                'idempotency_key' => $this->paymentIdempotencyKey,
                'amount' => $amount,
                'payment_method' => $this->paymentMethod,
                'notes' => $this->paymentNotes ?: null,
                'collected_by' => Auth::id(),
            ]);

            $newAmountPaid = round($oldAmountPaid + $amount, 2);
            $fullyPaid = $newAmountPaid >= $sale->patient_share;

            $sale->update([
                'amount_paid' => $newAmountPaid,
                'payment_status' => $fullyPaid ? 'paid' : 'partial',
                'profit' => $fullyPaid ? $this->recalculateProfit($sale) : $sale->profit,
            ]);

            if ($fullyPaid) {
                $sale->items()->update([
                    'dispensed_quantity' => DB::raw('prescribed_quantity'),
                    'subtotal' => DB::raw('prescribed_quantity * selling_price'),
                ]);

                $cartIds = $sale->items->pluck('cart_id')->filter()->unique()->values();

                if ($cartIds->isNotEmpty()) {
                    Cart::whereIn('id', $cartIds)
                        ->where('is_dispensed', false)
                        ->update([
                            'purchased' => true,
                            'is_dispensed' => true,
                            'dispensed_at' => now(),
                            'dispensed_by' => Auth::id(),
                            'status' => 'completed',
                        ]);
                } elseif ($sale->patient_id) {
                    $productIds = $sale->items->pluck('product_id')->filter()->unique()->values();

                    Cart::where('patient_id', $sale->patient_id)
                        ->whereIn('product_id', $productIds)
                        ->where('purchased', true)
                        ->where('is_dispensed', false)
                        ->update([
                            'is_dispensed' => true,
                            'dispensed_at' => now(),
                            'dispensed_by' => Auth::id(),
                            'status' => 'completed',
                        ]);
                }
            }

            AuditTrail::record(
                $fullyPaid ? 'payment.completed' : 'payment.updated',
                ($fullyPaid ? 'Completed balance payment' : 'Collected part payment') . ' for sale ' . $sale->transaction_id,
                $sale,
                ['amount_paid' => $oldAmountPaid, 'payment_status' => $oldPaymentStatus],
                ['amount_paid' => $newAmountPaid, 'payment_status' => $fullyPaid ? 'paid' : 'partial', 'amount_collected' => $amount],
                $sale->patient_id
            );

            return [
                'sale_id' => $sale->id,
                'fully_paid' => $fullyPaid,
                'amount' => $amount,
                'duplicate' => false,
            ];
        });

        $this->closeModal();
        $this->dispatch('notify', ...[
            'type' => $result['duplicate'] ? 'info' : 'success',
            'message' => 'Payment of ' . currency() . ' ' . number_format($result['amount'], 2)
                . ($result['duplicate'] ? ' was already recorded.' : ' recorded successfully.'),
        ]);

        if ($result['fully_paid']) {
            // With one receipt per visit, settling the balance prints the visit's final receipt.
            $this->dispatch('print-released-receipt', ...[
                'url' => PatientVisits::enabled()
                    ? route('cashier.visit-receipt.sale', $result['sale_id'])
                    : route('cashier.receipt.show', $result['sale_id']),
            ]);
        }
    }

    private function ensurePaymentIdempotencyKey(): void
    {
        if (
            !is_string($this->paymentIdempotencyKey) ||
            !preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
                $this->paymentIdempotencyKey
            )
        ) {
            $this->rotatePaymentIdempotencyKey();
        }
    }

    private function rotatePaymentIdempotencyKey(): void
    {
        $this->paymentIdempotencyKey = (string) Str::uuid();
    }

    private function recalculateProfit(Sales $sale): float
    {
        $grossProfit = (float) $sale->items->sum(function ($item) {
            $cost = $item->unit_cost ?? $item->product->cost_price ?? 0; // cost when sold

            return ((float) $item->selling_price - (float) $cost) * (int) $item->prescribed_quantity;
        });

        return max(0, $grossProfit - (float) $sale->discount_amount);
    }

    public function updatedSearchQuery(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $summary = Sales::where('payment_status', 'partial')
            ->selectRaw('COUNT(*) as held_orders')
            ->selectRaw('COALESCE(SUM(' . Sales::PATIENT_BALANCE_SQL . '), 0) as total_balance')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) as deposits_collected')
            ->first();

        return view('livewire.outstanding-balances', [
            'balances'       => $this->balances,
            'selectedSale'   => $this->selectedSale,
            'historyForSale' => $this->historyForSale,
            'heldOrders' => (int) ($summary->held_orders ?? 0),
            'totalBalance' => (float) ($summary->total_balance ?? 0),
            'depositsCollected' => (float) ($summary->deposits_collected ?? 0),
        ])->layout('layouts.secretary.secretary-layout');
    }
}
