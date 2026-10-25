<?php

namespace App\Livewire\Doctor;

use App\Models\AuditTrail;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Referral;
use App\Models\Setting;
use Livewire\Component;
use Livewire\WithPagination;

class ReferralComponent extends Component
{
    use WithPagination;

    /* ── List filters ── */
    public $searchQuery  = '';
    public $statusFilter = '';
    public $typeFilter   = '';
    public $fromDate     = '';
    public $toDate       = '';
    public $perPage      = 15;

    /* ── Modal state ── */
    public $showModal      = false;
    public $editingId      = null;
    public $confirmDeleteId = null;

    /* ── Patient lookup ── */
    public $patientSearch      = '';
    public $patientSuggestions = [];
    public $selectedPatientId  = null;

    /* ── Shared form fields ── */
    public $letterType    = 'referral';
    public $referralDate  = '';
    public $patientName   = '';
    public $patientAgeSex = '';
    public $patientContact = '';
    public $selectedDiagnoses = [];
    public $status            = 'pending';
    public string $vaNotation = '6m';

    /* ── Referral-only fields ── */
    public $referralTo        = '';
    public $complaint         = '';
    public $vaOd              = '';
    public $vaOs              = '';
    public $refraction        = '';
    public $anteriorSegment   = '';
    public $posteriorSegment  = '';
    public $iop               = '';
    public $reasonForReferral = '';
    public $management        = '';

    /* ── Medical Report-only fields ── */
    public $clinicalFindings = '';
    public $treatment        = '';
    public $recommendation   = '';

    /* ── Excuse Duty-only fields ── */
    public $excuseFromDate = '';
    public $excuseToDate   = '';
    public $excuseNotes    = '';

    protected $paginationTheme = 'tailwind';

    protected function rules(): array
    {
        $base = [
            'letterType'    => 'required|in:referral,medical_report,excuse_duty',
            'referralDate'  => 'required|date',
            'patientName'   => 'required|string|max:255',
            'patientAgeSex' => 'nullable|string|max:100',
            'patientContact'=> 'nullable|string|max:100',
            'selectedDiagnoses' => 'nullable|array',
            'status'            => 'required|in:pending,completed,cancelled',
        ];

        if ($this->letterType === 'referral') {
            return array_merge($base, [
                'referralTo'        => 'required|string|max:255',
                'complaint'         => 'nullable|string|max:500',
                'vaOd'              => 'nullable|string|max:20',
                'vaOs'              => 'nullable|string|max:20',
                'refraction'        => 'nullable|string|max:100',
                'anteriorSegment'   => 'nullable|string|max:255',
                'posteriorSegment'  => 'nullable|string|max:255',
                'iop'               => 'nullable|string|max:50',
                'reasonForReferral' => 'nullable|string|max:1000',
                'management'        => 'nullable|string|max:1000',
            ]);
        }

        if ($this->letterType === 'medical_report') {
            return array_merge($base, [
                // A report says something: findings, a diagnosis, or both.
                'clinicalFindings' => 'nullable|required_without:selectedDiagnoses|string|max:2000',
                'treatment'        => 'nullable|string|max:1000',
                'recommendation'   => 'nullable|string|max:1000',
            ]);
        }

        if ($this->letterType === 'excuse_duty') {
            return array_merge($base, [
                // The excuse starts no earlier than the day the patient was seen (the letter date).
                'excuseFromDate' => 'required|date|after_or_equal:referralDate',
                'excuseToDate'   => 'required|date|after_or_equal:excuseFromDate',
                'excuseNotes'    => 'nullable|string|max:2000',
            ]);
        }

        return $base;
    }

    protected function messages(): array
    {
        return [
            'excuseFromDate.after_or_equal'      => 'The excuse cannot start before the day the patient was seen (the letter date).',
            'excuseToDate.after_or_equal'        => 'The last day must be on or after the first day.',
            'clinicalFindings.required_without'  => 'Write the clinical findings or choose a diagnosis.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'referralTo' => 'Refer to', 'referralDate' => 'Letter date', 'patientName' => 'Patient name',
            'excuseFromDate' => 'First day', 'excuseToDate' => 'Last day', 'clinicalFindings' => 'Clinical findings',
        ];
    }

    public function mount()
    {
        $this->referralDate = now()->format('Y-m-d');
        $this->fromDate     = now()->startOfMonth()->format('Y-m-d');
        $this->toDate       = now()->format('Y-m-d');
        $this->vaNotation   = Setting::getSettings()->va_notation ?? '6m';
    }

    /* ── List query ── */
    public function getReferralsProperty()
    {
        return Referral::with('patient', 'referredBy')
            ->when($this->searchQuery, fn ($q) =>
                $q->where(fn ($s) =>
                    $s->where('patient_name', 'like', '%'.$this->searchQuery.'%')
                      ->orWhere('referral_to',  'like', '%'.$this->searchQuery.'%')
                      ->orWhere('diagnosis',    'like', '%'.$this->searchQuery.'%')
                )
            )
            ->when($this->statusFilter, fn ($q) => $q->where('status',      $this->statusFilter))
            ->when($this->typeFilter,   fn ($q) => $q->where('letter_type', $this->typeFilter))
            ->when($this->fromDate && $this->toDate, fn ($q) =>
                $q->whereBetween('referral_date', [$this->fromDate, $this->toDate])
            )
            ->latest('referral_date')
            ->paginate($this->perPage);
    }

    /* ── Patient autocomplete ── */
    public function updatedPatientSearch($value)
    {
        $this->patientSuggestions = strlen($value) >= 2
            ? Patient::where('name',     'like', '%'.$value.'%')
                     ->orWhere('pxnumber','like', '%'.$value.'%')
                     ->limit(6)
                     ->get(['id','name','pxnumber','dob','gender','contact'])
                     ->toArray()
            : [];
    }

    public function selectPatient($patientId)
    {
        $patient = Patient::findOrFail($patientId);

        $this->selectedPatientId  = $patient->id;
        $this->patientName        = $patient->name;
        $this->patientContact     = $patient->contact ?? '';
        $age                      = $patient->dob ? \Carbon\Carbon::parse($patient->dob)->age : '?';
        $sex                      = ucfirst($patient->gender ?? '');
        $this->patientAgeSex      = "{$age}yrs / {$sex}";
        $this->patientSearch      = $patient->name;
        $this->patientSuggestions = [];
    }

    public function clearPatient()
    {
        $this->selectedPatientId  = null;
        $this->patientSearch      = '';
        $this->patientSuggestions = [];
        $this->patientName        = '';
        $this->patientAgeSex      = '';
        $this->patientContact     = '';
        $this->vaOd               = '';
        $this->vaOs               = '';
    }

    /* ── Open modal ── */
    public function openCreate($type = 'referral')
    {
        $this->resetForm();
        $this->letterType = $type;
        if ($type === 'excuse_duty') {
            $this->excuseNotes = Referral::defaultExcuseNotes();
        }
        $this->editingId  = null;
        $this->showModal  = true;
    }

    public function openEdit($id)
    {
        $r = Referral::findOrFail($id);
        // A completed letter has been handed over; it is reopened (status back to Pending) before it changes.
        if ($r->status === 'completed') {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This letter is completed. Set it back to Pending to edit it.']);
            return;
        }

        $this->editingId         = $id;
        $this->letterType        = $r->letter_type;
        $this->referralDate      = $r->referral_date->format('Y-m-d');
        $this->selectedPatientId  = $r->patient_id;
        $this->patientName        = $r->patient_name;
        $this->patientAgeSex      = $r->patient_age_sex   ?? '';
        $this->patientContact     = $r->patient_contact   ?? '';
        $this->patientSearch      = $r->patient_name;
        $this->status             = $r->status;

        $raw = $r->diagnosis ?? '';
        if ($raw && ($decoded = json_decode($raw, true)) && is_array($decoded)) {
            $this->selectedDiagnoses = $decoded;
        } elseif ($raw) {
            $this->selectedDiagnoses = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } else {
            $this->selectedDiagnoses = [];
        }

        // Referral
        $this->referralTo        = $r->referral_to        ?? '';
        $this->complaint         = $r->complaint          ?? '';
        $this->vaOd              = $r->va_od              ?? '';
        $this->vaOs              = $r->va_os              ?? '';
        $this->refraction        = $r->refraction         ?? '';
        $this->anteriorSegment   = $r->anterior_segment   ?? '';
        $this->posteriorSegment  = $r->posterior_segment  ?? '';
        $this->iop               = $r->iop                ?? '';
        $this->reasonForReferral = $r->reason_for_referral ?? '';
        $this->management        = $r->management         ?? '';

        // Medical Report
        $this->clinicalFindings  = $r->clinical_findings  ?? '';
        $this->treatment         = $r->treatment          ?? '';
        $this->recommendation    = $r->recommendation     ?? '';

        // Excuse Duty
        $this->excuseFromDate = $r->excuse_from_date ? $r->excuse_from_date->format('Y-m-d') : '';
        $this->excuseToDate   = $r->excuse_to_date   ? $r->excuse_to_date->format('Y-m-d')   : '';
        $this->excuseNotes    = $r->excuse_notes ?? Referral::defaultExcuseNotes();

        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /* ── Save ── */
    public function save()
    {
        $this->validate();
        if ($this->letterType === 'excuse_duty'
            && \Carbon\Carbon::parse($this->excuseFromDate)->diffInDays(\Carbon\Carbon::parse($this->excuseToDate)) + 1 > Referral::MAX_EXCUSE_DAYS) {
            $this->addError('excuseToDate', 'One letter can excuse at most ' . Referral::MAX_EXCUSE_DAYS . ' days. For longer, review the patient and issue another.');
            return;
        }

        $data = [
            'letter_type'     => $this->letterType,
            'patient_id'      => $this->selectedPatientId,
            'referral_date'   => $this->referralDate,
            'patient_name'    => $this->patientName,
            'patient_age_sex' => $this->patientAgeSex,
            'patient_contact' => $this->patientContact,
            'diagnosis'       => !empty($this->selectedDiagnoses) ? json_encode(array_values($this->selectedDiagnoses)) : null,
            'status'          => $this->status,
            // Referral
            'referral_to'        => $this->letterType === 'referral' ? $this->referralTo : null,
            'complaint'          => $this->complaint,
            'va_od'              => $this->vaOd,
            'va_os'              => $this->vaOs,
            'refraction'         => $this->refraction,
            'anterior_segment'   => $this->anteriorSegment,
            'posterior_segment'  => $this->posteriorSegment,
            'iop'                => $this->iop,
            'reason_for_referral'=> $this->reasonForReferral,
            'management'         => $this->management,
            // Medical Report
            'clinical_findings'  => $this->clinicalFindings,
            'treatment'          => $this->treatment,
            'recommendation'     => $this->recommendation,
            // Excuse Duty
            'excuse_from_date'   => $this->letterType === 'excuse_duty' ? $this->excuseFromDate : null,
            'excuse_to_date'     => $this->letterType === 'excuse_duty' ? $this->excuseToDate   : null,
            'excuse_notes'       => $this->letterType === 'excuse_duty' ? trim($this->excuseNotes) : null,
        ];

        // The letter stays issued by whoever wrote it; an edit records who changed it.
        if ($this->editingId) {
            $referral = Referral::findOrFail($this->editingId);
            if ($referral->status === 'completed') {
                $this->dispatch('notify', ...['type' => 'error', 'message' => 'This letter is completed. Set it back to Pending to edit it.']);
                return;
            }
            $old = $referral->only(array_keys($data));
            $referral->update($data + ['updated_by' => auth()->id()]);
            AuditTrail::record('referral.updated', "Edited {$referral->letter_type_label} #{$referral->id} for {$referral->patient_name}",
                $referral, $old, $referral->only(array_keys($data)), $referral->patient_id);
            $msg = 'Letter updated successfully.';
        } else {
            $referral = Referral::create($data + ['referred_by' => auth()->id()]);
            AuditTrail::record('referral.created', "Wrote {$referral->letter_type_label} #{$referral->id} for {$referral->patient_name}",
                $referral, [], $data, $referral->patient_id);
            $msg = 'Letter created successfully.';
        }

        $this->closeModal();
        $this->dispatch('notify', ...['type' => 'success', 'message' => $msg]);
    }

    /* ── Delete ── */
    public function confirmDelete($id)
    {
        abort_unless(auth()->user()->hasRole('Super Admin'), 403);
        $this->confirmDeleteId = $id;
        $this->dispatch('show-delete-confirm');
    }

    public function deleteReferral()
    {
        abort_unless(auth()->user()->hasRole('Super Admin'), 403);
        $referral = Referral::findOrFail($this->confirmDeleteId);
        AuditTrail::record('referral.deleted', "Deleted {$referral->letter_type_label} #{$referral->id} for {$referral->patient_name}",
            $referral, $referral->toArray(), [], $referral->patient_id);
        $referral->delete();
        $this->confirmDeleteId = null;
        $this->dispatch('hide-delete-confirm');
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Letter deleted.']);
    }

    public function cancelDelete()
    {
        $this->confirmDeleteId = null;
        $this->dispatch('hide-delete-confirm');
    }

    /* ── Status update ── */
    public function updateStatus($id, $newStatus)
    {
        if (! in_array($newStatus, ['pending', 'completed', 'cancelled'], true)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Unknown status.']);
            return;
        }
        $referral = Referral::findOrFail($id);
        $old = $referral->status;
        $referral->update(['status' => $newStatus, 'updated_by' => auth()->id()]);
        AuditTrail::record('referral.status', "{$referral->letter_type_label} #{$referral->id} for {$referral->patient_name}: {$old} → {$newStatus}",
            $referral, ['status' => $old], ['status' => $newStatus], $referral->patient_id);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Status updated.']);
    }

    /* ── Helpers ── */
    protected function resetForm()
    {
        $this->reset([
            'letterType', 'referralTo', 'referralDate', 'selectedPatientId',
            'patientSearch', 'patientSuggestions', 'patientName', 'patientAgeSex',
            'patientContact', 'complaint', 'vaOd', 'vaOs', 'refraction',
            'anteriorSegment', 'posteriorSegment', 'iop', 'selectedDiagnoses',
            'reasonForReferral', 'management', 'clinicalFindings', 'treatment',
            'recommendation', 'excuseFromDate', 'excuseToDate', 'excuseNotes',
        ]);
        $this->referralDate = now()->format('Y-m-d');
        $this->letterType   = 'referral';
        $this->status       = 'pending';
        $this->resetErrorBag();
    }

    public function updatedSearchQuery()  { $this->resetPage(); }
    public function updatedStatusFilter() { $this->resetPage(); }
    public function updatedTypeFilter()   { $this->resetPage(); }
    public function updatedFromDate()     { $this->resetPage(); }
    public function updatedToDate()       { $this->resetPage(); }

    public function render()
    {
        return view('livewire.doctor.referral-component', [
            'referrals' => $this->referrals,
            'diagnoses' => Diagnosis::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.clinic', ['menu' => 'doctor']);
    }
}
