<?php

namespace Tests\Feature;

use App\Livewire\Admin\BranchSmsLimitsComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Messaging\BranchSmsLimits;
use App\Services\Messaging\SmsCreditService;
use App\Services\Messaging\SmsCredentials;
use App\Services\Messaging\SmsDriver;
use App\Services\SmsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** A branch with an SMS limit stops sending at zero, whatever the message; the owner adds more. */
class BranchSmsLimitsTest extends TestCase
{
    use RefreshDatabase;

    private BranchLimitFakeSmsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.eazisms' => ['url' => 'https://platform.test/sms', 'key' => 'platform-key', 'default_sender' => 'EYEPLATFORM']]);
        $this->driver = new BranchLimitFakeSmsDriver();
        $this->app->instance(SmsDriver::class, $this->driver);
    }

    /** A hosted clinic with two branches and 100 credits in its wallet, acting at Osu. */
    private function clinic(): array
    {
        config(['tenancy.enabled' => true]);
        $owner = User::factory()->create();
        $owner->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-eyes', 'deployment_mode' => 'hosted', 'status' => 'active', 'billing_email' => 'owner@brighteyes.test']);
        $osu = $clinic->branches()->create(['code' => 'OSU', 'name' => 'Osu', 'is_default' => true, 'is_active' => true]);
        $kasoa = $clinic->branches()->create(['code' => 'KAS', 'name' => 'Kasoa', 'is_default' => false, 'is_active' => true]);
        $clinic->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        foreach ([$osu, $kasoa] as $branch) $branch->users()->attach($owner->id, ['status' => 'active', 'is_default' => $branch->is($osu)]);
        $plan = SubscriptionPlan::create(['name' => 'Plan', 'code' => 'plan', 'features' => ['*'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $this->at($owner, $clinic, $osu);
        $this->actingAs($owner);
        Setting::getSettings()->update(['sms_enabled' => true]);
        app(SmsCreditService::class)->add($clinic->id, 100, 'grant', ['note' => 'Test credits']);

        return [$clinic, $osu, $kasoa, $owner];
    }

    private function at(User $user, Clinic $clinic, Branch $branch): void
    {
        app(TenantContext::class)->set($user, $clinic, $branch, $clinic->branches()->pluck('id')->all());
    }

    private function send(string $message = 'Your glasses are ready', ?int $patientId = null, ?string $template = null): array
    {
        return app(SmsService::class)->send('0241234567', $message, $patientId, $template);
    }

    public function test_a_branch_at_its_limit_sends_nothing_more_while_other_branches_carry_on(): void
    {
        Mail::fake();
        [$clinic, $osu, $kasoa, $owner] = $this->clinic();
        app(BranchSmsLimits::class)->set($osu, 3);

        $this->assertTrue($this->send(str_repeat('a', 200))['success']);    // 2 parts
        $this->assertTrue($this->send()['success']);                          // 3 of 3
        $blocked = $this->send();
        $this->assertFalse($blocked['success']);
        $this->assertSame('Osu has used all its SMS. Ask the clinic owner to add more.', $blocked['error']);

        // Nothing taken from the clinic wallet for the refused message, and it is logged as not sent.
        $this->assertSame(97, app(SmsCreditService::class)->balance($clinic->id));
        $this->assertSame(3, $osu->fresh()->sms_used);
        $this->assertDatabaseHas('sms_logs', ['branch_id' => $osu->id, 'status' => 'skipped', 'charged_credits' => 0]);
        $this->assertCount(2, $this->driver->sent);

        // Kasoa has no limit and keeps sending from the same wallet.
        $this->at($owner, $clinic, $kasoa);
        $this->assertTrue($this->send()['success']);
        $this->assertSame(1, $kasoa->fresh()->sms_used);
        $this->assertSame(96, app(SmsCreditService::class)->balance($clinic->id));

        // The owner was told at 80% (3 of 3 crosses both, so only "out" goes) and once when it ran out.
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->heading === 'Osu has run out of SMS' && $mail->hasTo('owner@brighteyes.test'));
    }

    public function test_a_message_needing_more_parts_than_are_left_is_refused_whole(): void
    {
        [, $osu] = $this->clinic();
        app(BranchSmsLimits::class)->set($osu, 1);

        $blocked = $this->send(str_repeat('a', 200));   // 2 parts, 1 left
        $this->assertSame('This message needs 2 SMS but Osu only has 1 left. Ask the clinic owner to add more.', $blocked['error']);
        $this->assertSame(0, $osu->fresh()->sms_used);
        $this->assertTrue($this->send('short')['success']);
    }

    public function test_owner_is_warned_at_eighty_percent_and_adding_sms_starts_sending_again(): void
    {
        Mail::fake();
        [, $osu] = $this->clinic();
        app(BranchSmsLimits::class)->set($osu, 5);

        foreach (range(1, 4) as $i) $this->send();   // 4 of 5 = 80%
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->heading === 'Osu is running low on SMS' && $mail->details['Left'] === '1');
        $this->send();
        $this->assertFalse($this->send()['success']);

        app(BranchSmsLimits::class)->add($osu->fresh(), 10);
        $this->assertSame(15, $osu->fresh()->sms_limit);
        $this->assertNull($osu->fresh()->sms_out_at);
        $this->assertTrue($this->send()['success']);
        $this->assertSame(6, $osu->fresh()->sms_used);
    }

    public function test_undelivered_sms_is_given_back_to_the_branch(): void
    {
        [, $osu] = $this->clinic();
        app(BranchSmsLimits::class)->set($osu, 2);

        $this->driver->result = ['success' => false, 'error' => 'Invalid number'];
        $failed = $this->send();
        $this->assertFalse($failed['success']);
        $this->assertSame(0, $osu->fresh()->sms_used);
        $this->assertDatabaseHas('sms_logs', ['id' => $failed['log_id'], 'branch_counted' => 0]);
        app(BranchSmsLimits::class)->giveBack(SmsLog::find($failed['log_id']));   // idempotent
        $this->assertSame(0, $osu->fresh()->sms_used);
    }

    public function test_birthday_and_recall_messages_count_against_the_patients_home_branch(): void
    {
        [$clinic, $osu, $kasoa, $owner] = $this->clinic();
        app(BranchSmsLimits::class)->set($kasoa, 0);
        $patient = DB::table('patients')->insertGetId(['clinic_id' => $clinic->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $owner->id,
            'pxnumber' => 'PX-1', 'name' => 'Esi', 'gender' => 'Female', 'contact' => '0241234567', 'home_branch_id' => $kasoa->id, 'created_at' => now(), 'updated_at' => now()]);

        // The scheduler runs as Osu, but Esi belongs to Kasoa, which has none left.
        $this->assertFalse($this->send('Happy birthday Esi', $patient, 'birthday_wishes')['success']);
        $this->assertSame(0, $osu->fresh()->sms_used);
        // A receipt for the same patient at Osu counts where it happened.
        $this->assertTrue($this->send('Payment received', $patient, 'payment_receipt')['success']);
        $this->assertSame(1, $osu->fresh()->sms_used);
    }

    public function test_only_the_owner_manages_limits_and_cannot_set_one_below_what_is_used(): void
    {
        [$clinic, $osu, , $owner] = $this->clinic();
        foreach (range(1, 3) as $i) $this->send();

        $screen = Livewire::test(BranchSmsLimitsComponent::class)->assertSee('Osu')->assertSee('Kasoa')->assertSee('No limit');
        $screen->set("limit.{$osu->id}", '2')->call('setLimit', $osu->id)->assertHasErrors("limit.{$osu->id}");
        $screen->set("limit.{$osu->id}", '10')->call('setLimit', $osu->id)->assertHasNoErrors();
        $this->assertSame(10, $osu->fresh()->sms_limit);
        $screen->set("add.{$osu->id}", '5')->call('addSms', $osu->id);
        $this->assertSame(15, $osu->fresh()->sms_limit);
        $this->assertDatabaseHas('audit_trails', ['event' => 'branch.sms_limit_changed']);
        $screen->call('removeLimit', $osu->id);
        $this->assertNull($osu->fresh()->sms_limit);

        $manager = User::factory()->create();
        $manager->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->actingAs($manager);
        Livewire::test(BranchSmsLimitsComponent::class)->assertForbidden();
    }
}

class BranchLimitFakeSmsDriver implements SmsDriver
{
    public array $sent = [];
    public ?array $result = null;

    public function send(SmsCredentials $credentials, string $to, string $message): array
    {
        if ($this->result) return $this->result;
        $this->sent[] = compact('to', 'message');

        return ['success' => true, 'message_id' => 'msg-' . count($this->sent)];
    }

    public function balance(SmsCredentials $credentials): array
    {
        return ['success' => true, 'response' => ['balance' => 42]];
    }
}
