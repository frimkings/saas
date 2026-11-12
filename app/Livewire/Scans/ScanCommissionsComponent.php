<?php

namespace App\Livewire\Scans;

use App\Models\Expense;
use App\Models\ScanCommission;
use App\Models\ScanCommissionPayout;
use App\Services\ClinicAccessService;
use App\Services\Scans\ScanCommissionService;
use App\Support\Feature;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Diagnostic Scans → Commissions (managers): what each partner clinic and doctor is owed for
 * referred scans, and paying it out by voucher. Each payout is also an expense, so the cash book
 * and the day close balance.
 */
class ScanCommissionsComponent extends Component
{
    public ?string $payeeType = null;
    public ?int $payeeId = null;

    public string $upTo = '';
    public string $payMethod = 'cash';
    public string $payReference = '';
    public string $payNotes = '';

    public function mount(): void
    {
        abort_unless(DiagnosticScansComponent::canManage(), 403);
    }

    public function openPayee(string $type, int $id): void
    {
        abort_unless(in_array($type, ['partner', 'staff'], true), 422);
        $this->payeeType = $type;
        $this->payeeId = $id;
        $this->upTo = now()->toDateString();
        $this->payMethod = 'cash';
        $this->reset(['payReference', 'payNotes']);
        $this->resetErrorBag();
    }

    public function closePayee(): void
    {
        $this->payeeType = null;
        $this->payeeId = null;
        $this->resetErrorBag();
    }

    public function pay(): void
    {
        abort_unless(DiagnosticScansComponent::canManage(), 403);
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
        abort_unless($this->payeeType && $this->payeeId, 404);
        $this->resetErrorBag();
        $this->validate([
            'upTo' => 'required|date|before_or_equal:today',
            'payMethod' => 'required|in:'.implode(',', array_keys(Expense::PAYMENT_METHODS)),
            'payReference' => 'nullable|string|max:100',
            'payNotes' => 'nullable|string|max:1000',
        ], ['upTo.before_or_equal' => "Pay up to today at the latest."]);

        try {
            $payout = app(ScanCommissionService::class)->payOut($this->payeeType, $this->payeeId, Carbon::parse($this->upTo),
                $this->payMethod, trim($this->payReference) ?: null, trim($this->payNotes) ?: null);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) $this->addError($field, $messages[0]);
            return;
        }
        $this->closePayee();
        session()->flash('success', "Paid {$payout->payeeName()} ".currency().' '.number_format((float) $payout->amount, 2)." ({$payout->voucher_number}). It is recorded as an expense.");
    }

    public function render()
    {
        $service = app(ScanCommissionService::class);
        $owed = $service->owed();

        $lines = collect();
        $payee = null;
        if ($this->payeeType && $this->payeeId) {
            $payee = $owed->first(fn ($row) => $row['type'] === $this->payeeType && $row['id'] === $this->payeeId);
            $lines = ScanCommission::for($this->payeeType, $this->payeeId)->where('status', 'accrued')
                ->with(['order:id,order_number,patient_id', 'order.patient:id,name,pxnumber', 'item:id,scan_name,eye'])
                ->orderBy('earned_on')->orderBy('id')->get();
        }
        $upTo = rescue(fn () => Carbon::parse($this->upTo)->toDateString(), now()->toDateString(), false);
        $payable = round((float) $lines->filter(fn ($line) => $line->earned_on->toDateString() <= $upTo)->sum('amount'), 2);

        $monthStart = now()->startOfMonth();

        return view('livewire.scans.scan-commissions-component', [
            'owed' => $owed,
            'tiles' => [
                'owed' => round((float) $owed->where('amount', '>', 0)->sum('amount'), 2),
                'payees' => $owed->where('amount', '>', 0)->count(),
                'earnedMonth' => round((float) ScanCommission::where('status', '!=', 'void')->whereDate('earned_on', '>=', $monthStart)->sum('amount'), 2),
                'paidMonth' => round((float) ScanCommissionPayout::withoutGlobalScope('branch')->where('paid_at', '>=', $monthStart)->sum('amount'), 2),
            ],
            'payee' => $payee,
            'lines' => $lines,
            'payable' => $payable,
            'methods' => Expense::PAYMENT_METHODS,
            'payouts' => ScanCommissionPayout::withoutGlobalScope('branch')->with(['partner:id,name,code', 'user:id,name', 'payer:id,name'])
                ->withCount('commissions')->latest('paid_at')->limit(15)->get(),
        ]);
    }
}
