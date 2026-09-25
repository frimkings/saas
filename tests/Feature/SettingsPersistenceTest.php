<?php

namespace Tests\Feature;

use App\Livewire\Admin\SettingsComponent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsPersistenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_discard_restores_saved_details_and_preferences_without_writing(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $setting = Setting::getSettings();
        $setting->update(['clinic_name' => 'Saved Clinic', 'currency_symbol' => '$', 'va_notation' => '20ft']);
        $before = $setting->fresh()->getAttributes();
        $registeredName = $setting->clinic->name;

        Livewire::actingAs($admin)->test(SettingsComponent::class)
            ->set('state.clinic_email', 'invalid')
            ->call('updateSettings')->assertHasErrors('state.clinic_email')
            ->set('currency_symbol', 'KSh')->set('va_notation', '6m')
            ->call('discardChanges')
            ->assertSet('state.clinic_name', $registeredName)
            ->assertSet('currency_symbol', '$')->assertSet('va_notation', '20ft')
            ->assertHasNoErrors();

        $this->assertSame($before, $setting->fresh()->getAttributes());
    }

    public function test_legacy_examples_are_empty_in_form_and_optional_fields_can_be_cleared(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $setting = Setting::getSettings();
        $setting->update([
            'clinic_name' => Setting::DEFAULT_CLINIC_NAME,
            'clinic_address' => Setting::DEFAULT_CLINIC_ADDRESS,
            'clinic_contact' => Setting::DEFAULT_CLINIC_CONTACT,
            'clinic_email' => Setting::DEFAULT_CLINIC_EMAIL,
        ]);

        Livewire::actingAs($admin)->test(SettingsComponent::class)
            ->assertSet('state.clinic_name', $setting->clinic->name)->assertSet('state.clinic_address', '')
            ->assertSet('state.clinic_contact', '')->assertSet('state.clinic_email', '')
            ->set('state.clinic_name', 'Actual Clinic')->call('updateSettings')
            ->assertHasNoErrors()->assertSet('missingSetupFields', []);

        $this->assertNull($setting->fresh()->clinic_email);
        $this->assertNull($setting->fresh()->clinic_address);
        $this->assertNull($setting->fresh()->clinic_contact);
    }

    public function test_super_admin_can_save_clinic_information(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $setting = Setting::getSettings();

        Livewire::actingAs($admin)
            ->test(SettingsComponent::class)
            ->set('state.clinic_name', 'Vision Space Clinic')
            ->set('state.clinic_address', 'Accra Central')
            ->set('state.clinic_contact', '0598882009')
            ->set('state.clinic_email', 'clinic@example.com')
            ->call('updateSettings')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $setting->refresh();

        $this->assertSame($setting->clinic->name, $setting->clinic_name);
        $this->assertNotSame('Vision Space Clinic', $setting->getRawOriginal('clinic_name'));
        $this->assertSame('Accra Central', $setting->clinic_address);
        $this->assertSame('0598882009', $setting->clinic_contact);
        $this->assertSame('clinic@example.com', $setting->clinic_email);
    }
}
