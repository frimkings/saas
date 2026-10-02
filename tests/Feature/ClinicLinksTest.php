<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClinicLinksComponent;
use App\Livewire\Admin\SmsTemplatesComponent;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\Messaging\ClinicLinks;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class ClinicLinksTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startOfflineTrial();
        Role::findOrCreate('Manager', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($this->admin);
        Setting::getSettings()->update(['clinic_link' => null, 'review_link' => null, 'whatsapp_link' => null, 'website_link' => null]);
    }

    public function test_whatsapp_numbers_become_wa_me_links(): void
    {
        $this->assertSame('https://wa.me/233241234567', ClinicLinks::whatsapp('024 123 4567'));
        $this->assertSame('https://wa.me/233241234567', ClinicLinks::whatsapp('+233241234567'));
        $this->assertSame('https://wa.me/message/ABC', ClinicLinks::whatsapp('https://wa.me/message/ABC'));
        $this->assertNull(ClinicLinks::whatsapp('  '));
    }

    public function test_settings_screen_saves_links_and_branch_overrides(): void
    {
        $default = Branch::where('clinic_id', Setting::getSettings()->clinic_id)->where('is_active', true)->orderByDesc('is_default')->first();
        $east = Branch::create(['clinic_id' => $default->clinic_id, 'code' => 'EAST' . uniqid(), 'name' => 'East Legon', 'is_active' => true, 'timezone' => 'UTC']);

        Livewire::test(ClinicLinksComponent::class)
            ->set('links.clinic_link', 'not a link')
            ->call('save')
            ->assertHasErrors('links.clinic_link')
            ->set('links.clinic_link', 'https://maps.app.goo.gl/main')
            ->set('links.whatsapp_link', '0241234567')
            ->set('links.instagram_link', 'https://instagram.com/brighteyes')
            ->set("branchLinks.{$east->id}.map_link", 'https://maps.app.goo.gl/east')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('links.whatsapp_link', 'https://wa.me/233241234567');

        $settings = Setting::getSettings()->fresh();
        $this->assertSame('https://maps.app.goo.gl/main', $settings->clinic_link);
        $this->assertSame('https://instagram.com/brighteyes', $settings->instagram_link);
        $this->assertSame('https://maps.app.goo.gl/east', $east->fresh()->map_link);

        // A branch's own link wins; the clinic's fills in otherwise. The old [LINK] is the map link.
        $this->assertSame('https://maps.app.goo.gl/east', ClinicLinks::replacements($east->fresh())['[MAP_LINK]']);
        $this->assertSame('https://wa.me/233241234567', ClinicLinks::replacements($east->fresh())['[WHATSAPP_LINK]']);
        $this->assertSame('https://maps.app.goo.gl/main', ClinicLinks::replacements($default)['[LINK]']);
    }

    public function test_messages_insert_links_by_name_and_wait_for_a_missing_link(): void
    {
        SmsTemplate::ensureDefaults();
        SmsTemplate::where('key', 'spectacles_ready')->update(['is_enabled' => true,
            'message' => 'Hello [NAME], your glasses are ready at [CLINIC]. Find us: [MAP_LINK] Chat: [WHATSAPP_LINK]']);

        $this->assertSame('', SmsTemplate::render('spectacles_ready', ['[NAME]' => 'Ama']), 'Not sent with a gap.');

        Setting::getSettings()->update(['clinic_link' => 'https://maps.app.goo.gl/x', 'whatsapp_link' => 'https://wa.me/233241234567']);
        $message = SmsTemplate::render('spectacles_ready', ['[NAME]' => 'Ama']);
        $this->assertStringContainsString('Find us: https://maps.app.goo.gl/x Chat: https://wa.me/233241234567', $message);

        // The old [LINK] keeps working.
        $this->assertStringContainsString('https://maps.app.goo.gl/x', SmsTemplate::fillMessage('Directions: [LINK]', []));
    }

    public function test_messages_page_offers_the_links_and_warns_about_unset_ones(): void
    {
        SmsTemplate::ensureDefaults();
        SmsTemplate::where('key', 'spectacles_ready')->update(['message' => 'Ready! [REVIEW_LINK]']);

        Livewire::test(SmsTemplatesComponent::class)
            ->call('edit', 'spectacles_ready')
            ->assertSee('[WHATSAPP_LINK]')
            ->assertSee('[TIKTOK]')
            ->assertSee('Not sent until [REVIEW_LINK] is set in');
    }

    public function test_managers_set_links_from_optical_settings_only(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $this->actingAs($manager);

        Livewire::test(ClinicLinksComponent::class)->assertForbidden();
        Livewire::test(ClinicLinksComponent::class, ['optical' => true])->assertOk()->assertSee('[FACEBOOK]');
    }
}
