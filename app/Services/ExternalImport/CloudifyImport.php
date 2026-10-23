<?php

namespace App\Services\ExternalImport;

use App\Models\ExternalImportBatch;
use App\Models\Refractions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cloudify (ECMS) exports one .xlsx per table, every row carrying the patient's ECMS id. This
 * recognises each file by its columns, checks it (analyze) and writes it into an existing clinic
 * (commit). Every row written is listed in external_import_rows so ExternalImportRollback can undo it.
 *
 * Cloudify has no visits: complaints, examinations, diagnoses, plans, medications and refractions
 * recorded for a patient on one day become one consultation (with a cleared visit behind it).
 * Payments have no invoices, so they go to patient_legacy_payments, never to sales; expenses go to
 * legacy_expenses. Both are history: the clinic's books start when it starts using this system.
 */
class CloudifyImport
{
    public const SOURCE = 'cloudify';

    /** key => [label, columns that identify the file, where it goes] */
    public const SHEETS = [
        'patients' => ['Patient details', ['PATEINT_ECMS_ID', 'DATE_OF_BIRTH', 'GENDER', 'EMERGENCY_CONTACT'], 'Patients'],
        'complaints' => ['Complaints', ['PATEINT_ECMS_ID', 'DATE_OF_COMPLAIN', 'COMPLAINS'], 'Consultations: complaint and history'],
        'exams' => ['Ocular examination', ['PATEINT_ECMS_ID', 'DATE_EXAMINATION', 'OD_CORNEA'], 'Consultations: examination findings and IOP'],
        'diagnoses' => ['Diagnosis', ['PATEINT_ECMS_ID', 'DIAGNOSIS_DATE', 'DIAGNOSIS'], 'Consultation diagnoses (adds missing names to the diagnosis list)'],
        'plans' => ['Management plans', ['PATEINT_ECMS_ID', 'MANAGEMENT_PLAN', 'PLAN_DATE'], 'Consultation notes: plan'],
        'medications' => ['Medications', ['PATEINT_ECMS_ID', 'MEDICATION_ID', 'ITEM', 'DATE_OF_PRESCRIPTION'], 'Consultation notes and the Prescription tab: previous medications'],
        'refractions' => ['Refraction', ['PATEINT_ECMS_ID', 'DATE_OF_EXAMINATION', 'SPHERE_OD_SR'], 'Refractions'],
        'appointments' => ['Appointments', ['PATEINT_ECMS_ID', 'APPOINTMENT_DATE', 'APPOINTMENT_STATUS'], 'Appointments'],
        'bookings' => ['Bookings', ['PATEINT_ECMS_ID', 'BOOKING_ID', 'BOOKING_DATE'], 'Appointments'],
        'payments' => ['Payments', ['PATEINT_ECMS_ID', 'PAYMENT_DATE', 'PAYMENT_AMOUNT'], 'Payment history on the patient record (not finance)'],
        'expenses' => ['Expenses', ['EXPENSE_ID', 'EXPENSE_DATE', 'EXPENSE_AMOUNT'], 'Expense history (not in finance)'],
        'items' => ['Item list', ['ITEM_ID', 'ITEM_NAME', 'PRICE'], 'Products and services (prices only, stock 0)'],
    ];

    /** Sheets whose rows belong to a day's consultation, with the column holding that day. */
    private const VISIT_SHEETS = ['complaints' => 'DATE_OF_COMPLAIN', 'exams' => 'DATE_EXAMINATION', 'diagnoses' => 'DIAGNOSIS_DATE',
        'plans' => 'PLAN_DATE', 'medications' => 'DATE_OF_PRESCRIPTION', 'refractions' => 'DATE_OF_EXAMINATION'];

    /** Options the platform admin can switch off before importing. */
    public const OPTIONAL = ['new_diagnoses' => 'Diagnosis names missing from the clinic list', 'appointments' => 'Appointments and bookings', 'items' => 'Item list as products', 'expenses' => 'Expense history'];

    private ExternalImportBatch $batch;
    private int $clinicId;
    private int $branchId;
    private int $ownerId;
    private array $staff = [];
    private array $tracked = [];
    private array $counts = [];
    private array $skipped = [];

    public function __construct(private XlsxTableReader $reader) {}

    /** The sheet key a file's header row belongs to, or null when it isn't a Cloudify table. */
    public static function detect(array $headers): ?string
    {
        $best = null;
        foreach (self::SHEETS as $key => [, $signature]) {
            if (! array_diff($signature, $headers) && (! $best || count($signature) > count(self::SHEETS[$best][1]))) $best = $key;
        }

        return $best;
    }

    /**
     * Reads every stored file, recognises it and returns the dry-run report. Files are updated in
     * place with the sheet they hold (or why they are left out). Nothing is written to the clinic.
     */
    public function analyze(ExternalImportBatch $batch): array
    {
        $files = $batch->files ?? [];
        $sheets = [];
        $hashes = [];
        $errors = [];
        $unknown = [];
        $duplicates = [];
        foreach ($files as $i => $file) {
            try {
                $table = $this->reader->read(Storage::disk('local')->path($file['path']));
            } catch (\Throwable $e) {
                $files[$i]['sheet'] = null;
                $errors[] = $file['name'].': '.$e->getMessage();
                continue;
            }
            $key = self::detect($table['headers']);
            $files[$i]['sheet'] = $key;
            $files[$i]['rows'] = count($table['rows']);
            if (! $key) { $unknown[] = $file['name']; continue; }
            $hash = hash('sha256', json_encode($table['rows']));
            if (isset($sheets[$key])) {
                if ($hashes[$key] === $hash) { $files[$i]['sheet'] = null; $files[$i]['duplicate'] = true; $duplicates[] = $file['name']; continue; }
                $errors[] = 'Two different '.self::SHEETS[$key][0].' files were uploaded ('.$sheets[$key]['file'].' and '.$file['name'].'). Upload only one.';
                continue;
            }
            $hashes[$key] = $hash;
            $sheets[$key] = ['file' => $file['name'], 'rows' => $table['rows']];
        }
        $batch->files = $files;
        if (! isset($sheets['patients'])) $errors[] = 'The Patient details file (…PATIENTS_DETAILS….xlsx) is needed: every other record is linked to a patient.';

        $patients = collect($sheets['patients']['rows'] ?? []);
        $ids = $patients->pluck('PATEINT_ECMS_ID')->filter()->flip();
        $numbers = $patients->pluck('CLINIC_PATIENT_ID')->filter()->map(fn ($n) => strtolower(trim($n)));
        $taken = DB::table('patients')->where('clinic_id', $batch->clinic_id)->whereIn(DB::raw('LOWER(pxnumber)'), $numbers->unique()->values()->all())->count();

        $report = ['sheets' => [], 'orphans' => [], 'dates' => [null, null]];
        foreach (self::SHEETS as $key => [$label, , $into]) {
            $rows = $sheets[$key]['rows'] ?? null;
            $report['sheets'][$key] = ['label' => $label, 'into' => $into, 'file' => $sheets[$key]['file'] ?? null, 'rows' => $rows === null ? null : count($rows)];
            if ($rows && $key !== 'patients' && isset(self::SHEETS[$key][1][0]) && self::SHEETS[$key][1][0] === 'PATEINT_ECMS_ID') {
                $orphans = collect($rows)->filter(fn ($r) => ! isset($ids[$r['PATEINT_ECMS_ID'] ?? '']))->count();
                if ($orphans) $report['orphans'][$key] = $orphans;
            }
        }

        $visits = [];
        foreach (self::VISIT_SHEETS as $key => $column) foreach ($sheets[$key]['rows'] ?? [] as $row) {
            if (! isset($ids[$row['PATEINT_ECMS_ID'] ?? '']) || ! ($day = $this->date($row[$column] ?? null))) continue;
            $visits[$row['PATEINT_ECMS_ID'].'|'.$day->toDateString()] = true;
            $this->widen($report['dates'], $day);
        }
        foreach (['payments' => 'PAYMENT_DATE', 'expenses' => 'EXPENSE_DATE', 'appointments' => 'APPOINTMENT_DATE', 'bookings' => 'BOOKING_DATE'] as $key => $column)
            foreach ($sheets[$key]['rows'] ?? [] as $row) if ($day = $this->date($row[$column] ?? null)) $this->widen($report['dates'], $day);

        $future = collect(array_merge($sheets['appointments']['rows'] ?? [], $sheets['bookings']['rows'] ?? []))
            ->filter(fn ($r) => ($d = $this->date($r['APPOINTMENT_DATE'] ?? $r['BOOKING_DATE'] ?? null)) && $d->gte(today()) && isset($ids[$r['PATEINT_ECMS_ID'] ?? '']))->count();
        $items = collect($sheets['items']['rows'] ?? []);

        $report['dates'] = array_map(fn ($d) => $d?->toDateString(), $report['dates']);
        $report['patients'] = [
            'total' => $ids->count(),
            'no_dob' => $patients->filter(fn ($p) => ! $this->date($p['DATE_OF_BIRTH'] ?? null))->count(),
            'no_gender' => $patients->filter(fn ($p) => $this->gender($p['GENDER'] ?? null) === '')->count(),
            'no_phone' => $patients->filter(fn ($p) => trim($p['CONTACT'] ?? '') === '')->count(),
            'renumbered' => $numbers->count() - $numbers->unique()->count() + $taken + $patients->filter(fn ($p) => trim($p['CLINIC_PATIENT_ID'] ?? '') === '')->count(),
            'already_in_clinic' => DB::table('patients')->where('clinic_id', $batch->clinic_id)->count(),
        ];
        $report['visits'] = count($visits);
        $known = $this->clinicDiagnoses($batch->clinic_id);
        $report['new_diagnoses'] = collect($sheets['diagnoses']['rows'] ?? [])->flatMap(fn ($r) => $this->diagnosisNames($r['DIAGNOSIS'] ?? ''))
            ->map(fn ($n) => $this->diagnosisKey($n))->unique()->reject(fn ($k) => isset($known[$k]))->count();
        $report['future_appointments'] = $future;
        $report['payments'] = ['count' => count($sheets['payments']['rows'] ?? []), 'total' => round(collect($sheets['payments']['rows'] ?? [])->sum(fn ($r) => (float) ($r['PAYMENT_AMOUNT'] ?? 0)), 2)];
        $report['expenses'] = ['count' => count($sheets['expenses']['rows'] ?? []), 'total' => round(collect($sheets['expenses']['rows'] ?? [])->sum(fn ($r) => (float) ($r['EXPENSE_AMOUNT'] ?? 0)), 2)];
        $report['items'] = ['count' => $items->count(), 'negative_stock' => $items->filter(fn ($i) => is_numeric($i['NEW_QTY'] ?? null) && (float) $i['NEW_QTY'] < 0)->count()];
        $report['staff'] = $this->staffIdentities($sheets);
        $report['unknown_files'] = $unknown;
        $report['duplicate_files'] = $duplicates;
        $report['errors'] = $errors;

        return $report;
    }

    /** People named in the export: emails in CREATED_BY, and doctors by name on appointments. */
    private function staffIdentities(array $sheets): array
    {
        $found = [];
        foreach ($sheets as $sheet) foreach ($sheet['rows'] as $row) {
            if ($email = strtolower(trim($row['CREATED_BY'] ?? ''))) $found['email:'.$email] ??= ['key' => 'email:'.$email, 'label' => $email, 'kind' => 'email', 'rows' => 0];
            if ($email) $found['email:'.$email]['rows']++;
            $doctor = trim($row['ASSIGNED_DOCTOR'] ?? $row['ASSIGN_DOCTOR'] ?? '');
            if ($doctor !== '') {
                $key = 'name:'.mb_strtolower($doctor);
                $found[$key] ??= ['key' => $key, 'label' => $doctor, 'kind' => 'doctor', 'rows' => 0];
                $found[$key]['rows']++;
            }
        }

        return array_values(collect($found)->sortByDesc('rows')->all());
    }

    /** Writes the export into the batch's clinic. Returns counts of what was created and skipped. */
    public function commit(ExternalImportBatch $batch, ?callable $progress = null): array
    {
        $this->batch = $batch;
        $this->clinicId = $batch->clinic_id;
        $this->branchId = $batch->branch_id ?: (int) DB::table('branches')->where('clinic_id', $this->clinicId)->orderByDesc('is_default')->orderBy('id')->value('id');
        if (! $this->branchId) throw new RuntimeException('The clinic has no branch to import into.');
        $settings = $batch->settings ?? [];
        $this->ownerId = (int) ($settings['owner_id'] ?? 0) ?: (int) DB::table('clinic_user')->where('clinic_id', $this->clinicId)->where('status', 'active')
            ->orderByRaw("clinic_role = 'Super Admin' DESC")->orderBy('user_id')->value('user_id');
        if (! $this->ownerId) throw new RuntimeException('The clinic has no active staff account to record the import under.');
        $this->staff = $settings['staff'] ?? [];
        $include = $settings['include'] ?? [];
        $this->counts = $this->skipped = $this->tracked = [];

        $sheets = [];
        foreach ($batch->files ?? [] as $file) if (! empty($file['sheet'])) $sheets[$file['sheet']] = $this->reader->read(Storage::disk('local')->path($file['path']))['rows'];

        $step = function (int $percent, string $label) use ($progress) { $this->flush(); $progress && $progress($percent, $label); };
        $step(5, 'patients');
        $patients = $this->importPatients($sheets['patients'] ?? []);
        $step(25, 'consultations');
        $visits = $this->importVisits($sheets, $patients, $include['new_diagnoses'] ?? true);
        if ($include['appointments'] ?? true) { $step(70, 'appointments'); $this->importAppointments($sheets, $patients, $visits); }
        $step(78, 'payments');
        $this->importPayments($sheets['payments'] ?? [], $patients);
        if ($include['items'] ?? true) { $step(86, 'products'); $this->importItems($sheets['items'] ?? []); }
        if ($include['expenses'] ?? true) { $step(92, 'expenses'); $this->importExpenses($sheets['expenses'] ?? []); }
        $step(99, 'finishing');

        ksort($this->counts);
        ksort($this->skipped);

        return ['created' => $this->counts, 'skipped' => $this->skipped];
    }

    private function importPatients(array $rows): array
    {
        $taken = DB::table('patients')->where('clinic_id', $this->clinicId)->pluck('pxnumber')->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])->all();
        $map = [];
        // gender is a required Male/Female/Other column, so a patient Cloudify has no gender for comes in as Other.
        foreach ($rows as $row) {
            $ecms = trim($row['PATEINT_ECMS_ID'] ?? '');
            if ($ecms === '' || isset($map[$ecms])) { $this->skip('patients', 'repeated or missing Cloudify id'); continue; }
            $number = trim($row['CLINIC_PATIENT_ID'] ?? '');
            if ($number === '' || isset($taken[mb_strtolower($number)])) $number = 'CLD-'.$ecms;
            $taken[mb_strtolower($number)] = true;
            $at = $this->dateTime($row['CREATED_DATE'] ?? null) ?? now();
            $map[$ecms] = $this->insert('patients', [
                'clinic_id' => $this->clinicId, 'home_branch_id' => $this->branchId, 'uuid' => (string) Str::uuid(),
                'user_id' => $this->user($row['CREATED_BY'] ?? null), 'pxnumber' => Str::limit($number, 250, ''),
                'name' => $this->name($row['FIRSTNAME'] ?? '', $row['LASTNAME'] ?? '') ?: 'Unnamed patient '.$ecms,
                'dob' => $this->date($row['DATE_OF_BIRTH'] ?? null)?->toDateString(), 'gender' => $this->gender($row['GENDER'] ?? null) ?: 'Other',
                'contact' => trim($row['CONTACT'] ?? ''), 'email' => filter_var(trim($row['EMAIL'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
                'address' => $this->text($row['ADDRESS'] ?? null), 'occupation' => $this->text($row['OCCUPATION'] ?? null),
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        return $map;
    }

    /** One consultation per patient per day, from every clinical sheet. Returns "ecms|Y-m-d" => true. */
    private function importVisits(array $sheets, array $patients, bool $addDiagnoses): array
    {
        $visits = [];
        foreach (self::VISIT_SHEETS as $key => $column) foreach ($sheets[$key] ?? [] as $row) {
            $ecms = $row['PATEINT_ECMS_ID'] ?? '';
            $day = $this->date($row[$column] ?? null);
            if (! isset($patients[$ecms]) || ! $day) { $this->skip($key, isset($patients[$ecms]) ? 'no date' : 'patient not in the patient file'); continue; }
            $visit = &$visits[$ecms.'|'.$day->toDateString()];
            $visit[$key][] = $row;
            $created = $this->dateTime($row['CREATED_DATE'] ?? null);
            if ($created && $created->isSameDay($day) && (! isset($visit['at']) || $created->lt($visit['at']))) $visit['at'] = $created;
            $visit['by'] ??= $row['CREATED_BY'] ?? null;
            unset($visit);
        }
        ksort($visits);

        $diagnoses = $this->clinicDiagnoses($this->clinicId);

        foreach ($visits as $key => $visit) {
            [$ecms, $day] = explode('|', $key);
            $at = $visit['at'] ?? Carbon::parse($day.' 12:00:00');
            $user = $this->user($visit['by'] ?? null);
            $clearance = $this->insert('cashier_patient_clearances', [
                'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'uuid' => (string) Str::uuid(), 'user_id' => $user,
                'patient_id' => $patients[$ecms], 'payment_status' => 'Paid', 'doctor_status' => 1, 'clearance_date' => $day,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $consultation = $this->insert('consultations', $this->consultationRow($visit) + [
                'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'user_id' => $user, 'patient_id' => $patients[$ecms],
                'clearance_id' => $clearance, 'created_at' => $at, 'updated_at' => $at,
            ]);

            // Each drug also as a row, for the Prescription tab's list of previous medications (the notes keep the text).
            foreach ($visit['medications'] ?? [] as $row) {
                if (! $item = $this->text($row['ITEM'] ?? null)) continue;
                $this->insert('patient_legacy_medications', [
                    'clinic_id' => $this->clinicId, 'patient_id' => $patients[$ecms], 'consultation_id' => $consultation,
                    'external_import_batch_id' => $this->batch->id, 'source' => self::SOURCE, 'prescribed_on' => $day,
                    'item' => Str::limit($item, 250, ''), 'quantity' => Str::limit(trim($row['QUANTITY'] ?? ''), 30, '') ?: null,
                    'note' => $this->text($row['NOTE'] ?? null), 'created_at' => $at, 'updated_at' => $at,
                ]);
            }

            $linked = [];
            foreach ($visit['diagnoses'] ?? [] as $row) foreach ($this->diagnosisNames($row['DIAGNOSIS'] ?? '') as $name) {
                $key = $this->diagnosisKey($name);
                $id = $diagnoses[$key] ?? null;
                if (! $id && ! $addDiagnoses) { $this->skip('diagnoses', 'name not in the clinic list, kept in the visit notes'); continue; }
                $id ??= $diagnoses[$key] = $this->insert('diagnoses', ['clinic_id' => $this->clinicId, 'name' => Str::limit(Str::ucfirst($this->withoutEye($name)), 250, ''), 'created_at' => now(), 'updated_at' => now()]);
                if (isset($linked[$id])) continue;
                $linked[$id] = true;
                $this->insert('consultation_diagnosis', ['consultation_id' => $consultation, 'diagnosis_id' => $id, 'created_at' => $at, 'updated_at' => $at]);
            }

            $refractions = array_values(array_filter($visit['refractions'] ?? [], fn ($r) => $this->hasRefraction($r)));
            if ($refractions) $this->insert('refractions', $this->refractionRow($refractions) + [
                'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'user_id' => $user, 'consultation_id' => $consultation,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        return array_fill_keys(array_keys($visits), true);
    }

    private function consultationRow(array $visit): array
    {
        $complaints = $visit['complaints'] ?? [];
        $exams = $visit['exams'] ?? [];
        $exam = $exams[0] ?? [];
        $notes = [];

        $types = collect($complaints)->pluck('NATURE_OF_COMPLAIN')->map(fn ($t) => $this->text($t))->filter()->unique();
        if ($types->isNotEmpty()) $notes[] = 'Visit type: '.$types->implode(', ');
        foreach ($visit['diagnoses'] ?? [] as $row) {
            if ($text = $this->text($row['DIAGNOSIS'] ?? null)) $notes[] = 'Diagnosis: '.str_replace(':', '; ', $text);
            if ($text = $this->text($row['EXAMINATION_NOTE'] ?? null)) $notes[] = 'Examination note: '.$text;
            if ($text = $this->text($row['DIAGNOSIS_NOTE'] ?? null)) $notes[] = 'Diagnosis note: '.$text;
            $answers = collect(['Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6'])->map(fn ($q) => ($a = $this->text($row[$q] ?? null)) ? $q.': '.str_replace(':', ', ', $a) : null)->filter();
            if ($answers->isNotEmpty()) $notes[] = 'Questionnaire answers: '.$answers->implode(' · ');
        }
        $other = collect(['OTHER_OD' => 'Other OD', 'OTHER_OS' => 'Other OS', 'OD_OPTIC_DISC' => 'Optic disc OD', 'OS_OPTIC_DISC' => 'Optic disc OS', 'OD_RETINA' => 'Retina OD', 'OS_RETINA' => 'Retina OS'])
            ->map(fn ($label, $col) => ($v = $this->text($exam[$col] ?? null)) ? $label.': '.$v : null)->filter();
        if ($other->isNotEmpty()) $notes[] = $other->implode("\n");
        foreach (array_slice($exams, 1) as $more) {
            $findings = collect($more)->except(['PATEINT_ECMS_ID', 'OUT_PATIENT_ID', 'CLINIC_PATIENT_ID', 'TITLE', 'FIRSTNAME', 'LASTNAME', 'CONTACT', 'DATE_EXAMINATION', 'CREATED_DATE', 'CREATED_BY', 'UPDATED_BY', 'UPDATED_DATE', 'COMPANY_UNIQUE_CODE', 'BRANCH_CODE', 'OCULAR_EXAM_ID'])
                ->map(fn ($v, $k) => ($v = $this->text($v)) ? str_replace('_', ' ', ucfirst(strtolower($k))).': '.$v : null)->filter();
            if ($findings->isNotEmpty()) $notes[] = "Further examination the same day:\n".$findings->implode("\n");
        }
        foreach ($visit['plans'] ?? [] as $row) if ($text = $this->text($row['MANAGEMENT_PLAN'] ?? null)) $notes[] = 'Plan: '.$text;
        $meds = collect($visit['medications'] ?? [])->map(function ($row) {
            $item = $this->text($row['ITEM'] ?? null);
            if (! $item) return null;
            $qty = trim($row['QUANTITY'] ?? '');

            return '- '.$item.($qty !== '' ? ' × '.$qty : '').(($note = $this->text($row['NOTE'] ?? null)) ? ' — '.$note : '');
        })->filter();
        if ($meds->isNotEmpty()) $notes[] = "Medications:\n".$meds->implode("\n");
        $notes[] = 'Imported from Previous Software.';

        $field = fn ($col) => $this->text($exam[$col] ?? null);

        return [
            'chiefComplaint' => collect($complaints)->map(fn ($r) => $this->text($r['COMPLAINS'] ?? null))->filter()->unique()->implode("\n") ?: 'Not recorded in previous software',
            'others' => collect($complaints)->map(fn ($r) => $this->text($r['COMPLAIN_DESC'] ?? null))->filter()->unique()->implode("\n\n") ?: null,
            'vaOD6m' => $field('OD_VISUAL_ACTIVITY'), 'vaOS6m' => $field('OS_VISUAL_ACTIVITY'),
            'lidsOD' => $field('OD_LID'), 'lidsOS' => $field('OS_LID'), 'conjunctivaOD' => $field('OD_CONJ'), 'conjunctivaOS' => $field('OS_CONJ'),
            'corneaOD' => $field('OD_CORNEA'), 'corneaOS' => $field('OS_CORNEA'), 'acOD' => $field('OD_AC'), 'acOS' => $field('OS_AC'),
            'pupilOD' => $field('OD_PUPIL'), 'pupilOS' => $field('OS_PUPIL'), 'lensOD' => $field('OD_LENS'), 'lensOS' => $field('OS_LENS'),
            'vitreousOD' => $field('OD_VITREOUS'), 'vitreousOS' => $field('OS_VITREOUS'), 'fundusOD' => $field('OD_FUNDUS'), 'fundusOS' => $field('OS_FUNDUS'),
            'cdrOD' => $field('OD_CDR'), 'cdrOS' => $field('OS_CDR'),
            'IOPOD' => $this->iop($exam['OTHER_OD'] ?? null), 'IOPOS' => $this->iop($exam['OTHER_OS'] ?? null),
            'notes' => implode("\n\n", $notes),
        ];
    }

    private function hasRefraction(array $row): bool
    {
        foreach ($row as $col => $value) if (preg_match('/_(AR|SR|CR|CA|LCVA)$|_(AIDED|UNAIDED|BCVA)$/', $col) && trim($value) !== '') return true;

        return false;
    }

    /** Subjective (SR) and auto refraction (AR) go to their columns; everything else Cloudify keeps goes to the notes. */
    private function refractionRow(array $rows): array
    {
        $r = $rows[0];
        $sph = fn ($col) => $this->power($r[$col] ?? null);
        $axis = fn ($col) => is_numeric($v = trim($r[$col] ?? '')) && $v >= 0 && $v <= 180 ? (int) $v : null;
        $va = fn (...$cols) => collect($cols)->map(fn ($c) => $this->text($r[$c] ?? null))->filter()->first();
        $hasSr = $sph('SPHERE_OD_SR') !== null || $sph('SPHERE_OS_SR') !== null;
        $sfx = $hasSr ? 'SR' : 'AR';
        $odBcva = Str::limit($va('R_EYE_D_BCVA', 'R_EYE_D_AIDED') ?? '', 20, '');
        $osBcva = Str::limit($va('L_EYE_D_BCVA', 'L_EYE_D_AIDED') ?? '', 20, '');
        $pdParts = array_filter([$r['PD_OD_'.$sfx] ?? null, $r['PD_OS_'.$sfx] ?? null], 'is_numeric');
        $lens = ['SV' => 'Single Vision', 'SINGLE VISION' => 'Single Vision', 'PROGRESSIVE' => 'Progressive', 'BIFOCAL' => 'Bifocal'];
        $lensType = $this->text($r['LENS_TYPE'] ?? null);

        $notes = [];
        $pairs = ['LENS_MATERIALS' => 'Lens material', 'LENS_TREATMENT' => 'Lens treatment', 'STYLE' => 'Frame style', 'COLOR' => 'Colour', 'RIM_TYPE' => 'Rim type', 'EXAMINATION_TYPE' => 'Examination type'];
        $details = collect($pairs)->map(fn ($label, $col) => ($v = $this->text($r[$col] ?? null)) ? $label.': '.$v : null)->filter();
        if ($details->isNotEmpty()) $notes[] = $details->implode(' · ');
        foreach (['CR' => 'Contact lens', 'CA' => 'CA'] as $sfxNote => $label) {
            $eyes = collect(['OD', 'OS'])->map(function ($eye) use ($r, $sfxNote) {
                $parts = collect(['SPHERE' => 'Sph', 'CYLINDER' => 'Cyl', 'AXIS' => 'Axis', 'ADD' => 'Add', 'PD' => 'PD'])
                    ->map(fn ($l, $c) => ($v = $this->text($r[$c.'_'.$eye.'_'.$sfxNote] ?? null)) ? $l.' '.$v : null)->filter();

                return $parts->isNotEmpty() ? $eye.' '.$parts->implode(' ') : null;
            })->filter();
            if ($eyes->isNotEmpty()) $notes[] = $label.': '.$eyes->implode('; ');
        }
        $unaided = collect(['R_EYE_D_UNAIDED' => 'RE distance', 'R_EYE_N_UNAIDED' => 'RE near', 'L_EYE_D_UNAIDED' => 'LE distance', 'L_EYE_N_UNAIDED' => 'LE near', 'BOTH_EYE_D_UNAIDED' => 'BE distance', 'BOTH_EYE_N_UNAIDED' => 'BE near'])
            ->map(fn ($l, $c) => ($v = $this->text($r[$c] ?? null)) ? $l.' '.$v : null)->filter();
        if ($unaided->isNotEmpty()) $notes[] = 'Unaided VA: '.$unaided->implode(', ');
        foreach (['SPHERE_OD_SR', 'SPHERE_OS_SR', 'SPHERE_OD_AR', 'SPHERE_OS_AR', 'ADD_OD_SR', 'ADD_OS_SR'] as $col)
            if (($v = trim($r[$col] ?? '')) !== '' && $this->power($v) === null) $notes[] = str_replace('_', ' ', $col).' as written: '.$v;
        if (strtoupper(trim($r['IS_REVIEW'] ?? '')) === 'Y' && is_numeric($r['IS_REVIEW_DURATION'] ?? null)) $notes[] = 'Review in '.(int) $r['IS_REVIEW_DURATION'].' days.';
        if (count($rows) > 1) $notes[] = (count($rows) - 1).' further refraction(s) recorded the same day in the previous software; the first is shown.';

        return [
            'objective_method' => $sph('SPHERE_OD_AR') !== null || $sph('SPHERE_OS_AR') !== null ? 'auto_refraction' : null,
            'objective_od_sphere' => $sph('SPHERE_OD_AR'), 'objective_od_cylinder' => $sph('CYLINDER_OD_AR'), 'objective_od_axis' => $axis('AXIS_OD_AR'),
            'objective_os_sphere' => $sph('SPHERE_OS_AR'), 'objective_os_cylinder' => $sph('CYLINDER_OS_AR'), 'objective_os_axis' => $axis('AXIS_OS_AR'),
            'subjective_od_sphere' => $sph('SPHERE_OD_SR'), 'subjective_od_cylinder' => $sph('CYLINDER_OD_SR'), 'subjective_od_axis' => $axis('AXIS_OD_SR'), 'subjective_od_add' => $sph('ADD_OD_SR'),
            'subjective_os_sphere' => $sph('SPHERE_OS_SR'), 'subjective_os_cylinder' => $sph('CYLINDER_OS_SR'), 'subjective_os_axis' => $axis('AXIS_OS_SR'), 'subjective_os_add' => $sph('ADD_OS_SR'),
            'subjective_od_bcva' => $odBcva ?: null, 'subjective_os_bcva' => $osBcva ?: null,
            'refractionOD' => Refractions::formatPrescription($sph('SPHERE_OD_'.$sfx), $sph('CYLINDER_OD_'.$sfx), $axis('AXIS_OD_'.$sfx)),
            'refractionOS' => Refractions::formatPrescription($sph('SPHERE_OS_'.$sfx), $sph('CYLINDER_OS_'.$sfx), $axis('AXIS_OS_'.$sfx)),
            'refractionOD_ADD' => $this->text($r['ADD_OD_'.$sfx] ?? null), 'refractionOS_ADD' => $this->text($r['ADD_OS_'.$sfx] ?? null),
            'refractionOD_distance_va' => $odBcva, 'refractionOS_distance_va' => $osBcva,
            'refractionOD_near_va' => $va('R_EYE_N_BCVA', 'R_EYE_N_AIDED'), 'refractionOS_near_va' => $va('L_EYE_N_BCVA', 'L_EYE_N_AIDED'),
            'pd' => $pdParts ? (int) round(array_sum($pdParts)) : null,
            'lensType' => $lensType ? ($lens[strtoupper($lensType)] ?? $lensType) : null,
            'refractionnotes' => $notes ? implode("\n", $notes)."\nImported from Previous Software." : 'Imported from Previous Software.',
        ];
    }

    /**
     * Past appointments come in as Seen or Missed with reminders off, so no SMS goes out for them.
     * Future ones are Pending and get the clinic's usual reminder.
     */
    private function importAppointments(array $sheets, array $patients, array $visits): void
    {
        $rows = array_merge(
            array_map(fn ($r) => ['kind' => 'appointment', 'row' => $r, 'date' => $r['APPOINTMENT_DATE'] ?? null, 'doctor' => $r['ASSIGNED_DOCTOR'] ?? null], $sheets['appointments'] ?? []),
            array_map(fn ($r) => ['kind' => 'booking', 'row' => $r, 'date' => $r['BOOKING_DATE'] ?? null, 'doctor' => $r['ASSIGN_DOCTOR'] ?? null], $sheets['bookings'] ?? []),
        );
        $types = ['REVIEW' => 'Review', 'NEW' => 'New visit', 'COLLECTION' => 'Collection', 'OTHER REASONS' => 'Appointment'];
        foreach ($rows as ['kind' => $kind, 'row' => $row, 'date' => $date, 'doctor' => $doctor]) {
            $ecms = $row['PATEINT_ECMS_ID'] ?? '';
            $day = $this->date($date);
            $table = $kind === 'booking' ? 'bookings' : 'appointments';
            if (! isset($patients[$ecms]) || ! $day) { $this->skip($table, isset($patients[$ecms]) ? 'no date' : 'patient not in the patient file'); continue; }
            $hour = is_numeric($row['TIME_HH'] ?? null) ? (int) $row['TIME_HH'] % 12 + (strtoupper(trim($row['MT'] ?? '')) === 'PM' ? 12 : 0) : 9;
            $at = $day->copy()->setTime($hour, is_numeric($row['TIME_MM'] ?? null) ? (int) $row['TIME_MM'] : 0);
            $status = strtoupper(trim($row['APPOINTMENT_STATUS'] ?? $row['BOOKING_STATUS'] ?? ''));
            $past = $day->lt(today());
            $seen = $status === 'ATTENDED' || ($status !== 'UNATTENDED' && isset($visits[$ecms.'|'.$day->toDateString()]));
            $state = match (true) {
                in_array($status, ['CANCELLED', 'CANCELED'], true) => 'Cancelled',
                ! $past && $status !== 'ATTENDED' => 'Pending',
                $seen => 'Seen',
                default => 'Missed',
            };
            $reason = $this->text($row['REASON'] ?? $row['BOOKING_REASON'] ?? null);
            $type = strtoupper(trim($row['APPOINTMENT_TYPE'] ?? ''));
            $created = $this->dateTime($row['CREATED_DATE'] ?? null) ?? $at;
            $this->insert('appointments', [
                'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'patient_id' => $patients[$ecms],
                'doctor_id' => $this->doctor($doctor), 'user_id' => $this->user($row['CREATED_BY'] ?? null),
                'title' => Str::limit($types[$type] ?? ($reason ? Str::ucfirst(mb_strtolower($reason)) : 'Consultation'), 250, ''),
                'notes' => trim(($reason ? $reason.'. ' : '').'Imported from Previous Software ('.$kind.').'),
                'scheduled_at' => $at, 'status' => $state,
                'completed_at' => $state === 'Seen' ? $at : null,
                'missed_at' => $state === 'Missed' ? $at : null, 'missed_followup_sent_at' => $state === 'Missed' ? now() : null,
                'created_at' => $created, 'updated_at' => $created,
            ] + ($past ? ['reminder_channel' => 'none'] : []));
        }
    }

    private function importPayments(array $rows, array $patients): void
    {
        $modes = ['CASH' => 'Cash', 'MTN MOMO' => 'MTN MoMo', 'VODACASH' => 'Vodafone Cash', 'CHEQUE' => 'Cheque', 'INSURANCE' => 'Insurance', 'BANK TRANSFER' => 'Bank transfer', 'CREDIT CARD' => 'Card'];
        foreach ($rows as $row) {
            $ecms = $row['PATEINT_ECMS_ID'] ?? '';
            $paid = $this->dateTime($row['PAYMENT_DATE'] ?? null);
            if (! isset($patients[$ecms]) || ! $paid || ! is_numeric($row['PAYMENT_AMOUNT'] ?? null)) { $this->skip('payments', isset($patients[$ecms]) ? 'no date or amount' : 'patient not in the patient file'); continue; }
            $mode = strtoupper(trim($row['PAYMENT_MODE'] ?? ''));
            $this->insert('patient_legacy_payments', [
                'clinic_id' => $this->clinicId, 'patient_id' => $patients[$ecms], 'external_import_batch_id' => $this->batch->id, 'source' => self::SOURCE,
                'paid_at' => $paid, 'amount' => round((float) $row['PAYMENT_AMOUNT'], 2),
                'payment_method' => $mode === '' ? null : ($modes[$mode] ?? Str::limit(Str::title(mb_strtolower($mode)), 40, '')),
                'reference' => Str::limit($this->text($row['REFERENECE'] ?? $row['REFERENCE'] ?? null) ?? '', 100, '') ?: null,
                'invoice_number' => Str::limit(trim($row['INVOICE_ID'] ?? ''), 60, '') ?: null,
                'notes' => $this->text($row['NOTE'] ?? null), 'recorded_by' => Str::limit($this->staffLabel($row['CREATED_BY'] ?? null) ?? '', 150, '') ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Prices only: Cloudify's stock counts can't be trusted, so every product starts at 0 for a fresh count. */
    private function importItems(array $rows): void
    {
        $byType = DB::table('categories')->where('clinic_id', $this->clinicId)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'type']);
        $categoryIds = [];
        $names = ['drug' => 'Drugs', 'frame' => 'Frames', 'lens' => 'Lenses', 'service' => 'Services', 'product' => 'Others'];
        $category = function (string $type) use (&$categoryIds, $byType, $names) {
            return $categoryIds[$type] ??= $byType->firstWhere('type', $type)?->id
                ?? $byType->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower($names[$type]))?->id
                ?? $this->insert('categories', ['clinic_id' => $this->clinicId, 'user_id' => $this->ownerId, 'name' => $names[$type], 'type' => $type, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        };
        $existing = DB::table('products')->where('clinic_id', $this->clinicId)->pluck('name')->mapWithKeys(fn ($n) => [mb_strtolower(trim($n)) => true])->all();
        $batches = DB::table('products')->where('clinic_id', $this->clinicId)->whereNotNull('batch_number')->pluck('batch_number')->mapWithKeys(fn ($b) => [mb_strtolower($b) => true])->all();
        foreach ($rows as $row) {
            $name = $this->text($row['ITEM_NAME'] ?? null);
            if (! $name || isset($existing[mb_strtolower($name)])) { $this->skip('items', $name ? 'already in the product list' : 'no name'); continue; }
            $existing[mb_strtolower($name)] = true;
            $group = strtoupper(trim($row['ITEM_CATEGORY'] ?? ''));
            $type = match (true) {
                in_array($group, ['EYE DROP', 'EYE DROPS', 'DRUG', 'DRUGS'], true) => 'drug',
                $group === 'FRAME' => 'frame',
                $group === 'LENS' => 'lens',
                in_array($group, ['SERVICE', 'PROCEDURE'], true) || strtoupper(trim($row['ITEM_TYPE'] ?? '')) === 'SERVICE' => 'service',
                default => 'product',
            };
            $at = $this->dateTime($row['CREATED_DATE'] ?? null) ?? now();
            $this->insert('products', [
                'clinic_id' => $this->clinicId, 'user_id' => $this->user($row['CREATED_BY'] ?? null), 'name' => Str::limit($name, 250, ''),
                'category_id' => $category($type), 'quantity' => 0, 'cost_price' => 0,
                'selling_price' => is_numeric($row['PRICE'] ?? null) ? round((float) $row['PRICE'], 2) : 0,
                'created_at' => $at, 'updated_at' => $at,
            ] + self::productDefaults($row['ITEM_ID'] ?? null, $at, $batches));
        }
    }

    /**
     * Every product here has a batch number (4-15 characters, unique in the clinic), a manufacture date and a
     * later expiry date: the Products page and the stock lists rely on them. Cloudify has none, so an imported
     * product gets batch CLD-<item id>, its Cloudify creation date, and an expiry two years from the import,
     * to be replaced with real batches and dates at the stock count. $taken is updated with the batch used.
     */
    public static function productDefaults($itemId, Carbon $created, array &$taken): array
    {
        $base = 'CLD-'.Str::limit(preg_replace('/[^A-Za-z0-9]/', '', (string) $itemId) ?: Str::upper(Str::random(6)), 9, '');
        $batch = $base;
        for ($n = 2; isset($taken[mb_strtolower($batch)]); $n++) $batch = Str::limit($base, 15 - strlen("-$n"), '')."-$n";
        $taken[mb_strtolower($batch)] = true;
        $made = $created->copy()->startOfDay()->min(today());

        return ['batch_number' => $batch, 'manufacture_date' => $made->toDateString(), 'expiry_date' => today()->addYears(2)->toDateString()];
    }

    /** History only, like payments: legacy_expenses, never the Expense Tracker or the income statement. */
    private function importExpenses(array $rows): void
    {
        foreach ($rows as $row) {
            $day = $this->date($row['EXPENSE_DATE'] ?? null);
            if (! $day || ! is_numeric($row['EXPENSE_AMOUNT'] ?? null) || (float) $row['EXPENSE_AMOUNT'] <= 0) { $this->skip('expenses', 'no date or amount'); continue; }
            $name = $this->text($row['EXPENSE_CATEGORY'] ?? null);
            $at = $this->dateTime($row['CREATED_DATE'] ?? null) ?? $day;
            $this->insert('legacy_expenses', [
                'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'external_import_batch_id' => $this->batch->id, 'source' => self::SOURCE,
                'expense_date' => $day->toDateString(), 'category' => $name ? Str::limit($name, 250, '') : null,
                'description' => Str::limit($this->text($row['EXPENSE_DESCRIPTION'] ?? null) ?? $name ?? 'Expense', 250),
                'amount' => round((float) $row['EXPENSE_AMOUNT'], 2), 'notes' => $this->text($row['NOTE'] ?? null),
                'reference' => Str::limit('CLD-'.trim($row['EXPENSE_ID'] ?? ''), 100, ''),
                'recorded_by' => Str::limit($this->staffLabel($row['CREATED_BY'] ?? null) ?? '', 150, '') ?: null,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    private function insert(string $table, array $row): int
    {
        $id = (int) DB::table($table)->insertGetId($row);
        $this->tracked[] = ['external_import_batch_id' => $this->batch->id, 'table_name' => $table, 'record_id' => $id];
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
        if (count($this->tracked) >= 500) $this->flush();

        return $id;
    }

    /** Writes the list of created rows; also called when an import fails so its partial rows can be undone. */
    public function flush(): void
    {
        foreach (array_chunk($this->tracked, 500) as $chunk) DB::table('external_import_rows')->insert($chunk);
        $this->tracked = [];
    }

    private function skip(string $sheet, string $reason): void
    {
        $this->skipped[$sheet][$reason] = ($this->skipped[$sheet][$reason] ?? 0) + 1;
    }

    /** The clinic account chosen for a Cloudify email or name; the owner when none was chosen. */
    private function user(?string $email): int
    {
        $email = strtolower(trim((string) $email));

        return (int) ($email !== '' ? ($this->staff['email:'.$email] ?? 0) : 0) ?: $this->ownerId;
    }

    private function doctor(?string $name): ?int
    {
        $name = mb_strtolower(trim((string) $name));

        return $name !== '' && ! empty($this->staff['name:'.$name]) ? (int) $this->staff['name:'.$name] : null;
    }

    private function staffLabel(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') return null;
        $id = $this->staff['email:'.$email] ?? null;

        return $id ? (DB::table('users')->where('id', $id)->value('name') ?? $email) : $email;
    }

    /** Excel stores dates as day numbers since 1899-12-30; some exports write them as text. */
    public function dateTime($value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (is_numeric($value)) return (float) $value > 1 && (float) $value < 2958465 ? Carbon::create(1899, 12, 30, 0, 0, 0)->addSeconds((int) round((float) $value * 86400)) : null;
        try { return Carbon::parse($value); } catch (\Throwable) { return null; }
    }

    private function date($value): ?Carbon
    {
        return $this->dateTime($value)?->startOfDay();
    }

    private function widen(array &$range, Carbon $day): void
    {
        if (! $range[0] || $day->lt($range[0])) $range[0] = $day;
        if (! $range[1] || $day->gt($range[1])) $range[1] = $day;
    }

    private function text($value): ?string
    {
        $value = trim(preg_replace("/[ \t\x{00A0}]+/u", ' ', preg_replace("/\n{3,}/", "\n\n", (string) $value)));

        return $value === '' ? null : $value;
    }

    /** "ROSE ASEBI" becomes "Rose Asebi"; names typed in mixed case are left as typed. */
    private function name(string $first, string $last): string
    {
        $name = $this->text($first.' '.$last) ?? '';

        return $name !== '' && mb_strtoupper($name) === $name ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE) : $name;
    }

    private function gender($value): string
    {
        return match (strtolower(trim((string) $value))) { 'm', 'male' => 'Male', 'f', 'female' => 'Female', 'other', 'o' => 'Other', default => '' };
    }

    private function power($value): ?float
    {
        $value = str_replace(['−', ' '], ['-', ''], trim((string) $value));
        if (in_array(strtolower($value), ['pl', 'plano', 'plan'], true)) return 0.0;

        return is_numeric($value) && abs((float) $value) < 100 ? round((float) $value, 2) : null;
    }

    private function iop($value): ?float
    {
        return preg_match('/(\d{1,2}(?:\.\d+)?)\s*mm\s*hg/i', (string) $value, $m) ? (float) $m[1] : null;
    }

    private function diagnosisNames(string $text): array
    {
        return collect(explode(':', $text))->map(fn ($n) => $this->text($n))->filter()
            ->map(fn ($n) => Str::ucfirst($n))->unique(fn ($n) => mb_strtolower($n))->values()->all();
    }

    /** "Cataract BE", "cataract (be)" and "Cataract" are one diagnosis; the eye stays in the visit notes. */
    private function withoutEye(string $name): string
    {
        $eye = '(BE|OU|RE|LE|OD|OS|B\/E|BOTH EYES|RIGHT EYE|LEFT EYE|LE\s*>\s*RE|RE\s*>\s*LE)';
        do {
            $before = $name;
            $name = trim(preg_replace('/[\s,;.]*\(?\s*'.$eye.'\s*\)?[\s.,;]*$/i', '', $name));
        } while ($name !== $before && $name !== '');

        return $name === '' ? trim($before) : $name;
    }

    private function diagnosisKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', $this->withoutEye($name)));
    }

    /** The clinic's diagnosis list keyed by diagnosisKey(). */
    private function clinicDiagnoses(int $clinicId): array
    {
        $map = [];
        foreach (DB::table('diagnoses')->where('clinic_id', $clinicId)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name']) as $d) $map[$this->diagnosisKey($d->name)] ??= $d->id;

        return $map;
    }
}
