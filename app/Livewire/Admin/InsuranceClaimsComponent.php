<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\InsuranceClaim;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Sales;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;

class InsuranceClaimsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // ── Filters ───────────────────────────────────────────────────────────────
    public string $activeTab      = 'all';
    public string $search         = '';
    public string $fromDate       = '';
    public string $toDate         = '';
    public string $insurerFilter  = '';
    public string $preAuthFilter  = '';
    public int    $perPage        = 15;

    protected $queryString = [
        'activeTab'     => ['except' => 'all', 'as' => 'tab'],
        'search'        => ['except' => ''],
        'insurerFilter' => ['except' => ''],
        'preAuthFilter' => ['except' => ''],
    ];

    // ── Create/Edit modal ─────────────────────────────────────────────────────
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $claimId    = null;

    public array $state = [
        'patient_search'      => '',
        'patient_id'          => '',
        'insurer_search'      => '',
        'insurer_id'          => '',
        'sale_id'             => '',
        'member_id'           => '',
        'member_name'         => '',
        'policy_number'       => '',
        'claim_amount'        => '',
        'notes'               => '',
        'pre_auth_status'     => 'not_required',
        'pre_auth_code'       => '',
        'pre_auth_amount'     => '',
        'pre_auth_date'       => '',
        'pre_auth_expiry_date'=> '',
        'pre_auth_notes'      => '',
    ];

    public array $patientResults = [];
    public array $insurerResults = [];
    public array $patientSales   = [];

    // ── Status modal ──────────────────────────────────────────────────────────
    public bool   $showStatusModal   = false;
    public ?int   $statusClaimId     = null;
    public string $pendingStatus     = '';
    public array  $statusState = [
        'submission_date'  => '',
        'approval_date'    => '',
        'approved_amount'  => '',
        'payment_date'     => '',
        'rejection_reason' => '',
    ];

    // ── Listeners ─────────────────────────────────────────────────────────────

    public function updatingActiveTab(): void     { $this->resetPage(); }
    public function updatingSearch(): void        { $this->resetPage(); }
    public function updatingInsurerFilter(): void { $this->resetPage(); }
    public function updatingPreAuthFilter(): void { $this->resetPage(); }

    // ── Create / Edit ─────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $claim = InsuranceClaim::with(['patient', 'insurer'])->findOrFail($id);
        if ($claim->status !== 'draft') {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Only draft claims can be edited.']);
            return;
        }

        $this->claimId   = $id;
        $this->isEditing = true;

        $this->state = [
            'patient_search'       => $claim->patient->name ?? '',
            'patient_id'           => $claim->patient_id,
            'insurer_search'       => $claim->insurer->name ?? '',
            'insurer_id'           => $claim->insurer_id,
            'sale_id'              => $claim->sale_id ?? '',
            'member_id'            => $claim->member_id ?? '',
            'member_name'          => $claim->member_name ?? '',
            'policy_number'        => $claim->policy_number ?? '',
            'claim_amount'         => $claim->claim_amount,
            'notes'                => $claim->notes ?? '',
            'pre_auth_status'      => $claim->pre_auth_status ?? 'not_required',
            'pre_auth_code'        => $claim->pre_auth_code ?? '',
            'pre_auth_amount'      => $claim->pre_auth_amount ?? '',
            'pre_auth_date'        => $claim->pre_auth_date?->toDateString() ?? '',
            'pre_auth_expiry_date' => $claim->pre_auth_expiry_date?->toDateString() ?? '',
            'pre_auth_notes'       => $claim->pre_auth_notes ?? '',
        ];

        $this->loadPatientSales((int) $claim->patient_id);
        $this->showModal = true;
    }

    public function updatedStatePatientSearch(): void
    {
        $q = trim($this->state['patient_search']);
        $this->state['patient_id'] = '';
        $this->state['sale_id'] = '';
        $this->patientSales = [];

        if (strlen($q) < 2) {
            $this->patientResults = [];
            return;
        }
        $this->patientResults = Patient::where(function ($query) use ($q) {
            $query->where('name', 'like', "%{$q}%")
                  ->orWhere('pxnumber', 'like', "%{$q}%");
        })->limit(8)->get(['id', 'name', 'pxnumber'])->toArray();
    }

    public function updatedStateInsurerSearch(): void
    {
        $q = trim($this->state['insurer_search']);
        $this->state['insurer_id'] = '';

        if (strlen($q) < 1) {
            $this->insurerResults = [];
            return;
        }

        $this->insurerResults = Insurer::where('active', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('scheme_type', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'code', 'scheme_type'])
            ->toArray();
    }

    public function selectPatient(int $id, string $name): void
    {
        $this->state['patient_id']     = $id;
        $this->state['patient_search'] = $name;
        $this->state['sale_id']        = '';
        $this->patientResults          = [];
        $this->loadPatientSales($id);

        // Pre-fill insurance defaults from patient record (only when creating)
        if (!$this->isEditing) {
            $patient = Patient::with('insurer')->find($id);
            if ($patient?->insurer_id) {
                $this->state['insurer_id']     = $patient->insurer_id;
                $this->state['insurer_search'] = $patient->insurer->name ?? '';
                $this->state['member_id']     = $patient->insurance_member_id ?? '';
                $this->state['member_name']   = $patient->insurance_member_name ?: $patient->name;
                $this->state['policy_number'] = $patient->insurance_policy_number ?? '';
                $this->insurerResults          = [];
            }
        }
    }

    public function selectInsurer(int $id): void
    {
        $insurer = Insurer::where('active', true)->findOrFail($id);

        $this->state['insurer_id']     = $insurer->id;
        $this->state['insurer_search'] = $insurer->name;
        $this->insurerResults          = [];
        $this->resetErrorBag('state.insurer_id');
    }

    public function updatedStateSaleId(): void
    {
        if ($this->state['sale_id']) {
            $sale = Sales::where('patient_id', $this->state['patient_id'])->find($this->state['sale_id']);
            if (!$sale) {
                $this->state['sale_id'] = '';
                $this->dispatch('notify', ...[
                    'type'    => 'error',
                    'message' => 'The selected sale does not belong to the selected patient.',
                ]);
                return;
            }

            if (empty($this->state['claim_amount'])) {
                $this->state['claim_amount'] = $sale->total_amount;
            }
        }
    }

    public function updatedStatePreAuthStatus(): void
    {
        $this->clearPreAuthFieldsForStatus();
    }

    public function save(): void
    {
        $data = $this->validateForm();
        $data = $this->normalizeClaimData($data);

        if ($this->isEditing) {
            $claim = InsuranceClaim::findOrFail($this->claimId);
            if ($claim->status !== 'draft') {
                $this->dispatch('notify', ...['type' => 'error', 'message' => 'Only draft claims can be edited.']);
                return;
            }

            $old   = $claim->only(array_keys($data));
            $data['updated_by'] = auth()->id();
            $claim->update($data);
            AuditTrail::record(
                'insurance_claim.updated',
                "Updated claim #{$claim->id} for {$claim->patient->name}",
                $claim, $old, $data, $claim->patient_id
            );
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Claim updated.']);
        } else {
            $data['created_by'] = auth()->id();
            $claim = InsuranceClaim::create($data);
            AuditTrail::record(
                'insurance_claim.created',
                "Logged insurance claim #{$claim->id} for {$claim->patient->name}",
                $claim, [], $data, $claim->patient_id
            );
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Claim logged.']);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function deleteClaim(int $id): void
    {
        $claim = InsuranceClaim::findOrFail($id);
        if (!in_array($claim->status, ['draft', 'rejected'])) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Only draft or rejected claims can be deleted.',
            ]);
            return;
        }
        AuditTrail::record('insurance_claim.deleted', "Deleted claim #{$claim->id}", $claim, $claim->toArray(), [], $claim->patient_id);
        $claim->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Claim deleted.']);
    }

    // ── Status workflow ───────────────────────────────────────────────────────

    public function openStatusModal(int $id, string $newStatus): void
    {
        $claim = InsuranceClaim::findOrFail($id);
        if (!$this->canTransition($claim->status, $newStatus)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Invalid claim status transition.']);
            return;
        }

        $this->statusClaimId   = $id;
        $this->pendingStatus   = $newStatus;
        $this->statusState     = [
            'submission_date'  => now()->toDateString(),
            'approval_date'    => now()->toDateString(),
            'approved_amount'  => '',
            'payment_date'     => now()->toDateString(),
            'rejection_reason' => '',
        ];
        $this->showStatusModal = true;
    }

    public function applyStatus(): void
    {
        $claim = InsuranceClaim::findOrFail($this->statusClaimId);
        if (!$this->canTransition($claim->status, $this->pendingStatus)) {
            $this->showStatusModal = false;
            $this->statusClaimId = null;
            $this->pendingStatus = '';
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Invalid claim status transition.']);
            return;
        }

        $update = ['status' => $this->pendingStatus, 'updated_by' => auth()->id()];

        switch ($this->pendingStatus) {
            case 'submitted':
                $this->validate(['statusState.submission_date' => 'required|date'], [], ['statusState.submission_date' => 'Submission Date']);
                $update['submission_date'] = $this->statusState['submission_date'];
                break;

            case 'approved':
                $this->validate([
                    'statusState.approval_date'    => 'required|date',
                    'statusState.approved_amount'  => 'required|numeric|min:0',
                ], [], [
                    'statusState.approval_date'   => 'Approval Date',
                    'statusState.approved_amount' => 'Approved Amount',
                ]);
                $update['approval_date']   = $this->statusState['approval_date'];
                $update['approved_amount'] = $this->statusState['approved_amount'];
                break;

            case 'partially_approved':
                $this->validate([
                    'statusState.approval_date'    => 'required|date',
                    'statusState.approved_amount'  => 'required|numeric|min:0',
                ], [], [
                    'statusState.approval_date'   => 'Approval Date',
                    'statusState.approved_amount' => 'Approved Amount',
                ]);
                $update['approval_date']   = $this->statusState['approval_date'];
                $update['approved_amount'] = $this->statusState['approved_amount'];
                break;

            case 'rejected':
                $this->validate(['statusState.rejection_reason' => 'required|string|max:500'], [], ['statusState.rejection_reason' => 'Rejection Reason']);
                $update['rejection_reason'] = $this->statusState['rejection_reason'];
                break;

            case 'paid':
                $this->validate(['statusState.payment_date' => 'required|date'], [], ['statusState.payment_date' => 'Payment Date']);
                $update['payment_date'] = $this->statusState['payment_date'];
                break;
        }

        $update = $this->normalizeStatusData($update);
        $old = $claim->only(array_keys($update));
        $claim->update($update);
        AuditTrail::record(
            "insurance_claim.{$this->pendingStatus}",
            "Claim #{$claim->id} marked as {$this->pendingStatus}",
            $claim, $old, $update, $claim->patient_id
        );

        $this->showStatusModal = false;
        $this->statusClaimId   = null;
        $this->pendingStatus   = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Claim status updated.']);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function exportCsv()
    {
        $claims = $this->buildQuery()->with(['patient', 'insurer'])->get();

        $rows = collect([['ID', 'Patient', 'Px#', 'Insurer', 'Scheme', 'Member ID', 'Policy #', 'Claim Amount', 'Approved Amount', 'Status', 'Submitted', 'Approved', 'Paid', 'Date']]);

        foreach ($claims as $c) {
            $rows->push([
                $c->id,
                $c->patient->name ?? '—',
                $c->patient->pxnumber ?? '—',
                $c->insurer->name ?? '—',
                $c->insurer->scheme_type ?? '—',
                $c->member_id ?? '—',
                $c->policy_number ?? '—',
                $c->claim_amount,
                $c->approved_amount ?? '—',
                $c->status,
                $c->submission_date?->format('Y-m-d') ?? '—',
                $c->approval_date?->format('Y-m-d') ?? '—',
                $c->payment_date?->format('Y-m-d') ?? '—',
                $c->created_at->format('Y-m-d'),
            ]);
        }

        $filename = 'insurance_claims_' . now()->format('Y-m-d') . '.csv';

        return Response::streamDownload(function () use ($rows) {
            $fp = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        $claims   = $this->buildQuery()->with(['patient', 'insurer'])->paginate($this->perPage);
        $insurers = Insurer::where('active', true)->orderBy('name')->get(['id', 'name', 'scheme_type']);

        $draftCount      = InsuranceClaim::where('status', 'draft')->count();
        $submittedCount  = InsuranceClaim::where('status', 'submitted')->count();
        $approvedSum     = InsuranceClaim::whereIn('status', ['approved', 'partially_approved', 'paid'])
                             ->sum('approved_amount');
        $outstandingSum  = InsuranceClaim::whereIn('status', ['approved', 'partially_approved'])
                             ->sum('approved_amount');
        $pendingPreAuth  = InsuranceClaim::where('pre_auth_status', 'pending')->count();

        return view('livewire.admin.insurance-claims-component', compact(
            'claims', 'insurers', 'draftCount', 'submittedCount', 'approvedSum', 'outstandingSum', 'pendingPreAuth'
        ))->layout('layouts.admin.admin-layout');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildQuery()
    {
        return InsuranceClaim::when($this->activeTab !== 'all', fn ($q) => $q->where('status', $this->activeTab))
            ->when($this->search, fn ($q) => $q->whereHas('patient', fn ($p) =>
                $p->where('name', 'like', "%{$this->search}%")
                  ->orWhere('pxnumber', 'like', "%{$this->search}%")
            ))
            ->when($this->insurerFilter, fn ($q) => $q->where('insurer_id', $this->insurerFilter))
            ->when($this->preAuthFilter, fn ($q) => $q->where('pre_auth_status', $this->preAuthFilter))
            ->when($this->fromDate, fn ($q) => $q->whereDate('created_at', '>=', $this->fromDate))
            ->when($this->toDate,   fn ($q) => $q->whereDate('created_at', '<=', $this->toDate))
            ->latest();
    }

    private function validateForm(): array
    {
        $patientId = (int) ($this->state['patient_id'] ?: 0);
        $saleUnique = Rule::unique('insurance_claims', 'sale_id');
        if ($this->isEditing) {
            $saleUnique->ignore($this->claimId);
        }

        return $this->validate([
            'state.patient_id'         => 'required|exists:patients,id',
            'state.insurer_id'         => [
                'required',
                Rule::exists('insurers', 'id')
                    ->where(fn ($query) => $query->where('active', true)->whereNull('deleted_at')),
            ],
            'state.sale_id'            => [
                'nullable',
                Rule::exists('sales', 'id')
                    ->where(fn ($query) => $query->where('patient_id', $patientId)->whereNull('deleted_at')),
                $saleUnique,
            ],
            'state.member_id'          => 'nullable|string|max:60',
            'state.member_name'        => 'nullable|string|max:120',
            'state.policy_number'      => 'nullable|string|max:60',
            'state.claim_amount'       => 'required|numeric|min:0.01',
            'state.notes'              => 'nullable|string|max:500',
            'state.pre_auth_status'    => 'required|in:not_required,pending,approved,rejected',
            'state.pre_auth_code'      => 'nullable|string|max:100',
            'state.pre_auth_amount'    => 'nullable|numeric|min:0',
            'state.pre_auth_date'      => 'nullable|date',
            'state.pre_auth_expiry_date' => 'nullable|date',
            'state.pre_auth_notes'     => 'nullable|string|max:500',
        ], [], [
            'state.patient_id'      => 'Patient',
            'state.insurer_id'      => 'Insurer',
            'state.claim_amount'    => 'Claim Amount',
            'state.pre_auth_status' => 'Pre-Auth Status',
        ])['state'];
    }

    private function normalizeClaimData(array $data): array
    {
        foreach ([
            'sale_id',
            'member_id',
            'member_name',
            'policy_number',
            'notes',
            'pre_auth_code',
            'pre_auth_amount',
            'pre_auth_date',
            'pre_auth_expiry_date',
            'pre_auth_notes',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        if (!in_array($data['pre_auth_status'] ?? 'not_required', ['pending', 'approved', 'rejected'], true)) {
            $data['pre_auth_code'] = null;
            $data['pre_auth_notes'] = null;
        }

        if (($data['pre_auth_status'] ?? 'not_required') !== 'approved') {
            $data['pre_auth_amount'] = null;
            $data['pre_auth_date'] = null;
            $data['pre_auth_expiry_date'] = null;
        }

        return $data;
    }

    private function clearPreAuthFieldsForStatus(): void
    {
        if (!in_array($this->state['pre_auth_status'], ['pending', 'approved', 'rejected'], true)) {
            $this->state['pre_auth_code'] = '';
            $this->state['pre_auth_notes'] = '';
        }

        if ($this->state['pre_auth_status'] !== 'approved') {
            $this->state['pre_auth_amount'] = '';
            $this->state['pre_auth_date'] = '';
            $this->state['pre_auth_expiry_date'] = '';
        }
    }

    private function normalizeStatusData(array $data): array
    {
        foreach ([
            'submission_date',
            'approval_date',
            'approved_amount',
            'payment_date',
            'rejection_reason',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function canTransition(string $currentStatus, string $nextStatus): bool
    {
        $transitions = [
            'draft'              => ['submitted'],
            'submitted'          => ['approved', 'partially_approved', 'rejected'],
            'approved'           => ['paid'],
            'partially_approved' => ['paid'],
            'rejected'           => [],
            'paid'               => [],
        ];

        return in_array($nextStatus, $transitions[$currentStatus] ?? [], true);
    }

    private function loadPatientSales(int $patientId): void
    {
        // Exclude sales already linked to another claim (allow current claim's own sale on edit)
        $claimedSaleIds = InsuranceClaim::whereNotNull('sale_id')
            ->when($this->claimId, fn ($q) => $q->where('id', '!=', $this->claimId))
            ->pluck('sale_id');

        $this->patientSales = Sales::where('patient_id', $patientId)
            ->whereNotIn('id', $claimedSaleIds)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'transaction_id', 'total_amount', 'created_at'])
            ->map(fn ($s) => [
                'id'    => $s->id,
                'label' => "#{$s->id} — " . ($s->transaction_id ?? 'No TxID') . " — " . currency() . " " . number_format($s->total_amount, 2) . " ({$s->created_at->format('d M Y')})",
            ])
            ->toArray();
    }

    private function resetForm(): void
    {
        $this->claimId = null;
        $this->state   = [
            'patient_search'       => '',
            'patient_id'           => '',
            'insurer_search'       => '',
            'insurer_id'           => '',
            'sale_id'              => '',
            'member_id'            => '',
            'member_name'          => '',
            'policy_number'        => '',
            'claim_amount'         => '',
            'notes'                => '',
            'pre_auth_status'      => 'not_required',
            'pre_auth_code'        => '',
            'pre_auth_amount'      => '',
            'pre_auth_date'        => '',
            'pre_auth_expiry_date' => '',
            'pre_auth_notes'       => '',
        ];
        $this->patientResults = [];
        $this->insurerResults = [];
        $this->patientSales   = [];
        $this->resetValidation();
    }
}
