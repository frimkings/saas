<?php

namespace App\Livewire\Secretary;

use App\Models\SmsTemplate;
use App\Support\Messaging\WhatsAppLink;
use App\Models\AuditTrail;
use App\Models\Patient;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Support\Tenancy\TenantContext;

class PatientsComponent extends Component
{
    use WithPagination, WithFileUploads;

    protected $paginationTheme = 'bootstrap';

    public $state = [];
    public $nameSearch = '';
    public $suggestions = [];

    public $isEditing = false;
    public $showRegistryForm = false;
    public $formMessage = '';
    public $formMessageType = 'info';
    public $paymentType = 'cash';
    public $showInsuranceModal = false;
    public $activeTab = 'today';
    public $birthdaysTodayCount = 0;

    public $pxSearch      = '';
    public $fromDate      = null;
    public $toDate        = null;
    public $fromDateDisplay = '';
    public $toDateDisplay = '';
    public $dobDisplay = '';
    public $dobAge = null;
    public $dateRangeError = '';
    public $duplicatePatients = [];
    public $insurerFilter = '';

    public $selectedPatients = [];
    public $selectAll = false;

    public $showImportPanel = false;
    public $importFile = null;
    public $importResults = null;

    public function mount()
    {
        if (!Auth::user()->hasAnyRole(['Secretary', 'Super Admin'])) {
            return redirect()->route('dashboard')->with('error', 'Access Denied.');
        }
        $this->resetForm();
        $this->setDefaultDates();
    }

    // Fix #1/#3: re-check role on every Livewire AJAX request, not just mount()
    public function hydrate()
    {
        if (!Auth::user()->hasAnyRole(['Secretary', 'Super Admin'])) {
            abort(403);
        }
    }

    public function setDefaultDates()
    {
        $this->fromDate = Carbon::today()->startOfMonth()->toDateString();
        $this->toDate = Carbon::today()->toDateString();
        $this->fromDateDisplay = Carbon::parse($this->fromDate)->format('d/m/y');
        $this->toDateDisplay = Carbon::parse($this->toDate)->format('d/m/y');
    }

    public function updatedFromDateDisplay(): void
    {
        $this->fromDate = $this->parseDisplayDate($this->fromDateDisplay, false);
        $this->validateDateRange();
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedToDateDisplay(): void
    {
        $this->toDate = $this->parseDisplayDate($this->toDateDisplay, false);
        $this->validateDateRange();
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedDobDisplay(): void
    {
        $date = $this->parseDisplayDate($this->dobDisplay);
        $this->dobAge = $date ? Carbon::parse($date)->age : null;
        $this->checkDuplicatePatients();
    }

    public function updatedStateContact(): void
    {
        $this->checkDuplicatePatients();
    }

    public function setDatePreset(string $preset): void
    {
        $to = Carbon::today();
        $from = match ($preset) {
            'today' => $to->copy(),
            'last_30_days' => $to->copy()->subDays(29),
            default => $to->copy()->startOfMonth(),
        };
        $this->fromDate = $from->toDateString();
        $this->toDate = $to->toDateString();
        $this->fromDateDisplay = $from->format('d/m/y');
        $this->toDateDisplay = $to->format('d/m/y');
        $this->dateRangeError = '';
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPxSearch()
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedInsurerFilter()
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedActiveTab()
    {
        $this->resetPage();
        $this->clearSelection();
    }

    private function patientQuery()
    {
        return $this->activeTab === 'archived' ? Patient::onlyTrashed() : Patient::query();
    }

    public function updatingPage()
    {
        $this->clearSelection();
    }

    public function resetFilters()
    {
        $this->pxSearch      = '';
        $this->insurerFilter = '';
        $this->setDefaultDates();
        $this->resetPage();
        $this->clearSelection();
    }

    public function getGenderStatsProperty()
    {
        $query = $this->applyFilters($this->patientQuery());

        return [
            'male'   => (clone $query)->where('gender', 'Male')->count(),
            'female' => (clone $query)->where('gender', 'Female')->count(),
            'other'  => (clone $query)->where('gender', 'Other')->count(),
        ];
    }

    // WhatsAppLink keeps only digits, so a stored number like "0244&text=injected"
    // cannot rewrite the WhatsApp URL parameters.
    public function generateWhatsAppLink($name, $contact)
    {
        $clinicName = Setting::getSettings()->clinic_name;
        return WhatsAppLink::to($contact, "Hello " . $name . ", this is " . $clinicName . ".") ?? '#';
    }

    public function generateBirthdayWhatsAppLink($name, $contact)
    {
        return WhatsAppLink::to($contact, SmsTemplate::render('birthday_wishes', ['[NAME]' => $name])) ?? '#';
    }

    public function generateCallLink($contact)
    {
        $phone = $this->formatPhoneForCall($contact);
        return $phone ? 'tel:' . $phone : '#';
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedPatients = $this->applyFilters($this->patientQuery())
                ->pluck('id')->map(fn($id) => (string)$id)->toArray();
        } else {
            $this->selectedPatients = [];
        }
    }

    public function clearSelection()
    {
        $this->selectedPatients = [];
        $this->selectAll = false;
    }

    public function archiveSelected()
    {
        // Fix #1: resolve IDs against the DB so forged/injected IDs are silently ignored
        $validIds = Patient::whereIn('id', $this->selectedPatients)->pluck('id')->all();
        $count    = count($validIds);
        Patient::whereIn('id', $validIds)->delete();
        // Fix #8 force=true: bulk-delete must be logged even if license is downgraded
        AuditTrail::record('patient.archived', "Archived {$count} patient record(s).", null, [], [], null, true);
        $this->clearSelection();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Selected records archived!']);
    }

    public function restoreSelected(): void
    {
        $patients = Patient::onlyTrashed()->whereIn('id', $this->selectedPatients)->get();
        foreach ($patients as $patient) {
            $patient->restore();
            AuditTrail::record('patient.restored', "Restored patient profile: {$patient->name} ({$patient->pxnumber})", $patient, [], [], $patient->id, true);
        }
        $count = $patients->count();
        $this->clearSelection();
        $this->dispatch('notify', ...['type' => 'success', 'message' => "{$count} patient record(s) restored."]);
    }

    // Fix #5: pass the query builder; downloadCSV streams with chunkById
    public function exportRegistry()
    {
        $query = $this->applyFilters($this->patientQuery());
        // Fix #8 force=true: exports are security-critical — always audit
        AuditTrail::record('patient.exported', 'Exported patient registry.', null, [], [], null, true);
        return $this->downloadCSV($query, 'Patient_Registry_Report');
    }

    public function exportSelected()
    {
        if (empty($this->selectedPatients)) return;
        // Fix #1: resolve against DB so forged IDs in the public property are ignored
        $query = ($this->activeTab === 'archived' ? Patient::onlyTrashed() : Patient::query())
            ->whereIn('id', $this->selectedPatients);
        AuditTrail::record('patient.exported', 'Exported ' . count($this->selectedPatients) . ' selected patient(s).', null, [], [], null, true);
        return $this->downloadCSV($query, 'Selected_Patients_Export');
    }

    // Fix #2 + #5: accepts a query Builder, streams with chunkById (no unbounded get()),
    // and sanitizes every value against CSV formula injection.
    private function downloadCSV($query, $filename)
    {
        $fileName = $filename . '_' . date('Y-m-d') . '.csv';
        $callback = function () use ($query) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['PX Number', 'Name', 'Email', 'Contact', 'DOB', 'Gender', 'Civil Status', 'Occupation', 'Address', 'Registered Date']);
            $query->chunkById(500, function ($patients) use ($file) {
                foreach ($patients as $px) {
                    fputcsv($file, array_map([$this, 'sanitizeCsvValue'], [
                        $px->pxnumber,
                        $px->name,
                        $px->email,
                        $px->contact,
                        $px->dob ? Carbon::parse($px->dob)->format('d/m/y') : '',
                        $px->gender,
                        $px->civil_status,
                        $px->occupation,
                        $px->address,
                        $px->created_at->format('d/m/y'),
                    ]));
                }
            });
            fclose($file);
        };
        return response()->streamDownload($callback, $fileName);
    }

    // Fix #2: prefix formula-trigger characters so Excel/Sheets won't execute them
    private function sanitizeCsvValue($value): string
    {
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    public function importCsv()
    {
        $this->validate([
            'importFile' => 'required|file|max:4096|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
        ], [
            'importFile.required' => 'Please choose a CSV file.',
            'importFile.mimetypes' => 'The file must be a valid CSV file.',
            'importFile.max' => 'CSV file must be under 4 MB.',
        ]);

        // Fix #11: content-sniff — reject binary files even if MIME was spoofed
        $firstBytes = file_get_contents($this->importFile->getRealPath(), false, null, 0, 512);
        if ($firstBytes !== false && !mb_check_encoding($firstBytes, 'UTF-8') && !mb_check_encoding($firstBytes, 'ASCII')) {
            $this->addError('importFile', 'The file does not appear to be a valid text/CSV file.');
            return;
        }

        $handle  = fopen($this->importFile->getRealPath(), 'r');
        $header  = fgetcsv($handle);
        if (is_array($header) && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $rowNum   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $data = $this->mapImportRow($header, $row);

            $payload = [
                'name'         => trim((string) ($data['name'] ?? $data['full_name'] ?? '')),
                'email'        => trim((string) ($data['email'] ?? $data['email_address'] ?? '')),
                'contact'      => trim((string) ($data['contact'] ?? $data['phone'] ?? $data['phone_number'] ?? '')),
                'dob'          => $this->normalizeImportDate($data['dob'] ?? $data['birthday'] ?? $data['date_of_birth'] ?? null),
                'gender'       => $this->normalizeGender($data['gender'] ?? ''),
                'civil_status' => trim((string) ($data['civil_status'] ?? $data['marital_status'] ?? '')),
                'occupation'   => trim((string) ($data['occupation'] ?? '')),
                'address'      => trim((string) ($data['address'] ?? '')),
            ];

            if ($payload['name'] === '' && $payload['contact'] === '') {
                $skipped++;
                continue;
            }

            $validator = Validator::make($payload, [
                'name'         => 'required|string|max:255',
                'contact'      => 'required|string|max:50',
                'email'        => 'nullable|email|max:255',
                'dob'          => 'required|date|date_format:Y-m-d|before:today',
                'gender'       => 'required|in:Male,Female,Other',
                'address'      => 'required|string|max:1000',
                'occupation'   => 'nullable|string|max:255',
                'civil_status' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: " . $validator->errors()->first();
                $skipped++;
                continue;
            }

            if (Patient::where('contact', $payload['contact'])->where('name', $payload['name'])->exists()) {
                $skipped++;
                continue;
            }

            Patient::createWithGeneratedPxNumber(array_merge($validator->validated(), [
                'user_id'  => Auth::id(),
            ]));
            $imported++;
        }

        fclose($handle);

        $this->importFile    = null;
        $this->importResults = compact('imported', 'skipped', 'errors');
        AuditTrail::record('patient.imported', "Imported {$imported} patient record(s); skipped {$skipped} row(s).", null, [], ['imported' => $imported, 'skipped' => $skipped, 'errors' => count($errors)], null, true);
        $this->showImportPanel = true;
        $this->clearSelection();
        $this->resetPage();
    }

    public function clearImport()
    {
        $this->importFile    = null;
        $this->importResults = null;
        $this->showImportPanel = false;
        $this->resetValidation('importFile');
    }

    public function downloadTemplate()
    {
        $callback = function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['name', 'email', 'contact', 'dob', 'gender', 'civil_status', 'occupation', 'address']);
            fputcsv($file, ['Ama Patient', 'ama@example.com', '0244123456', '1995-06-20', 'Female', 'Single', 'Teacher', 'Kumasi']);
            fclose($file);
        };
        return response()->streamDownload($callback, 'patients_import_template.csv');
    }

    public function resetForm()
    {
        $this->state = [
            'id' => null, 'pxnumber' => '', 'name' => '', 'contact' => '', 'email' => '',
            'dob' => '', 'gender' => '', 'address' => '', 'occupation' => '',
            'civil_status' => '',
            'insurer_id' => null, 'insurance_member_id' => '', 'insurance_member_name' => '', 'insurance_policy_number' => '',
            'sms_opt_out' => false, 'whatsapp_opt_out' => false, 'marketing_opt_out' => false,
        ];
        $this->nameSearch = '';
        $this->dobDisplay = '';
        $this->dobAge = null;
        $this->duplicatePatients = [];
        $this->isEditing  = false;
        $this->paymentType = 'cash';
        $this->showInsuranceModal = false;
        $this->formMessage = '';
        $this->formMessageType = 'info';
        $this->resetValidation();
    }

    public function startRegistration(): void
    {
        $this->resetForm();
        $this->showRegistryForm = true;
    }

    public function closeRegistryForm(): void
    {
        $this->resetForm();
        $this->showRegistryForm = false;
    }

    public function choosePaymentType(string $type): void
    {
        $this->paymentType = $type === 'insurance' ? 'insurance' : 'cash';

        if ($this->paymentType === 'cash') {
            $this->clearInsuranceDetails();
            return;
        }

        $this->showInsuranceModal = true;
    }

    public function openInsuranceModal(): void
    {
        $this->paymentType = 'insurance';
        $this->showInsuranceModal = true;
    }

    public function closeInsuranceModal(): void
    {
        $this->showInsuranceModal = false;
    }

    public function clearInsuranceDetails(): void
    {
        $this->state['insurer_id'] = null;
        $this->state['insurance_member_id'] = '';
        $this->state['insurance_member_name'] = '';
        $this->state['insurance_policy_number'] = '';
        $this->showInsuranceModal = false;
        $this->resetValidation();
    }

    public function saveEntry()
    {
        $this->state['name'] = $this->nameSearch;
        $this->state['dob'] = $this->parseDisplayDate($this->dobDisplay);
        $this->checkDuplicatePatients();
        if ($this->paymentType !== 'insurance') {
            $this->clearInsuranceDetails();
        }
        $this->state['insurer_id'] = $this->state['insurer_id'] ?: null;

        $validator = Validator::make($this->state, [
            'name'         => 'required|string|max:255',
            'contact'      => 'required',
            'email'        => 'nullable|email',
            'dob'          => 'required|date|before:today',
            'gender'       => 'required|in:Male,Female,Other',
            'address'      => 'required',
            'occupation'               => 'nullable',
            'civil_status'             => 'nullable',
            'insurer_id'               => [
                $this->paymentType === 'insurance' ? 'required' : 'nullable',
                Rule::exists('insurers', 'id')
                    ->where(fn ($query) => $query
                        ->where('clinic_id', app(TenantContext::class)->clinicId())
                        ->where('active', true)
                        ->whereNull('deleted_at')),
            ],
            'insurance_member_id'      => 'nullable|string|max:60',
            'insurance_member_name'    => 'nullable|string|max:120',
            'insurance_policy_number'  => 'nullable|string|max:60',
            'sms_opt_out'              => 'boolean',
            'whatsapp_opt_out'         => 'boolean',
            'marketing_opt_out'        => 'boolean',
        ], [], [
            'insurer_id' => 'Insurer',
            'insurance_member_id' => 'Member ID',
            'insurance_member_name' => 'Member Name',
            'insurance_policy_number' => 'Policy Number',
        ]);

        $validator->after(function ($validator) {
            if ($this->paymentType !== 'insurance') {
                return;
            }

            if (
                trim((string) ($this->state['insurance_member_id'] ?? '')) === ''
                && trim((string) ($this->state['insurance_policy_number'] ?? '')) === ''
            ) {
                $validator->errors()->add('insurance_member_id', 'Enter either the member ID or policy number.');
            }
        });

        $validatedData = $validator->validate();

        if (!$this->isEditing && count($this->duplicatePatients) > 0) {
            $this->addError('name', 'A possible matching patient already exists. Open the existing profile or change the identifying details.');
            return;
        }

        if ($this->isEditing) {
            $patient = Patient::find($this->state['id']);

            if (!$patient) {
                $this->isEditing = false;
                $this->formMessageType = 'warning';
                $this->formMessage = 'This patient record is no longer available. Please choose another record.';
                $this->dispatch('notify', ...['type' => 'warning', 'message' => $this->formMessage]);
                return;
            }

            $old     = $patient->only(array_keys($validatedData));
            $patient->update($validatedData);
            AuditTrail::record('patient.updated', "Updated patient profile: {$patient->name} ({$patient->pxnumber})", $patient, $old, $validatedData, $patient->id);
            $this->formMessageType = 'success';
            $this->formMessage = 'Patient profile updated successfully.';
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Profile Updated!']);
        } else {
            $validatedData['user_id']  = Auth::id();
            $patient = Patient::createWithGeneratedPxNumber($validatedData);
            AuditTrail::record('patient.created', "Registered new patient: {$patient->name} ({$patient->pxnumber})", $patient, [], [], $patient->id);
            $this->formMessageType = 'success';
            $this->formMessage = "Registered {$patient->name}.";
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'New Patient Saved!']);
        }
        $message = $this->formMessage;
        $messageType = $this->formMessageType;
        $this->resetForm();
        $this->formMessage = $message;
        $this->formMessageType = $messageType;
        $this->showRegistryForm = false;
    }

    private function applyFilters($query)
    {
        if ($this->pxSearch) {
            $term = $this->pxSearch;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('pxnumber', 'like', "%{$term}%")
                  ->orWhere('insurance_member_id', 'like', "%{$term}%")
                  ->orWhere('insurance_policy_number', 'like', "%{$term}%")
                  ->orWhereHas('insurer', fn ($i) => $i->where('name', 'like', "%{$term}%"));
            });
        }

        if (!$this->pxSearch) {
            if ($this->dateRangeError !== '') {
                return $query->whereRaw('1 = 0');
            }
            if ($this->fromDate && $this->toDate) {
                $query->whereBetween('created_at', [
                    Carbon::parse($this->fromDate)->startOfDay(),
                    Carbon::parse($this->toDate)->endOfDay(),
                ]);
            }
        }

        if ($this->insurerFilter) {
            $query->where('insurer_id', $this->insurerFilter);
        }

        if ($this->activeTab === 'birthdays') {
            $query->whereMonth('dob', Carbon::now()->month)->whereDay('dob', Carbon::now()->day);
        } elseif ($this->activeTab === 'cash') {
            $query->whereNull('insurer_id');
        } elseif ($this->activeTab === 'insurance') {
            $query->whereNotNull('insurer_id');
        }
        return $query;
    }

    private function mapImportRow($header, array $row): array
    {
        if (!is_array($header) || empty($header)) {
            return [];
        }
        $mapped = [];
        foreach ($header as $index => $column) {
            $key          = strtolower(trim((string) $column));
            $key          = str_replace([' ', '-', '.'], '_', $key);
            $mapped[$key] = $row[$index] ?? null;
        }
        return $mapped;
    }

    private function normalizeImportDate($value): ?string
    {
        if (!$value) return null;
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeGender($value): string
    {
        $value = strtolower(trim((string) $value));
        return match ($value) {
            'm', 'male'    => 'Male',
            'f', 'female'  => 'Female',
            'other', 'o'   => 'Other',
            default        => '',
        };
    }

    private function formatPhoneForCall($contact): string
    {
        return preg_replace('/[^0-9+]/', '', (string) $contact);
    }

    public function edit(int $id)
    {
        $patient = Patient::find($id);

        if (!$patient) {
            $this->formMessageType = 'warning';
            $this->formMessage = 'This patient record is no longer available. Refresh the list and try again.';
            $this->dispatch('notify', ...['type' => 'warning', 'message' => $this->formMessage]);
            return;
        }

        $this->state      = $patient->toArray();
        $this->dobDisplay = $patient->dob ? Carbon::parse($patient->dob)->format('d/m/y') : '';
        $this->dobAge = $patient->dob ? Carbon::parse($patient->dob)->age : null;
        $this->nameSearch = $patient->name;
        $this->isEditing  = true;
        $this->paymentType = $patient->insurer_id ? 'insurance' : 'cash';
        $this->showInsuranceModal = false;
        $this->suggestions = [];
        $this->resetValidation();
        $this->formMessageType = 'info';
        $this->formMessage = "Editing {$patient->name}'s profile. Update the details and save when ready.";
        $this->showRegistryForm = true;
    }

    public function updatedNameSearch($value)
    {
        $this->checkDuplicatePatients();
        if (strlen($value) < 2) { $this->suggestions = []; return; }
        $this->suggestions = Patient::where('name', 'like', '%' . $value . '%')->limit(5)->get()->toArray();
    }

    // Fix #7: type-hint int; use findOrFail so an invalid/forged ID throws 404
    // rather than silently loading nothing and potentially resetting the form state.
    public function selectPatient(int $id)
    {
        $p = Patient::findOrFail($id);
        $this->state      = $p->toArray();
        $this->dobDisplay = $p->dob ? Carbon::parse($p->dob)->format('d/m/y') : '';
        $this->dobAge = $p->dob ? Carbon::parse($p->dob)->age : null;
        $this->nameSearch = $p->name;
        $this->isEditing  = true;
        $this->paymentType = $p->insurer_id ? 'insurance' : 'cash';
        $this->showInsuranceModal = false;
        $this->suggestions = [];
    }

    public function render()
    {
        if ($this->birthdaysTodayCount === 0) {
            $this->birthdaysTodayCount = Patient::whereMonth('dob', Carbon::now()->month)
                ->whereDay('dob', Carbon::now()->day)
                ->count();
        }

        $query = $this->applyFilters($this->patientQuery());
        return view('livewire.secretary.patients-component', [
            'patients' => $query->latest()->paginate(10),
            'insurers' => \App\Models\Insurer::where('active', true)->orderBy('name')->get(['id', 'name', 'scheme_type']),
        ])->layout('layouts.secretary.secretary-layout');
    }

    public function getRegistryStatsProperty(): array
    {
        return [
            'active' => Patient::count(),
            'cash' => Patient::whereNull('insurer_id')->count(),
            'insured' => Patient::whereNotNull('insurer_id')->count(),
            'archived' => Patient::onlyTrashed()->count(),
        ];
    }

    private function parseDisplayDate(?string $value, bool $preventFutureDate = true): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!d/m/y', $value);
            $errors = Carbon::getLastErrors();

            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }

            // PHP expands years 00-69 into 2000-2069. That century correction
            // is appropriate for dates of birth, but not registry filters.
            if ($preventFutureDate && $date->isFuture()) {
                $date->subCentury();
            }

            return $date->toDateString();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function validateDateRange(): void
    {
        $this->dateRangeError = '';
        if ($this->fromDateDisplay !== '' && !$this->fromDate) {
            $this->dateRangeError = 'Enter the From date as dd/mm/yy.';
            return;
        }
        if ($this->toDateDisplay !== '' && !$this->toDate) {
            $this->dateRangeError = 'Enter the To date as dd/mm/yy.';
            return;
        }
        if ($this->fromDate && $this->toDate && Carbon::parse($this->fromDate)->gt(Carbon::parse($this->toDate))) {
            $this->dateRangeError = 'The From date cannot be later than the To date.';
        }
    }

    private function checkDuplicatePatients(): void
    {
        $name = trim($this->nameSearch);
        $contact = trim((string) ($this->state['contact'] ?? ''));
        $dob = $this->parseDisplayDate($this->dobDisplay);

        if (mb_strlen($name) < 3 || (!$dob && $contact === '')) {
            $this->duplicatePatients = [];
            return;
        }

        $query = Patient::where('name', $name);
        if ($this->isEditing && !empty($this->state['id'])) {
            $query->where('id', '!=', $this->state['id']);
        }
        $query->where(function ($match) use ($dob, $contact) {
            if ($dob) {
                $match->whereDateIndexed('dob', $dob);
            }
            if ($contact !== '') {
                $match->orWhere('contact', $contact);
            }
        });

        $this->duplicatePatients = $query->limit(3)->get(['id', 'name', 'pxnumber', 'dob', 'contact'])->toArray();
    }
}
