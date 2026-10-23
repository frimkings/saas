<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\Platform\ExternalImportComponent;
use App\Models\{CashierPatientClearance, Clinic, ExternalImportBatch, Patient, PatientLegacyPayment, User};
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;
use ZipArchive;

/**
 * Legacy Imports → From other software, with made-up rows shaped like a Cloudify (ECMS) backup:
 * one .xlsx per table, dates as Excel day numbers, every row carrying the patient's ECMS id.
 */
class ExternalImportCloudifyTest extends TestCase
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
        $this->clinic = $this->activeClinic(['name' => 'Bright Eyes', 'slug' => 'bright-eyes']);
        $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->owner = User::factory()->create(['name' => 'Owner Person', 'email' => 'owner@bright.test']);
        $this->doctor = User::factory()->create(['name' => 'Kofi Mensah', 'email' => 'kofi@bright.test']);
        $this->clinic->users()->attach($this->owner->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        $this->clinic->users()->attach($this->doctor->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Doctor']);
        DB::table('diagnoses')->insert(['clinic_id' => $this->clinic->id, 'name' => 'Presbyopia', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['clinic_id' => $this->clinic->id, 'user_id' => $this->owner->id, 'name' => 'Drugs', 'type' => 'drug', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function serial(string $date): string
    {
        $at = Carbon::parse($date);

        return (string) round(Carbon::create(1899, 12, 30)->diffInSeconds($at) / 86400, 6);
    }

    /** A minimal .xlsx: inline strings, numbers as numbers. */
    private function xlsx(array $headers, array $rows): string
    {
        $cell = function ($value, int $col, int $row) {
            $ref = chr(64 + $col).$row;
            if ($value === null || $value === '') return '';
            if (is_int($value) || is_float($value)) return '<c r="'.$ref.'" t="n"><v>'.$value.'</v></c>';

            return '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
        };
        $xml = '';
        foreach (array_merge([$headers], array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? null, $headers), $rows)) as $i => $values) {
            $xml .= '<row r="'.($i + 1).'">'.implode('', array_map(fn ($v, $c) => $cell($v, $c + 1, $i + 1), $values, array_keys($values))).'</row>';
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function export(): array
    {
        $who = 'FRONTDESK@OLD.TEST';
        $doc = 'KOFI@BRIGHT.TEST';
        $p = fn (int $id, string $no, string $first, string $last, array $more = []) => $more + ['PATEINT_ECMS_ID' => $id, 'OUT_PATIENT_ID' => 'PN'.$id, 'CLINIC_PATIENT_ID' => $no, 'FIRSTNAME' => $first, 'LASTNAME' => $last, 'CONTACT' => '02400000'.($id % 100), 'CREATED_BY' => $who, 'CREATED_DATE' => $this->serial('2024-03-01 09:00')];
        $future = now()->addMonth()->toDateString();

        return [
            'CLINIC_BACKUP_COPY_PATIENTS_DETAILS.xlsx' => [['PATEINT_ECMS_ID', 'OUT_PATIENT_ID', 'TITLE', 'FIRSTNAME', 'LASTNAME', 'DATE_OF_BIRTH', 'GENDER', 'CONTACT', 'EMAIL', 'EMERGENCY_CONTACT', 'ADDRESS', 'OCCUPATION', 'CREATED_BY', 'CREATED_DATE', 'CLINIC_PATIENT_ID'], [
                $p(101, 'BE/24/01', 'AMA', 'SERWAA', ['DATE_OF_BIRTH' => $this->serial('1980-05-15'), 'GENDER' => 'Female', 'ADDRESS' => 'Adum', 'OCCUPATION' => 'Trader']),
                $p(102, 'BE/24/02', 'Yaw', 'Boateng', ['GENDER' => 'Male']),
                $p(103, 'BE/24/02', 'Esi', 'Owusu'),
            ]],
            'CLINIC_BACKUP_COPY_BU_COMPLAINS.xlsx' => [['PATEINT_ECMS_ID', 'DATE_OF_COMPLAIN', 'NATURE_OF_COMPLAIN', 'COMPLAIN_DESC', 'COMPLAINS', 'CREATED_DATE', 'CREATED_BY'], [
                ['PATEINT_ECMS_ID' => 101, 'DATE_OF_COMPLAIN' => $this->serial('2024-03-05'), 'NATURE_OF_COMPLAIN' => 'New', 'COMPLAIN_DESC' => "odq: itching+_x000D_\npohx: nil", 'COMPLAINS' => 'Blurred near vision', 'CREATED_DATE' => $this->serial('2024-03-05 10:15'), 'CREATED_BY' => $doc],
                ['PATEINT_ECMS_ID' => 999, 'DATE_OF_COMPLAIN' => $this->serial('2024-03-05'), 'COMPLAINS' => 'Unknown patient'],
            ]],
            'CLINIC_BACKUP_COPY_BU_OCULAR_EXAMINATION.xlsx' => [['PATEINT_ECMS_ID', 'DATE_EXAMINATION', 'OD_VISUAL_ACTIVITY', 'OS_VISUAL_ACTIVITY', 'OD_CORNEA', 'OS_CORNEA', 'OD_CDR', 'OTHER_OD', 'OTHER_OS', 'CREATED_BY', 'OCULAR_EXAM_ID'], [
                ['PATEINT_ECMS_ID' => 101, 'DATE_EXAMINATION' => $this->serial('2024-03-05'), 'OD_VISUAL_ACTIVITY' => '6/6', 'OS_VISUAL_ACTIVITY' => '6/9', 'OD_CORNEA' => 'Clear', 'OS_CORNEA' => 'Clear', 'OD_CDR' => '0.3', 'OTHER_OD' => 'IOP @ 10:42 am 17mmHg', 'OTHER_OS' => 'IOP 15 mmHg', 'CREATED_BY' => $doc, 'OCULAR_EXAM_ID' => 1],
            ]],
            'CLINIC_BACKUP_COPY_BU_DIAGNOSIS.xlsx' => [['PATEINT_ECMS_ID', 'DIAGNOSIS_DATE', 'DIAGNOSIS', 'Q1', 'CREATED_BY'], [
                ['PATEINT_ECMS_ID' => 101, 'DIAGNOSIS_DATE' => $this->serial('2024-03-05'), 'DIAGNOSIS' => 'presbyopia BE:Dry eyes', 'Q1' => 'Never', 'CREATED_BY' => $doc],
            ]],
            'CLINIC_BACKUP_COPY_BU_DIAGNOSIS (1).xlsx' => [['PATEINT_ECMS_ID', 'DIAGNOSIS_DATE', 'DIAGNOSIS', 'Q1', 'CREATED_BY'], [
                ['PATEINT_ECMS_ID' => 101, 'DIAGNOSIS_DATE' => $this->serial('2024-03-05'), 'DIAGNOSIS' => 'presbyopia BE:Dry eyes', 'Q1' => 'Never', 'CREATED_BY' => $doc],
            ]],
            'CLINIC_BACKUP_COPY_BU_MEDICATIONS.xlsx' => [['PATEINT_ECMS_ID', 'MEDICATION_ID', 'ITEM', 'QUANTITY', 'NOTE', 'DATE_OF_PRESCRIPTION', 'CREATED_BY'], [
                ['PATEINT_ECMS_ID' => 101, 'MEDICATION_ID' => 1, 'ITEM' => 'Lubricant drops', 'QUANTITY' => 1, 'NOTE' => '1 drop tds', 'DATE_OF_PRESCRIPTION' => $this->serial('2024-03-05'), 'CREATED_BY' => $doc],
            ]],
            'CLINIC_BACKUP_COPY_BI_REFRACTION.xlsx' => [['PATEINT_ECMS_ID', 'DATE_OF_EXAMINATION', 'LENS_TYPE', 'LENS_TREATMENT', 'SPHERE_OD_SR', 'SPHERE_OS_SR', 'CYLINDER_OD_SR', 'AXIS_OD_SR', 'ADD_OD_SR', 'ADD_OS_SR', 'R_EYE_D_AIDED', 'L_EYE_D_AIDED', 'IS_REVIEW', 'IS_REVIEW_DURATION'], [
                ['PATEINT_ECMS_ID' => 101, 'DATE_OF_EXAMINATION' => $this->serial('2024-03-05'), 'LENS_TYPE' => 'PROGRESSIVE', 'LENS_TREATMENT' => 'BLUE CUT', 'SPHERE_OD_SR' => '+0.50', 'SPHERE_OS_SR' => '+0.75', 'CYLINDER_OD_SR' => '-0.75', 'AXIS_OD_SR' => '90', 'ADD_OD_SR' => '+1.50', 'ADD_OS_SR' => '+1.50', 'R_EYE_D_AIDED' => '6/6', 'L_EYE_D_AIDED' => '6/6', 'IS_REVIEW' => 'Y', 'IS_REVIEW_DURATION' => 365],
            ]],
            'CLINIC_BACKUP_COPY_BU_APPOINTMENTS.xlsx' => [['PATEINT_ECMS_ID', 'APPOINTMENT_DATE', 'REASON', 'ASSIGNED_DOCTOR', 'APPOINTMENT_TYPE', 'APPOINTMENT_STATUS', 'CREATED_BY'], [
                ['PATEINT_ECMS_ID' => 101, 'APPOINTMENT_DATE' => $this->serial('2024-03-05'), 'ASSIGNED_DOCTOR' => 'Kofi Mensah', 'APPOINTMENT_TYPE' => 'NEW', 'APPOINTMENT_STATUS' => 'CONFIRMED', 'CREATED_BY' => $who],
                ['PATEINT_ECMS_ID' => 102, 'APPOINTMENT_DATE' => $this->serial('2024-04-01'), 'ASSIGNED_DOCTOR' => 'Kofi Mensah', 'APPOINTMENT_TYPE' => 'REVIEW', 'APPOINTMENT_STATUS' => 'UNATTENDED', 'CREATED_BY' => $who],
                ['PATEINT_ECMS_ID' => 101, 'APPOINTMENT_DATE' => $this->serial($future), 'ASSIGNED_DOCTOR' => 'Locum Doctor', 'APPOINTMENT_TYPE' => 'REVIEW', 'APPOINTMENT_STATUS' => 'CONFIRMED', 'CREATED_BY' => $who],
            ]],
            'CLINIC_BACKUP_COPY_BU_PAYMENTS.xlsx' => [['PATEINT_ECMS_ID', 'PAYMENT_DATE', 'INVOICE_ID', 'PAYMENT_MODE', 'PAYMENT_AMOUNT', 'REFERENECE', 'CREATED_BY'], [
                ['PATEINT_ECMS_ID' => 101, 'PAYMENT_DATE' => $this->serial('2024-03-05 11:00'), 'INVOICE_ID' => '676408328', 'PAYMENT_MODE' => 'MTN MOMO', 'PAYMENT_AMOUNT' => 350, 'REFERENECE' => 'R1', 'CREATED_BY' => $who],
                ['PATEINT_ECMS_ID' => 101, 'PAYMENT_DATE' => $this->serial('2024-06-10 09:30'), 'PAYMENT_MODE' => 'CASH', 'PAYMENT_AMOUNT' => 80, 'CREATED_BY' => $who],
            ]],
            'CLINIC_BACKUP_COPY_BU_ITEM_LIST.xlsx' => [['ITEM_ID', 'ITEM_NAME', 'PRICE', 'NEW_QTY', 'ITEM_TYPE', 'ITEM_CATEGORY', 'CREATED_BY'], [
                ['ITEM_ID' => 1, 'ITEM_NAME' => 'Ciprofloxacin', 'PRICE' => 30, 'NEW_QTY' => -103, 'ITEM_TYPE' => 'Product', 'ITEM_CATEGORY' => 'EYE DROP', 'CREATED_BY' => $who],
                ['ITEM_ID' => 2, 'ITEM_NAME' => 'Consultation', 'PRICE' => 100, 'ITEM_TYPE' => 'Service', 'ITEM_CATEGORY' => 'SERVICE', 'CREATED_BY' => $who],
            ]],
            'CLINIC_BACKUP_COPY_BU_EXPENSE.xlsx' => [['EXPENSE_ID', 'EXPENSE_DATE', 'EXPENSE_CATEGORY', 'EXPENSE_AMOUNT', 'NOTE', 'EXPENSE_DESCRIPTION', 'CREATED_BY'], [
                ['EXPENSE_ID' => 7, 'EXPENSE_DATE' => $this->serial('2024-03-08'), 'EXPENSE_CATEGORY' => 'Drinking water', 'EXPENSE_AMOUNT' => 100, 'EXPENSE_DESCRIPTION' => 'Dispenser bottle', 'CREATED_BY' => $who],
            ]],
            'notes.xlsx' => [['SOMETHING', 'ELSE'], [['SOMETHING' => 'x', 'ELSE' => 'y']]],
        ];
    }

    private function uploads(?array $export = null): array
    {
        return collect($export ?? $this->export())->map(fn ($sheet, $name) => UploadedFile::fake()->createWithContent($name, $this->xlsx(...$sheet)))->values()
            ->push(UploadedFile::fake()->createWithContent('~$CLINIC_BACKUP_COPY_BU_PAYMENTS.xlsx', 'Excel lock file'))->all();
    }

    private function checked(?array $files = null)
    {
        return Livewire::actingAs($this->admin)->test(ExternalImportComponent::class)
            ->set('clinicId', $this->clinic->id)->set('sheetFiles', $files ?? $this->uploads())->call('checkFiles')->assertHasNoErrors();
    }

    private function imported()
    {
        return $this->checked()->set('confirmation', 'IMPORT bright-eyes')->call('startImport')->assertHasNoErrors();
    }

    public function test_only_platform_admins_open_the_page_and_it_sits_beside_the_sql_import(): void
    {
        $this->actingAs(User::factory()->create())->get(route('platform.imports.other'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('platform.imports.other'))->assertOk()->assertSee('From other software')->assertSee('Check files');
        $this->actingAs($this->admin)->get(route('platform.imports'))->assertOk()->assertSee('From other software');
    }

    public function test_checking_files_recognises_each_table_and_writes_nothing_to_the_clinic(): void
    {
        $this->checked()->assertSee('Kofi Mensah')->assertSee('Ignored as exact copies')->assertSee('notes.xlsx');

        $batch = ExternalImportBatch::sole();
        $a = $batch->analysis;
        $this->assertSame('analyzed', $batch->status);
        $this->assertSame([], $a['errors']);
        $this->assertSame(3, $a['patients']['total']);
        $this->assertSame(1, $a['patients']['renumbered']);
        $this->assertSame(2, $a['patients']['no_dob']);
        $this->assertSame(1, $a['visits']);
        $this->assertSame(1, $a['future_appointments']);
        $this->assertEquals(['count' => 2, 'total' => 430], $a['payments']);
        $this->assertSame(1, $a['items']['negative_stock']);
        $this->assertSame(['complaints' => 1], $a['orphans']);
        $this->assertSame(['CLINIC_BACKUP_COPY_BU_DIAGNOSIS (1).xlsx'], $a['duplicate_files']);
        $this->assertSame(['notes.xlsx'], $a['unknown_files'], 'an Excel lock file is ignored, not reported');
        $this->assertSame(1, $a['new_diagnoses'], '"presbyopia BE" is the existing Presbyopia; only "Dry eyes" is new');
        $this->assertEqualsCanonicalizing(['email:frontdesk@old.test', 'email:kofi@bright.test', 'name:kofi mensah', 'name:locum doctor'], array_column($a['staff'], 'key'));
        $this->assertSame(0, Patient::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->count());
    }

    public function test_staff_are_matched_to_clinic_accounts_by_email_and_name(): void
    {
        $component = $this->checked();
        $this->assertSame((string) $this->doctor->id, $component->get('staff')[md5('email:kofi@bright.test')]);
        $this->assertSame((string) $this->doctor->id, $component->get('staff')[md5('name:kofi mensah')]);
        $this->assertSame('', $component->get('staff')[md5('email:frontdesk@old.test')]);
    }

    public function test_a_missing_patient_file_or_two_different_copies_of_a_table_stop_the_import(): void
    {
        $export = $this->export();
        unset($export['CLINIC_BACKUP_COPY_PATIENTS_DETAILS.xlsx']);
        $export['CLINIC_BACKUP_COPY_BU_DIAGNOSIS (1).xlsx'][1][0]['DIAGNOSIS'] = 'Cataract';
        $this->checked($this->uploads($export))->assertSee('Two different Diagnosis files')->assertSee('Patient details file')->assertDontSee('Import into Bright Eyes');
        $this->assertSame(2, count(ExternalImportBatch::sole()->analysis['errors']));
    }

    public function test_importing_brings_in_patients_visits_and_history_without_touching_finance(): void
    {
        $this->imported();
        $batch = ExternalImportBatch::sole();
        $this->assertSame('completed', $batch->status, (string) $batch->error);
        $this->assertNull($batch->stored_dir);
        $this->assertSame([], Storage::disk('local')->allFiles('external-imports'));

        $ama = DB::table('patients')->where('clinic_id', $this->clinic->id)->where('pxnumber', 'BE/24/01')->first();
        $this->assertSame('Ama Serwaa', $ama->name);
        $this->assertSame('1980-05-15', $ama->dob);
        $this->assertSame('Female', $ama->gender);
        $this->assertSame(['BE/24/01', 'BE/24/02', 'CLD-103'], DB::table('patients')->where('clinic_id', $this->clinic->id)->orderBy('pxnumber')->pluck('pxnumber')->all());

        $consultation = DB::table('consultations')->where('patient_id', $ama->id)->sole();
        $this->assertSame('2024-03-05 10:15:00', $consultation->created_at);
        $this->assertSame('Blurred near vision', $consultation->chiefComplaint);
        $this->assertStringContainsString("odq: itching+\npohx: nil", $consultation->others);
        $this->assertSame('6/9', $consultation->vaOS6m);
        $this->assertEquals(17, $consultation->IOPOD);
        $this->assertEquals(15, $consultation->IOPOS);
        $this->assertStringContainsString('- Lubricant drops × 1 — 1 drop tds', $consultation->notes);
        $this->assertStringContainsString('Questionnaire answers: Q1: Never', $consultation->notes);
        $this->assertSame($this->doctor->id, $consultation->user_id);
        $this->assertDatabaseHas('cashier_patient_clearances', ['id' => $consultation->clearance_id, 'payment_status' => 'Paid', 'doctor_status' => 1, 'clearance_date' => '2024-03-05']);

        $diagnoses = DB::table('consultation_diagnosis')->join('diagnoses', 'diagnoses.id', '=', 'consultation_diagnosis.diagnosis_id')->where('consultation_id', $consultation->id)->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Dry eyes', 'Presbyopia'], $diagnoses, 'an existing name is reused (eye suffix ignored), a new one is added');

        $rx = DB::table('refractions')->where('consultation_id', $consultation->id)->sole();
        $this->assertEquals(0.5, $rx->subjective_od_sphere);
        $this->assertEquals(-0.75, $rx->subjective_od_cylinder);
        $this->assertSame(90, (int) $rx->subjective_od_axis);
        $this->assertEquals(1.5, $rx->subjective_os_add);
        $this->assertSame('Progressive', $rx->lensType);
        $this->assertSame('6/6', $rx->refractionOD_distance_va);
        $this->assertStringContainsString('Lens treatment: BLUE CUT', $rx->refractionnotes);

        $appointments = DB::table('appointments')->where('clinic_id', $this->clinic->id)->orderBy('scheduled_at')->get();
        $this->assertSame(['Seen', 'Missed', 'Pending'], $appointments->pluck('status')->all());
        $this->assertSame(['none', 'none'], $appointments->take(2)->pluck('reminder_channel')->all(), 'no SMS for past appointments');
        $this->assertNotSame('none', $appointments[2]->reminder_channel, 'the future one gets the usual reminder');
        $this->assertNotNull($appointments[1]->missed_followup_sent_at);
        $this->assertSame([$this->doctor->id, $this->doctor->id, null], $appointments->pluck('doctor_id')->map(fn ($id) => $id === null ? null : (int) $id)->all());

        $this->assertSame(['MTN MoMo', 'Cash'], PatientLegacyPayment::withoutGlobalScopes()->orderBy('paid_at')->pluck('payment_method')->all());
        $this->assertEquals(430, PatientLegacyPayment::withoutGlobalScopes()->sum('amount'));
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('payment_transactions')->count());

        $products = DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')->where('products.clinic_id', $this->clinic->id)->orderBy('products.name')->get(['products.name', 'products.quantity', 'products.selling_price', 'categories.name as category', 'categories.type']);
        $this->assertSame([['Ciprofloxacin', 0, 'Drugs', 'drug'], ['Consultation', 0, 'Services', 'service']], $products->map(fn ($p) => [$p->name, (int) $p->quantity, $p->category, $p->type])->all());
        $cipro = DB::table('products')->where('name', 'Ciprofloxacin')->first();
        $this->assertSame(['CLD-1', today()->toDateString(), today()->addYears(2)->toDateString()], [$cipro->batch_number, $cipro->manufacture_date, $cipro->expiry_date], 'the fields every product here must have');
        $this->assertEquals(0, $cipro->cost_price);
        $this->assertDatabaseHas('legacy_expenses', ['clinic_id' => $this->clinic->id, 'expense_date' => '2024-03-08', 'amount' => 100, 'description' => 'Dispenser bottle',
            'category' => 'Drinking water', 'reference' => 'CLD-7', 'source' => 'cloudify']);
        $this->assertSame(0, DB::table('expenses')->count(), 'old expenses are history, not in the books');
        $this->assertSame(0, DB::table('expense_categories')->where('name', 'Drinking water')->count());
        $this->assertSame(['complaints' => ['patient not in the patient file' => 1]], $batch->result['skipped']);
    }

    public function test_switched_off_parts_stay_out(): void
    {
        $this->checked()->set('include.new_diagnoses', false)->set('include.expenses', false)->set('include.items', false)->set('include.appointments', false)
            ->set('confirmation', 'IMPORT bright-eyes')->call('startImport')->assertHasNoErrors();

        $this->assertSame('completed', ExternalImportBatch::sole()->status);
        $this->assertSame(0, DB::table('legacy_expenses')->count());
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('appointments')->count());
        $this->assertSame(3, DB::table('patients')->count());
        $this->assertSame(['Presbyopia'], DB::table('consultation_diagnosis')->join('diagnoses', 'diagnoses.id', '=', 'diagnosis_id')->pluck('name')->all());
        $this->assertStringContainsString('Diagnosis: presbyopia BE; Dry eyes', DB::table('consultations')->value('notes'));
    }

    public function test_the_confirmation_phrase_is_required_and_a_second_import_is_refused_until_the_first_is_undone(): void
    {
        $this->checked()->set('confirmation', 'IMPORT')->call('startImport')->assertHasErrors('confirmation');
        $this->assertSame('analyzed', ExternalImportBatch::sole()->status);

        $this->imported();
        $this->checked()->assertSee('already has an import')->set('confirmation', 'IMPORT bright-eyes')->call('startImport')->assertHasErrors('import');
        $this->assertSame(3, DB::table('patients')->count());
    }

    public function test_undo_removes_exactly_what_was_imported(): void
    {
        $before = ['diagnoses' => DB::table('diagnoses')->count(), 'expense_categories' => DB::table('expense_categories')->count()];
        $component = $this->imported()->set('undoConfirmation', 'UNDO bright-eyes')->call('undoImport')->assertHasNoErrors();

        $component->assertSee('The import was undone');
        $this->assertSame('rolled_back', ExternalImportBatch::sole()->status);
        foreach (['patients', 'consultations', 'cashier_patient_clearances', 'refractions', 'consultation_diagnosis', 'appointments', 'patient_legacy_payments', 'products', 'legacy_expenses', 'external_import_rows'] as $table)
            $this->assertSame(0, DB::table($table)->count(), $table);
        $this->assertSame($before, ['diagnoses' => DB::table('diagnoses')->count(), 'expense_categories' => DB::table('expense_categories')->count()]);
        $this->assertSame(1, DB::table('categories')->count(), 'the clinic\'s own Drugs category stays');
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'external_import.undone', 'clinic_id' => $this->clinic->id]);
    }

    public function test_undo_is_refused_once_the_clinic_has_used_an_imported_patient(): void
    {
        $component = $this->imported();
        $patient = DB::table('patients')->where('pxnumber', 'BE/24/02')->first();
        DB::table('cashier_patient_clearances')->insert(['clinic_id' => $this->clinic->id, 'branch_id' => $this->clinic->branches()->value('id'), 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->owner->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => 0, 'clearance_date' => today()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $component->set('undoConfirmation', 'UNDO bright-eyes')->call('undoImport')->assertHasErrors('undo');
        $this->assertSame('completed', ExternalImportBatch::sole()->status);
        $this->assertSame(3, DB::table('patients')->count());
    }

    public function test_a_zip_of_the_export_works_like_the_separate_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($this->export() as $name => $sheet) $zip->addFromString('backup/'.$name, $this->xlsx(...$sheet));
        $zip->addFromString('__MACOSX/backup/._junk.xlsx', 'junk');
        $zip->close();

        $this->checked([UploadedFile::fake()->createWithContent('backup.zip', file_get_contents($path))]);
        unlink($path);
        $this->assertSame(3, ExternalImportBatch::sole()->analysis['patients']['total']);
    }

    public function test_batch_numbers_stay_unique_and_valid(): void
    {
        $taken = ['cld-7125' => true];
        $a = \App\Services\ExternalImport\CloudifyImport::productDefaults('7125', now()->addDay(), $taken);
        $b = \App\Services\ExternalImport\CloudifyImport::productDefaults('ABCDEFGHIJKLMNOP', now()->subYear(), $taken);
        $this->assertSame('CLD-7125-2', $a['batch_number']);
        $this->assertSame(today()->toDateString(), $a['manufacture_date'], 'never in the future');
        $this->assertSame('CLD-ABCDEFGHI', $b['batch_number']);
        foreach ([$a, $b] as $d) $this->assertTrue(strlen($d['batch_number']) >= 4 && strlen($d['batch_number']) <= 15 && $d['expiry_date'] > $d['manufacture_date']);
    }

    public function test_the_patient_record_lists_old_payments_apart_from_bills(): void
    {
        $this->imported();
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $this->doctor->assignRole($role);
        $branch = $this->clinic->branches()->first();
        $branch->users()->attach($this->doctor->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->doctor->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($this->doctor, $this->clinic, $branch, [$branch->id]);
        $this->actingAs($this->doctor)->withSession([config('tenancy.session_keys.clinic') => $this->clinic->id, config('tenancy.session_keys.branch') => $branch->id]);
        $patient = Patient::where('pxnumber', 'BE/24/01')->firstOrFail();
        $clearance = CashierPatientClearance::create(['user_id' => $this->doctor->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])
            ->assertSee('Previous Software Payments')->assertSee('Payments in the Previous Software')->assertSee('676408328')
            ->assertSee('MTN MoMo')->assertSee('430.00')->assertSee('not in receipts, the cash book or finance reports');

        $other = Patient::where('pxnumber', 'BE/24/02')->firstOrFail();
        $otherClearance = CashierPatientClearance::create(['user_id' => $this->doctor->id, 'patient_id' => $other->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);
        Livewire::test(PatientRecordsComponent::class, ['clearance' => $otherClearance])->assertDontSee('Previous Software Payments');

        // Imported products have what the Products page needs (batch, dates), so it opens.
        Livewire::test(\App\Livewire\Admin\ProductsComponent::class)->assertOk()->assertSee('Ciprofloxacin')->assertSee('CLD-1');
    }

    public function test_the_prescription_tab_lists_previous_medications_and_adds_one_again(): void
    {
        $this->imported();
        $this->assertDatabaseHas('patient_legacy_medications', ['clinic_id' => $this->clinic->id, 'item' => 'Lubricant drops', 'quantity' => '1', 'note' => '1 drop tds', 'prescribed_on' => '2024-03-05', 'source' => 'cloudify']);
        $this->assertStringContainsString('Lubricant drops', DB::table('consultations')->where('notes', 'like', '%Lubricant%')->value('notes'), 'the note keeps the text');

        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $this->doctor->assignRole($role);
        $branch = $this->clinic->branches()->first();
        $branch->users()->attach($this->doctor->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->doctor->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($this->doctor, $this->clinic, $branch, [$branch->id]);
        $this->actingAs($this->doctor)->withSession([config('tenancy.session_keys.clinic') => $this->clinic->id, config('tenancy.session_keys.branch') => $branch->id]);

        // The clinic now stocks the drug under the same name, so "Add again" can find it.
        $drops = DB::table('products')->where('clinic_id', $this->clinic->id)->where('name', 'Ciprofloxacin')->value('id');
        DB::table('products')->where('id', $drops)->update(['name' => 'Lubricant Drops', 'quantity' => 20, 'selling_price' => 35]);
        $patient = Patient::where('pxnumber', 'BE/24/01')->firstOrFail();
        $earlier = DB::table('consultations')->where('patient_id', $patient->id)->value('id');
        $cart = \App\Models\Cart::create(['patient_id' => $patient->id, 'dispensed_by' => $this->doctor->id, 'consultation_id' => $earlier, 'product_id' => $drops, 'quantity' => 2, 'price' => 35, 'total' => 70,
            'eye' => 'Right Eye', 'frequency' => 'Twice Daily', 'duration_value' => 5, 'duration_unit' => 'days', 'status' => 'dispensed', 'is_dispensed' => true, 'purchased' => true]);
        // A prescription from another branch of the clinic shows too, marked with that branch.
        $east = $this->clinic->branches()->create(['code' => 'EAST', 'name' => 'East Legon', 'is_default' => false, 'is_active' => true]);
        DB::table('carts')->where('id', \App\Models\Cart::create(['patient_id' => $patient->id, 'dispensed_by' => $this->doctor->id, 'consultation_id' => $earlier, 'product_id' => $drops,
            'quantity' => 1, 'price' => 35, 'total' => 35, 'eye' => 'Left Eye', 'frequency' => 'At Bedtime', 'status' => 'dispensed', 'is_dispensed' => true, 'purchased' => true])->id)
            ->update(['branch_id' => $east->id, 'created_at' => now()->subYear()]);
        $clearance = CashierPatientClearance::create(['user_id' => $this->doctor->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);
        $legacy = DB::table('patient_legacy_medications')->where('patient_id', $patient->id)->value('id');

        $page = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->assertSee('Previous medications')->assertSee('Lubricant drops')->assertSee('1 drop tds')->assertSee('Previous software')
            ->assertSee('Right Eye · Twice Daily · 5 days')->assertSee('Add again')->assertSee('Left Eye · At Bedtime')->assertSee('East Legon');
        $this->assertSame(['cart', 'cart', 'legacy'], $page->viewData('previousMedications')->pluck('source')->all(), 'newest first');

        $page->call('repeatMedication', 'cart', $cart->id);
        $item = collect($page->get('productsList'))->sole();
        $this->assertSame([$drops, 1, 'Right Eye', 'Twice Daily', 5], [(int) $item['product_id'], $item['quantity'], $item['eye'], $item['frequency'], (int) $item['duration_value']]);

        $fresh = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')->call('repeatMedication', 'legacy', $legacy);
        $this->assertSame([$drops], collect($fresh->get('productsList'))->pluck('product_id')->map(fn ($id) => (int) $id)->all(), 'matched by name, whatever the case');

        DB::table('products')->where('id', $drops)->update(['name' => 'Something else']);
        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->assertSee('Not in product list')->call('repeatMedication', 'legacy', $legacy)->assertSet('productsList', []);

        // Once the clinic matches the old name to a product, "Add again" uses it whatever the product is called.
        DB::table('legacy_medication_matches')->insert(['clinic_id' => $this->clinic->id, 'name_key' => 'lubricant drops', 'product_id' => $drops, 'created_at' => now(), 'updated_at' => now()]);
        $matched = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')->call('repeatMedication', 'legacy', $legacy);
        $this->assertSame([$drops], collect($matched->get('productsList'))->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_imports_from_before_the_medications_table_get_their_drugs_from_the_notes(): void
    {
        $this->imported();
        $before = DB::table('patient_legacy_medications')->get(['patient_id', 'consultation_id', 'item', 'quantity', 'note', 'prescribed_on', 'external_import_batch_id'])->map(fn ($r) => (array) $r)->all();
        DB::table('external_import_rows')->where('table_name', 'patient_legacy_medications')->delete();
        DB::table('patient_legacy_medications')->delete();

        $migration = require database_path('migrations/2026_10_23_000003_create_patient_legacy_medications_table.php');
        $migration->backfillFromNotes();
        $migration->backfillFromNotes(); // runs once per consultation

        $this->assertEquals($before, DB::table('patient_legacy_medications')->get(['patient_id', 'consultation_id', 'item', 'quantity', 'note', 'prescribed_on', 'external_import_batch_id'])->map(fn ($r) => (array) $r)->all());
        $this->assertSame(DB::table('patient_legacy_medications')->count(), DB::table('external_import_rows')->where('table_name', 'patient_legacy_medications')->count(), 'undo knows them');
        $this->assertSame([
            ['item' => 'OPTILEX', 'quantity' => '1', 'note' => '1 DROP TDS x 14(BE)'],
            ['item' => 'Tonometry', 'quantity' => null, 'note' => null],
        ], $migration::parse("Visit type: New\n\nMedications:\n- OPTILEX × 1 — 1 DROP TDS x 14(BE)\n- Tonometry\n\nImported from Previous Software."));
        $this->assertSame([], $migration::parse('No drugs here.'));
    }
}
