<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReceiptSettingsComponent;
use App\Models\{CashierPatientClearance, Consultations, Patient, Refractions, Setting, User};
use App\Support\Clinical\Slip;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** The spectacle and drug prescription slips: A5, or two copies on one A4 sheet. */
class PrescriptionSlipsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private function visit(string $role = 'Super Admin'): array
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $r = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($r);
        $clinic = $this->activeClinic(['name' => 'Slip Clinic', 'slug' => 'slip-'.uniqid()]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $r->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $this->actingAs($user)->withSession([config('tenancy.session_keys.clinic') => $clinic->id, config('tenancy.session_keys.branch') => $branch->id]);

        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Kofi Mensah', 'contact' => '0240000001', 'dob' => '1980-02-01', 'gender' => 'Male']);
        $clearance = CashierPatientClearance::create(['user_id' => $user->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'clearance_date' => now()->toDateString()]);
        $consultation = Consultations::create(['patient_id' => $patient->id, 'user_id' => $user->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blur']);

        return compact('user', 'patient', 'consultation');
    }

    /** The page size dompdf wrote: [width, height] in points. */
    private function mediaBox(string $pdf): array
    {
        preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m);

        return [(int) round((float) $m[1]), (int) round((float) $m[2])];
    }

    public function test_the_eye_and_lens_are_read_from_what_was_recorded(): void
    {
        $this->assertSame(['RIGHT', 'LEFT', 'BOTH', 'BOTH', null], array_map([Slip::class, 'eye'], ['OD (Right Eye)', 'OS (Left Eye)', 'Both Eyes', 'OU', '']));

        $lens = Slip::lens('Progressive Blue Block Photo');
        $this->assertSame(['Single vision' => false, 'Bifocal' => false, 'Progressive' => true], $lens['design']);
        $this->assertSame(['Anti-reflective' => false, 'Blue filter' => true, 'Photochromic' => true], $lens['coatings']);
        $this->assertTrue(Slip::lens('SV AR')['design']['Single vision']);
        $this->assertTrue(Slip::lens('SV AR')['coatings']['Anti-reflective']);
    }

    public function test_a_refraction_prints_its_structured_prescription_or_the_older_free_text(): void
    {
        $structured = new Refractions(['subjective_od_sphere' => -1.25, 'subjective_od_cylinder' => -0.5, 'subjective_od_axis' => 90, 'subjective_od_add' => 2]);
        $this->assertSame(['sph' => '-1.25', 'cyl' => '-0.50', 'axis' => '090°', 'add' => '+2.00'], array_intersect_key(Slip::rx($structured, 'od'), array_flip(['sph', 'cyl', 'axis', 'add'])));

        $older = new Refractions(['refractionOD' => '-1.00/-0.50x180', 'refractionOD_distance_va' => '6/6']);
        $this->assertSame(['-1.00/-0.50x180', '6/6'], [Slip::rx($older, 'od')['text'], Slip::rx($older, 'od')['va']]);
    }

    public function test_print_refraction_gives_an_a5_pdf_or_two_copies_on_a4(): void
    {
        ['consultation' => $consultation] = $this->visit();
        $this->get(route('refraction.print', $consultation))->assertNotFound();

        Refractions::create(['user_id' => auth()->id(), 'refractionOD' => '', 'refractionOS' => '', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6', 'consultation_id' => $consultation->id, 'subjective_od_sphere' => 1, 'subjective_os_sphere' => 1.25, 'pd' => 64, 'lensType' => 'SV Blue AR']);
        $a5 = $this->get(route('refraction.print', $consultation))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame([420, 595], $this->mediaBox($a5->getContent()), 'A5 portrait');

        Livewire::test(ReceiptSettingsComponent::class)->set('prescription_paper', Slip::A4_TWO)->call('save')->assertHasNoErrors();
        $this->assertSame(Slip::A4_TWO, Setting::getSettings()->fresh()->prescription_paper);
        $two = $this->get(route('refraction.print', $consultation))->assertOk();
        $this->assertSame([842, 595], $this->mediaBox($two->getContent()), 'A4 landscape, two copies');

        $html = view('pdf.refraction-slip', ['consultation' => $consultation, 'refraction' => Refractions::first(), 'patient' => $consultation->patient,
            'settings' => Setting::getSettings(), 'paper' => Slip::A4_TWO])->render();
        $this->assertStringContainsString('Patient copy', $html);
        $this->assertStringContainsString('Clinic copy', $html);
        $this->assertStringContainsString('+1.25', $html);
        $this->assertStringContainsString('Valid until '.$consultation->created_at->copy()->addYears(2)->format('d M Y'), $html);
        $this->assertStringNotContainsString('Blur', $html, 'the complaint stays off the lab slip');
    }

    public function test_the_drug_prescription_follows_the_same_paper_choice(): void
    {
        ['consultation' => $consultation] = $this->visit('Doctor');
        $first = $this->get(route('doctor.prescription.print', $consultation))->assertOk();
        $this->assertSame([420, 595], $this->mediaBox($first->getContent()));

        Setting::getSettings()->update(['prescription_paper' => Slip::A4_TWO]);
        $this->assertSame([842, 595], $this->mediaBox($this->get(route('doctor.prescription.print', $consultation))->assertOk()->getContent()));
    }

    public function test_staff_without_a_clinical_role_cannot_print_a_refraction(): void
    {
        ['consultation' => $consultation] = $this->visit('Cashier');
        Refractions::create(['user_id' => auth()->id(), 'refractionOD' => '', 'refractionOS' => '', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6', 'consultation_id' => $consultation->id, 'subjective_od_sphere' => 1]);

        $this->get(route('refraction.print', $consultation))->assertForbidden();
    }
}
