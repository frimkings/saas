<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Notifications\ClinicBackupNotifiable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NoClinicContextSettingsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        Setting::withoutGlobalScopes()->orderBy('id')->first()?->update(['clinic_email' => 'first-clinic@clinic.test']);
        PlatformSetting::put(['support_name' => 'Acme Dev Studio', 'support_email' => 'dev@acme.test']);
    }

    public function test_settings_without_a_clinic_never_borrow_another_clinics_row()
    {
        $settings = Setting::getSettings();

        $this->assertFalse($settings->exists);
        $this->assertSame('Acme Dev Studio', $settings->clinic_name);
        $this->assertSame('dev@acme.test', $settings->clinic_email);
        $this->assertEmpty($settings->backup_extra_paths);
    }

    public function test_multi_clinic_backup_emails_go_to_the_platform()
    {
        $this->assertSame('dev@acme.test', (new ClinicBackupNotifiable)->routeNotificationForMail());
    }
}
