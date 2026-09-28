<?php

namespace App\Livewire\Cashier;

use App\Models\AuditTrail;
use App\Models\CashierPatientClearance;
use App\Models\Category;
use App\Models\ClearanceRevokeLog;
use App\Models\Patient;
use App\Models\InsuranceClaim;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class CashierPatientClearanceComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // --- Tab state ---
    public string $activeTab = 'pending'; // pending | cleared

    // --- Pending tab ---
    public string $searchTerm = '';

    // --- Cleared tab filters ---
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $statusFilter  = '';
    public string $genderFilter  = '';
    public string $clearedSearch = '';

    // --- Compose clearance ---
    public $patientClearanceId   = null;
    public string $selectedServiceId = ''; // numeric product id OR 'unpaid'
    public string $patientName   = '';
    public array $clearancePayments = [];
    public float $outstandingBalance = 0.0;
    public array $insuranceSummary = [];

    // --- Inline status update ---
    public ?int   $editingClearanceId     = null;
    public string $editingPaymentStatus   = '';

    // --- Revoke request ---
    public ?int   $requestingRevokeId     = null;
    public string $requestingRevokeName   = '';
    public string $revokeReason           = '';

    protected $rules = [
        'selectedServiceId' => 'required',
        'revokeReason'      => 'required|string|min:10|max:500',
    ];

    protected $messages = [
        'selectedServiceId.required' => 'Please select a service or choose Unpaid.',
        'revokeReason.required'      => 'Please provide a reason for the revoke request.',
        'revokeReason.min'           => 'Reason must be at least 10 characters.',
    ];

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount(): void
    {
        $this->dateFrom = now()->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    public function updatingSearchTerm(): void   { $this->resetPage(); }
    public function updatingClearedSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void  { $this->resetPage(); }
    public function updatingGenderFilter(): void  { $this->resetPage(); }
    public function updatingDateFrom(): void      { $this->resetPage(); }
    public function updatingDateTo(): void        { $this->resetPage(); }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function hydrate(): void
    {
        if (!Auth::user()?->hasAnyRole(['Cashier', 'Secretary', 'Manager', 'Super Admin'])) {
            redirect()->route('dashboard');
        }
    }

    // ---------------------------------------------------------------
    // Pending: open clearance modal
    // ---------------------------------------------------------------
    public function openClearanceModal(int $patientId): void
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Patient not found!']);
            return;
        }

        $existsToday = CashierPatientClearance::where('patient_id', $patientId)
            ->where('clearance_date', now()->toDateString())
            ->exists();

        if ($existsToday) {
            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'This patient already has a clearance record today!',
            ]);
            return;
        }

        $this->resetValidation();
        $this->selectedServiceId  = '';
        $this->patientClearanceId = $patientId;
        $this->patientName        = $patient->name;
        $this->outstandingBalance = (float) Sales::where('patient_id', $patientId)
            ->where('created_at', '<', now()->startOfDay())
            ->where('is_refunded', false)
            ->selectRaw('COALESCE(SUM(GREATEST(total_amount - amount_paid, 0)), 0) AS balance')
            ->value('balance');

        $claim = InsuranceClaim::with('insurer')->where('patient_id', $patientId)->latest()->first();
        $approvedCoverage = (float) ($claim?->approved_amount ?? $claim?->pre_auth_amount ?? 0);
        $claimAmount = (float) ($claim?->claim_amount ?? 0);
        $this->insuranceSummary = $patient->insurer_id ? [
            'insurer' => $claim?->insurer?->name ?? $patient->insurer?->name ?? 'Insurance',
            'member_id' => $claim?->member_id ?? $patient->insurance_member_id,
            'member_name' => $claim?->member_name ?? $patient->insurance_member_name,
            'policy_number' => $claim?->policy_number ?? $patient->insurance_policy_number,
            'status' => $claim?->statusLabel() ?? 'No claim submitted',
            'pre_auth_status' => $claim?->preAuthLabel() ?? 'No Pre-Auth',
            'coverage' => $approvedCoverage,
            'patient_contribution' => max(0, $claimAmount - $approvedCoverage),
        ] : [];

        $this->dispatch('show-addClearanceModal-form');
    }

    public function createClearance(string $serviceValue = '', string $paymentsJson = '[]'): void
    {
        if ($serviceValue !== '') {
            $this->selectedServiceId = $serviceValue;
        }

        $this->validate(['selectedServiceId' => 'required']);

        $patient = Patient::find($this->patientClearanceId);
        if (!$patient) {
            $this->addError('selectedServiceId', 'The selected patient is no longer available. Close this window and try again.');
            return;
        }

        $isUnpaid  = $this->selectedServiceId === 'unpaid';
        $serviceId = $isUnpaid ? null : (int) $this->selectedServiceId;
        $service = null;

        if (!$isUnpaid) {
            $service = Product::whereKey($serviceId)
                ->whereHas('category', fn ($query) => $query
                    ->where('name', 'like', '%service%')
                    ->orWhere('type', 'service'))
                ->first();

            if (!$service) {
                $this->addError('selectedServiceId', 'Selected service is invalid.');
                return;
            }
        }

        $decodedPayments = json_decode($paymentsJson, true);
        if (!is_array($decodedPayments)) {
            $this->addError('selectedServiceId', 'Payment details are invalid. Please re-enter them.');
            return;
        }

        $allowedMethods = ['cash', 'momo', 'card', 'cheque'];
        $payments = collect($decodedPayments)->map(function ($payment) use ($allowedMethods) {
            $method = strtolower(trim((string) ($payment['method'] ?? '')));
            $amount = round((float) ($payment['amount'] ?? 0), 2);

            return in_array($method, $allowedMethods, true) && $amount > 0
                ? ['method' => $method, 'amount' => $amount]
                : null;
        })->filter()->values()->all();

        if (!$isUnpaid) {
            $totalAmount = round((float) $service->selling_price, 2);
            $amountPaid = round((float) collect($payments)->sum('amount'), 2);

            if (abs($amountPaid - $totalAmount) > 0.005) {
                $this->addError(
                    'selectedServiceId',
                    'Payments must equal the service total of ' . currency() . ' ' . number_format($totalAmount, 2) . '.'
                );
                return;
            }
        } elseif ($payments !== []) {
            $this->addError('selectedServiceId', 'An unpaid clearance cannot include payments.');
            return;
        }

        $paymentStatus = $isUnpaid ? 'Unpaid' : 'Paid';

        DB::beginTransaction();
        try {
            $existsToday = CashierPatientClearance::where('patient_id', $this->patientClearanceId)
                ->where('clearance_date', now()->toDateString())
                ->exists();

            if ($existsToday) {
                DB::rollBack();
                $this->dispatch('hide-addClearanceModal-modal');
                $this->dispatch('notify', ...[
                    'type'    => 'warning',
                    'message' => 'Clearance already exists for this patient today!',
                ]);
                return;
            }

            $existing = CashierPatientClearance::withTrashed()
                ->where('patient_id', $this->patientClearanceId)
                ->where('clearance_date', now()->toDateString())
                ->first();

            if ($existing) {
                $existing->restore();
                $existing->update([
                    'user_id'        => Auth::id(),
                    'service_id'     => $serviceId,
                    'payment_status' => $paymentStatus,
                    'doctor_status'  => false,
                    'sale_id'        => null,
                ]);
                $clearance = $existing;
                AuditTrail::record('clearance.restored', "Restored clearance for {$this->patientName} ({$paymentStatus})", $clearance, [], [], $this->patientClearanceId);
            } else {
                $clearance = CashierPatientClearance::create([
                    'patient_id'     => $this->patientClearanceId,
                    'user_id'        => Auth::id(),
                    'service_id'     => $serviceId,
                    'payment_status' => $paymentStatus,
                    'doctor_status'  => false,
                    'clearance_date' => now()->toDateString(),
                ]);
                AuditTrail::record('clearance.created', "Created clearance for {$this->patientName} ({$paymentStatus})", $clearance, [], [], $this->patientClearanceId);
            }

            // Record sale for paid clearances
            $receiptUrl = route('cashier.clearance-receipt', $clearance->id);
            if (!$isUnpaid && $serviceId) {
                $transactionId = now()->format('dmY') . '-' . strtoupper(Str::random(8));
                $totalAmount   = (float) $service->selling_price;
                $amountPaid    = collect($payments)->sum('amount');
                $paymentStatus = 'paid';
                $profit        = max(0, $totalAmount - (float) ($service->cost_price ?? 0));

                $sale = Sales::create([
                    'business_line' => 'clinic',
                    'user_id'        => Auth::id(),
                    'patient_id'     => $this->patientClearanceId,
                    'transaction_id' => $transactionId,
                    'total_amount'   => $totalAmount,
                    'amount_paid'    => $amountPaid,
                    'payment_status' => $paymentStatus,
                    'bill_status'    => 'open',
                    'expires_at'     => now()->endOfDay(),
                    'profit'         => $profit,
                ]);

                SaleItem::create([
                    'sale_id'             => $sale->id,
                    'product_id'          => $serviceId,
                    'prescribed_quantity' => 1,
                    'dispensed_quantity'  => 1,
                    'selling_price'       => $totalAmount,
                    'subtotal'            => $totalAmount,
                    'notes'               => 'Clearance Service',
                ]);

                $methodNames = [];
                foreach ($payments as $p) {
                    PaymentTransaction::create([
                        'sale_id'        => $sale->id,
                        'amount'         => $p['amount'],
                        'payment_method' => $p['method'],
                        'notes'          => 'Clearance payment',
                        'collected_by'   => Auth::id(),
                    ]);
                    $methodNames[] = ucfirst($p['method']) . ' ' . currency() . number_format($p['amount'], 2);
                }

                $clearance->update(['sale_id' => $sale->id]);

                AuditTrail::record(
                    'sale.created',
                    "Clearance sale: {$this->patientName} — {$service->name} (" . currency() . " {$totalAmount}) | " . implode(', ', $methodNames),
                    $sale, [], [], $this->patientClearanceId
                );

                $receiptUrl = route('cashier.receipt.show', $sale->id);
            }

            // Fresh-load relations for the event payload (created inside transaction)
            $clearance->load(['patient', 'service', 'sale']);

            $paymentLines = [];
            foreach ($payments as $p) {
                $paymentLines[] = [
                    'method' => ucfirst($p['method']),
                    'amount' => number_format((float) $p['amount'], 2),
                ];
            }

            DB::commit();

            $this->dispatch('hide-addClearanceModal-modal');
            $this->dispatch('notify', ...[
                'type'    => 'success',
                'message' => "Patient {$this->patientName} cleared successfully!",
            ]);
            $this->dispatch('show-clearance-receipt-modal', ...[
                'patient'  => $clearance->patient->name ?? '',
                'pxnumber' => $clearance->patient->pxnumber ?? '',
                'txn'      => $clearance->sale?->transaction_id
                                ?? 'CLR-' . str_pad($clearance->id, 6, '0', STR_PAD_LEFT),
                'service'  => $clearance->service?->name ?? 'No specific service',
                'amount'   => number_format((float) ($clearance->service?->selling_price ?? 0), 2),
                'status'   => $clearance->payment_status,
                'payments' => $paymentLines,
                'printUrl' => $receiptUrl,
            ]);

            $this->reset(['patientClearanceId', 'selectedServiceId', 'patientName', 'clearancePayments']);
            $this->resetPage();

            Log::info('Patient clearance created', [
                'clearance_id'   => $clearance->id,
                'patient_id'     => $clearance->patient_id,
                'user_id'        => Auth::id(),
                'service_id'     => $clearance->service_id,
                'payment_status' => $clearance->payment_status,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating patient clearance', [
                'error'      => $e->getMessage(),
                'patient_id' => $this->patientClearanceId,
                'user_id'    => Auth::id(),
            ]);
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'An error occurred while processing clearance. Please try again.',
            ]);
        }
    }

    public function closeModal(): void
    {
        $this->reset(['patientClearanceId', 'selectedServiceId', 'patientName', 'clearancePayments', 'outstandingBalance', 'insuranceSummary']);
        $this->resetValidation();
        $this->dispatch('hide-addClearanceModal-modal');
    }

    // ---------------------------------------------------------------
    // Cleared tab: inline status editing
    // ---------------------------------------------------------------
    public function startEditStatus(int $clearanceId): void
    {
        $clearance = CashierPatientClearance::find($clearanceId);
        if (!$clearance) return;

        $this->editingClearanceId   = $clearanceId;
        $this->editingPaymentStatus = $clearance->payment_status;
    }

    public function saveStatus(): void
    {
        $this->validate(['editingPaymentStatus' => 'required|in:Paid,Unpaid']);

        $clearance = CashierPatientClearance::find($this->editingClearanceId);
        if (!$clearance) return;

        $old = ['payment_status' => $clearance->payment_status];
        $clearance->update(['payment_status' => $this->editingPaymentStatus]);
        AuditTrail::record('clearance.status_updated', "Updated payment status to {$this->editingPaymentStatus} for clearance #{$clearance->id}", $clearance, $old, ['payment_status' => $this->editingPaymentStatus], $clearance->patient_id);

        $this->editingClearanceId   = null;
        $this->editingPaymentStatus = '';

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Payment status updated.']);
    }

    public function cancelEditStatus(): void
    {
        $this->editingClearanceId   = null;
        $this->editingPaymentStatus = '';
    }

    // ---------------------------------------------------------------
    // Cleared tab: revoke request workflow
    // ---------------------------------------------------------------
    public function openRevokeModal(int $clearanceId): void
    {
        $clearance = CashierPatientClearance::with('patient')->find($clearanceId);
        if (!$clearance) return;

        if ($clearance->consultation()->exists()) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Cannot request revoke — a consultation is already linked to this clearance.',
            ]);
            return;
        }

        $alreadyPending = ClearanceRevokeLog::where('clearance_id', $clearanceId)
            ->where('status', ClearanceRevokeLog::STATUS_PENDING)
            ->exists();

        if ($alreadyPending) {
            $this->dispatch('notify', ...[
                'type'    => 'warning',
                'message' => 'A revoke request for this clearance is already awaiting approval.',
            ]);
            return;
        }

        $this->requestingRevokeId   = $clearanceId;
        $this->requestingRevokeName = optional($clearance->patient)->name ?? 'Unknown';
        $this->revokeReason         = '';
        $this->resetErrorBag('revokeReason');
        $this->dispatch('show-revokeRequestModal');
    }

    public function submitRevokeRequest(): void
    {
        $this->validate(['revokeReason' => 'required|string|min:10|max:500']);

        $clearance = CashierPatientClearance::find($this->requestingRevokeId);
        if (!$clearance) {
            $this->cancelRevokeRequest();
            return;
        }

        if ($clearance->consultation()->exists()) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Cannot request revoke — a consultation is already linked to this clearance.',
            ]);
            $this->cancelRevokeRequest();
            return;
        }

        $revokeLog = ClearanceRevokeLog::create([
            'clearance_id' => $clearance->id,
            'status'       => ClearanceRevokeLog::STATUS_PENDING,
            'requested_by' => Auth::id(),
            'reason'       => $this->revokeReason,
            'requested_at' => now(),
        ]);

        AuditTrail::record('clearance.revoke_requested', "Revoke requested for clearance #{$clearance->id} ({$this->requestingRevokeName}): {$this->revokeReason}", $clearance, [], [], $clearance->patient_id);

        NotificationService::sendToRoles(
            ['Manager', 'Super Admin'],
            'clearance_revoke_requested',
            'Clearance Revoke Request',
            Auth::user()->name . " requested revoke of clearance for {$this->requestingRevokeName}.",
            'fas fa-undo',
            'text-warning',
            route('admin.clearance-revoke-approvals'),
            null,
            Auth::id()
        );
        app(\App\Services\OwnerAlerts::class)->revokeRequested($revokeLog, (string) $this->requestingRevokeName);

        $name = $this->requestingRevokeName;
        $this->cancelRevokeRequest();
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => "Revoke request for {$name} submitted. Awaiting manager approval.",
        ]);
    }

    public function cancelRevokeRequest(): void
    {
        $this->requestingRevokeId   = null;
        $this->requestingRevokeName = '';
        $this->revokeReason         = '';
        $this->resetErrorBag('revokeReason');
        $this->dispatch('hide-revokeRequestModal');
    }

    // ---------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------
    public function render()
    {
        if (!Auth::user()?->hasAnyRole(['Secretary', 'Super Admin', 'Manager', 'Cashier'])) {
            return redirect()->route('dashboard');
        }

        $today = now()->toDateString();

        // Stats (always based on today)
        $pendingCount   = Patient::whereDoesntHave('clearances', fn($q) => $q->where('clearance_date', $today))->count();
        $clearedToday   = CashierPatientClearance::where('clearance_date', $today)->count();
        $paidToday      = CashierPatientClearance::where('clearance_date', $today)->where('payment_status', 'Paid')->count();
        $unpaidToday    = CashierPatientClearance::where('clearance_date', $today)->where('payment_status', 'Unpaid')->count();

        // Pending tab
        $patients = Patient::query()
            ->when($this->searchTerm, function ($q) {
                $s = '%' . $this->searchTerm . '%';
                $q->where(fn($inner) => $inner
                    ->where('name', 'like', $s)
                    ->orWhere('pxnumber', 'like', $s)
                    ->orWhere('contact', 'like', $s)
                    ->orWhere('occupation', 'like', $s)
                );
            })
            ->whereDoesntHave('clearances', fn($q) => $q->where('clearance_date', $today))
            ->latest()
            ->paginate(10, ['*'], 'pending_page');

        // Cleared tab
        $from = $this->dateFrom ?: $today;
        $to   = $this->dateTo   ?: $today;
        if ($from > $to) $to = $from; // guard against inverted range

        $services = Product::whereHas('category', function ($q) {
            $q->where('name', 'like', '%service%')
              ->orWhere('type', 'service');
        })->orderBy('name')->get();

        $clearances = CashierPatientClearance::with(['patient.insurer', 'patient.latestInsuranceClaim', 'user', 'service', 'sale', 'pendingRevokeLog'])
            ->whereDate('clearance_date', '>=', $from)
            ->whereDate('clearance_date', '<=', $to)
            ->when($this->statusFilter, fn($q) => $q->where('payment_status', $this->statusFilter))
            ->when($this->genderFilter, fn($q) => $q->whereHas('patient', fn($p) => $p->where('gender', $this->genderFilter)))
            ->when($this->clearedSearch, fn($q) => $q->whereHas('patient', fn($p) =>
                $p->where('name', 'like', '%' . $this->clearedSearch . '%')
                  ->orWhere('pxnumber', 'like', '%' . $this->clearedSearch . '%')
            ))
            ->latest()
            ->paginate(10, ['*'], 'cleared_page');

        $reconciliation = PaymentTransaction::query()
            ->selectRaw('payment_method, COUNT(*) AS transaction_count, SUM(amount) AS total_amount')
            ->whereDate('created_at', $today)
            ->whereHas('sale', fn ($query) => $query->where('is_refunded', false))
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get();
        $reconciliationTotal = (float) $reconciliation->sum('total_amount');

        return view('livewire.cashier.cashier-patient-clearance-component', compact(
            'patients', 'clearances', 'services',
            'pendingCount', 'clearedToday', 'paidToday', 'unpaidToday',
            'reconciliation', 'reconciliationTotal'
        ))->layout('layouts.secretary.secretary-layout');
    }
}
