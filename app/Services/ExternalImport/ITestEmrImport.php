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
 * iTestEMR (Optocodes Innovations) has no export, so its data comes as a capture of its own pages:
 * the Patient's Directory saved as a .csv (one row per patient), every patient's "Print All Records"
 * page saved into one .html file, and the stock pages saved as .csv files. This recognises each file,
 * checks it (analyze) and writes it into an existing clinic (commit), listing every row written in
 * external_import_rows so ExternalImportRollback can undo it.
 *
 * Each iTestEMR visit becomes one consultation with a cleared visit behind it. Old payments are not in
 * the capture; the clinic's books start when it starts using this system.
 */
class ITestEmrImport
{
    use ImportHelpers;

    public const SOURCE = 'itestemr';

    /** key => [label, columns that identify a .csv (null for the records page), where it goes] */
    public const SHEETS = [
        'patients' => ["Patient's Directory", ['Patients ID', 'Name', 'Next of Kin', 'First Visit'], 'Patients'],
        'records' => ['Patient folders (Print All Records)', null, 'Consultations, diagnoses, refractions and previous medications'],
        'frames' => ['Frame inventory', ['Frame Code', 'Cost', 'Price Low', 'Price High'], 'Products: frames (prices and cost, stock 0)'],
        'accessories' => ['Accessories', ['Accessory', 'Sell', 'Qty Left'], 'Products: accessories (prices, stock 0)'],
        'lenses' => ['Lens prices', ['LensID', 'Lens Type', 'Sph Range', 'Sell'], 'Products: lenses (prices and cost, stock 0)'],
        'drugs' => ['Drug list', ['Drug Name', 'Bought', 'Sold', 'Route'], 'Products: drugs (no price in the capture, stock 0)'],
        'staff' => ['Staff directory', ['Name', 'Role', 'Registered', 'Staff ID'], 'Not imported: add staff accounts by hand'],
        'drug_stocking' => ['Drug stocking history', ['Drug', 'Supplier', 'Expiry Date', 'Stocked By'], 'Not imported: kept with the backup'],
        'drug_expiry' => ['Drug expiry report', ['Stock ID', 'Drug Name', 'Notice'], 'Not imported: kept with the backup'],
        'frame_stocking' => ['Frame stocking history', ['Stock ID#', 'Stock Date', 'Total Cost'], 'Not imported: kept with the backup'],
        'lens_types' => ['Lens types', ['ID', 'Lens Type'], 'Not imported: lens names are in the lens prices'],
        'lens_stocking' => ['Lens stocking history', ['Lens', 'Index', 'Reflectivity', 'Qty', 'Date'], 'Not imported: kept with the backup'],
        // Only an administrator login can read these.
        'payments' => ['Payments (Cash Payment Report)', ['Date', 'No', 'Patient', 'Mode', 'Amount', 'Taken By'], 'Payment history on the patient record (not finance)'],
        'balances' => ['Outstanding balances (Cash Credit)', ['Invoice', 'Patient ID', 'Net TopUp', 'Amount Paid', 'Balance'], 'Unpaid balances the cashier collects (Outstanding balances)'],
        'spectacles' => ['Spectacle orders', ['Order ID', 'Patient ID', 'RE Rx', 'LE Rx', 'Frame Price'], 'Visit notes: spectacle orders (history, not the live board)'],
        'services' => ['Services and products price list', ['Product ID', 'Product/Service', 'Price', 'Type'], 'Products: services and registration with prices'],
        'drug_prices' => ['Drug inventory with prices', ['Drug Name', 'Quantity Bought', 'Unit Price (₵)', 'Selling Price (₵)'], 'Products: drugs with cost and selling price (stock 0)'],
    ];

    /** Options the platform admin can switch off before importing. */
    public const OPTIONAL = ['new_diagnoses' => 'Diagnosis names missing from the clinic list', 'appointments' => 'Upcoming review dates as appointments', 'items' => 'Frames, accessories, lenses, drugs and services as products',
        'balances' => 'Unpaid balances from the previous software, to collect'];

    public const FILE_TYPES = ['csv', 'html', 'htm'];

    private int $clinicId;
    private int $branchId;
    private int $ownerId;
    private array $staff = [];
    /** patient id => [Y-m-d => consultation id], filled while visits are imported. */
    private array $visitIds = [];
    /** consultation id => true for the visits this run created (notes on any other visit are listed for undo). */
    private array $ownVisits = [];

    public function __construct(private CsvTableReader $csv, private ITestEmrRecordsReader $records) {}

    /** The sheet key a .csv's header row belongs to, or null when it isn't one of the captured pages. */
    public static function detect(array $headers): ?string
    {
        $best = null;
        foreach (self::SHEETS as $key => [, $signature]) {
            if ($signature && ! array_diff($signature, $headers) && (! $best || count($signature) > count(self::SHEETS[$best][1]))) $best = $key;
        }

        return $best;
    }

    /** Reads every stored file and returns the dry-run report. Nothing is written to the clinic. */
    public function analyze(ExternalImportBatch $batch): array
    {
        [$sheets, $files, $errors, $unknown, $duplicates] = $this->load($batch->files ?? [], true);
        $batch->files = $files;
        // A big clinic comes in parts (patient stages, then LISTS and ORDERS): patients an earlier part brought in count as known.
        $earlier = $this->earlierPatients($batch->clinic_id);
        if (! isset($sheets['patients']) && ! $earlier) $errors[] = "The Patient's Directory file (patients.csv) is needed: every record is linked to a patient. For a clinic copied in stages, import the patient stages first.";

        $patients = collect($sheets['patients'] ?? []);
        $numbers = $patients->pluck('Patients ID')->map(fn ($n) => trim($n))->filter();
        $repeat = $numbers->filter(fn ($n) => isset($earlier[mb_strtolower($n)]));
        $new = $numbers->reject(fn ($n) => isset($earlier[mb_strtolower($n)]));
        $known = $numbers->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])->all() + array_map(fn () => true, $earlier);
        $taken = DB::table('patients')->where('clinic_id', $batch->clinic_id)->whereIn(DB::raw('LOWER(pxnumber)'), $new->map(fn ($n) => mb_strtolower($n))->unique()->values()->all())->count();

        $folders = collect($sheets['records'] ?? []);
        $newFolders = $folders->filter(fn ($f) => isset($known[mb_strtolower($f['number'])]) && ! isset($earlier[mb_strtolower($f['number'])]));
        $visits = $newFolders->flatMap(fn ($f) => $f['visits']);
        $report = ['sheets' => [], 'orphans' => [], 'dates' => [null, null]];
        foreach (self::SHEETS as $key => [$label, , $into]) {
            $rows = $sheets[$key] ?? null;
            $report['sheets'][$key] = ['label' => $label, 'into' => $into, 'file' => $this->fileFor($files, $key), 'rows' => $rows === null ? null : count($rows)];
        }
        if ($orphans = $folders->reject(fn ($f) => isset($known[mb_strtolower($f['number'])]))->count()) $report['orphans']['records'] = $orphans;
        foreach ($visits as $visit) if ($day = $this->date($visit['date'] ?? null)) $this->widen($report['dates'], $day);
        foreach ($patients as $p) if ($day = $this->date($p['First Visit'] ?? null)) $this->widen($report['dates'], $day);

        $withFolder = $folders->pluck('number')->map(fn ($n) => mb_strtolower($n))->flip();
        $diagnoses = $this->clinicDiagnoses($batch->clinic_id);
        $report['dates'] = array_map(fn ($d) => $d?->toDateString(), $report['dates']);
        $report['part'] = [
            'earlier_patients' => count($earlier),
            'already_imported' => $repeat->count(),
            'already_imported_folders' => $folders->filter(fn ($f) => isset($earlier[mb_strtolower($f['number'])]))->count(),
            // A period copied again: visits patients from earlier parts had since, on days not yet here.
            'later_visits' => $earlier ? array_sum(array_map(fn ($w) => count($w[1]), $this->laterVisits($folders->all(), $earlier, $this->earlierVisits($batch->clinic_id)))) : 0,
            'internal_ids' => $patients->pluck('internal_id')->filter(fn ($v) => ctype_digit((string) $v))->map(fn ($v) => (int) $v)->pipe(fn ($c) => $c->isEmpty() ? null : [$c->min(), $c->max()]),
        ];
        $report['patients'] = [
            'total' => $patients->count(),
            'no_dob' => $patients->filter(fn ($p) => ! $this->date($p['DOB'] ?? null))->count(),
            'no_gender' => $patients->filter(fn ($p) => $this->gender($p['Sex'] ?? null) === '')->count(),
            'no_phone' => $patients->filter(fn ($p) => trim($p['Contact'] ?? '') === '')->count(),
            'renumbered' => $new->count() - $new->map(fn ($n) => mb_strtolower($n))->unique()->count() + $taken + $patients->filter(fn ($p) => trim($p['Patients ID'] ?? '') === '')->count(),
            'already_in_clinic' => DB::table('patients')->where('clinic_id', $batch->clinic_id)->count(),
            'no_folder' => $folders->isEmpty() ? [] : $new->reject(fn ($n) => isset($withFolder[mb_strtolower($n)]))->values()->all(),
            'next_of_kin' => $patients->filter(fn ($p) => trim($p['Next of Kin'] ?? '') !== '' || trim($p['Next of Kin Contact'] ?? '') !== '')->count(),
        ];
        $report['visits'] = $newFolders->sum(fn ($f) => count($this->byDay($f['visits'])));
        $report['same_day_visits'] = $visits->count() - $report['visits'];
        $report['new_diagnoses'] = $visits->flatMap(fn ($v) => $v['diagnoses'] ?? [])->map(fn ($n) => $this->diagnosisKey($n))->unique()->reject(fn ($k) => isset($diagnoses[$k]))->count();
        $report['future_appointments'] = $newFolders->filter(fn ($f) => $this->nextReview($f['visits']))->count();
        $names = $this->patientsByName(array_merge($patients->reject(fn ($p) => isset($earlier[mb_strtolower(trim($p['Patients ID'] ?? ''))]))->all(), $this->directoryOf($earlier)), fn ($p) => $p['Patients ID'] ?? '');
        $payments = collect($sheets['payments'] ?? []);
        $report['payments'] = ['count' => $payments->count(), 'total' => round($payments->sum(fn ($r) => $this->money($r['Amount'] ?? 0)), 2),
            'unmatched' => $payments->filter(fn ($r) => count($names[$this->nameKey($r['Patient'] ?? '')] ?? []) !== 1)->count()];
        $balances = collect($sheets['balances'] ?? [])->filter(fn ($r) => $this->money($r['Balance'] ?? 0) > 0);
        $report['balances'] = ['count' => $balances->count(), 'total' => round($balances->sum(fn ($r) => $this->money($r['Balance'])), 2),
            'unknown_patients' => $balances->reject(fn ($r) => isset($known[mb_strtolower(trim($r['Patient ID'] ?? ''))]))->count()];
        $report['spectacles'] = count($sheets['spectacles'] ?? []);
        $report['expenses'] = ['count' => 0, 'total' => 0];
        $report['items'] = ['count' => collect(['frames', 'accessories', 'lenses', isset($sheets['drug_prices']) ? 'drug_prices' : 'drugs'])->sum(fn ($k) => count($sheets[$k] ?? []))
            + collect($sheets['services'] ?? [])->reject(fn ($r) => strcasecmp(trim($r['Type'] ?? ''), 'Accessory') === 0)->count(), 'negative_stock' => 0,
            'drug_prices' => isset($sheets['drug_prices'])];
        $report['staff'] = $this->staffIdentities($visits);
        $report['unknown_files'] = $unknown;
        $report['duplicate_files'] = $duplicates;
        $report['errors'] = $errors;

        return $report;
    }

    /**
     * Reads the stored files: [sheets, files with the sheet each holds, errors, unknown file names, duplicate file names].
     * The records page becomes a list of patient folders; every .csv becomes its rows.
     */
    private function load(array $files, bool $strict): array
    {
        $sheets = $hashes = $errors = $unknown = $duplicates = [];
        foreach ($files as $i => $file) {
            $path = \App\Support\ImportFiles::localPath($file);
            $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            try {
                if (in_array($ext, ['html', 'htm'], true)) {
                    $key = ITestEmrRecordsReader::looks((string) file_get_contents($path)) ? 'records' : null;
                    $rows = $key ? $this->records->read($path) : [];
                } else {
                    $table = $this->csv->read($path);
                    $key = self::detect($table['headers']);
                    $rows = $table['rows'];
                }
            } catch (\Throwable $e) {
                $files[$i]['sheet'] = null;
                $errors[] = $file['name'].': '.$e->getMessage();
                continue;
            }
            $files[$i]['sheet'] = $key;
            $files[$i]['rows'] = count($rows);
            if (! $key) { $unknown[] = $file['name']; continue; }
            $hash = hash('sha256', json_encode($rows));
            if (isset($sheets[$key])) {
                if ($hashes[$key] === $hash) { $files[$i]['sheet'] = null; $files[$i]['duplicate'] = true; $duplicates[] = $file['name']; continue; }
                if ($strict) $errors[] = 'Two different '.self::SHEETS[$key][0].' files were uploaded ('.$this->fileFor($files, $key).' and '.$file['name'].'). Upload only one.';
                continue;
            }
            $hashes[$key] = $hash;
            $sheets[$key] = $rows;
        }

        return [$sheets, $files, $errors, $unknown, $duplicates];
    }

    private function fileFor(array $files, string $key): ?string
    {
        foreach ($files as $file) if (($file['sheet'] ?? null) === $key) return $file['name'];

        return null;
    }

    /** Doctors named on visits and refractions, to match to the clinic's accounts. */
    private function staffIdentities($visits): array
    {
        $found = [];
        $add = function (?string $name) use (&$found) {
            if (($name = trim((string) $name)) === '') return;
            $key = 'name:'.mb_strtolower($name);
            $found[$key] ??= ['key' => $key, 'label' => $name, 'kind' => 'clinician', 'rows' => 0];
            $found[$key]['rows']++;
        };
        foreach ($visits as $visit) { $add($visit['doctor'] ?? null); $add($visit['refraction']['by'] ?? null); }

        return array_values(collect($found)->sortByDesc('rows')->all());
    }

    /** Writes the capture into the batch's clinic. Returns counts of what was created and skipped. */
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
        $this->counts = $this->skipped = $this->tracked = $this->visitIds = $this->ownVisits = $this->changed = [];

        [$sheets] = $this->load(array_filter($batch->files ?? [], fn ($f) => ! empty($f['sheet'])), false);

        $step = function (int $percent, string $label) use ($progress) { $this->flush(); $progress && $progress($percent, $label); };
        // Patients and visits an earlier part of the same clinic brought in: linked to, never added again.
        $earlier = $this->earlierPatients($this->clinicId);
        $this->visitIds = $this->earlierVisits($this->clinicId);
        $step(5, 'patients');
        $patients = $this->importPatients($sheets['patients'] ?? [], $earlier);
        $step(20, 'consultations');
        $this->importVisits($sheets['records'] ?? [], $patients, $include['new_diagnoses'] ?? true, $include['appointments'] ?? true, $earlier);
        $linked = $patients + array_map(fn ($p) => $p['id'], $earlier);
        $directory = array_merge(array_values(array_filter($sheets['patients'] ?? [], fn ($p) => ! isset($earlier[mb_strtolower(trim($p['Patients ID'] ?? ''))]))), $this->directoryOf($earlier));
        $step(80, 'spectacle orders');
        $this->importSpectacles($sheets['spectacles'] ?? [], $linked);
        $step(84, 'payments');
        $this->importPayments($sheets['payments'] ?? [], $directory, $linked);
        if ($include['balances'] ?? true) { $step(88, 'balances'); $this->importBalances($sheets['balances'] ?? [], $linked); }
        if ($include['items'] ?? true) { $step(90, 'products'); $this->importItems($sheets); }
        $step(99, 'finishing');

        ksort($this->counts);
        ksort($this->skipped);

        return ['created' => $this->counts, 'skipped' => $this->skipped];
    }

    /**
     * Patients that earlier finished iTestEMR imports of this clinic brought in (a clinic copied in stages):
     * lower-cased patient number => [id, name]. A part never adds them again; its records link to them.
     */
    private function earlierPatients(int $clinicId): array
    {
        $map = [];
        DB::table('external_import_rows as r')->join('external_import_batches as b', 'b.id', '=', 'r.external_import_batch_id')
            ->join('patients as p', 'p.id', '=', 'r.record_id')
            ->where('b.clinic_id', $clinicId)->where('b.source', self::SOURCE)->where('b.status', 'completed')->where('r.table_name', 'patients')
            ->select(['p.id', 'p.pxnumber', 'p.name'])->orderBy('p.id')
            ->chunk(5000, function ($rows) use (&$map) {
                foreach ($rows as $p) $map[mb_strtolower(trim($p->pxnumber))] ??= ['id' => (int) $p->id, 'number' => $p->pxnumber, 'name' => $p->name];
            });

        return $map;
    }

    /** patient id => [Y-m-d => consultation id] for the visits earlier finished iTestEMR imports of this clinic brought in. */
    private function earlierVisits(int $clinicId): array
    {
        $map = [];
        DB::table('external_import_rows as r')->join('external_import_batches as b', 'b.id', '=', 'r.external_import_batch_id')
            ->join('consultations as c', 'c.id', '=', 'r.record_id')
            ->where('b.clinic_id', $clinicId)->where('b.source', self::SOURCE)->where('b.status', 'completed')->where('r.table_name', 'consultations')
            ->select(['c.id', 'c.patient_id', 'c.created_at'])->orderBy('c.id')
            ->chunk(5000, function ($rows) use (&$map) {
                foreach ($rows as $c) $map[(int) $c->patient_id][Carbon::parse($c->created_at)->toDateString()] ??= (int) $c->id;
            });

        return $map;
    }

    /** Earlier parts' patients as directory rows, so payments (matched by name) can find them. */
    private function directoryOf(array $earlier): array
    {
        return array_values(array_map(fn ($p) => ['Patients ID' => $p['number'], 'Name' => $p['name']], $earlier));
    }

    /** Returns lower-cased iTestEMR patient number => new patient id (patients an earlier part brought in are left out). */
    private function importPatients(array $rows, array $earlier = []): array
    {
        $taken = DB::table('patients')->where('clinic_id', $this->clinicId)->pluck('pxnumber')->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])->all();
        $map = [];
        foreach ($rows as $i => $row) {
            $original = trim($row['Patients ID'] ?? '');
            if ($original !== '' && isset($earlier[mb_strtolower($original)])) { $this->skip('patients', 'already brought in by an earlier part'); continue; }
            if ($original !== '' && isset($map[mb_strtolower($original)])) { $this->skip('patients', 'repeated patient number'); continue; }
            $number = $original;
            if ($number === '' || isset($taken[mb_strtolower($number)])) $number = 'ITE-'.(trim($row['internal_id'] ?? '') ?: $i + 1);
            $taken[mb_strtolower($number)] = true;
            $at = $this->date($row['First Visit'] ?? null)?->setTime(8, 0) ?? now();
            $id = $this->insert('patients', [
                'clinic_id' => $this->clinicId, 'home_branch_id' => $this->branchId, 'uuid' => (string) Str::uuid(),
                'user_id' => $this->ownerId, 'pxnumber' => Str::limit($number, 250, ''),
                'name' => Str::limit($this->text($row['Name'] ?? null) ?? 'Unnamed patient '.$number, 250, ''),
                'dob' => $this->date($row['DOB'] ?? null)?->toDateString(), 'gender' => $this->gender($row['Sex'] ?? null) ?: 'Other',
                'contact' => Str::limit(trim($row['Contact'] ?? ''), 250, ''), 'email' => filter_var(trim($row['E-mail'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
                'address' => $this->text($row['Address'] ?? null), 'occupation' => $this->text($row['Occupation'] ?? null),
                'created_at' => $at, 'updated_at' => $at,
            ]);
            if ($original !== '') $map[mb_strtolower($original)] = $id;
        }

        return $map;
    }

    /**
     * A clinic copied again for a period (a top-up before it switches over): for patients an earlier part brought in,
     * the visits after their last imported one, on days with no visit here yet (the clinic may have seen them here
     * since). lower-cased patient number => [patient id, visits by day]. Visits up to the last imported one never
     * come in again, so one the clinic removed after the import stays removed.
     */
    private function laterVisits(array $folders, array $earlier, array $imported): array
    {
        $want = [];
        foreach ($folders as $folder) {
            $key = mb_strtolower($folder['number']);
            if (! isset($earlier[$key])) continue;
            $id = $earlier[$key]['id'];
            $last = ($imported[$id] ?? []) ? max(array_keys($imported[$id])) : null;
            $days = array_values(array_filter($this->byDay($folder['visits']), fn ($v) => $last === null || $this->date($v['date'])->toDateString() > $last));
            if ($days) $want[$key] = [$id, $days];
        }
        $taken = [];
        foreach (array_chunk(array_column($want, 0), 1000) as $ids) {
            foreach (DB::table('cashier_patient_clearances')->whereIn('patient_id', $ids)->get(['patient_id', 'clearance_date']) as $c) $taken[$c->patient_id][Carbon::parse($c->clearance_date)->toDateString()] = true;
        }
        foreach ($want as $key => [$id, $days]) {
            $days = array_values(array_filter($days, fn ($v) => ! isset($taken[$id][$this->date($v['date'])->toDateString()])));
            if ($days) $want[$key] = [$id, $days]; else unset($want[$key]);
        }

        return $want;
    }

    private function importVisits(array $folders, array $patients, bool $addDiagnoses, bool $appointments, array $earlier = []): void
    {
        $diagnoses = $this->clinicDiagnoses($this->clinicId);
        $later = $this->laterVisits($folders, $earlier, $this->visitIds);
        foreach ($folders as $folder) {
            $key = mb_strtolower($folder['number']);
            $patient = $patients[$key] ?? null;
            $days = null;
            if (! $patient && isset($later[$key])) [$patient, $days] = $later[$key];
            if (! $patient) { $this->skip('records', isset($earlier[$key]) ? 'patient and visits already brought in by an earlier part' : 'patient not in the directory file'); continue; }
            if ($days === null) {
                foreach ($folder['visits'] as $visit) if (! $this->date($visit['date'] ?? null)) $this->skip('records', 'visit without a date');
            } else {
                $this->counts['later visits for patients already here'] = ($this->counts['later visits for patients already here'] ?? 0) + count($days);
            }
            foreach ($days ?? $this->byDay($folder['visits']) as $visit) {
                $day = $this->date($visit['date']);
                $at = $this->dateTime($visit['time'] ?? null);
                $at = $at && $at->isSameDay($day) ? $at : $day->copy()->setTime(12, 0);
                $user = $this->user($visit['doctor'] ?? null);
                $clearance = $this->insert('cashier_patient_clearances', [
                    'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'uuid' => (string) Str::uuid(), 'user_id' => $user,
                    'patient_id' => $patient, 'payment_status' => 'Paid', 'doctor_status' => 1, 'clearance_date' => $day->toDateString(),
                    'created_at' => $at, 'updated_at' => $at,
                ]);
                $consultation = $this->insert('consultations', $this->consultationRow($visit) + [
                    'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'user_id' => $user, 'patient_id' => $patient,
                    'clearance_id' => $clearance, 'created_at' => $at, 'updated_at' => $at,
                ]);
                $this->visitIds[$patient][$day->toDateString()] = $consultation;
                $this->ownVisits[$consultation] = true;

                foreach (array_merge($visit['medications'] ?? [], $visit['external_medications'] ?? []) as $line) {
                    [$item, $quantity] = $this->medication($line);
                    if (! $item) continue;
                    $this->insert('patient_legacy_medications', [
                        'clinic_id' => $this->clinicId, 'patient_id' => $patient, 'consultation_id' => $consultation,
                        'external_import_batch_id' => $this->batch->id, 'source' => self::SOURCE, 'prescribed_on' => $day->toDateString(),
                        'item' => Str::limit($item, 250, ''), 'quantity' => $quantity !== null && mb_strlen($quantity) <= 30 ? $quantity : null,
                        'note' => $quantity !== null && mb_strlen($quantity) > 30 ? $quantity : null, 'created_at' => $at, 'updated_at' => $at,
                    ]);
                }

                $linked = [];
                foreach ($visit['diagnoses'] ?? [] as $name) {
                    $id = $this->diagnosisId($name, $diagnoses, $addDiagnoses);
                    if (! $id) { $this->skip('diagnoses', 'name not in the clinic list, kept in the visit notes'); continue; }
                    if (isset($linked[$id])) continue;
                    $linked[$id] = true;
                    $this->insert('consultation_diagnosis', ['consultation_id' => $consultation, 'diagnosis_id' => $id, 'created_at' => $at, 'updated_at' => $at]);
                }

                if ($row = $this->refractionRow($visit)) $this->insert('refractions', $row + [
                    'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'consultation_id' => $consultation,
                    'user_id' => $this->user($visit['refraction']['by'] ?? $visit['doctor'] ?? null), 'created_at' => $at, 'updated_at' => $at,
                ]);
            }

            if ($appointments && ($next = $this->nextReview($folder['visits']))) {
                [$when, $visit] = $next;
                // A later visit's review, once: not when the review came from a visit already here, or is already booked.
                if ($days !== null && (! in_array($visit['date'], array_column($days, 'date'), true)
                    || DB::table('appointments')->where('patient_id', $patient)->whereDate('scheduled_at', $when->toDateString())->exists())) continue;
                $this->insert('appointments', [
                    'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'patient_id' => $patient,
                    'doctor_id' => $this->doctor($visit['doctor'] ?? null), 'user_id' => $this->ownerId, 'title' => 'Review',
                    'notes' => trim('Review'.(($visit['review']['period'] ?? null) ? ' after '.$visit['review']['period'] : '').' from the visit of '.Carbon::parse($visit['date'])->format('d M Y').'. Imported from Previous Software.'),
                    'scheduled_at' => $when, 'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * One visit per day: a patient can be seen only once a day here, so two iTestEMR visits on the same day
     * become one consultation holding both (lists joined, the first reading kept, the later review date).
     */
    private function byDay(array $visits): array
    {
        $days = [];
        foreach ($visits as $visit) if ($this->date($visit['date'] ?? null)) $days[$visit['date']][] = $visit;
        ksort($days);

        return array_map(function (array $same) {
            usort($same, fn ($a, $b) => strcmp($a['time'] ?? '', $b['time'] ?? ''));
            $visit = array_shift($same);
            foreach ($same as $other) {
                foreach (['diagnoses', 'medications', 'external_medications'] as $list) $visit[$list] = array_values(array_unique(array_merge($visit[$list] ?? [], $other[$list] ?? [])));
                foreach ($other['history'] ?? [] as $label => $text) {
                    $mine = $visit['history'][$label] ?? null;
                    if ($text !== null && $text !== $mine) $visit['history'][$label] = $mine === null ? $text : $mine."\n".$text;
                }
                if (! array_filter($visit['vision']['unaided'] ?? []) && isset($other['vision'])) $visit['vision'] = $other['vision'];
                foreach (['refraction', 'spectacles'] as $rx) if (! $this->hasPrescription($visit[$rx] ?? []) && ! ($visit[$rx]['auto'] ?? null) && ! empty($other[$rx])) $visit[$rx] = $other[$rx];
                if ($other['plan'] ?? null) $visit['plan'] = trim(($visit['plan'] ?? '')."\n".$other['plan']);
                if (! empty($other['review']['next'])) $visit['review'] = $other['review'];
                $visit['visit_id'] = trim(($visit['visit_id'] ?? '').', '.($other['visit_id'] ?? ''), ', ');
                $visit['merged'] = ($visit['merged'] ?? 1) + 1;
            }

            return $visit;
        }, array_values($days));
    }

    /** The latest visit's review date, when it is still ahead: [when, visit] or null. */
    private function nextReview(array $visits): ?array
    {
        $latest = collect($visits)->filter(fn ($v) => $this->date($v['date'] ?? null))->sortByDesc(fn ($v) => ($v['date'] ?? '').' '.($v['time'] ?? ''))->first();
        if (! $latest || ! ($day = $this->date($latest['review']['next'] ?? null)) || $day->lt(today())) return null;
        $time = preg_match('/^(\d{1,2}):(\d{2})/', (string) ($latest['review']['time'] ?? ''), $t) ? [(int) $t[1], (int) $t[2]] : [9, 0];

        return [$day->setTime(...$time), $latest];
    }

    private function consultationRow(array $visit): array
    {
        $history = $visit['history'] ?? [];
        $vision = $visit['vision'] ?? [];
        $notes = [];

        $vitals = collect($vision['vitals'] ?? [])->reject(fn ($v) => ! preg_match('/\d/', $v))->map(fn ($v, $label) => $label.' '.$v);
        if ($vitals->isNotEmpty()) $notes[] = 'Vitals: '.$vitals->implode(' · ');
        $eyes = fn (array $row) => collect(['od' => 'OD', 'os' => 'OS', 'ou' => 'OU'])->map(fn ($l, $k) => ($row[$k] ?? null) !== null ? $l.' '.$row[$k] : null)->filter()->implode(', ');
        if ($line = $eyes($vision['pinhole'] ?? [])) $notes[] = 'Pinhole VA: '.$line;
        if (($vision['unaided']['ou'] ?? null) !== null) $notes[] = 'Unaided VA OU: '.$vision['unaided']['ou'];
        $iop = $vision['iop'] ?? [];
        if (($line = $eyes($iop)) && ($this->iop($iop['od'] ?? null) === null && ($iop['od'] ?? null) !== null || $this->iop($iop['os'] ?? null) === null && ($iop['os'] ?? null) !== null || ($iop['ou'] ?? null) !== null)) $notes[] = 'IOP as recorded: '.$line;
        if ($visit['diagnoses'] ?? []) $notes[] = 'Diagnosis: '.implode('; ', $visit['diagnoses']);
        if ($visit['medications'] ?? []) $notes[] = "Medications:\n- ".implode("\n- ", $visit['medications']);
        if ($visit['external_medications'] ?? []) $notes[] = "Medications bought outside:\n- ".implode("\n- ", $visit['external_medications']);
        if ($visit['plan'] ?? null) $notes[] = 'Plan: '.$visit['plan'];
        if (! empty($visit['refraction']) && $this->hasPrescription($visit['spectacles'] ?? [])) $notes[] = 'Spectacle prescription: '.$this->describe($visit['spectacles']);
        $review = $visit['review'] ?? [];
        if ($review['period'] ?? null) $notes[] = 'Review in '.$review['period'].(($next = $this->date($review['next'] ?? null)) ? ' (next visit '.$next->format('d M Y').')' : '').'.';
        if ($review['status'] ?? null) $notes[] = 'Consultation status: '.$review['status'];
        if ($visit['doctor'] ?? null) $notes[] = 'Seen by '.$visit['doctor'].' in the previous software.';
        if (($visit['merged'] ?? 1) > 1) $notes[] = 'The previous software had '.$visit['merged'].' visits on this day; they are kept together here.';
        $notes[] = 'Imported from Previous Software'.(($visit['visit_id'] ?? '') !== '' ? ' (visit '.$visit['visit_id'].')' : '').'.';

        $others = collect(['Hx of Complaint' => 'History of complaint', 'Medical History' => 'Medical history', 'Drug History' => 'Drug history'])
            ->map(fn ($label, $key) => ($v = $this->text($history[$key] ?? null)) ? $label.': '.$v : null)->filter()->implode("\n");

        return [
            'chiefComplaint' => $this->text($history['Presenting Complaint'] ?? null) ?? 'Not recorded in previous software',
            'others' => $others ?: null,
            'vaOD6m' => Str::limit($vision['unaided']['od'] ?? '', 250, '') ?: null, 'vaOS6m' => Str::limit($vision['unaided']['os'] ?? '', 250, '') ?: null,
            'IOPOD' => $this->iop($iop['od'] ?? null), 'IOPOS' => $this->iop($iop['os'] ?? null),
            'notes' => implode("\n\n", $notes),
        ];
    }

    /** "Orbidex – 3 weeks" is Orbidex, 3 weeks; a line without a dash is the drug alone. */
    private function medication(string $line): array
    {
        $parts = preg_split('/\s+[–—-]\s+/u', trim($line), 2);
        $item = $this->text($parts[0] ?? null);

        return [$item, $this->text($parts[1] ?? null)];
    }

    private function hasPrescription(array $rx): bool
    {
        foreach (['od', 'os'] as $eye) foreach (['sphere', 'cylinder', 'axis'] as $k) if (($rx[$eye][$k] ?? null) !== null) return true;

        return ($rx['add'] ?? null) !== null;
    }

    private function describe(array $rx): string
    {
        $eye = fn ($e) => strtoupper($e).' '.(Refractions::formatPrescription($this->power($rx[$e]['sphere'] ?? null), $this->power($rx[$e]['cylinder'] ?? null), $this->axis($rx[$e]['axis'] ?? null)) ?: '—')
            .(($rx[$e]['va'] ?? null) ? ' VA '.$rx[$e]['va'] : '');

        return $eye('od').'; '.$eye('os').(($rx['add'] ?? null) ? '; Add '.$rx['add'] : '').(($rx['pd'] ?? null) ? '; PD '.$rx['pd'] : '');
    }

    /**
     * The Refraction section is the subjective refraction (and its auto-refraction line the objective one);
     * the Spectacle Prescription is what was dispensed. A visit with only one of them uses it for both.
     */
    private function refractionRow(array $visit): ?array
    {
        $ref = $visit['refraction'] ?? [];
        $specs = $visit['spectacles'] ?? [];
        $hasRef = $this->hasPrescription($ref) || ($ref['auto'] ?? null);
        $hasSpecs = $this->hasPrescription($specs);
        if (! $hasRef && ! $hasSpecs) return null;
        $sub = $this->hasPrescription($ref) ? $ref : $specs;
        $final = $hasSpecs ? $specs : $sub;
        $adds = $this->adds($final['add'] ?? null);
        $subAdds = $this->adds($sub['add'] ?? null);
        $auto = $this->autoRefraction($ref['auto'] ?? null);
        $pd = preg_match('/(\d{2}(?:\.\d+)?)/', (string) ($final['pd'] ?? $sub['pd'] ?? ''), $p) ? (int) round((float) $p[1]) : null;

        $notes = [];
        foreach (['od', 'os'] as $e) foreach (['sphere', 'cylinder'] as $k)
            if (($v = $sub[$e][$k] ?? null) !== null && $this->power($v) === null) $notes[] = strtoupper($e).' '.$k.' as written: '.$v;
        if ($hasRef && $hasSpecs && $this->describe($ref) !== $this->describe($specs)) $notes[] = 'Refraction: '.$this->describe($ref)."\nSpectacle prescription: ".$this->describe($specs);
        if (($ref['pd'] ?? null) && ($specs['pd'] ?? null) && $ref['pd'] !== $specs['pd']) $notes[] = 'PD '.$ref['pd'].' at refraction, '.$specs['pd'].' on the spectacle prescription.';

        return [
            'objective_method' => $auto ? 'auto_refraction' : null,
            'objective_od_sphere' => $auto['od'][0] ?? null, 'objective_od_cylinder' => $auto['od'][1] ?? null, 'objective_od_axis' => $auto['od'][2] ?? null,
            'objective_os_sphere' => $auto['os'][0] ?? null, 'objective_os_cylinder' => $auto['os'][1] ?? null, 'objective_os_axis' => $auto['os'][2] ?? null,
            'objective_notes' => ($ref['auto'] ?? null) ? 'Auto-refraction as recorded: '.$ref['auto'] : null,
            'subjective_od_sphere' => $this->power($sub['od']['sphere'] ?? null), 'subjective_od_cylinder' => $this->power($sub['od']['cylinder'] ?? null), 'subjective_od_axis' => $this->axis($sub['od']['axis'] ?? null),
            'subjective_os_sphere' => $this->power($sub['os']['sphere'] ?? null), 'subjective_os_cylinder' => $this->power($sub['os']['cylinder'] ?? null), 'subjective_os_axis' => $this->axis($sub['os']['axis'] ?? null),
            'subjective_od_add' => $this->power($subAdds['od']), 'subjective_os_add' => $this->power($subAdds['os']),
            'subjective_od_bcva' => Str::limit($sub['od']['va'] ?? '', 20, '') ?: null, 'subjective_os_bcva' => Str::limit($sub['os']['va'] ?? '', 20, '') ?: null,
            // These four columns are NOT NULL in MySQL, so an eye without a reading gets ''.
            'refractionOD' => Refractions::formatPrescription($this->power($final['od']['sphere'] ?? null), $this->power($final['od']['cylinder'] ?? null), $this->axis($final['od']['axis'] ?? null)) ?? '',
            'refractionOS' => Refractions::formatPrescription($this->power($final['os']['sphere'] ?? null), $this->power($final['os']['cylinder'] ?? null), $this->axis($final['os']['axis'] ?? null)) ?? '',
            'refractionOD_ADD' => $adds['od'], 'refractionOS_ADD' => $adds['os'],
            'refractionOD_distance_va' => Str::limit($final['od']['va'] ?? '', 250, ''), 'refractionOS_distance_va' => Str::limit($final['os']['va'] ?? '', 250, ''),
            'pd' => $pd,
            'refractionnotes' => ($notes ? implode("\n", $notes)."\n" : '').'Imported from Previous Software.',
        ];
    }

    /** "OD +2.50 | OS +2.50" (spaces after the sign allowed) to ['od' => '+2.50', 'os' => '+2.50']. */
    private function adds(?string $text): array
    {
        $out = ['od' => null, 'os' => null];
        foreach (['od', 'os'] as $eye) if (preg_match('/'.$eye.'\s*([+-]?\s*\d+(?:\.\d+)?)/i', (string) $text, $m)) $out[$eye] = str_replace(' ', '', $m[1]);

        return $out;
    }

    /** "OD = +0.75/-0.75x99 OS = No data" to sphere, cylinder, axis per eye; null when neither eye reads. */
    private function autoRefraction(?string $text): ?array
    {
        $out = [];
        foreach (['od', 'os'] as $eye) {
            if (! preg_match('/'.$eye.'\s*=\s*([+-]?\s*\d+(?:\.\d+)?|pl(?:ano)?)\s*(?:\/\s*([+-]?\s*\d+(?:\.\d+)?))?\s*(?:[x*×]\s*(\d{1,3}))?/iu', (string) $text, $m)) continue;
            $out[$eye] = [$this->power($m[1]), isset($m[2]) && $m[2] !== '' ? $this->power($m[2]) : null, isset($m[3]) && $m[3] !== '' ? $this->axis($m[3]) : null];
        }

        return $out ?: null;
    }

    private function axis($value): ?int
    {
        $value = trim((string) $value);

        return is_numeric($value) && $value >= 0 && $value <= 180 ? (int) $value : null;
    }

    private function iop($value): ?float
    {
        return preg_match('/^\s*(\d{1,2}(?:\.\d+)?)\s*(mm\s*hg)?\s*$/i', (string) $value, $m) ? (float) $m[1] : null;
    }

    /**
     * Spectacle orders stay history: each is written into the notes of the patient's consultation that day
     * (or the nearest earlier one, else the next), never onto the live Spectacle Orders board.
     */
    private function importSpectacles(array $rows, array $patients): void
    {
        foreach ($rows as $row) {
            $patient = $patients[mb_strtolower(trim($row['Patient ID'] ?? ''))] ?? null;
            $day = $this->date($row['Date'] ?? null);
            if (! $patient || ! $day) { $this->skip('spectacles', $patient ? 'no date' : 'patient not in the directory file'); continue; }
            $consultation = $this->consultationNear($patient, $day);
            if (! $consultation) { $this->skip('spectacles', 'the patient has no visit to attach it to'); continue; }
            $rx = fn ($v) => ($v = $this->text(preg_replace('/^\/\s*X$/', '', trim((string) $v)))) ? $v : null;
            $parts = array_filter([
                ($r = $rx($row['RE Rx'] ?? null)) ? 'RE '.$r : null,
                ($r = $rx($row['LE Rx'] ?? null)) ? 'LE '.$r : null,
                ($v = $this->text($row['Add OD'] ?? null)) ? 'Add OD '.$v : null,
                ($v = $this->text($row['Add OS'] ?? null)) ? 'Add OS '.$v : null,
                ($v = $this->text($row['PD'] ?? null)) ? 'PD '.$v : null,
            ]);
            $items = array_filter([
                ($v = $this->text($row['Lens'] ?? null)) ? 'Lens: '.$v.(($p = $this->money($row['Lens Price'] ?? 0)) > 0 ? ' (GHS '.number_format($p, 2).')' : '') : null,
                ($v = $this->text($row['Frame'] ?? null)) ? 'Frame: '.$v.(($p = $this->money($row['Frame Price'] ?? 0)) > 0 ? ' (GHS '.number_format($p, 2).')' : '') : null,
            ]);
            $heading = 'Spectacle order '.trim($row['Order ID'] ?? '').' in the previous software, ';
            $notes = (string) DB::table('consultations')->where('id', $consultation)->value('notes');
            if (str_contains($notes, $heading)) { $this->skip('spectacles', 'already in the visit notes'); continue; }
            $note = $heading.$day->format('d M Y').':'
                .($parts ? "\nRx: ".implode('; ', $parts) : '').($items ? "\n".implode("\n", $items) : '')
                ."\nTotal GHS ".number_format($this->money($row['Total'] ?? 0), 2)
                .(($s = $this->text($row['Staff'] ?? null)) ? ' · recorded by '.$s : '')
                .(($v = $this->text($row['Remarks'] ?? null)) && $v !== 'N/A' ? "\nRemarks: ".$v : '');
            DB::table('consultations')->where('id', $consultation)->update(['notes' => trim($notes."\n\n".$note)]);
            // A visit an earlier part brought in keeps it after an undo of that part, so undo of this one takes it out again.
            if (! isset($this->ownVisits[$consultation])) $this->track('consultations.notes', $consultation);
            $this->counts['spectacle orders in visit notes'] = ($this->counts['spectacle orders in visit notes'] ?? 0) + 1;
        }
    }

    /** The patient's imported consultation on that day, else the latest before it, else the first after it. */
    private function consultationNear(int $patient, Carbon $day): ?int
    {
        $visits = $this->visitIds[$patient] ?? [];
        if (! $visits) return null;
        $date = $day->toDateString();
        if (isset($visits[$date])) return $visits[$date];
        ksort($visits);
        $before = array_filter($visits, fn ($d) => $d < $date, ARRAY_FILTER_USE_KEY);

        return $before ? end($before) : reset($visits);
    }

    /**
     * Old payments are history (patient_legacy_payments), never the books. The Cash Payment Report names the
     * patient but gives no number, so a payment is matched by name: one patient with that name, or, when several
     * share it, the one seen that day. Anything else is left out and counted.
     */
    private function importPayments(array $rows, array $directory, array $patients): void
    {
        if (! $rows) return;
        $byName = $this->patientsByName($directory, fn ($p) => $patients[mb_strtolower(trim($p['Patients ID'] ?? ''))] ?? null);
        $modes = ['CASH' => 'Cash', 'MOBILE MONEY' => 'Mobile Money', 'CHEQUE' => 'Cheque', 'ELECTRONIC CARD' => 'Card'];
        // The same capture imported twice (or a part uploaded again) adds no payment twice.
        $already = DB::table('patient_legacy_payments')->where('clinic_id', $this->clinicId)->where('source', self::SOURCE)->pluck('reference')->flip()->all();
        foreach ($rows as $row) {
            $day = $this->date($row['Date'] ?? null);
            $amount = $this->money($row['Amount'] ?? 0);
            if (! $day || $amount <= 0) { $this->skip('payments', 'no date or amount'); continue; }
            $reference = Str::limit('ITE-PAY-'.trim($row['No'] ?? ''), 100, '');
            if (trim($row['No'] ?? '') !== '' && isset($already[$reference])) { $this->skip('payments', 'already brought in'); continue; }
            $already[$reference] = true;
            $named = array_values(array_filter($byName[$this->nameKey($row['Patient'] ?? '')] ?? []));
            $candidates = count($named) > 1 ? array_values(array_filter($named, fn ($id) => isset($this->visitIds[$id][$day->toDateString()]))) : $named;
            if (count($candidates) !== 1) {
                $this->skip('payments', ! $named ? 'name not found among the patients' : ($candidates ? 'name shared by several patients seen that day' : 'name shared by several patients, none seen that day'));
                continue;
            }
            $mode = strtoupper(trim($row['Mode'] ?? ''));
            $this->insert('patient_legacy_payments', [
                'clinic_id' => $this->clinicId, 'patient_id' => $candidates[0], 'external_import_batch_id' => $this->batch->id, 'source' => self::SOURCE,
                'paid_at' => $day->copy()->setTime(12, 0), 'amount' => $amount,
                'payment_method' => $mode === '' ? null : ($modes[$mode] ?? Str::limit(Str::title(mb_strtolower($mode)), 40, '')),
                'reference' => $reference,
                'invoice_number' => null, 'notes' => null, 'recorded_by' => Str::limit($this->text($row['Taken By'] ?? null) ?? '', 150, '') ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * What a patient still owes becomes an unpaid sale for that balance alone, dated on the old invoice so it stays
     * out of the period the clinic works in Nexus. It shows under Outstanding balances and is collected the usual
     * way; only the money collected then enters the books.
     */
    private function importBalances(array $rows, array $patients): void
    {
        foreach ($rows as $row) {
            $balance = $this->money($row['Balance'] ?? 0);
            if ($balance <= 0) continue;
            $patient = $patients[mb_strtolower(trim($row['Patient ID'] ?? ''))] ?? null;
            if (! $patient) { $this->skip('balances', 'patient not in the directory file'); continue; }
            $day = $this->date($row['Date'] ?? null) ?? today();
            $invoice = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($row['Invoice'] ?? '')) ?: Str::upper(Str::random(6));
            $reference = 'ITE-INV-'.$invoice;
            if (DB::table('sales')->where('clinic_id', $this->clinicId)->where('transaction_id', $reference)->exists()) { $this->skip('balances', 'already brought in'); continue; }
            $at = $day->copy()->setTime(12, 0);
            $this->insert('sales', [
                'clinic_id' => $this->clinicId, 'branch_id' => $this->branchId, 'user_id' => $this->ownerId, 'patient_id' => $patient,
                'consultation_id' => $this->visitIds[$patient][$day->toDateString()] ?? null,
                'transaction_id' => $reference, 'total_amount' => $balance, 'amount_paid' => 0, 'insurer_amount' => 0,
                'payment_status' => 'partial', 'bill_status' => 'finalized', 'finalized_at' => $at, 'finalized_by' => $this->ownerId,
                'profit' => 0, 'discount_amount' => 0, 'is_refunded' => 0, 'business_line' => 'clinic',
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    /** lower-cased name with single spaces => list of values given by $value for each patient row. */
    private function patientsByName(array $directory, callable $value): array
    {
        $map = [];
        foreach ($directory as $p) if (($key = $this->nameKey($p['Name'] ?? '')) !== '') $map[$key][] = $value($p);

        return $map;
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', str_replace('.', '', $name))));
    }

    private function money($value): float
    {
        $value = str_replace([',', '₵', 'GHS', ' '], '', (string) $value);

        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    /** Prices (and cost where iTestEMR has it) only: every product starts at stock 0 for a fresh count at go-live. */
    private function importItems(array $sheets): void
    {
        $categories = [];
        $existing = DB::table('products')->where('clinic_id', $this->clinicId)->pluck('name')->mapWithKeys(fn ($n) => [mb_strtolower(trim($n)) => true])->all();
        $batches = DB::table('products')->where('clinic_id', $this->clinicId)->whereNotNull('batch_number')->pluck('batch_number')->mapWithKeys(fn ($b) => [mb_strtolower($b) => true])->all();
        $money = fn ($v) => is_numeric($v = str_replace(',', '', trim((string) $v))) ? round((float) $v, 2) : 0;
        $add = function (string $sheet, ?string $name, string $type, float $cost, float $price, string $code, ?string $created = null) use (&$existing, &$batches, &$categories) {
            $name = $this->text($name);
            if (! $name || isset($existing[mb_strtolower($name)])) { $this->skip($sheet, $name ? 'already in the product list' : 'no name'); return; }
            $existing[mb_strtolower($name)] = true;
            $at = $this->dateTime($created) ?? now();
            $this->insert('products', [
                'clinic_id' => $this->clinicId, 'user_id' => $this->ownerId, 'name' => Str::limit($name, 250, ''),
                'category_id' => $this->categoryFor($type, $this->ownerId, $categories), 'quantity' => 0,
                'made_to_order' => $type === 'service', // a service is never counted, so the POS never blocks it
                'cost_price' => $cost, 'selling_price' => $price, 'created_at' => $at, 'updated_at' => $at,
            ] + self::productDefaults($code, $at, $batches, 'ITE'));
        };

        foreach ($sheets['frames'] ?? [] as $row) {
            $high = $money($row['Price High'] ?? null);
            $add('frames', $row['Frame Code'] ?? null, 'frame', $money($row['Cost'] ?? null), $high ?: $money($row['Price Low'] ?? null), 'F'.($row['#'] ?? ''), $row['Created'] ?? null);
        }
        foreach ($sheets['accessories'] ?? [] as $row) $add('accessories', $row['Accessory'] ?? null, 'product', 0, $money($row['Sell'] ?? null), 'A'.($row['ID'] ?? ''));
        foreach ($sheets['lenses'] ?? [] as $row) {
            $name = collect([$row['Lens Type'] ?? null, $row['Index'] ?? null, $row['Coating'] ?? null, $row['Reflectivity'] ?? null])->map(fn ($v) => $this->text($v))->filter()->implode(' ');
            $ranges = collect(['Sph Range' => 'Sph', 'Cyl Range' => 'Cyl', 'Add Range' => 'Add'])->map(fn ($l, $k) => ($v = $this->text($row[$k] ?? null)) && $v !== '0' ? $l.' '.$v : null)->filter()->implode(', ');
            $add('lenses', $name.($ranges ? ' ('.$ranges.')' : ''), 'lens', $money($row['Cost'] ?? null), $money($row['Sell'] ?? null), 'L'.($row['LensID'] ?? ''));
        }
        // The administrator's Drug Inventory has cost and selling prices; the clinician's drug list has neither.
        if ($sheets['drug_prices'] ?? []) {
            foreach ($sheets['drug_prices'] as $row) $add('drug_prices', $row['Drug Name'] ?? null, 'drug', $money($row['Unit Price (₵)'] ?? null), $money($row['Selling Price (₵)'] ?? null), 'D'.($row['ID'] ?? ''));
        } else {
            foreach ($sheets['drugs'] ?? [] as $row) $add('drugs', $row['Drug Name'] ?? null, 'drug', 0, 0, 'D'.($row['#'] ?? ''));
        }
        // The price list: registration and services as services; accessories were added above with the same prices.
        foreach ($sheets['services'] ?? [] as $row) {
            $type = strtolower(trim($row['Type'] ?? ''));
            if ($type === 'accessory') { $add('services', $row['Product/Service'] ?? null, 'product', 0, $money($row['Price'] ?? null), 'S'.($row['Product ID'] ?? '')); continue; }
            $add('services', $row['Product/Service'] ?? null, 'service', 0, $money($row['Price'] ?? null), 'S'.($row['Product ID'] ?? ''));
        }
    }

    /** The clinic account chosen for an iTestEMR clinician's name; the owner when none was chosen. */
    private function user(?string $name): int
    {
        return $this->doctor($name) ?? $this->ownerId;
    }

    private function doctor(?string $name): ?int
    {
        $name = mb_strtolower(trim((string) $name));

        return $name !== '' && ! empty($this->staff['name:'.$name]) ? (int) $this->staff['name:'.$name] : null;
    }
}
