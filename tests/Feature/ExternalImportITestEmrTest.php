<?php

namespace Tests\Feature;

use App\Livewire\Platform\ExternalImportComponent;
use App\Models\{Clinic, ExternalImportBatch, Patient, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;
use ZipArchive;

/**
 * Legacy Imports → From other software → iTestEMR, with made-up records shaped like a capture of
 * iTestEMR's pages: the Patient's Directory as .csv, the Print All Records pages in one .html, stock lists as .csv.
 */
class ExternalImportITestEmrTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;
    private User $owner;
    private User $doctor;
    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_platform_admin' => true])->save();
        $this->clinic = $this->activeClinic(['name' => 'Hope Eyes', 'slug' => 'hope-eyes']);
        $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->owner = User::factory()->create(['name' => 'Owner Person', 'email' => 'owner@hope.test']);
        $this->doctor = User::factory()->create(['name' => 'Dr. Isaac Poku', 'email' => 'isaac@hope.test']);
        $this->clinic->users()->attach($this->owner->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        $this->clinic->users()->attach($this->doctor->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Doctor']);
        DB::table('diagnoses')->insert(['clinic_id' => $this->clinic->id, 'name' => 'Presbyopia', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function csv(array $headers, array $rows): string
    {
        $q = fn ($v) => preg_match('/[",\r\n]/', (string) $v) ? '"'.str_replace('"', '""', $v).'"' : (string) $v;

        return "\u{FEFF}".implode("\r\n", array_map(fn ($r) => implode(',', array_map($q, $r)), [$headers, ...array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $headers), $rows)]));
    }

    private function patientsCsv(): string
    {
        $headers = ['Patients ID', 'Name', 'Sex', 'DOB', 'Age', 'Contact', 'Address', 'E-mail', 'Occupation', 'Company', 'Next of Kin', 'Next of Kin Contact', 'Insurance Scheme', 'Insurance#', 'Staff ID', 'Patient Type', 'SMS Alert', 'First Visit', 'Branch', 'Photo', 'internal_id'];

        return $this->csv($headers, [
            ['Patients ID' => 'KAS/0002/26', 'Name' => 'Ama Serwaa', 'Sex' => 'Female', 'DOB' => '15 May 1960', 'Contact' => '0240000001', 'Address' => 'Kasoa', 'Occupation' => 'Trader', 'Next of Kin' => 'Kofi', 'Next of Kin Contact' => '0240000009', 'First Visit' => '05-Mar-2026', 'internal_id' => '3'],
            ['Patients ID' => 'KAS/0001/26', 'Name' => 'Yaw Boateng', 'Sex' => 'Male', 'DOB' => '', 'Contact' => '0240000002', 'First Visit' => '01-Mar-2026', 'internal_id' => '2'],
        ]);
    }

    /** One folder per patient, as the capture saves them: cover table, then the sections of each visit. */
    private function recordsHtml(): string
    {
        $future = now()->addMonths(6)->toDateString();
        $visit = fn (int $n, string $date, string $time, string $id, string $body) => '<div class="page-break"></div><!-- VISIT HEADER --><div class="section"><div class="section-title text-danger fw-medium"> Visit #'.$n.' – '.$date.' </div>'
            .'<table class="table-clinic"><tbody><tr><th>Doctor</th><td>Dr. Isaac Asare Poku</td><th>Visit ID</th><td>'.$id.'</td></tr><tr><th>Visit Time</th><td>'.$date.' '.$time.'</td><th>Total Visits</th><td></td></tr></tbody></table></div>'.$body;
        $cover = fn (string $name, string $no) => '<div class="cover-page"><div class="cover-patient-title"> Patient Folder </div><table class="cover-table"><tbody><tr><td><strong>Patient Name:</strong></td><td>'.$name.'</td></tr><tr><td><strong>Patient ID:</strong></td><td>'.$no.'</td></tr></tbody></table></div>';
        $treatment = fn (array $dx, array $meds) => '<div class="section"><div class="section-title">Diagnosis &amp; Treatment</div><p class="label">Diagnosis:</p>'.implode('', array_map(fn ($d, $i) => '<p>'.($i + 1).'. '.$d.'</p>', $dx, array_keys($dx)))
            .'<p class="label">Medication:</p>'.implode('', array_map(fn ($m, $i) => '<p>'.($i + 1).'. '.$m.'</p>', $meds, array_keys($meds))).'</div>'
            .'<div class="section"><div class="section-title">External Medication</div><strong>External Medication:</strong><ol></ol><p><strong>Additional Treatment Plan:</strong></p><p>-</p></div>';
        $review = fn (string $period, string $next) => '<div class="section"><div class="section-title">Review &amp; Follow-Up</div><table style="width:100%"><tbody><tr><td><strong>Review Period:</strong> '.$period.'</td><td><strong>Next Visit:</strong> '.$next.' 00:00:00</td><td><strong>Time:</strong> 10:30:00</td></tr><tr><td colspan="3"><strong>Consultation Status:</strong></td></tr></tbody></table></div>';

        $ama = $cover('Ama Serwaa', 'KAS/0002/26')
            .$visit(1, '2026-03-05', '10:15:00', '501',
                '<div class="section"><div class="section-title">Vision &amp; Vitals</div><table class="table-clinic"><thead><tr><th></th><th>OD</th><th>OS</th><th>OU</th></tr></thead><tbody>'
                .'<tr><th>Unaided VA</th><td>6/9</td><td>6/12-2</td><td></td></tr><tr><th>Pinhole</th><td>6/6</td><td></td><td>-</td></tr><tr><th>IOP (mmHg)</th><td>17</td><td>15</td><td>-</td></tr></tbody></table>'
                .'<div class="vitals-grid"><div class="vital-box"><strong>Pulse</strong><br> 72 bpm </div><div class="vital-box"><strong>Blood Pressure</strong><br> 130/85 </div><div class="vital-box"><strong>Temperature</strong><br> - °C </div><div class="vital-box"><strong>Weight</strong><br> - kg </div></div></div>'
                .'<div class="section"><div class="section-title">History</div><p><span class="label">Presenting Complaint:</span> Blurred near vision</p><p><span class="label">Hx of Complaint:</span></p><p><span class="label">Medical History:</span> Hypertension</p><p><span class="label">Drug History:</span> None</p></div>'
                .'<div class="section"><div class="section-title"> Refraction <span style="font-size:11px"> – By Dr. Isaac Asare Poku </span></div><table style="width:100%"><thead><tr><th>Eye</th><th>Sphere</th><th>Cylinder</th><th>Axis</th><th>VA</th></tr></thead><tbody>'
                .'<tr><td><strong>OD</strong></td><td>+0.50</td><td>-0.75</td><td>90</td><td>6/6</td></tr><tr><td><strong>OS</strong></td><td>+0.75</td><td></td><td></td><td>6/6</td></tr></tbody></table>'
                .'<p><strong>Add:</strong> OD + 1.50 | OS + 1.50 </p><p><strong>PD:</strong> 63</p><p><strong>Auto-Refraction:</strong></p><pre style="background:#f8f8f8">OD = +0.75/-1.00x88 OS = No data</pre></div>'
                .$treatment(['Presbyopia (BE)', 'Dry Eyes (BE)'], ['Oculose – 30', 'Visomox – 1 drop four times a day for one week'])
                .'<div class="section"><div class="section-title">Spectacle Prescription</div><table style="width:100%"><tbody><tr><th>Eye</th><th>Sphere</th><th>Cyl</th><th>Axis</th><th>VA</th></tr>'
                .'<tr><td><strong>OD</strong></td><td>+0.50</td><td>-0.75</td><td>90</td><td>6/6</td></tr><tr><td><strong>OS</strong></td><td>+0.75</td><td></td><td></td><td>6/6</td></tr></tbody></table><p><strong>ADD:</strong> OD +1.50 | OS +1.50 </p><p><strong>PD:</strong> 63</p></div>'
                .$review('1 year', '2027-03-05'))
            .$visit(2, now()->subDay()->toDateString(), '09:00:00', '640', $treatment(['Bacterial Conjunctivitis (RE)'], []).'<div class="section"><div class="section-title">Spectacle Prescription</div></div>'.$review('6 months', $future));
        $yaw = $cover('Yaw Boateng', 'KAS/0001/26')
            .$visit(1, '2026-03-01', '08:30:00', '400', $treatment(['Normal Eyes (BE)'], []).'<div class="section"><div class="section-title">Spectacle Prescription</div><table style="width:100%"><tbody><tr><th>Eye</th><th>Sphere</th><th>Cyl</th><th>Axis</th><th>VA</th></tr>'
                .'<tr><td><strong>OD</strong></td><td>-2.00</td><td></td><td></td><td>6/6</td></tr><tr><td><strong>OS</strong></td><td>-2.25</td><td></td><td></td><td>6/6</td></tr></tbody></table><p><strong>ADD:</strong> OD | OS </p><p><strong>PD:</strong> 61</p></div>'.$review('1 week', '2026-03-08'))
            .$visit(2, '2026-03-01', '11:00:00', '401', $treatment(['Myopia (BE)'], ['Oculose – 30']).'<div class="section"><div class="section-title">Spectacle Prescription</div></div>');
        $stranger = $cover('Not In Directory', 'KAS/0099/26').$visit(1, '2026-03-02', '08:00:00', '1', $treatment(['Myopia (BE)'], []));

        return '<!doctype html><html><head><meta charset="utf-8"></head><body>'
            .'<section class="px" id="p3">'.$ama.'</section><section class="px" id="p2">'.$yaw.'</section><section class="px" id="p100">'.$stranger.'</section></body></html>';
    }

    private function uploads(bool $withPatients = true): array
    {
        $files = [
            UploadedFile::fake()->createWithContent('eyes-of-hope-patient-records.html', $this->recordsHtml()),
            UploadedFile::fake()->createWithContent('eyes-of-hope-frames.csv', $this->csv(['#', 'Frame Code', 'Cost', 'Price Low', 'Price High', 'Bought', 'Sold', 'Left', 'Broken', 'Remarks', 'Created'], [
                ['#' => '4', 'Frame Code' => 'Anti Eye Strain Frame', 'Cost' => '33.00', 'Price Low' => '250.00', 'Price High' => '270.00', 'Left' => '3', 'Created' => '20 Feb 2026'],
                ['#' => '1', 'Frame Code' => '', 'Cost' => '0.00', 'Left' => '2', 'Created' => '19 Feb 2026'],
            ])),
            UploadedFile::fake()->createWithContent('eyes-of-hope-drugs.csv', $this->csv(['#', 'Drug Name', 'Bought', 'Sold', 'Left', 'Type', 'Route'], [['#' => '15', 'Drug Name' => 'Latmo', 'Bought' => '117', 'Sold' => '68', 'Left' => '49', 'Type' => 'AntiGlaucoma', 'Route' => 'Eye']])),
            UploadedFile::fake()->createWithContent('eyes-of-hope-lens-prices.csv', $this->csv(['LensID', 'Index', 'CL', 'Lens Type', 'Reflectivity', 'Coating', 'Sph Range', 'Cyl Range', 'Add Range', 'Cost', 'Sell', 'Lens Stocked', 'Lens Sold'], [
                ['LensID' => '6', 'Index' => '1.56', 'Lens Type' => 'Photochromic single V', 'Reflectivity' => 'Anti Reflective', 'Coating' => 'PhotoChromic', 'Sph Range' => '0.00 to -6.00', 'Cyl Range' => '0.00 to-2.00', 'Add Range' => '0', 'Cost' => '60.00', 'Sell' => '250.00'],
            ])),
            UploadedFile::fake()->createWithContent('eyes-of-hope-staff.csv', $this->csv(['Name', 'Branch', 'Contact', 'Email', 'Registered', 'Role', 'Department', 'Next of Kin', 'Next of Kin Contact', 'Staff ID', 'State'], [['Name' => 'Someone', 'Role' => 'Clinician', 'Registered' => '2026-02-12', 'Staff ID' => '3']])),
            UploadedFile::fake()->createWithContent('notes.csv', "a,b\n1,2"),
        ];
        if ($withPatients) $files[] = UploadedFile::fake()->createWithContent('eyes-of-hope-patients.csv', $this->patientsCsv());

        return $files;
    }

    /** What an administrator login adds to the capture: payments, balances, spectacle orders and price lists. */
    private function financeUploads(): array
    {
        return [
            UploadedFile::fake()->createWithContent('payments.csv', $this->csv(['Date', 'No', 'Patient', 'Mode', 'Amount', 'Taken By'], [
                ['Date' => '2026-03-05', 'No' => '7', 'Patient' => 'Ama  Serwaa', 'Mode' => 'Mobile Money', 'Amount' => '350.00', 'Taken By' => 'Miracle Collins'],
                ['Date' => '2026-03-01', 'No' => '8', 'Patient' => 'Yaw Boateng', 'Mode' => 'Cash', 'Amount' => '80.00', 'Taken By' => 'Miracle Collins'],
                ['Date' => '2026-03-02', 'No' => '9', 'Patient' => 'Someone Unknown', 'Mode' => 'Cash', 'Amount' => '20.00', 'Taken By' => 'Miracle Collins'],
            ])),
            UploadedFile::fake()->createWithContent('outstanding-balances.csv', $this->csv(['Date', 'Invoice', 'Patient ID', 'Patient', 'Contact', 'Item', 'Net TopUp', 'Amount Paid', 'Balance'], [
                ['Date' => '05 Mar, 2026', 'Invoice' => '170', 'Patient ID' => 'KAS/0002/26', 'Patient' => 'Ama Serwaa', 'Item' => 'Lens', 'Net TopUp' => '₵480.00', 'Amount Paid' => '₵250.00', 'Balance' => '₵230.00'],
                ['Date' => '01 Mar, 2026', 'Invoice' => '171', 'Patient ID' => 'KAS/0001/26', 'Patient' => 'Yaw Boateng', 'Item' => 'Frame', 'Net TopUp' => '₵300.00', 'Amount Paid' => '₵300.00', 'Balance' => '₵0.00'],
            ])),
            UploadedFile::fake()->createWithContent('spectacle-orders.csv', $this->csv(['Order ID', 'Date', 'Patient ID', 'Patient', 'Contact', 'RE Rx', 'LE Rx', 'Lens', 'Add OD', 'Add OS', 'PD', 'Frame', 'Frame Price', 'Lens Price', 'Total', 'Ordered', 'Orders In', 'Received', 'Remarks', 'Staff'], [
                ['Order ID' => '366', 'Date' => '2026-03-05', 'Patient ID' => 'KAS/0002/26', 'Patient' => 'Ama Serwaa', 'RE Rx' => '+0.50 / -0.75 X 90 6/6', 'LE Rx' => '+0.75 / X 6/6', 'Lens' => 'PHOTOCHROMIC PROGRESSIVE', 'PD' => '63', 'Frame' => 'EOH FF247', 'Frame Price' => '250.00', 'Lens Price' => '800.00', 'Total' => '1050.00', 'Remarks' => 'N/A', 'Staff' => 'Mensah Ruth'],
            ])),
            UploadedFile::fake()->createWithContent('service-prices.csv', $this->csv(['Product ID', 'Branch', 'Product/Service', 'Price', 'Type', 'Comment'], [
                ['Product ID' => '5', 'Product/Service' => 'Cycloplegic Refraction', 'Price' => '30.00', 'Type' => 'Service'],
                ['Product ID' => '1', 'Product/Service' => 'Registration', 'Price' => '50.00', 'Type' => 'Registration'],
            ])),
            UploadedFile::fake()->createWithContent('drug-prices.csv', $this->csv(['ID', 'Drug Name', 'Quantity Bought', 'Quantity Sold', 'Quantity Left', 'Unit Price (₵)', 'Selling Price (₵)', 'Unit Profit (₵)', 'Drug Type', 'Drug Route'], [
                ['ID' => '15', 'Drug Name' => 'Latmo', 'Quantity Bought' => '117', 'Unit Price (₵)' => '45.00', 'Selling Price (₵)' => '90.00', 'Drug Type' => 'AntiGlaucoma', 'Drug Route' => 'Eye'],
            ])),
        ];
    }

    private function checked(?array $files = null)
    {
        return Livewire::actingAs($this->admin)->test(ExternalImportComponent::class)
            ->set('clinicId', $this->clinic->id)->set('source', 'itestemr')->set('sheetFiles', $files ?? $this->uploads())->call('checkFiles')->assertHasNoErrors();
    }

    private function imported()
    {
        return $this->checked()->set('staff.'.md5('name:dr. isaac asare poku'), (string) $this->doctor->id)
            ->set('confirmation', 'IMPORT hope-eyes')->call('startImport')->assertHasNoErrors();
    }

    public function test_checking_the_capture_recognises_each_file_and_writes_nothing(): void
    {
        $this->checked()->assertSee('iTestEMR')->assertSee('Dr. Isaac Asare Poku')->assertSee('notes.csv')->assertSee('next-of-kin field');

        $batch = ExternalImportBatch::sole();
        $a = $batch->analysis;
        $this->assertSame('itestemr', $batch->source);
        $this->assertSame([], $a['errors']);
        $this->assertSame(2, $a['patients']['total']);
        $this->assertSame(1, $a['patients']['no_dob']);
        $this->assertSame(1, $a['patients']['next_of_kin']);
        $this->assertSame([], $a['patients']['no_folder']);
        $this->assertSame(3, $a['visits'], 'the folder of a patient missing from the directory is not counted; Yaw\'s two visits on one day count once');
        $this->assertSame(1, $a['same_day_visits']);
        $this->assertSame(['records' => 1], $a['orphans']);
        $this->assertSame(1, $a['future_appointments'], 'only Ama\'s latest review is still ahead');
        $this->assertSame(4, $a['items']['count']);
        $this->assertSame(4, $a['new_diagnoses'], 'Presbyopia exists; Dry Eyes, Bacterial Conjunctivitis, Normal Eyes and Myopia are new');
        $this->assertSame(['notes.csv'], $a['unknown_files']);
        $this->assertSame('eyes-of-hope-staff.csv', $a['sheets']['staff']['file']);
        $this->assertSame(['name:dr. isaac asare poku'], array_column($a['staff'], 'key'));
        $this->assertSame(0, Patient::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->count());
    }

    public function test_choosing_itestemr_shows_the_capture_button_and_its_steps(): void
    {
        $page = Livewire::actingAs($this->admin)->test(ExternalImportComponent::class);
        $page->assertDontSee('Nexus · iTestEMR capture');

        $page->set('source', 'itestemr')->assertSee('Getting the files out of iTestEMR')->assertSee('Nexus · iTestEMR capture')->assertSee('Copy the script');
        $script = file_get_contents(resource_path('tools/itestemr-capture.js'));
        $this->assertStringContainsString('href="javascript:'.e(rawurlencode($script)).'"', $page->html(), 'the bookmark button carries the whole capture script');
        foreach (['patients.csv', 'patient-records.html', 'hms-print-all-patient-folder', 'capture-report.txt'] as $part) $this->assertStringContainsString($part, $script);
    }

    public function test_the_patients_file_is_required_and_other_kinds_of_file_are_refused(): void
    {
        $this->checked($this->uploads(false))->assertSee('Directory file (patients.csv) is needed')->assertDontSee('Import into Hope Eyes');

        Livewire::actingAs($this->admin)->test(ExternalImportComponent::class)->set('clinicId', $this->clinic->id)->set('source', 'itestemr')
            ->set('sheetFiles', [UploadedFile::fake()->createWithContent('x.xlsx', 'x')])->call('checkFiles')->assertHasErrors('sheetFiles');
    }

    public function test_importing_brings_in_patients_visits_refractions_and_products(): void
    {
        $this->imported();
        $batch = ExternalImportBatch::sole();
        $this->assertSame('completed', $batch->status, (string) $batch->error);
        $this->assertSame([], Storage::disk('local')->allFiles('external-imports'));

        $ama = DB::table('patients')->where('clinic_id', $this->clinic->id)->where('pxnumber', 'KAS/0002/26')->first();
        $this->assertSame(['Ama Serwaa', '1960-05-15', 'Female', '0240000001', 'Kasoa', 'Trader'], [$ama->name, $ama->dob, $ama->gender, $ama->contact, $ama->address, $ama->occupation]);
        $this->assertSame('2026-03-05', substr($ama->created_at, 0, 10));
        $this->assertSame(['KAS/0001/26', 'KAS/0002/26'], DB::table('patients')->orderBy('pxnumber')->pluck('pxnumber')->all());

        $first = DB::table('consultations')->where('patient_id', $ama->id)->orderBy('created_at')->first();
        $this->assertSame('2026-03-05 10:15:00', $first->created_at);
        $this->assertSame('Blurred near vision', $first->chiefComplaint);
        $this->assertSame("Medical history: Hypertension\nDrug history: None", $first->others);
        $this->assertSame(['6/9', '6/12-2'], [$first->vaOD6m, $first->vaOS6m]);
        $this->assertEquals([17, 15], [$first->IOPOD, $first->IOPOS]);
        $this->assertSame($this->doctor->id, (int) $first->user_id);
        foreach (['Vitals: Pulse 72 bpm · Blood Pressure 130/85', 'Pinhole VA: OD 6/6', 'Diagnosis: Presbyopia (BE); Dry Eyes (BE)', '- Oculose – 30', 'Review in 1 year (next visit 05 Mar 2027).', 'Imported from Previous Software (visit 501).'] as $line)
            $this->assertStringContainsString($line, $first->notes);
        $this->assertStringNotContainsString('Temperature', $first->notes, 'blank vitals stay out');
        $this->assertDatabaseHas('cashier_patient_clearances', ['id' => $first->clearance_id, 'payment_status' => 'Paid', 'doctor_status' => 1, 'clearance_date' => '2026-03-05']);

        $dx = DB::table('consultation_diagnosis')->join('diagnoses', 'diagnoses.id', '=', 'diagnosis_id')->where('consultation_id', $first->id)->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Dry Eyes', 'Presbyopia'], $dx);
        $meds = DB::table('patient_legacy_medications')->where('consultation_id', $first->id)->orderBy('item')->get();
        $this->assertSame([['Oculose', '30', null], ['Visomox', null, '1 drop four times a day for one week']], $meds->map(fn ($m) => [$m->item, $m->quantity, $m->note])->all());
        $this->assertSame(['itestemr'], $meds->pluck('source')->unique()->values()->all());

        $rx = DB::table('refractions')->where('consultation_id', $first->id)->sole();
        $this->assertEquals([0.5, -0.75, 90, 1.5, '6/6'], [$rx->subjective_od_sphere, $rx->subjective_od_cylinder, $rx->subjective_od_axis, $rx->subjective_od_add, $rx->subjective_od_bcva]);
        $this->assertEquals([0.75, -1.0, 88], [$rx->objective_od_sphere, $rx->objective_od_cylinder, $rx->objective_od_axis]);
        $this->assertSame('auto_refraction', $rx->objective_method);
        $this->assertNull($rx->objective_os_sphere);
        $this->assertSame(['+1.50', '+1.50', 63], [$rx->refractionOD_ADD, $rx->refractionOS_ADD, (int) $rx->pd]);
        $this->assertNotNull($rx->refractionOD);

        $yaw = DB::table('patients')->where('pxnumber', 'KAS/0001/26')->first();
        $yawRx = DB::table('refractions')->join('consultations', 'consultations.id', '=', 'refractions.consultation_id')->where('consultations.patient_id', $yaw->id)->sole();
        $this->assertEquals([-2.0, -2.25, null], [$yawRx->subjective_od_sphere, $yawRx->subjective_os_sphere, $yawRx->objective_method], 'a spectacle prescription alone becomes the refraction');
        $yawVisit = DB::table('consultations')->where('patient_id', $yaw->id)->sole();
        $this->assertSame('2026-03-01 08:30:00', $yawVisit->created_at, 'two visits on one day become one consultation at the first time');
        $this->assertStringContainsString('Diagnosis: Normal Eyes (BE); Myopia (BE)', $yawVisit->notes);
        $this->assertStringContainsString('2 visits on this day', $yawVisit->notes);
        $this->assertStringContainsString('(visit 400, 401)', $yawVisit->notes);
        $this->assertSame(2, DB::table('consultation_diagnosis')->where('consultation_id', $yawVisit->id)->count());

        $this->assertSame(3, DB::table('consultations')->count());
        $this->assertSame(['records' => ['patient not in the directory file' => 1]], array_intersect_key($batch->result['skipped'], ['records' => 1]));

        $appointment = DB::table('appointments')->sole();
        $this->assertSame([$ama->id, 'Pending', 'Review', $this->doctor->id], [(int) $appointment->patient_id, $appointment->status, $appointment->title, (int) $appointment->doctor_id]);
        $this->assertSame(now()->addMonths(6)->toDateString().' 10:30:00', $appointment->scheduled_at);

        $products = DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')->orderBy('products.name')
            ->get(['products.name', 'products.quantity', 'products.cost_price', 'products.selling_price', 'products.batch_number', 'categories.type']);
        $this->assertSame([
            ['Anti Eye Strain Frame', 0, 33.0, 270.0, 'frame'],
            ['Latmo', 0, 0.0, 0.0, 'drug'],
            ['Photochromic single V 1.56 PhotoChromic Anti Reflective (Sph 0.00 to -6.00, Cyl 0.00 to-2.00)', 0, 60.0, 250.0, 'lens'],
        ], $products->map(fn ($p) => [$p->name, (int) $p->quantity, (float) $p->cost_price, (float) $p->selling_price, $p->type])->all());
        $this->assertTrue($products->every(fn ($p) => str_starts_with($p->batch_number, 'ITE-')));
        $this->assertSame(['frames' => ['no name' => 1]], array_intersect_key($batch->result['skipped'], ['frames' => 1]));
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_an_administrator_capture_brings_payments_balances_spectacle_orders_and_prices(): void
    {
        $this->checked([...$this->uploads(), ...$this->financeUploads()]);
        $a = ExternalImportBatch::sole()->analysis;
        $this->assertSame([], $a['errors']);
        $this->assertEquals(['count' => 3, 'total' => 450, 'unmatched' => 1], $a['payments']);
        $this->assertEquals(['count' => 1, 'total' => 230, 'unknown_patients' => 0], $a['balances'], 'a fully paid invoice is not a balance');
        $this->assertSame(1, $a['spectacles']);

        Livewire::actingAs($this->admin)->test(ExternalImportComponent::class)->call('selectBatch', ExternalImportBatch::sole()->id)
            ->set('confirmation', 'IMPORT hope-eyes')->call('startImport')->assertHasNoErrors();
        $batch = ExternalImportBatch::sole();
        $this->assertSame('completed', $batch->status, (string) $batch->error);
        $ama = DB::table('patients')->where('pxnumber', 'KAS/0002/26')->value('id');
        $yaw = DB::table('patients')->where('pxnumber', 'KAS/0001/26')->value('id');

        $payments = DB::table('patient_legacy_payments')->orderBy('paid_at')->get();
        $this->assertSame([[$yaw, 80.0, 'Cash'], [$ama, 350.0, 'Mobile Money']], $payments->map(fn ($p) => [(int) $p->patient_id, (float) $p->amount, $p->payment_method])->all(), 'matched by name, spaces ignored');
        $this->assertSame(['itestemr'], $payments->pluck('source')->unique()->values()->all());
        $this->assertSame(['payments' => ['name not found among the patients' => 1]], array_intersect_key($batch->result['skipped'], ['payments' => 1]));
        $this->assertSame(0, DB::table('payment_transactions')->count(), 'old payments stay out of the books');

        $sale = DB::table('sales')->sole();
        $this->assertSame([$ama, 'ITE-INV-170', 230.0, 0.0, 'partial', '2026-03-05'], [(int) $sale->patient_id, $sale->transaction_id, (float) $sale->total_amount, (float) $sale->amount_paid, $sale->payment_status, substr($sale->created_at, 0, 10)]);

        $notes = DB::table('consultations')->where('patient_id', $ama)->whereDate('created_at', '2026-03-05')->value('notes');
        $this->assertStringContainsString("Spectacle order 366 in the previous software, 05 Mar 2026:\nRx: RE +0.50 / -0.75 X 90 6/6; LE +0.75 / X 6/6; PD 63", $notes);
        $this->assertStringContainsString('Frame: EOH FF247 (GHS 250.00)', $notes);
        $this->assertStringContainsString('Total GHS 1,050.00 · recorded by Mensah Ruth', $notes);

        $latmo = DB::table('products')->where('name', 'Latmo')->sole();
        $this->assertEquals([45, 90], [$latmo->cost_price, $latmo->selling_price], 'the administrator\'s drug prices replace the priceless drug list');
        $services = DB::table('products')->join('categories', 'categories.id', '=', 'category_id')->where('categories.type', 'service')->orderBy('products.name')->pluck('selling_price', 'products.name')->map(fn ($p) => (float) $p)->all();
        $this->assertSame(['Cycloplegic Refraction' => 30.0, 'Registration' => 50.0], $services);
    }

    public function test_undo_is_refused_once_an_imported_balance_has_been_collected(): void
    {
        $this->checked([...$this->uploads(), ...$this->financeUploads()])->set('confirmation', 'IMPORT hope-eyes')->call('startImport')->assertHasNoErrors();
        DB::table('sales')->update(['amount_paid' => 100]);

        Livewire::actingAs($this->admin)->test(ExternalImportComponent::class)->call('selectBatch', ExternalImportBatch::sole()->id)
            ->set('undoConfirmation', 'UNDO hope-eyes')->call('undoImport')->assertHasErrors('undo');
        $this->assertSame('completed', ExternalImportBatch::sole()->status);
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(2, DB::table('patients')->count());
    }

    public function test_with_a_bucket_attached_the_files_live_in_the_bucket_so_any_server_can_import(): void
    {
        config()->set('filesystems.disks.documents', ['driver' => 's3', 'bucket' => 'clinic-documents']);
        Storage::fake('documents');

        $this->checked();
        $batch = ExternalImportBatch::sole();
        $this->assertSame(['documents'], collect($batch->files)->pluck('disk')->unique()->values()->all());
        $this->assertNotEmpty(Storage::disk('documents')->allFiles($batch->stored_dir));
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nothing is left on the server that checked the files.');
        $this->assertSame(2, $batch->analysis['patients']['total'], 'The check read the files back from the bucket.');

        Livewire::actingAs($this->admin)->test(ExternalImportComponent::class)->call('selectBatch', $batch->id)
            ->set('staff.'.md5('name:dr. isaac asare poku'), (string) $this->doctor->id)
            ->set('confirmation', 'IMPORT hope-eyes')->call('startImport')->assertHasNoErrors();

        $batch->refresh();
        $this->assertSame('completed', $batch->status, (string) $batch->error);
        $this->assertSame(2, DB::table('patients')->where('clinic_id', $this->clinic->id)->count());
        $this->assertSame([], Storage::disk('documents')->allFiles('external-imports'), 'The bucket copy is removed after the import.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'The temporary copies made for reading are removed.');
    }

    public function test_undo_removes_exactly_what_was_imported(): void
    {
        $diagnoses = DB::table('diagnoses')->count();
        $this->imported()->set('undoConfirmation', 'UNDO hope-eyes')->call('undoImport')->assertHasNoErrors();

        $this->assertSame('rolled_back', ExternalImportBatch::sole()->status);
        foreach (['patients', 'consultations', 'cashier_patient_clearances', 'refractions', 'consultation_diagnosis', 'appointments', 'patient_legacy_medications', 'products', 'categories', 'external_import_rows'] as $table)
            $this->assertSame(0, DB::table($table)->count(), $table);
        $this->assertSame($diagnoses, DB::table('diagnoses')->count());
    }

    public function test_a_zip_of_the_capture_works_like_the_separate_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($this->uploads() as $file) $zip->addFromString('backup/'.$file->getClientOriginalName(), file_get_contents($file->getRealPath()));
        $zip->close();
        $this->checked([UploadedFile::fake()->createWithContent('eyes-of-hope.zip', file_get_contents($path))]);
        unlink($path);

        $this->assertSame([], ExternalImportBatch::sole()->analysis['errors']);
        $this->assertSame(3, ExternalImportBatch::sole()->analysis['visits']);
    }
}
