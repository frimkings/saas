<?php

namespace Tests\Feature;

use App\Livewire\Platform\AnnouncementsComponent;
use App\Mail\PlatformAnnouncementMail;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\OwnerEmail;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementRecipient;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Platform\Announcements;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformAnnouncementsTest extends TestCase
{
    use DatabaseTransactions;

    private User $platformAdmin;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::findOrCreate('Super Admin', 'web');
        $this->platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->plan = SubscriptionPlan::create(['name' => 'Gold', 'code' => 'gold-' . uniqid(), 'features' => ['*'], 'base_price' => 0, 'billing_interval' => 'monthly']);
    }

    // ── Audience and recipients ──────────────────────────────────────────

    public function test_email_goes_to_owner_and_super_admins_of_the_chosen_clinics_only(): void
    {
        [$active, $admin] = $this->clinic('Active Eye', 'active', 'owner@active.test');
        [$trial] = $this->clinic('Trial Eye', 'trial', 'owner@trial.test');

        $announcement = $this->announcement(['statuses' => ['active'], 'plan_ids' => [$this->plan->id]]);
        $preview = app(Announcements::class)->preview($announcement->audience);
        $this->assertSame(1, $preview['clinics']);
        $this->assertSame(1, $preview['owners']);
        $this->assertSame(1, $preview['admins']);

        app(Announcements::class)->send($announcement);

        Mail::assertSent(PlatformAnnouncementMail::class, 2);
        Mail::assertSent(PlatformAnnouncementMail::class, fn ($mail) => $mail->hasTo('owner@active.test') && $mail->clinicName === 'Active Eye');
        Mail::assertSent(PlatformAnnouncementMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertNotSent(PlatformAnnouncementMail::class, fn ($mail) => $mail->hasTo('owner@trial.test'));
        $this->assertSame(PlatformAnnouncement::SENT, $announcement->fresh()->status);
        $this->assertSame(2, OwnerEmail::where('kind', 'announcement')->where('status', 'sent')->count());
    }

    public function test_picked_clinics_win_over_filters_and_a_clinic_without_email_is_banner_only(): void
    {
        [$picked] = $this->clinic('Picked Eye', 'trial', null, admin: false);
        $this->clinic('Other Eye', 'active', 'owner@other.test');

        $announcement = $this->announcement(['statuses' => ['active'], 'clinic_ids' => [$picked->id]]);
        app(Announcements::class)->send($announcement);

        Mail::assertNothingSent();
        $row = $announcement->recipients()->sole();
        $this->assertSame($picked->id, $row->clinic_id);
        $this->assertSame(PlatformAnnouncementRecipient::SKIPPED, $row->status);
    }

    public function test_long_lists_send_a_few_now_and_the_rest_in_scheduled_batches_and_never_twice(): void
    {
        foreach (range(1, 7) as $n) {
            $this->clinic("Clinic {$n}", 'active', "owner{$n}@clinics.test", admin: false);
        }
        $announcement = $this->announcement(['plan_ids' => [$this->plan->id]]);
        $service = app(Announcements::class);

        $service->send($announcement);
        Mail::assertSent(PlatformAnnouncementMail::class, Announcements::SEND_NOW);
        $this->assertSame(2, $announcement->recipients()->where('status', 'queued')->count());
        $this->assertSame(PlatformAnnouncement::SENDING, $announcement->fresh()->status);

        $this->artisan('platform:send-announcements')->assertSuccessful();
        Mail::assertSent(PlatformAnnouncementMail::class, 7);
        $this->assertSame(PlatformAnnouncement::SENT, $announcement->fresh()->status);

        $before = $announcement->recipients()->count();
        $service->send($announcement->fresh());
        $this->assertSame($before, $announcement->recipients()->count());
    }

    public function test_failed_emails_can_be_retried(): void
    {
        [$clinic] = $this->clinic('Retry Eye', 'active', 'owner@retry.test', admin: false);
        $announcement = $this->announcement(['clinic_ids' => [$clinic->id]]);
        app(Announcements::class)->send($announcement);

        // As if the provider had refused it.
        $row = $announcement->recipients()->sole();
        $row->update(['status' => 'failed', 'error' => 'Rate limited']);
        OwnerEmail::where('dedupe_key', $clinic->id . ':announcement:' . $announcement->id . ':owner@retry.test')->update(['status' => 'failed']);

        $this->assertSame(1, app(Announcements::class)->retryFailed($announcement->fresh()));
        $this->assertSame('sent', $row->fresh()->status);
    }

    // ── Banner ───────────────────────────────────────────────────────────

    public function test_banner_shows_to_the_clinics_super_admins_until_dismissed_or_ended(): void
    {
        [$clinic, $admin] = $this->clinic('Banner Eye', 'active', 'owner@banner.test');
        [$otherClinic, $otherAdmin] = $this->clinic('Other Banner Eye', 'active', 'owner@other-banner.test');
        $staff = User::factory()->create();
        $clinic->users()->attach($staff->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Cashier']);

        $announcement = $this->announcement(['clinic_ids' => [$clinic->id]], ['send_email' => false]);
        $service = app(Announcements::class);
        $service->send($announcement);

        $this->assertTrue($service->bannerFor($admin, $clinic->id)?->is($announcement));
        $this->assertNull($service->bannerFor($staff, $clinic->id), 'Only Super Admins see it.');
        $this->assertNull($service->bannerFor($otherAdmin, $otherClinic->id), 'Other clinics do not.');

        $this->actingAs($admin)->post(route('announcements.dismiss', $announcement))->assertNoContent();
        $this->assertNull($service->bannerFor($admin, $clinic->id));

        [, $secondAdmin] = [$clinic, User::factory()->create()];
        $clinic->users()->attach($secondAdmin->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        $this->assertNotNull($service->bannerFor($secondAdmin, $clinic->id));
        $announcement->update(['banner_until' => today()->subDay()]);
        $this->assertNull($service->bannerFor($secondAdmin, $clinic->id), 'Ended banners are gone.');
    }

    // ── Message formatting ───────────────────────────────────────────────

    public function test_message_supports_bold_lists_and_clinic_name_but_no_other_markup(): void
    {
        $html = (string) Announcements::bodyHtml("Hello [CLINIC], **act now**.\n\n- One\n- Two\n\n<script>x</script>", 'Bright Eyes');

        $this->assertStringContainsString('Hello Bright Eyes, <strong>act now</strong>.', $html);
        $this->assertStringContainsString('<li>One</li><li>Two</li>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ── Screen ───────────────────────────────────────────────────────────

    public function test_platform_admin_writes_previews_tests_and_sends(): void
    {
        [$clinic] = $this->clinic('Screen Eye', 'active', 'owner@screen.test', admin: false);
        $this->actingAs($this->platformAdmin);

        Livewire::test(AnnouncementsComponent::class)
            ->call('create')
            ->set('form.subject', 'SMS are now off')
            ->set('form.heading', 'Choose your SMS')
            ->set('form.body', 'Hello [CLINIC], please choose your messages.')
            ->set('audience.clinic_ids', [(string) $clinic->id])
            ->assertSee('Goes to')
            ->call('sendTest')
            ->assertHasNoErrors()
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('screen', 'show')
            ->assertSee('owner@screen.test');

        Mail::assertSent(PlatformAnnouncementMail::class, fn ($mail) => $mail->hasTo($this->platformAdmin->email));
        Mail::assertSent(PlatformAnnouncementMail::class, fn ($mail) => $mail->hasTo('owner@screen.test'));
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'PLATFORM_ANNOUNCEMENT_SENT']);
    }

    public function test_only_platform_admins_open_the_screen(): void
    {
        $this->actingAs(User::factory()->create())->get(route('platform.announcements'))->assertForbidden();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0: Clinic, 1: ?User} */
    private function clinic(string $name, string $status, ?string $ownerEmail, bool $admin = true): array
    {
        $clinic = Clinic::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(), 'status' => 'active',
            'deployment_mode' => 'hosted', 'billing_email' => $ownerEmail]);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->plan->id, 'status' => $status, 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $user = null;
        if ($admin) {
            $user = User::factory()->create();
            $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        }

        return [$clinic, $user];
    }

    private function announcement(array $audience, array $overrides = []): PlatformAnnouncement
    {
        return PlatformAnnouncement::create($overrides + [
            'subject' => 'Action needed', 'heading' => 'Choose your SMS', 'body' => 'Hello [CLINIC].',
            'audience' => $audience + ['statuses' => [], 'plan_ids' => [], 'modes' => [], 'clinic_ids' => []],
            'send_email' => true, 'show_banner' => true, 'status' => PlatformAnnouncement::DRAFT,
            'created_by' => $this->platformAdmin->id,
        ]);
    }
}
