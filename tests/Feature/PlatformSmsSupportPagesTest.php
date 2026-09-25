<?php

namespace Tests\Feature;

use App\Livewire\Platform\SmsMessagingComponent;
use App\Livewire\Platform\SupportSettingsComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformSmsSupportPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    public function test_admin_without_a_clinic_is_put_in_platform_mode_so_page_actions_work(): void
    {
        // E.g. signed back in by the remember-me cookie: the session has no workspace mode.
        $this->actingAs($this->admin())->get(route('platform.sms'))
            ->assertOk()->assertSessionHas('workspace_mode', 'platform')
            ->assertSee('Platform navigation')->assertSee('Check balance now');
    }

    public function test_sms_page_sections_switch_and_adjust_preselects_the_clinic(): void
    {
        Livewire::actingAs($this->admin())->test(SmsMessagingComponent::class)
            ->assertSet('section', 'credits')->assertSee('Adjust credits')
            ->call('setSection', 'bundles')->assertSee('Add a bundle')
            ->call('setSection', 'senders')->assertSee('No pending requests.')
            ->call('adjustFor', 7)->assertSet('creditClinicId', 7);
    }

    public function test_support_page_uses_the_platform_frame_and_shows_a_preview(): void
    {
        Livewire::actingAs($this->admin())->test(SupportSettingsComponent::class)
            ->assertSee('Platform navigation')->assertSee('What clinics see')
            ->set('name', 'Help Desk')->set('whatsapp', '0241234567')->assertSee('Contact Help Desk')->assertSee('WhatsApp 0241234567')
            ->call('save')->assertHasNoErrors()->assertSee('Support contact saved.');
    }
}
