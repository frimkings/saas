<?php

namespace App\Livewire\Admin;

use App\Models\DayClose;
use App\Models\PaymentCorrection;
use App\Models\PaymentTransaction;
use App\Services\Finance\CashBook;
use App\Services\Finance\DayCloseService;
use App\Services\Finance\PaymentCorrectionService;
use App\Support\PaymentMethods;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Balancing the till at the end of the day, for the branch: what each method should hold,
 * what was counted, who took what, and the day's mistakes put right (payment corrections and
 * voids, decided by the owner) before the day is closed.
 */
class DayCloseComponent extends Component
{
    public string $date = '';
    public array $counted = [];
    public string $banked = '';
    public string $notes = '';

    public ?int $correctingPaymentId = null;
    public string $correctTo = '';
    public string $correctReason = '';

    public ?int $rejectingCorrectionId = null;
    public string $rejectReason = '';

    public string $reopenReason = '';

    protected $queryString = ['date' => ['except' => '']];

    public function mount(): void
    {
        $this->date = $this->validDate($this->date);
    }

    private function validDate(string $value): string
    {
        try {
            $date = $value !== '' ? Carbon::parse($value) : today();
        } catch (\Throwable) {
            $date = today();
        }

        return min($date->toDateString(), today()->toDateString());
    }

    public function updatedDate(): void
    {
        $this->date = $this->validDate($this->date);
        $this->reset('counted', 'banked', 'notes', 'correctingPaymentId', 'rejectingCorrectionId', 'reopenReason');
        $this->resetErrorBag();
    }

    private function day(): Carbon
    {
        return Carbon::parse($this->date)->startOfDay();
    }

    public function close(): void
    {
        $days = app(DayCloseService::class);
        $this->validate([
            'counted' => 'array',
            'counted.*' => 'nullable|numeric|min:0|max:99999999',
            'banked' => 'nullable|numeric|min:0|max:99999999',
            'notes' => 'nullable|string|max:1000',
        ], [], ['counted.*' => 'count', 'banked' => 'amount banked']);

        try {
            $days->close($this->day(), $this->counted, (float) ($this->banked ?: 0), trim($this->notes));
        } catch (\RuntimeException $e) {
            $this->addError('close', $e->getMessage());
            return;
        }
        $this->reset('counted', 'banked', 'notes');
        session()->flash('success', 'The day ' . $this->day()->format('d M Y') . ' is closed.');
    }

    public function reopen(): void
    {
        $days = app(DayCloseService::class);
        abort_unless(DayCloseService::isOwner(), 403);
        $this->validate(['reopenReason' => 'required|string|min:5|max:500'], [], ['reopenReason' => 'reason']);
        $close = $days->find($this->day());
        abort_unless($close, 404);
        try {
            $days->reopen($close, trim($this->reopenReason));
        } catch (\RuntimeException $e) {
            $this->addError('reopenReason', $e->getMessage());
            return;
        }
        $this->reopenReason = '';
        session()->flash('success', 'The day is open again. Close it once it is put right.');
    }

    public function startCorrection(int $paymentId): void
    {
        $this->correctingPaymentId = $paymentId;
        $this->correctTo = '';
        $this->correctReason = '';
        $this->resetErrorBag(['correctTo', 'correctReason']);
    }

    public function requestCorrection(): void
    {
        $corrections = app(PaymentCorrectionService::class);
        $this->validate(['correctTo' => 'required|string', 'correctReason' => 'required|string|min:5|max:500'], [], ['correctTo' => 'right method', 'correctReason' => 'reason']);
        $payment = PaymentTransaction::findOrFail($this->correctingPaymentId);
        try {
            $corrections->request($payment, $this->correctTo, $this->correctReason);
        } catch (\RuntimeException $e) {
            $this->addError('correctTo', $e->getMessage());
            return;
        }
        $this->correctingPaymentId = null;
        session()->flash('success', 'Correction sent to the owner for approval.');
    }

    public function approveCorrection(int $id): void
    {
        $corrections = app(PaymentCorrectionService::class);
        try {
            $corrections->approve(PaymentCorrection::findOrFail($id));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
            return;
        }
        session()->flash('success', 'Payment moved to the right method.');
    }

    public function rejectCorrection(): void
    {
        $corrections = app(PaymentCorrectionService::class);
        $this->validate(['rejectReason' => 'required|string|min:5|max:500'], [], ['rejectReason' => 'reason']);
        try {
            $corrections->reject(PaymentCorrection::findOrFail($this->rejectingCorrectionId), $this->rejectReason);
        } catch (\RuntimeException $e) {
            $this->addError('rejectReason', $e->getMessage());
            return;
        }
        $this->rejectingCorrectionId = null;
        $this->rejectReason = '';
        session()->flash('success', 'Correction rejected.');
    }

    public function render()
    {
        $days = app(DayCloseService::class);
        $book = app(CashBook::class);
        $day = $this->day();
        $close = $days->find($day);
        $closed = (bool) $close?->isClosed();
        $figures = $book->day($day, $closed ? $close->id : null);
        $branch = app(TenantContext::class)->branch();
        $float = (float) ($branch?->till_float ?? 0);
        $countedCash = $this->counted['cash'] ?? '';

        $payments = $days->payments($day);
        $corrections = PaymentCorrection::with(['payment:id,created_at', 'sale' => fn ($q) => $q->withTrashed()->select('id', 'transaction_id'), 'requester:id,name', 'decider:id,name'])
            ->whereIn('payment_transaction_id', $payments->pluck('id'))->latest('id')->get();

        // What moved after the count, so a closed day that no longer matches stands out.
        $drift = $closed ? collect($figures)->map(fn ($f, $key) => round($f['expected'] - (float) ($close->methods[$key]['expected'] ?? 0), 2))->filter(fn ($d) => abs($d) >= 0.005) : collect();

        return view('livewire.admin.day-close-component', [
            'day' => $day,
            'close' => $close,
            'closed' => $closed,
            'figures' => $figures,
            'cashiers' => $closed ? $close->cashiers : $book->cashiers($day),
            'blockers' => $closed ? ['voids' => collect(), 'corrections' => collect()] : $days->blockers($day),
            'payments' => $payments,
            'corrections' => $corrections,
            'pendingByPayment' => $corrections->where('status', PaymentCorrection::PENDING)->keyBy('payment_transaction_id'),
            'methodOptions' => PaymentMethods::active(PaymentMethods::CLINIC) + PaymentMethods::active(PaymentMethods::OPTICAL),
            'drift' => $drift,
            'float' => $float,
            'suggestedBank' => is_numeric($countedCash) ? max(0, round((float) $countedCash - $float, 2)) : null,
            'isOwner' => DayCloseService::isOwner(),
            'recent' => DayClose::with('closer:id,name')->latest('business_date')->limit(10)->get(),
            'branch' => $branch,
        ])->layout(...(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']) ? ['layouts.admin.admin-layout'] : ['layouts.clinic', ['menu' => 'reception']]));
    }
}
