<?php

namespace Tests\Feature;

use App\Models\{CashierPatientClearance, Consultations, Patient, Setting};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** A logo thousands of pixels wide (often a small file) ran the PDF engine out of memory: prints were a blank 500. */
class PrintedLogoTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 9);

        return ob_get_clean();
    }

    public function test_a_large_logo_is_shrunk_for_printing_and_a_small_one_is_kept(): void
    {
        $small = $this->png(300, 120);
        $this->assertSame($small, Setting::printableLogo($small));
        $this->assertSame('<svg/>', Setting::printableLogo('<svg/>'));

        [$width, $height] = getimagesizefromstring(Setting::printableLogo($this->png(3000, 1200)));
        $this->assertSame([Setting::PRINT_LOGO_MAX_PX, 240], [$width, $height]);
    }

    public function test_a_large_jpeg_is_shrunk_and_a_logo_that_cannot_be_used_says_why(): void
    {
        $image = imagecreatetruecolor(2400, 1000);
        ob_start();
        imagejpeg($image);
        [$width] = getimagesizefromstring(Setting::printableLogo(ob_get_clean()));
        $this->assertSame(Setting::PRINT_LOGO_MAX_PX, $width);

        Storage::fake('public');
        \Illuminate\Support\Facades\Log::spy();
        $this->ownerOn('clinic-starter');
        $settings = Setting::getSettings();
        $settings->update(['clinic_logo' => 'logos/gone.png']);
        $this->assertNull($settings->logoDataUri());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'not in storage'));
    }

    public function test_the_prescription_slip_prints_with_a_large_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/logo.png', $this->png(3000, 2000));
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        Setting::getSettings()->update(['clinic_logo' => 'logos/logo.png']);
        app(\App\Support\RequestMemo::class)->forget('');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Ama Mensah', 'contact' => '0244556677', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Red eye']);

        $this->get(route('doctor.prescription.print', $consultation))->assertOk();
    }
}
